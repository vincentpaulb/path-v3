<?php

namespace App\Http\Controllers;

use App\Models\Comment;
use App\Models\EvaluatorRemark;
use App\Models\EvaluatorRemarkReply;
use App\Models\Proposal;
use App\Models\ProposalFile;
use App\Models\ProposalDocumentStatus;
use App\Models\RequiredDocument;
use App\Models\Reply;
use App\Services\ActivityService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;

class InteractionController extends Controller
{
    public function __construct(private ActivityService $activity) {}

    private function guardProposal(Request $request, Proposal $proposal): void
    {
        abort_unless($proposal->exists && $proposal->getKey(), 404, 'Proposal not found.');

        $user = $request->user();

        if ($user->dataScope() === 'province') {
            abort_unless($proposal->province === $user->province, 403);
        }

        if ($user->isEvaluator()) {
            abort_unless(
                $proposal->evaluators()->where('evaluators.email', $user->email)->exists(),
                403,
                'This proposal is not assigned to your evaluator account.'
            );
        }
    }

    public function missing(Request $request, Proposal $proposal)
    {
        abort_if($request->user()->isEvaluator(), 403, 'Evaluator accounts cannot add administrative document-review entries.');
        $this->guardProposal($request, $proposal);
        abort_unless($request->user()->hasPermission('proposals.missing_evaluation'), 403);

        $data = $request->validate([
            'description' => 'required|string|max:5000',
            'evaluation_status' => 'required|string|max:1000',
            'moa_check' => 'nullable|string|max:1000',
        ]);

        $proposal->missingDocuments()->create($data + ['created_by' => $request->user()->id]);

        // Keep the ID-based checklist state aligned with the legacy free-text
        // document-review snapshot. Each exact document name listed here is
        // lacking; current master documents not listed are treated as submitted.
        $lines = collect(preg_split('/\r\n|\r|\n/', $data['description']))
            ->map(fn ($line) => trim($line))
            ->filter();
        $noLacking = $lines->contains(fn ($line) => strcasecmp($line, 'No lacking documents') === 0);
        $lineKeys = $lines->map(fn ($line) => strtolower($line))->flip();
        $lackingCount = 0;

        foreach (RequiredDocument::query()->orderBy('id')->get() as $document) {
            $isLacking = ! $noLacking && $lineKeys->has(strtolower($document->name));
            if ($isLacking) $lackingCount++;

            ProposalDocumentStatus::updateOrCreate(
                ['proposal_id' => $proposal->id, 'required_document_id' => $document->id],
                ['status' => $isLacking ? ProposalDocumentStatus::LACKING : ProposalDocumentStatus::SUBMITTED, 'source' => 'document_review']
            );
        }

        if (RequiredDocument::query()->exists()) {
            $proposal->update(['document_status' => $lackingCount === 0 ? 'Complete' : 'Incomplete']);
        }

        $this->activity->record($proposal, $request->user(), 'Added lacking-document evaluation');

        return back()->with('success', 'Lacking document entry added.');
    }

