<?php

namespace App\Http\Controllers;

use App\Models\LiquidationDocument;
use App\Models\LiquidationFinding;
use App\Models\LiquidationRecord;
use App\Models\LiquidationTransaction;
use App\Models\Proposal;
use App\Models\ProposalAcquisition;
use App\Models\ProposalBudgetLine;
use App\Services\ActivityService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;
use PhpOffice\PhpSpreadsheet\Cell\Coordinate;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

class ProposalLiquidationController extends Controller
{
    private const REALIGNMENT_ACTION = 'Recorded equipment realignment';

    private const ACQUISITION_STATUSES = ['For Acquisition', 'Acquired', 'For Liquidation', 'Liquidated', 'Not Acquired'];
    private const TRANSACTION_TYPES = ['Obligation', 'Disbursement / Liquidation', 'Adjustment Increase', 'Adjustment Decrease', 'Refund', 'Reversal'];
    private const REFERENCE_TYPES = ['ORS/BURS', 'DV', 'JEV', 'OR', 'Sales Invoice', 'PO', 'Contract', 'Payroll', 'Acknowledgment Receipt', 'Other'];
    private const DOCUMENT_TYPES = ['Purchase Order', 'Sales Invoice / Official Receipt', 'Delivery Receipt', 'Inspection and Acceptance Report', 'Proof of Payment', 'Contract', 'Quotation', 'Equipment Photograph', 'Serial / Property Tag Photograph', 'Acknowledgment Receipt', 'Liquidation Report', 'Proof of Refund', 'Status Report', 'Other Supporting Document'];
    private const WORKFLOW_STATUSES = ['Draft', 'Submitted', 'Under Review', 'Returned for Compliance', 'Approved', 'Completed'];

    public function __construct(private ActivityService $activity) {}

    public function index(Request $request, Proposal $proposal): View
    {
        $this->guard($request, $proposal);
        $this->ensureFunded($proposal);

        $proposal->load([
            'liquidationRecord.updatedBy',
            'liquidationRecord.reviewedBy',
            'budgetLines.creator',
            'acquisitions.budgetLine',
            'acquisitions.creator',
            'liquidationTransactions.budgetLine',
            'liquidationTransactions.acquisition',
            'liquidationTransactions.encoder',
            'liquidationDocuments.acquisition',
            'liquidationDocuments.transaction',
            'liquidationDocuments.uploader',
            'liquidationFindings.acquisition',
            'liquidationFindings.transaction',
            'liquidationFindings.raisedBy',
            'liquidationFindings.resolvedBy',
        ]);

        $financials = $this->financials($proposal);
        $canManage = $request->user()->hasPermission('liquidation.manage');
        $canReview = $request->user()->hasPermission('liquidation.review');

        return view('proposals.liquidation', [
            'proposal' => $proposal,
            'financials' => $financials,
            'realignmentHistory' => $proposal->activities()
                ->where('action', self::REALIGNMENT_ACTION)
                ->with('user')
                ->latest('created_at')
                ->orderByDesc('id')
                ->get(),
            'canManage' => $canManage,
            'canReview' => $canReview,
            'acquisitionStatuses' => self::ACQUISITION_STATUSES,
            'transactionTypes' => self::TRANSACTION_TYPES,
            'referenceTypes' => self::REFERENCE_TYPES,
            'documentTypes' => self::DOCUMENT_TYPES,
            'workflowStatuses' => self::WORKFLOW_STATUSES,
        ]);
    }

    public function storeBudgetLine(Request $request, Proposal $proposal): RedirectResponse
    {
        $this->guardManage($request, $proposal);
        $data = $this->budgetLineData($request, $proposal);
        $line = $proposal->budgetLines()->create($data + ['created_by' => $request->user()->id]);
        $this->activity->record($proposal, $request->user(), 'Added liquidation approved budget item', ['budget_line_id' => $line->id, 'item' => $line->item_name, 'approved_amount' => $line->approved_amount]);
        return $this->backToLiquidation($proposal, 'Approved budget / technology item added.', 'approved-budget');
    }

