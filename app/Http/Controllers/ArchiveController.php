<?php

namespace App\Http\Controllers;

use App\Models\{Evaluator, Proposal, RequiredDocument, User};
use App\Services\ActivityService;
use Illuminate\Database\QueryException;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;

class ArchiveController extends Controller
{
    public function __construct(private ActivityService $activity) {}

    public function index(Request $request, ?string $section = null)
    {
        new \App\Support\DateRangeFilter($request);
        $allowed = $this->allowedSections($request->user());
        abort_if($allowed === [], 403, 'Your role has no archived record categories assigned.');

        $section ??= $allowed[0];
        abort_unless(in_array($section, $allowed, true), 403);

        $search = trim((string) $request->input('search'));

        $counts = [
            'proposals' => in_array('proposals', $allowed, true) ? $this->proposalBase($request->user())->count() : 0,
            'evaluators' => in_array('evaluators', $allowed, true) ? Evaluator::onlyTrashed()->count() : 0,
            'users' => in_array('users', $allowed, true) ? User::onlyTrashed()->where('role', '!=', 'Evaluator')->count() : 0,
            'required-documents' => in_array('required-documents', $allowed, true) ? RequiredDocument::onlyTrashed()->count() : 0,
        ];

        $items = match ($section) {
            'proposals' => $this->proposalArchive($request->user(), $search),
            'evaluators' => $this->evaluatorArchive($search),
            'users' => $this->userArchive($search),
            'required-documents' => $this->requiredDocumentArchive($search),
        };

        return view('archives.index', compact('section', 'allowed', 'counts', 'items', 'search'));
    }

    public function restoreProposal(Request $request, int $id)
    {
        abort_unless($request->user()->hasPermission('proposals.restore'), 403);
        $proposal = $this->proposalBase($request->user())->findOrFail($id);
        $proposal->restore();
        $proposal->forceFill(['deleted_by' => null])->save();
        $this->activity->record($proposal, $request->user(), 'Restored proposal from Archives');

        return back()->with('success', 'Proposal restored to active records.');
    }

    public function forceDeleteProposal(Request $request, int $id)
    {
        abort_unless($request->user()->isAdmin(), 403);
        $proposal = Proposal::onlyTrashed()->with('files')->findOrFail($id);
        $storedPaths = $proposal->files->pluck('path')->filter()->values()->all();

        DB::transaction(fn () => $proposal->forceDelete());

        if ($storedPaths !== []) {
            Storage::delete($storedPaths);
        }

        return back()->with('success', 'Proposal permanently deleted. This action cannot be reversed.');
    }

    public function restoreEvaluator(Request $request, int $id)
    {
        abort_unless($request->user()->hasPermission('evaluators.restore'), 403);
        $evaluator = Evaluator::onlyTrashed()->with('account')->findOrFail($id);

        DB::transaction(function () use ($evaluator) {
            $evaluator->restore();
            $evaluator->forceFill(['deleted_by' => null])->save();

            if ($evaluator->account?->trashed()) {
                $evaluator->account->restore();
                $evaluator->account->forceFill(['deleted_by' => null])->save();
            }
        });

        return back()->with('success', 'Evaluator restored. The linked portal account is active again.');
    }

    public function forceDeleteEvaluator(Request $request, int $id)
    {
        abort_unless($request->user()->isAdmin(), 403);
        $evaluator = Evaluator::onlyTrashed()->with('account')->findOrFail($id);
        $account = $evaluator->account;

        DB::transaction(function () use ($evaluator, $account) {
            $evaluator->forceDelete();

            if ($account?->trashed() && ! $this->userHasProtectedHistory($account)) {
                $account->forceDelete();
            }
        });

        return back()->with('success', 'Evaluator permanently deleted. Any portal identity still needed by historical records remains safely archived.');
    }

    public function restoreUser(Request $request, int $id)
    {
        abort_unless($request->user()->hasPermission('users.restore'), 403);
        $user = User::onlyTrashed()->where('role', '!=', 'Evaluator')->findOrFail($id);
        $user->restore();
        $user->forceFill(['deleted_by' => null])->save();

        return back()->with('success', 'User account restored.');
    }

    public function forceDeleteUser(Request $request, int $id)
    {
        abort_unless($request->user()->isAdmin(), 403);
        $user = User::onlyTrashed()->where('role', '!=', 'Evaluator')->findOrFail($id);

        if ($this->userHasProtectedHistory($user)) {
            return back()->with('error', 'This user cannot be permanently deleted because proposal, document, comment, file, or evaluator history still references the account. Keep the account archived to preserve record integrity.');
        }

        try {
            $user->forceDelete();
        } catch (QueryException) {
            return back()->with('error', 'This user is still referenced by system records and cannot be permanently deleted.');
        }

        return back()->with('success', 'User account permanently deleted.');
    }