    public function completeDocuments(Request $request, Proposal $proposal)
    {
        abort_if($request->user()->isEvaluator(), 403, 'Evaluator accounts cannot complete administrative document reviews.');
        $this->guardProposal($request, $proposal);
        abort_unless($request->user()->hasPermission('proposals.missing_evaluation'), 403);

        $documents = RequiredDocument::query()->orderBy('id')->get();
        if ($documents->isEmpty()) {
            return back()->with('warning', 'No required documents are configured. Add the master checklist first.');
        }

        if ($proposal->document_status === 'Complete') {
            return back()->with('info', 'This proposal already has complete required documents.');
        }

        DB::transaction(function () use ($documents, $proposal, $request) {
            $existing = $proposal->documentStatuses()->get()->keyBy('required_document_id');

            foreach ($documents as $document) {
                $previousStatus = $existing->get($document->id)?->status;
                $status = $previousStatus === ProposalDocumentStatus::NOT_APPLICABLE
                    ? ProposalDocumentStatus::NOT_APPLICABLE
                    : ProposalDocumentStatus::SUBMITTED;

                ProposalDocumentStatus::updateOrCreate(
                    ['proposal_id' => $proposal->id, 'required_document_id' => $document->id],
                    ['status' => $status, 'source' => 'document_review']
                );
            }

            $proposal->update(['document_status' => 'Complete']);
            $proposal->load('evaluators');

            $evaluationStatus = $proposal->evaluators->isNotEmpty()
                ? $proposal->evaluators->map(fn ($evaluator) => sprintf(
                    '%s (%s): %s',
                    $evaluator->full_name,
                    $evaluator->classification,
                    $evaluator->pivot?->evaluation_status ?: 'Assigned'
                ))->implode("\n")
                : 'No evaluator assigned';

            $proposal->missingDocuments()->create([
                'description' => 'No lacking documents',
                'evaluation_status' => $evaluationStatus,
                'moa_check' => null,
                'created_by' => $request->user()->id,
            ]);

            $this->activity->record($proposal, $request->user(), 'Completed required documents', [
                'required' => $documents->count(),
                'lacking' => 0,
            ]);
        });

        return redirect()->to(route('proposals.show', $proposal).'#document-review')
            ->with('success', 'Required documents marked as complete.');
    }

    public function comment(Request $request, Proposal $proposal)
    {
        $this->guardProposal($request, $proposal);
        abort_unless($request->user()->hasPermission('proposals.comment'), 403);

        $data = $request->validate(['content' => 'required|string|max:3000']);
        $proposal->comments()->create($data + ['user_id' => $request->user()->id]);
        $this->activity->record($proposal, $request->user(), 'Posted a proposal discussion comment');

        return back()->with('success', 'Comment posted.');
    }

    public function reply(Request $request, Comment $comment)
    {
        $comment->loadMissing('proposal');
        $this->guardProposal($request, $comment->proposal);
        abort_unless($request->user()->hasPermission('proposals.comment'), 403);

        $data = $request->validate(['content' => 'required|string|max:2000']);
        $comment->replies()->create($data + ['user_id' => $request->user()->id]);
        $this->activity->record($comment->proposal, $request->user(), 'Replied to a proposal discussion');

        return back()->with('success', 'Reply posted.');
    }

    public function replyToEvaluatorRemark(Request $request, Proposal $proposal, EvaluatorRemark $evaluatorRemark, ?EvaluatorRemarkReply $reply = null): RedirectResponse
    {
        $this->guardEvaluatorRemarkReply($request, $proposal);
        $user = $request->user();

        $url = route('proposals.show', $proposal);
        $anchor = $reply ? '#evaluator-remark-reply-'.$reply->id : '#evaluator-remark-'.$evaluatorRemark->id;
        $errorBag = $reply ? 'nestedEvaluatorRemarkReply'.$reply->id : 'evaluatorRemarkReply'.$evaluatorRemark->id;
        try {
            $data = $request->validateWithBag($errorBag, [
                'content' => ['required', 'string', 'max:2000'],
            ]);
        } catch (ValidationException $exception) {
            throw $exception->redirectTo($url.$anchor);
        }

        $postedReply = DB::transaction(function () use ($data, $evaluatorRemark, $proposal, $user, $reply) {
            $postedReply = $evaluatorRemark->replies()->create($data + [
                'user_id' => $user->id,
                'parent_reply_id' => $reply?->id,
            ]);
            $this->activity->record($proposal, $user, 'Posted an evaluator remark reply', [
                'evaluator_remark_id' => $evaluatorRemark->id,
                'reply_id' => $postedReply->id,
            ]);

            return $postedReply;
        });

        return redirect()->to($url.($reply ? '#evaluator-remark-reply-'.$postedReply->id : $anchor))->with('success', 'Reply posted.');
    }