    public function updateBudgetLine(Request $request, Proposal $proposal, ProposalBudgetLine $budgetLine): RedirectResponse
    {
        $this->guardManage($request, $proposal);
        $this->owns($proposal, $budgetLine);
        $data = $this->budgetLineData($request, $proposal);
        DB::transaction(function () use ($request, $proposal, $budgetLine, $data) {
            $line = $proposal->budgetLines()->lockForUpdate()->findOrFail($budgetLine->id);
            abort_if($line->added_by_realignment || $line->removed_by_realignment, 422);
            $before = $line->only(['budget_code', 'category', 'item_name', 'quantity', 'unit', 'approved_unit_cost', 'approved_amount', 'funding_source', 'fund_account']);
            $line->update(array_replace($data, ['sort_order' => $line->sort_order]));
            $this->activity->record($proposal, $request->user(), 'Updated liquidation approved budget item', ['budget_line_id' => $line->id, 'before' => $before, 'after' => $line->fresh()->only(array_keys($before))]);
        });
        return $this->backToLiquidation($proposal, 'Approved budget item updated.', 'approved-budget');
    }

    public function destroyBudgetLine(Request $request, Proposal $proposal, ProposalBudgetLine $budgetLine): RedirectResponse
    {
        $this->guardManage($request, $proposal);
        $this->owns($proposal, $budgetLine);
        return DB::transaction(function () use ($request, $proposal, $budgetLine) {
            $line = $proposal->budgetLines()->lockForUpdate()->findOrFail($budgetLine->id);
            if ($line->realignmentStatus() !== null || $proposal->budgetLines()->where('realignment_source_id', $line->id)->exists()) {
                return back()->with('warning', 'Use Realignment to remove this item from the current equipment plan.');
            }
            if ($line->acquisitions()->exists() || $line->transactions()->exists()) {
                return back()->with('warning', 'This budget item is already referenced by acquisition or ledger records and cannot be deleted.');
            }
            $line->delete();
            $this->activity->record($proposal, $request->user(), 'Deleted liquidation approved budget item', ['item' => $line->item_name]);
            return $this->backToLiquidation($proposal, 'Approved budget item deleted.', 'approved-budget');
        });
    }

    public function storeRealignment(Request $request, Proposal $proposal): RedirectResponse
    {
        $this->guardManage($request, $proposal);
        $data = $request->validateWithBag('realignment', [
            'operation' => ['required', Rule::in(['revise', 'add', 'remove'])],
            'budget_line_id' => ['required', 'integer'],
            'revised_amount' => ['exclude_unless:operation,revise', 'required', 'numeric', 'decimal:0,2', 'min:0', 'max:9999999999999.99'],
        ]);
        $operation = $data['operation'];
        $addition = $operation === 'add' ? $this->budgetLineData($request, $proposal, 'realignment') : null;

        DB::transaction(function () use ($request, $proposal, $data, $operation, $addition) {
            $source = $proposal->budgetLines()->lockForUpdate()->find($data['budget_line_id']);
            if (! $source || ! $source->canRealign($source->acquisitions()->exists())) {
                throw ValidationException::withMessages([
                    'budget_line_id' => 'Select originally approved equipment with no acquisition records that is still in the current plan.',
                ])->errorBag('realignment');
            }

            if ($operation === 'add') {
                $line = $proposal->budgetLines()->create(array_replace($addition, [
                    'approved_amount' => 0,
                    'approved_unit_cost' => 0,
                    'realigned_amount' => $addition['approved_amount'],
                    'added_by_realignment' => true,
                    'realignment_source_id' => $source->id,
                    'created_by' => $request->user()->id,
                ]));
                $before = null;
            } else {
                $line = $source;
                $before = $line->currentApprovedAmount();
                $line->update($operation === 'remove'
                    ? ['removed_by_realignment' => true]
                    : ['realigned_amount' => $data['revised_amount']]);
            }

            $this->activity->record($proposal, $request->user(), self::REALIGNMENT_ACTION, [
                'operation' => $operation,
                'budget_line_id' => $line->id,
                'original_budget_line_id' => $source->id,
                'original_item' => $source->item_name,
                'item' => $line->item_name,
                'previous_amount' => $before,
                'current_amount' => $line->currentApprovedAmount(),
            ]);
        });

        return $this->backToLiquidation($proposal, 'Equipment realignment saved.', 'approved-budget');
    }

    public function storeAcquisition(Request $request, Proposal $proposal): RedirectResponse
    {
        $this->guardManage($request, $proposal);
        $data = $this->acquisitionData($request, $proposal);
        $acquisition = DB::transaction(function () use ($proposal, $request, $data) {
            $this->ensureActiveAcquisitionLink($proposal, $data['budget_line_id'] ?? null);
            $acquisition = $proposal->acquisitions()->create($data + ['created_by' => $request->user()->id]);
            $this->syncMonitoringRecord($proposal, $request);
            return $acquisition;
        });
        $this->activity->record($proposal, $request->user(), 'Added equipment / technology acquisition', ['acquisition_id' => $acquisition->id, 'item' => $acquisition->actual_item_name, 'purchased_amount' => $acquisition->actual_cost, 'date_liquidated' => $acquisition->date_liquidated?->format('Y-m-d')]);
        return $this->backToLiquidation($proposal, 'Equipment acquisition saved.', 'acquisitions');
    }

