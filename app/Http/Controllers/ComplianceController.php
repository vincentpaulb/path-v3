<?php

namespace App\Http\Controllers;

use App\Models\ComplianceEvidence;
use App\Models\ComplianceRequirement;
use App\Models\ComplianceRequirementHistory;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\Rule;
use Illuminate\View\View;
use Symfony\Component\HttpFoundation\StreamedResponse;

class ComplianceController extends Controller
{
    public function index(): View
    {
        $requirements = $this->requirements();

        return view('compliance.index', [
            'requirements' => $requirements,
            'overallStatus' => $this->overallStatus($requirements),
        ]);
    }

    public function risks(): View
    {
        return view('compliance.risks', [
            'requirements' => $this->requirements(),
            'historyEntries' => ComplianceRequirementHistory::query()
                ->with(['requirement', 'user'])
                ->latest('created_at')
                ->limit(20)
                ->get(),
        ]);
    }

    public function update(Request $request, ComplianceRequirement $requirement): RedirectResponse
    {
        $data = $request->validate([
            'status' => ['required', Rule::in(ComplianceRequirement::STATUSES)],
            'responsible_office' => [Rule::requiredIf($request->input('status') === 'approved'), 'nullable', 'string', 'max:255'],
            'reviewed_at' => [Rule::requiredIf($request->input('status') === 'approved'), 'nullable', 'date'],
            'approved_at' => [Rule::requiredIf($request->input('status') === 'approved'), 'nullable', 'date', 'after_or_equal:reviewed_at'],
            'remarks' => [Rule::requiredIf($request->input('status') === 'rejected'), 'nullable', 'string', 'max:5000'],
            'evidence' => [
                Rule::requiredIf($request->input('status') === 'approved' && ! $requirement->evidence()->exists()),
                'nullable', 'file', 'max:10240', 'mimes:pdf,doc,docx,xls,xlsx,csv,txt,png,jpg,jpeg,webp',
            ],
        ], [
            'responsible_office.required' => 'A responsible office or approver is required before approval.',
            'reviewed_at.required' => 'A review date is required before approval.',
            'approved_at.required' => 'An approval date is required before approval.',
            'approved_at.after_or_equal' => 'The approval date cannot be earlier than the review date.',
            'remarks.required' => 'Please explain why this requirement was rejected.',
            'evidence.required' => 'At least one supporting evidence file is required before approval.',
            'evidence.mimes' => 'Evidence must be a PDF, Office document, CSV, text file, or common image.',
        ]);

        $before = $requirement->only(['status', 'responsible_office', 'reviewed_at', 'approved_at', 'remarks']);
        $storedPath = null;

        try {
            DB::transaction(function () use ($request, $requirement, $data, $before, &$storedPath): void {
                unset($data['evidence']);
                if ($data['status'] !== 'approved') {
                    $data['approved_at'] = null;
                }

                $requirement->update([
                    ...$data,
                    'updated_by' => $request->user()->id,
                ]);

                $evidenceName = null;
                if ($request->hasFile('evidence')) {
                    $file = $request->file('evidence');
                    $storedPath = $file->store('compliance-evidence/'.$requirement->id);
                    $evidenceName = $file->getClientOriginalName();
                    $requirement->evidence()->create([
                        'uploaded_by' => $request->user()->id,
                        'name' => $evidenceName,
                        'path' => $storedPath,
                        'mime_type' => $file->getMimeType(),
                        'size' => $file->getSize(),
                    ]);
                }

                $requirement->history()->create([
                    'user_id' => $request->user()->id,
                    'action' => $evidenceName ? 'Updated requirement and uploaded evidence' : 'Updated requirement',
                    'from_status' => $before['status'],
                    'to_status' => $data['status'],
                    'metadata' => [
                        'before' => $before,
                        'after' => $requirement->fresh()->only(['status', 'responsible_office', 'reviewed_at', 'approved_at', 'remarks']),
                        'evidence_name' => $evidenceName,
                    ],
                    'created_at' => now(),
                ]);
            });
        } catch (\Throwable $exception) {
            if ($storedPath) {
                Storage::delete($storedPath);
            }
            throw $exception;
        }

        return back()->with('success', "Compliance requirement updated: {$requirement->title}.");
    }

    public function download(ComplianceEvidence $evidence): StreamedResponse
    {
        abort_unless(Storage::exists($evidence->path), 404);

        return Storage::download($evidence->path, $evidence->name);
    }

    private function requirements()
    {
        return ComplianceRequirement::query()
            ->with(['updatedBy', 'latestEvidence', 'evidence.uploadedBy'])
            ->withCount('evidence')
            ->ordered()
            ->get();
    }

    private function overallStatus($requirements): string
    {
        if ($requirements->isNotEmpty() && $requirements->every(fn ($item) => $item->status === 'approved')) {
            return 'Required sign-offs recorded';
        }

        if ($requirements->contains(fn ($item) => $item->status === 'rejected')) {
            return 'Compliance action required';
        }

        if ($requirements->contains(fn ($item) => $item->status === 'under_review')) {
            return 'Formal review in progress';
        }

        return config('compliance.policy_status');
    }
}