    public function updateEvaluatorRemarkReply(Request $request, Proposal $proposal, EvaluatorRemark $evaluatorRemark, EvaluatorRemarkReply $reply): RedirectResponse
    {
        $this->guardEvaluatorRemarkReply($request, $proposal);
        $user = $request->user();
        abort_unless((int) $reply->user_id === (int) $user->id, 403);

        $url = route('proposals.show', $proposal).'#evaluator-remark-reply-'.$reply->id;
        try {
            $data = $request->validateWithBag('editEvaluatorRemarkReply'.$reply->id, [
                'content' => ['required', 'string', 'max:2000'],
            ]);
        } catch (ValidationException $exception) {
            throw $exception->redirectTo($url);
        }

        DB::transaction(function () use ($data, $evaluatorRemark, $proposal, $reply, $user) {
            $reply->update($data);
            if ($reply->wasChanged('content')) {
                $this->activity->record($proposal, $user, 'Edited an evaluator remark reply', [
                    'evaluator_remark_id' => $evaluatorRemark->id,
                    'reply_id' => $reply->id,
                ]);
            }
        });

        return redirect()->to($url)->with('success', 'Reply updated.');
    }

    private function guardEvaluatorRemarkReply(Request $request, Proposal $proposal): void
    {
        $this->guardProposal($request, $proposal);
        $user = $request->user();
        abort_unless($user->canReplyToEvaluatorRemarks() && (
            $user->hasPermission('proposals.view') || ($proposal->isFunded() && $user->hasPermission('funded.view'))
        ), 403);
    }

    public function destroyComment(Request $request, Comment $comment)
    {
        $comment->loadMissing('proposal');
        $this->guardProposal($request, $comment->proposal);
        abort_unless($request->user()->hasPermission('proposals.comment'), 403);
        abort_unless(
            (int) $comment->user_id === (int) $request->user()->id,
            403,
            'You can only delete comments that you posted.'
        );

        $proposal = $comment->proposal;
        $comment->delete();

        $this->activity->record($proposal, $request->user(), 'Deleted own discussion comment');

        return back()->with('success', 'Comment deleted.');
    }

    public function destroyReply(Request $request, Reply $reply)
    {
        $reply->loadMissing('comment.proposal');
        $proposal = $reply->comment?->proposal;
        abort_unless($proposal, 404, 'Proposal not found.');

        $this->guardProposal($request, $proposal);
        abort_unless($request->user()->hasPermission('proposals.comment'), 403);
        abort_unless(
            (int) $reply->user_id === (int) $request->user()->id,
            403,
            'You can only delete replies that you posted.'
        );

        $reply->delete();

        $this->activity->record($proposal, $request->user(), 'Deleted own discussion reply');

        return back()->with('success', 'Reply deleted.');
    }

    public function upload(Request $request, Proposal $proposal)
    {
        abort_if($request->user()->isEvaluator(), 403, 'Evaluator accounts cannot upload proposal files.');
        $this->guardProposal($request, $proposal);
        abort_unless($request->user()->hasPermission('proposals.upload_files'), 403);

        $request->validate([
            'file' => ['required', 'file', 'max:10240', 'mimes:pdf,doc,docx,xls,xlsx,csv,txt,png,jpg,jpeg,webp,zip'],
        ], [
            'file.mimes' => 'Upload a PDF, Office document, CSV, text file, common image, or ZIP archive.',
        ]);
        $file = $request->file('file');
        $path = $file->store('proposal-files/'.$proposal->id);

        $proposal->files()->create([
            'uploaded_by' => $request->user()->id,
            'name' => $file->getClientOriginalName(),
            'path' => $path,
            'mime_type' => $file->getMimeType(),
            'size' => $file->getSize(),
        ]);

        $this->activity->record($proposal, $request->user(), 'Uploaded proposal file', [
            'name' => $file->getClientOriginalName(),
        ]);

        return back()->with('success', 'File uploaded.');
    }

    public function download(Request $request, ProposalFile $file)
    {
        $file->loadMissing('proposal');
        $this->guardProposal($request, $file->proposal);
        abort_unless($request->user()->hasPermission('proposals.download_files'), 403);
        abort_unless(Storage::exists($file->path), 404);

        return Storage::download($file->path, $file->name);
    }
}