    public function updateAcquisition(Request $request, Proposal $proposal, ProposalAcquisition $acquisition): RedirectResponse
    {
        $this->guardManage($request, $proposal);
        $this->owns($proposal, $acquisition);
        $before = $acquisition->only(['budget_line_id', 'supplier_name', 'actual_item_name', 'specifications_liquidated', 'quantity', 'unit', 'realigned_amount', 'actual_cost', 'property_number', 'date_acquired', 'date_liquidated', 'status']);
        DB::transaction(function () use ($acquisition, $request, $proposal) {
            $data = $this->acquisitionData($request, $proposal);
            $current = $proposal->acquisitions()->lockForUpdate()->findOrFail($acquisition->id);
            if ((int) ($data['budget_line_id'] ?? 0) !== (int) $current->budget_line_id) {
                $this->ensureActiveAcquisitionLink($proposal, $data['budget_line_id'] ?? null);
            }
            $current->update($data);
            $this->syncMonitoringRecord($proposal, $request);
        });
        $this->activity->record($proposal, $request->user(), 'Updated equipment / technology acquisition', ['acquisition_id' => $acquisition->id, 'before' => $before, 'after' => $acquisition->fresh()->only(array_keys($before))]);
        return $this->backToLiquidation($proposal, 'Equipment acquisition updated.', 'acquisitions');
    }

    public function destroyAcquisition(Request $request, Proposal $proposal, ProposalAcquisition $acquisition): RedirectResponse
    {
        $this->guardManage($request, $proposal);
        $this->owns($proposal, $acquisition);
        if ($acquisition->transactions()->exists() || $acquisition->documents()->exists()) {
            return back()->with('warning', 'This acquisition is already referenced by ledger transactions or documents and cannot be deleted.');
        }
        $name = $acquisition->actual_item_name;
        DB::transaction(function () use ($acquisition, $proposal, $request) {
            $acquisition->delete();
            $this->syncMonitoringRecord($proposal, $request);
        });
        $this->activity->record($proposal, $request->user(), 'Deleted equipment / technology acquisition', ['item' => $name]);
        return $this->backToLiquidation($proposal, 'Equipment acquisition deleted.', 'acquisitions');
    }

    public function storeTransaction(Request $request, Proposal $proposal): RedirectResponse
    {
        $this->guardManage($request, $proposal);
        $data = $this->transactionData($request, $proposal);
        $transaction = DB::transaction(function () use ($proposal, $request, $data) {
            $transaction = $proposal->liquidationTransactions()->create($data + ['encoded_by' => $request->user()->id]);
            $this->syncMonitoringRecord($proposal, $request);
            return $transaction;
        });
        $this->activity->record($proposal, $request->user(), 'Added liquidation ledger transaction', ['transaction_id' => $transaction->id, 'type' => $transaction->transaction_type, 'amount' => $request->input('amount')]);
        return $this->backToLiquidation($proposal, 'Ledger transaction added and monitoring totals synchronized.', 'liquidation-ledger');
    }

    public function updateTransaction(Request $request, Proposal $proposal, LiquidationTransaction $transaction): RedirectResponse
    {
        $this->guardManage($request, $proposal);
        $this->owns($proposal, $transaction);
        $before = $transaction->only(['transaction_date', 'transaction_type', 'obligation_amount', 'disbursement_amount', 'adjustment_amount', 'refund_amount', 'reference_number', 'payee']);
        DB::transaction(function () use ($transaction, $request, $proposal) {
            $transaction->update($this->transactionData($request, $proposal));
            $this->syncMonitoringRecord($proposal, $request);
        });
        $this->activity->record($proposal, $request->user(), 'Updated liquidation ledger transaction', ['transaction_id' => $transaction->id, 'before' => $before, 'after' => $transaction->fresh()->only(array_keys($before))]);
        return $this->backToLiquidation($proposal, 'Ledger transaction updated.', 'liquidation-ledger');
    }