    public function restoreRequiredDocument(Request $request, int $id)
    {
        abort_unless($request->user()->hasPermission('required_documents.restore'), 403);
        $document = RequiredDocument::onlyTrashed()->findOrFail($id);
        $document->restore();
        $document->forceFill(['deleted_by' => null])->save();

        return back()->with('success', 'Required document restored to the active checklist.');
    }

    public function forceDeleteRequiredDocument(Request $request, int $id)
    {
        abort_unless($request->user()->isAdmin(), 403);
        $document = RequiredDocument::onlyTrashed()->findOrFail($id);
        $document->forceDelete();

        return back()->with('success', 'Required document permanently deleted. Historical proposal review text remains unchanged.');
    }

    private function allowedSections(User $user): array
    {
        $sections = [];

        if ($user->hasAnyPermission(['proposals.archive', 'proposals.restore'])) {
            $sections[] = 'proposals';
        }
        if ($user->hasAnyPermission(['evaluators.archive', 'evaluators.restore'])) {
            $sections[] = 'evaluators';
        }
        if ($user->hasAnyPermission(['users.archive', 'users.restore'])) {
            $sections[] = 'users';
        }
        if ($user->hasAnyPermission(['required_documents.archive', 'required_documents.restore'])) {
            $sections[] = 'required-documents';
        }

        return $sections;
    }

    private function proposalBase(User $user)
    {
        return Proposal::onlyTrashed()->visibleTo($user);
    }

    private function proposalArchive(User $user, string $search)
    {
        return $this->proposalBase($user)
            ->with(['owner', 'deletedBy'])
            ->when($search !== '', fn ($query) => $query->where(fn ($q) => $q
                ->where('project_title', 'like', "%{$search}%")
                ->orWhere('proponent', 'like', "%{$search}%")
                ->orWhere('sector', 'like', "%{$search}%")
                ->orWhere('province', 'like', "%{$search}%")))
            ->tap(fn ($q) => (new \App\Support\DateRangeFilter(request()))->apply($q, 'deleted_at'))
            ->orderByDesc('deleted_at')
            ->paginate(8)
            ->withQueryString();
    }

    private function evaluatorArchive(string $search)
    {
        return Evaluator::onlyTrashed()
            ->with(['deletedBy', 'account'])
            ->when($search !== '', fn ($query) => $query->where(fn ($q) => $q
                ->where('first_name', 'like', "%{$search}%")
                ->orWhere('last_name', 'like', "%{$search}%")
                ->orWhere('email', 'like', "%{$search}%")
                ->orWhere('organization', 'like', "%{$search}%")))
            ->tap(fn ($q) => (new \App\Support\DateRangeFilter(request()))->apply($q, 'deleted_at'))
            ->orderByDesc('deleted_at')
            ->paginate(8)
            ->withQueryString();
    }

    private function userArchive(string $search)
    {
        return User::onlyTrashed()
            ->where('role', '!=', 'Evaluator')
            ->with(['deletedBy', 'accessRole'])
            ->when($search !== '', fn ($query) => $query->where(fn ($q) => $q
                ->where('first_name', 'like', "%{$search}%")
                ->orWhere('last_name', 'like', "%{$search}%")
                ->orWhere('username', 'like', "%{$search}%")
                ->orWhere('email', 'like', "%{$search}%")))
            ->tap(fn ($q) => (new \App\Support\DateRangeFilter(request()))->apply($q, 'deleted_at'))
            ->orderByDesc('deleted_at')
            ->paginate(8)
            ->withQueryString();
    }

    private function requiredDocumentArchive(string $search)
    {
        return RequiredDocument::onlyTrashed()
            ->with('deletedBy')
            ->when($search !== '', fn ($query) => $query->where('name', 'like', "%{$search}%"))
            ->tap(fn ($q) => (new \App\Support\DateRangeFilter(request()))->apply($q, 'deleted_at'))
            ->orderByDesc('deleted_at')
            ->paginate(8)
            ->withQueryString();
    }

    private function userHasProtectedHistory(User $user): bool
    {
        $id = $user->id;

        return DB::table('proposals')->where('user_id', $id)->exists()
            || DB::table('missing_documents')->where('created_by', $id)->exists()
            || DB::table('comments')->where('user_id', $id)->exists()
            || DB::table('replies')->where('user_id', $id)->exists()
            || DB::table('proposal_files')->where('uploaded_by', $id)->exists()
            || DB::table('evaluator_remarks')->where('user_id', $id)->exists();
    }
}