    public function destroyTransaction(Request $request, Proposal $proposal, LiquidationTransaction $transaction): RedirectResponse
    {
        $this->guardManage($request, $proposal);
        $this->owns($proposal, $transaction);
        if ($transaction->documents()->exists()) {
            return back()->with('warning', 'Delete or reassign supporting documents attached to this ledger entry first.');
        }
        $snapshot = $transaction->only(['transaction_type', 'reference_number', 'particulars']);
        DB::transaction(function () use ($transaction, $proposal, $request) {
            $transaction->delete();
            $this->syncMonitoringRecord($proposal, $request);
        });
        $this->activity->record($proposal, $request->user(), 'Deleted liquidation ledger transaction', $snapshot);
        return $this->backToLiquidation($proposal, 'Ledger transaction deleted and monitoring totals recalculated.', 'liquidation-ledger');
    }

    public function storeDocument(Request $request, Proposal $proposal): RedirectResponse
    {
        $this->guardManage($request, $proposal);
        $data = $request->validate([
            'document_type' => ['required', Rule::in(self::DOCUMENT_TYPES)],
            'acquisition_id' => ['nullable', 'integer', Rule::exists('proposal_acquisitions', 'id')->where(fn ($q) => $q->where('proposal_id', $proposal->id))],
            'transaction_id' => ['nullable', 'integer', Rule::exists('liquidation_transactions', 'id')->where(fn ($q) => $q->where('proposal_id', $proposal->id))],
            'file' => ['required', 'file', 'max:15360', 'mimes:pdf,doc,docx,xls,xlsx,csv,txt,png,jpg,jpeg,webp,zip'],
        ]);
        $file = $request->file('file');
        $path = $file->store('liquidation-files/'.$proposal->id);
        $document = $proposal->liquidationDocuments()->create([
            'acquisition_id' => $data['acquisition_id'] ?? null,
            'transaction_id' => $data['transaction_id'] ?? null,
            'document_type' => $data['document_type'],
            'name' => $file->getClientOriginalName(),
            'path' => $path,
            'mime_type' => $file->getMimeType(),
            'size' => $file->getSize(),
            'uploaded_by' => $request->user()->id,
        ]);
        $this->activity->record($proposal, $request->user(), 'Uploaded liquidation supporting document', ['document_id' => $document->id, 'type' => $document->document_type, 'name' => $document->name]);
        return $this->backToLiquidation($proposal, 'Supporting document uploaded.', 'supporting-documents');
    }

    public function downloadDocument(Request $request, Proposal $proposal, LiquidationDocument $document)
    {
        $this->guard($request, $proposal);
        $this->owns($proposal, $document);
        abort_unless(Storage::exists($document->path), 404);
        return Storage::download($document->path, $document->name);
    }

    public function destroyDocument(Request $request, Proposal $proposal, LiquidationDocument $document): RedirectResponse
    {
        $this->guardManage($request, $proposal);
        $this->owns($proposal, $document);
        $name = $document->name;
        if (Storage::exists($document->path)) Storage::delete($document->path);
        $document->delete();
        $this->activity->record($proposal, $request->user(), 'Deleted liquidation supporting document', ['name' => $name]);
        return $this->backToLiquidation($proposal, 'Supporting document deleted.', 'supporting-documents');
    }

    public function storeFinding(Request $request, Proposal $proposal): RedirectResponse
    {
        $this->guardReview($request, $proposal);
        $data = $request->validate([
            'finding' => ['required', 'string', 'max:5000'],
            'acquisition_id' => ['nullable', 'integer', Rule::exists('proposal_acquisitions', 'id')->where(fn ($q) => $q->where('proposal_id', $proposal->id))],
            'transaction_id' => ['nullable', 'integer', Rule::exists('liquidation_transactions', 'id')->where(fn ($q) => $q->where('proposal_id', $proposal->id))],
        ]);
        $finding = $proposal->liquidationFindings()->create($data + ['status' => 'Open', 'raised_by' => $request->user()->id]);
        $this->activity->record($proposal, $request->user(), 'Added liquidation review finding', ['finding_id' => $finding->id, 'finding' => $finding->finding]);
        return $this->backToLiquidation($proposal, 'Liquidation finding added.', 'liquidation-review');
    }

    public function resolveFinding(Request $request, Proposal $proposal, LiquidationFinding $finding): RedirectResponse
    {
        $this->guardReview($request, $proposal);
        $this->owns($proposal, $finding);
        $data = $request->validate(['resolution_notes' => ['required', 'string', 'max:5000']]);
        $finding->update(['status' => 'Resolved', 'resolution_notes' => $data['resolution_notes'], 'resolved_by' => $request->user()->id, 'resolved_at' => now()]);
        $this->activity->record($proposal, $request->user(), 'Resolved liquidation review finding', ['finding_id' => $finding->id]);
        return $this->backToLiquidation($proposal, 'Finding marked resolved.', 'liquidation-review');
    }

    public function workflow(Request $request, Proposal $proposal): RedirectResponse
    {
        $this->guard($request, $proposal);
        $this->ensureFunded($proposal);
        $status = $request->validate(['workflow_status' => ['required', Rule::in(self::WORKFLOW_STATUSES)]])['workflow_status'];

        $reviewOnly = in_array($status, ['Under Review', 'Returned for Compliance', 'Approved', 'Completed'], true);
        abort_unless($request->user()->hasPermission($reviewOnly ? 'liquidation.review' : 'liquidation.manage'), 403);

        if (in_array($status, ['Approved', 'Completed'], true) && $proposal->liquidationFindings()->where('status', 'Open')->exists()) {
            return back()->with('warning', 'Resolve all open liquidation findings before approving or completing the liquidation.');
        }

        $record = $proposal->liquidationRecord()->firstOrCreate([], [
            'amount_released' => $proposal->amount,
            'amount_liquidated' => 0,
            'reported_unexpended_balance' => 0,
            'refunded_unexpended_amount' => 0,
            'liquidation_report_status' => 'No Report',
            'updated_by' => $request->user()->id,
        ]);
        $before = $record->workflow_status;
        $payload = ['workflow_status' => $status, 'updated_by' => $request->user()->id];
        if ($status === 'Submitted') $payload['submitted_at'] = now();
        if ($reviewOnly) {
            $payload['reviewed_at'] = now();
            $payload['reviewed_by'] = $request->user()->id;
        }
        if (in_array($status, ['Approved', 'Completed'], true)) {
            $payload['liquidation_report_status'] = 'Complete';
        } elseif ($status !== 'Draft' && $record->liquidation_report_status === 'No Report') {
            $payload['liquidation_report_status'] = 'Partial';
        }
        $record->update($payload);
        $this->syncMonitoringRecord($proposal, $request);
        $this->activity->record($proposal, $request->user(), 'Liquidation workflow status changed', ['from' => $before, 'to' => $status]);
        return $this->backToLiquidation($proposal, 'Liquidation workflow updated to '.$status.'.', 'liquidation-review');
    }

    public function export(Request $request, Proposal $proposal): BinaryFileResponse
    {
        $this->guard($request, $proposal);
        $this->ensureFunded($proposal);
        $proposal->load(['budgetLines', 'acquisitions.budgetLine', 'liquidationRecord']);

        $book = new Spreadsheet();
        $sheet = $book->getActiveSheet();
        $sheet->setTitle('Equipment Database');

        $headers = [
            'Province', 'Project Code', 'Proponent / Beneficiary', 'Sector', 'Address',
            'Date Approved', 'SETUP Fund Released', 'Date Released',
            'Equipment / Technology Acquired', 'Specifications as approved', 'Specifications as liquidated',
            'Quantity', 'Unit', 'Amount as approved', 'Amount as realigned', 'Purchased Amount', 'Balance',
            'SETUP Property No.', 'Date Liquidated', 'Name of Supplier', 'Date Acquired', 'Remarks', 'Realignment Status', 'Realigned From',
        ];
        $sheet->fromArray($headers, null, 'A1');

        $row = 2;
        $writtenBudgetIds = [];
        $budgetLinesById = $proposal->budgetLines->keyBy('id');
        $purchasedByBudgetLine = $proposal->acquisitions->whereNotNull('budget_line_id')
            ->groupBy('budget_line_id')->map->sum('actual_cost');

        foreach ($proposal->acquisitions->sortBy('id') as $acquisition) {
            $line = $acquisition->budgetLine;
            $firstForLine = $line && ! isset($writtenBudgetIds[$line->id]);
            if ($line) {
                $writtenBudgetIds[$line->id] = true;
            }

            $approved = $line && ! $line->added_by_realignment ? (float) $line->approved_amount : null;
            $realigned = $acquisition->realigned_amount !== null ? (float) $acquisition->realigned_amount : null;
            $purchased = (float) $acquisition->actual_cost;
            $basis = $realigned !== null ? $realigned : $approved;
            $balance = $basis !== null ? $basis - $purchased : null;
            $status = $line?->realignmentStatus();
            if ($status !== null) {
                $approved = $firstForLine ? $approved : null;
                $realigned = $firstForLine && ! $line->removed_by_realignment ? $line->currentApprovedAmount() : null;
                $balance = $realigned !== null ? $realigned - (float) $purchasedByBudgetLine[$line->id] : null;
            }

            $sheet->fromArray([
                $proposal->province,
                $proposal->proposal_code,
                $proposal->proponent,
                $proposal->sector,
                null,
                $proposal->date_approved?->format('Y-m-d'),
                (float) ($proposal->liquidationRecord?->amount_released ?? $proposal->amount),
                $proposal->date_funded?->format('Y-m-d'),
                $acquisition->actual_item_name,
                $line?->description,
                $acquisition->specifications_liquidated,
                (float) $acquisition->quantity,
                $acquisition->unit ?: $line?->unit,
                $approved,
                $realigned,
                $purchased,
                $balance,
                $acquisition->property_number,
                $acquisition->date_liquidated?->format('Y-m-d'),
                $acquisition->supplier_name,
                $acquisition->date_acquired?->format('Y-m-d'),
                $acquisition->remarks,
                $status,
                $budgetLinesById->get($line?->realignment_source_id)?->item_name,
            ], null, 'A'.$row++, true);
        }

        // Include equipment plan items that have no linked acquisition.
        foreach ($proposal->budgetLines as $line) {
            if (isset($writtenBudgetIds[$line->id])) {
                continue;
            }

            $sheet->fromArray([
                $proposal->province,
                $proposal->proposal_code,
                $proposal->proponent,
                $proposal->sector,
                null,
                $proposal->date_approved?->format('Y-m-d'),
                (float) ($proposal->liquidationRecord?->amount_released ?? $proposal->amount),
                $proposal->date_funded?->format('Y-m-d'),
                $line->item_name,
                $line->description,
                null,
                (float) $line->quantity,
                $line->unit,
                $line->added_by_realignment ? null : (float) $line->approved_amount,
                $line->realigned_amount !== null && ! $line->removed_by_realignment ? $line->currentApprovedAmount() : null,
                null,
                null,
                null,
                null,
                null,
                null,
                'No acquisition recorded.',
                $line->realignmentStatus(),
                $budgetLinesById->get($line->realignment_source_id)?->item_name,
            ], null, 'A'.$row++, true);
        }

        $sheet->freezePane('A2');
        $sheet->getStyle('A1:'.Coordinate::stringFromColumnIndex(count($headers)).'1')->getFont()->setBold(true);
        for ($col = 1; $col <= count($headers); $col++) {
            $sheet->getColumnDimension(Coordinate::stringFromColumnIndex($col))->setAutoSize(true);
        }

        $filename = 'PATH-Equipment-Liquidation-'.preg_replace('/[^A-Za-z0-9_-]+/', '-', $proposal->proposal_code ?: 'Proposal-'.$proposal->id).'.xlsx';
        $path = storage_path('app/'.$filename);
        (new Xlsx($book))->save($path);
        $book->disconnectWorksheets();

        return response()->download($path, $filename)->deleteFileAfterSend(true);
    }

    private function budgetLineData(Request $request, Proposal $proposal, string $errorBag = 'default'): array
    {
        $data = $request->validateWithBag($errorBag, [
            'item_name' => ['required', 'string', 'max:500'],
            'description' => ['nullable', 'string', 'max:5000'],
            'quantity' => ['required', 'numeric', 'gt:0', 'max:999999999'],
            'unit' => ['nullable', 'string', 'max:80'],
            'approved_amount' => ['required', 'numeric', 'min:0', 'max:9999999999999.99', ...($errorBag === 'realignment' ? ['decimal:0,2'] : [])],
        ]);

        $quantity = max(0.01, (float) $data['quantity']);
        $data['approved_unit_cost'] = round((float) $data['approved_amount'] / $quantity, 2);
        $data['category'] = 'Equipment / Technology';
        $data['sort_order'] = ($proposal->budgetLines()->max('sort_order') ?? 0) + 10;

        return $data;
    }

    private function ensureActiveAcquisitionLink(Proposal $proposal, ?int $budgetLineId): void
    {
        if ($budgetLineId === null) {
            return;
        }

        $line = $proposal->budgetLines()->lockForUpdate()->find($budgetLineId);
        if (! $line || $line->removed_by_realignment) {
            throw ValidationException::withMessages(['budget_line_id' => 'Select an item in the current equipment plan.']);
        }
    }

    private function acquisitionData(Request $request, Proposal $proposal): array
    {
        $data = $request->validate([
            'budget_line_id' => ['nullable', 'integer', Rule::exists('proposal_budget_lines', 'id')->where(fn ($q) => $q->where('proposal_id', $proposal->id))],
            'actual_item_name' => ['required', 'string', 'max:500'],
            'specifications_liquidated' => ['nullable', 'string', 'max:10000'],
            'quantity' => ['required', 'numeric', 'gt:0', 'max:999999999'],
            'unit' => ['nullable', 'string', 'max:80'],
            'realigned_amount' => ['nullable', 'numeric', 'min:0', 'max:9999999999999.99'],
            'actual_cost' => ['required', 'numeric', 'min:0', 'max:9999999999999.99'],
            'property_number' => ['nullable', 'string', 'max:180'],
            'supplier_name' => ['nullable', 'string', 'max:300'],
            'date_acquired' => ['nullable', 'date'],
            'date_liquidated' => ['nullable', 'date'],
            'remarks' => ['nullable', 'string', 'max:5000'],
        ]);

        $quantity = max(0.01, (float) $data['quantity']);
        $data['unit_cost'] = round((float) $data['actual_cost'] / $quantity, 2);

        if (! empty($data['date_liquidated'])) {
            $data['status'] = 'Liquidated';
        } elseif (! empty($data['date_acquired'])) {
            $data['status'] = 'Acquired';
        } else {
            $data['status'] = 'For Acquisition';
        }

        return $data;
    }

    private function transactionData(Request $request, Proposal $proposal): array
    {
        $data = $request->validate([
            'transaction_date' => ['required', 'date'], 'entry_number' => ['nullable', 'string', 'max:120'], 'reference_type' => ['nullable', Rule::in(self::REFERENCE_TYPES)],
            'reference_number' => ['nullable', 'string', 'max:180'], 'payee' => ['nullable', 'string', 'max:300'], 'particulars' => ['required', 'string', 'max:5000'],
            'transaction_type' => ['required', Rule::in(self::TRANSACTION_TYPES)], 'amount' => ['required', 'numeric', 'gt:0', 'max:9999999999999.99'],
            'budget_line_id' => ['nullable', 'integer', Rule::exists('proposal_budget_lines', 'id')->where(fn ($q) => $q->where('proposal_id', $proposal->id))],
            'acquisition_id' => ['nullable', 'integer', Rule::exists('proposal_acquisitions', 'id')->where(fn ($q) => $q->where('proposal_id', $proposal->id))],
            'funding_source' => ['nullable', 'string', 'max:180'], 'fund_account' => ['nullable', 'string', 'max:180'], 'status' => ['nullable', Rule::in(['Posted', 'Pending', 'Void'])],
            'remarks' => ['nullable', 'string', 'max:5000'],
        ]);
        $amount = (float) $data['amount'];
        $type = $data['transaction_type'];
        unset($data['amount']);
        $data += ['obligation_amount' => 0, 'disbursement_amount' => 0, 'adjustment_amount' => 0, 'refund_amount' => 0, 'status' => 'Posted'];
        if ($type === 'Obligation') $data['obligation_amount'] = $amount;
        elseif ($type === 'Disbursement / Liquidation') $data['disbursement_amount'] = $amount;
        elseif ($type === 'Adjustment Increase') $data['adjustment_amount'] = $amount;
        elseif ($type === 'Adjustment Decrease') $data['adjustment_amount'] = -$amount;
        elseif ($type === 'Refund') $data['refund_amount'] = $amount;
        elseif ($type === 'Reversal') $data['disbursement_amount'] = -$amount;
        return $data;
    }

    private function financials(Proposal $proposal): array
    {
        $budgetLines = $proposal->relationLoaded('budgetLines') ? $proposal->budgetLines : $proposal->budgetLines()->get();
        $transactions = $proposal->relationLoaded('liquidationTransactions') ? $proposal->liquidationTransactions : $proposal->liquidationTransactions()->get();
        $acquisitions = $proposal->relationLoaded('acquisitions') ? $proposal->acquisitions : $proposal->acquisitions()->get();
        $record = $proposal->relationLoaded('liquidationRecord') ? $proposal->liquidationRecord : $proposal->liquidationRecord()->first();

        $released = $record?->amount_released !== null ? (float) $record->amount_released : (float) $proposal->amount;
        $approvedEquipment = (float) $budgetLines->sum('approved_amount');
        $budgetLinesById = $budgetLines->keyBy('id');
        $realigned = $budgetLines->whereNotNull('realigned_amount')->sum(fn ($line) => $line->currentApprovedAmount())
            + $acquisitions->sum(fn ($acquisition) => $budgetLinesById->get($acquisition->budget_line_id)?->realignmentStatus() === null ? (float) $acquisition->realigned_amount : 0);
        $purchased = (float) $acquisitions->sum('actual_cost');

        // Equipment Database rule: Purchased Amount becomes liquidated only when Date Liquidated is supplied.
        // Keep the old ledger only as a fallback for proposals that have no equipment acquisition records yet.
        $usesEquipmentDatabase = $acquisitions->isNotEmpty();
        $liquidated = $usesEquipmentDatabase
            ? (float) $acquisitions->filter(fn ($acquisition) => $acquisition->date_liquidated !== null)->sum('actual_cost')
            : max(0, (float) $transactions->where('status', 'Posted')->sum('disbursement_amount'));

        $refunded = max(0, (float) $transactions->where('status', 'Posted')->sum('refund_amount'));
        $fundBalance = $released - $purchased;
        $purchasedNotLiquidated = max(0, $purchased - $liquidated);
        $rate = $released > 0 ? round(($liquidated / $released) * 100, 2) : 0;
        $status = $liquidated <= 0.005 ? 'Not Liquidated' : ($purchasedNotLiquidated <= 0.005 && abs($fundBalance) <= 0.005 ? 'Fully Liquidated' : 'Partially Liquidated');

        return [
            'released' => $released,
            'approved_equipment' => $approvedEquipment,
            'realigned_amount' => $realigned,
            'current_approved_equipment' => $budgetLines->sum(fn ($line) => $line->currentApprovedAmount()),
            'purchased_amount' => $purchased,
            'liquidated' => $liquidated,
            'fund_balance' => $fundBalance,
            'purchased_not_liquidated' => $purchasedNotLiquidated,
            'liquidation_rate' => $rate,
            'financial_status' => $status,
            // Backward-compatible keys still used by older export/monitoring code paths.
            'original_budget' => (float) $proposal->amount,
            'adjustments' => 0.0,
            'adjusted_budget' => (float) $proposal->amount,
            'obligated' => 0.0,
            'refunded' => $refunded,
            'documented_refund' => 0.0,
            'available_for_obligation' => max(0, $released - $purchased),
            'undisbursed_obligation' => $purchasedNotLiquidated,
            'remaining_release' => max(0, $fundBalance),
            'actual_acquisition_cost' => $purchased,
        ];
    }

    private function syncMonitoringRecord(Proposal $proposal, Request $request): void
    {
        $proposal->unsetRelation('acquisitions');
        $proposal->unsetRelation('liquidationTransactions');
        $proposal->unsetRelation('liquidationRecord');
        $financials = $this->financials($proposal);

        $record = LiquidationRecord::query()->firstOrCreate(['proposal_id' => $proposal->id], [
            'amount_released' => $proposal->amount,
            'reported_unexpended_balance' => 0,
            'proof_of_refund_received' => false,
            'liquidation_report_status' => 'No Report',
            'attachments_complete' => false,
            'status_report_submitted' => false,
            'workflow_status' => 'Draft',
            'updated_by' => $request->user()->id,
        ]);

        $payload = [
            'amount_liquidated' => $financials['liquidated'],
            'updated_by' => $request->user()->id,
        ];

        if ($financials['liquidated'] > 0.005 && $record->liquidation_report_status === 'No Report') {
            $payload['liquidation_report_status'] = 'Partial';
        }

        $record->update($payload);
    }

    private function guard(Request $request, Proposal $proposal): void
    {
        $scoped = Proposal::query()->visibleTo($request->user())->whereKey($proposal->id)->exists();
        abort_unless($scoped, 403);
        abort_unless($request->user()->hasAnyPermission(['liquidation.view', 'liquidation.manage', 'liquidation.review']), 403);
    }

    private function guardManage(Request $request, Proposal $proposal): void
    {
        $this->guard($request, $proposal);
        $this->ensureFunded($proposal);
        abort_unless($request->user()->hasPermission('liquidation.manage'), 403);
    }

    private function guardReview(Request $request, Proposal $proposal): void
    {
        $this->guard($request, $proposal);
        $this->ensureFunded($proposal);
        abort_unless($request->user()->hasPermission('liquidation.review'), 403);
    }

    private function ensureFunded(Proposal $proposal): void
    {
        abort_unless($proposal->date_funded && (float) $proposal->amount > 0, 422, 'Acquisition & Liquidation is available only for funded proposals.');
    }

    private function owns(Proposal $proposal, object $record): void
    {
        abort_unless((int) ($record->proposal_id ?? 0) === (int) $proposal->id, 404);
    }

    private function backToLiquidation(Proposal $proposal, string $message, string $anchor): RedirectResponse
    {
        return redirect()->to(route('proposals.liquidation.index', $proposal).'#'.$anchor)->with('success', $message);
    }
}
