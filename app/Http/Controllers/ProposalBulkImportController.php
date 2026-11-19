<?php

namespace App\Http\Controllers;

use App\Models\ActivityLog;
use App\Models\ProposalDocumentStatus;
use App\Models\ProposalImportBatch;
use App\Models\RequiredDocument;
use App\Services\ProposalBulkImportService;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Str;
use PhpOffice\PhpSpreadsheet\Cell\Coordinate;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Style\Alignment;
use PhpOffice\PhpSpreadsheet\Style\Fill;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;
use RuntimeException;

class ProposalBulkImportController extends Controller
{
    public function __construct(private ProposalBulkImportService $importer) {}

    public function index(Request $request)
    {
        $preview = null;
        $token = $request->session()->get('proposal_bulk_import_token');
        if ($token) {
            $payload = $this->loadPreviewPayload($token, $request->user()->id);
            if ($payload) {
                $preview = $payload['preview'] ?? null;
            } else {
                $request->session()->forget('proposal_bulk_import_token');
                $token = null;
            }
        }

        $history = ProposalImportBatch::query()
            ->with('creator')
            ->latest('id')
            ->limit(12)
            ->get();

        return view('proposals.bulk-import', [
            'preview' => $preview,
            'previewToken' => $token,
            'history' => $history,
            'requiredDocumentCount' => RequiredDocument::query()->count(),
            'maxEvaluators' => ProposalBulkImportService::MAX_EVALUATORS,
        ]);
    }

    public function historyData(Request $request, ProposalImportBatch $batch)
    {
        $search = trim((string) $request->query('search', ''));

        $query = ActivityLog::query()
            ->where('action', 'Proposal imported through Bulk Upload')
            ->where('metadata->batch_ref', $batch->batch_ref)
            ->with([
                'proposal.evaluators',
                'proposal.industrySector',
                'proposal.documentStatuses.requiredDocument',
            ]);

        (new \App\Support\DateRangeFilter($request))->apply($query, 'activity_logs.created_at');

        if ($search !== '') {
            $query->whereHas('proposal', function ($proposalQuery) use ($search) {
                $proposalQuery->where(function ($inner) use ($search) {
                    $like = '%'.$search.'%';
                    $inner->where('project_title', 'like', $like)
                        ->orWhere('proponent', 'like', $like)
                        ->orWhere('province', 'like', $like)
                        ->orWhere('sector', 'like', $like)
                        ->orWhere('status', 'like', $like)
                        ->orWhereHas('industrySector', fn ($industryQuery) => $industryQuery
                            ->where('code', 'like', $like)
                            ->orWhere('name', 'like', $like));
                });
            });
        }

        $items = $query
            ->orderBy('id')
            ->paginate(8)
            ->withQueryString();

        $items->setCollection($items->getCollection()->map(function (ActivityLog $log) {
            $proposal = $log->proposal;
            $metadata = is_array($log->metadata) ? $log->metadata : [];
            $snapshot = is_array($metadata['import_snapshot'] ?? null) ? $metadata['import_snapshot'] : null;

            if ($snapshot) {
                $documentStatuses = collect($snapshot['document_statuses'] ?? []);
                $documentSummary = [
                    'submitted' => $documentStatuses->where('status', ProposalDocumentStatus::SUBMITTED)->count(),
                    'lacking' => $documentStatuses->where('status', ProposalDocumentStatus::LACKING)->count(),
                    'na' => $documentStatuses->where('status', ProposalDocumentStatus::NOT_APPLICABLE)->count(),
                    'pending' => $documentStatuses->where('status', ProposalDocumentStatus::PENDING)->count(),
                ];

                return [
                    'source_row' => (int) ($metadata['source_row'] ?? 0),
                    'proposal_id' => $proposal?->id,
                    'archived' => (bool) ($proposal?->trashed()),
                    'snapshot_available' => true,
                    'project_title' => $snapshot['project_title'] ?? '—',
                    'proponent' => $snapshot['proponent'] ?? '—',
                    'address' => $snapshot['address'] ?? null,
                    'contact_person' => $snapshot['contact_person'] ?? null,
                    'contact_phone' => $snapshot['contact_phone'] ?? null,
                    'contact_email' => $snapshot['contact_email'] ?? null,
                    'province' => $snapshot['province'] ?? '—',
                    'sector' => $snapshot['sector'] ?? '—',
                    'industry_sector_code' => $snapshot['industry_sector_code'] ?? null,
                    'industry_sector_name' => $snapshot['industry_sector_name'] ?? null,
                    'amount' => $snapshot['amount'] ?? 0,
                    'proposal_link' => $snapshot['proposal_link'] ?? null,
                    'status' => $snapshot['status'] ?? null,
                    'funding_status' => $snapshot['funding_status'] ?? null,
                    'moa_status' => $snapshot['moa_status'] ?? null,
                    'check_status' => $snapshot['check_status'] ?? null,
                    'date_submitted' => $snapshot['date_submitted'] ?? null,
                    'date_approved' => $snapshot['date_approved'] ?? null,
                    'date_funded' => $snapshot['date_funded'] ?? null,
                    'evaluators' => collect($snapshot['evaluators'] ?? []),
                    'document_statuses' => $documentStatuses,
                    'document_summary' => $documentSummary,
                ];
            }

            $documentStatuses = collect($proposal?->documentStatuses ?? [])->map(fn ($status) => [
                'name' => $status->requiredDocument?->name ?? 'Required document',
                'status' => $status->status,
            ]);

            return [
                'source_row' => (int) ($metadata['source_row'] ?? 0),
                'proposal_id' => $proposal?->id,
                'archived' => (bool) ($proposal?->trashed()),
                'snapshot_available' => false,
                'project_title' => $proposal?->project_title ?? 'Deleted proposal',
                'proponent' => $proposal?->proponent ?? '—',
                'address' => $proposal?->address,
                'contact_person' => $proposal?->contact_person,
                'contact_phone' => $proposal?->contact_phone,
                'contact_email' => $proposal?->contact_email,
                'province' => $proposal?->province ?? '—',
                'sector' => $proposal?->sector ?? '—',
                'industry_sector_code' => $proposal?->industrySector?->code,
                'industry_sector_name' => $proposal?->industrySector?->name,
                'amount' => $proposal?->amount ?? 0,
                'proposal_link' => $proposal?->proposal_link,
                'status' => $proposal?->status,
                'funding_status' => $proposal?->funding_status,
                'moa_status' => $proposal?->moa_status,
                'check_status' => $proposal?->check_status,
                'date_submitted' => $proposal?->date_submitted?->format('Y-m-d'),
                'date_approved' => $proposal?->date_approved?->format('Y-m-d'),
                'date_funded' => $proposal?->date_funded?->format('Y-m-d'),
                'evaluators' => collect($proposal?->evaluators ?? [])->map(fn ($evaluator) => [
                    'name' => $evaluator->full_name,
                    'classification' => $evaluator->classification,
                    'evaluation_status' => $evaluator->pivot?->evaluation_status,
                    'assigned_at' => $evaluator->pivot?->created_at ? Carbon::parse($evaluator->pivot->created_at)->format('Y-m-d') : null,
                    'evaluated_at' => $evaluator->pivot?->evaluated_at ? Carbon::parse($evaluator->pivot->evaluated_at)->format('Y-m-d') : null,
                ]),
                'document_statuses' => $documentStatuses,
                'document_summary' => [
                    'submitted' => $documentStatuses->where('status', ProposalDocumentStatus::SUBMITTED)->count(),
                    'lacking' => $documentStatuses->where('status', ProposalDocumentStatus::LACKING)->count(),
                    'na' => $documentStatuses->where('status', ProposalDocumentStatus::NOT_APPLICABLE)->count(),
                    'pending' => $documentStatuses->where('status', ProposalDocumentStatus::PENDING)->count(),
                ],
            ];
        }));

        return view('proposals.bulk-import-history-data', [
            'batch' => $batch->load('creator'),
            'items' => $items,
            'search' => $search,
        ]);
    }

    public function template(Request $request)
    {
        if (strtolower((string) $request->query('format')) === 'csv') {
            $headers = $this->importer->templateHeaders();
            $filename = 'PATH-Proposal-Bulk-Import-Template-'.now()->format('Y-m-d').'.csv';

            return response()->streamDownload(function () use ($headers) {
                $out = fopen('php://output', 'wb');
                fwrite($out, "\xEF\xBB\xBF");
                fputcsv($out, $headers);
                fclose($out);
            }, $filename, ['Content-Type' => 'text/csv; charset=UTF-8']);
        }

        $headers = $this->importer->templateHeaders();
        $filename = 'PATH-Proposal-Bulk-Import-Template-'.now()->format('Y-m-d').'.xlsx';

        return response()->streamDownload(function () use ($headers) {
            $spreadsheet = new Spreadsheet;
            $sheet = $spreadsheet->getActiveSheet();
            $sheet->setTitle('Proposals');
            $sheet->fromArray($headers, null, 'A1');

            $lastColumn = Coordinate::stringFromColumnIndex(count($headers));
            $sheet->freezePane('A2');
            $sheet->setAutoFilter("A1:{$lastColumn}1");
            $sheet->getRowDimension(1)->setRowHeight(36);
            $sheet->getStyle("A1:{$lastColumn}1")->applyFromArray([
                'font' => ['bold' => true, 'color' => ['rgb' => 'FFFFFF']],
                'fill' => ['fillType' => Fill::FILL_SOLID, 'startColor' => ['rgb' => '123A58']],
                'alignment' => ['vertical' => Alignment::VERTICAL_CENTER, 'wrapText' => true],
            ]);

            foreach ($headers as $index => $header) {
                $column = Coordinate::stringFromColumnIndex($index + 1);
                $width = match ($header) {
                    'Proposal Title', 'Proponent / Firm', 'Address' => 34,
                    'Contact Person', 'Contact Email', 'Proposal Link' => 25,
                    default => str_starts_with($header, 'DOC | ') ? 24 : 18,
                };
                $sheet->getColumnDimension($column)->setWidth($width);
            }

            $instructions = $spreadsheet->createSheet();
            $instructions->setTitle('Instructions');
            $instructions->fromArray([
                ['PATH Proposal Bulk Import'],
                ['Enter one proposal per row on the Proposals sheet. Do not rename or remove required column headers.'],
                ['Required fields', 'Proposal Title, Proponent / Firm, Sector, Industry Sector, Province, Proposal Amount, Proposal Status, and Date Submitted.'],
                ['Address and contacts', 'Address, Contact Person, Contact Phone, and Contact Email are optional. Contact Email must be a valid email address.'],
                ['Map coordinates', 'Latitude and Longitude are optional, but must be entered together. Latitude range: -90 to 90. Longitude range: -180 to 180. Use decimal degrees, preferably 7 decimal places.'],
                ['Dates', 'Recommended format: YYYY-MM-DD. Excel date cells are also accepted.'],
                ['Document status', 'Use Submitted, Lacking, N/A, Pending, or leave blank for Pending.'],
                ['Evaluators', 'Use the exact evaluator full name or email. Status values: Assigned, Started, Ongoing, or Approved.'],
            ], null, 'A1');
            $instructions->getColumnDimension('A')->setWidth(24);
            $instructions->getColumnDimension('B')->setWidth(110);
            $instructions->getStyle('A1:B8')->getAlignment()->setWrapText(true)->setVertical(Alignment::VERTICAL_TOP);
            $instructions->getStyle('A1:B1')->getFont()->setBold(true)->setSize(16)->getColor()->setRGB('123A58');
            $instructions->getStyle('A3:A8')->getFont()->setBold(true);

            (new Xlsx($spreadsheet))->save('php://output');
            $spreadsheet->disconnectWorksheets();
        }, $filename, ['Content-Type' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet']);
    }

    public function preview(Request $request)
    {
        $request->validate([
            'import_file' => ['required', 'file', 'max:10240'],
        ]);

        $file = $request->file('import_file');
        $originalName = $file->getClientOriginalName();
        $extension = strtolower($file->getClientOriginalExtension());
        if (! in_array($extension, ['csv', 'txt', 'xlsx'], true)) {
            return back()->with('error', 'Upload a CSV or XLSX file.');
        }

        $directory = storage_path('app/private/proposal-imports');
        File::ensureDirectoryExists($directory);
        $token = (string) Str::uuid();
        $uploadedPath = $directory.DIRECTORY_SEPARATOR.$token.'.'.$extension;
        $file->move($directory, basename($uploadedPath));

        try {
            $preview = $this->importer->parseAndValidate($uploadedPath, $originalName, $request->user());
        } catch (\Throwable $e) {
            @unlink($uploadedPath);

            return back()->with('error', $e instanceof RuntimeException ? $e->getMessage() : 'PATH could not validate the uploaded file: '.$e->getMessage());
        }

        @unlink($uploadedPath);

        file_put_contents($this->previewPath($token), json_encode([
            'user_id' => $request->user()->id,
            'created_at' => now()->toIso8601String(),
            'preview' => $preview,
        ], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));

        $request->session()->put('proposal_bulk_import_token', $token);

        return redirect()->route('proposals.bulk-import.index')->with(
            $preview['error_rows'] > 0 ? 'warning' : 'success',
            $preview['error_rows'] > 0
                ? "Validation completed. {$preview['valid_rows']} row(s) can be imported; {$preview['error_rows']} row(s) need correction."
                : "Validation completed. All {$preview['valid_rows']} proposal row(s) are ready to import."
        );
    }

    public function commit(Request $request)
    {
        $data = $request->validate(['preview_token' => ['required', 'string', 'max:80']]);
        $payload = $this->loadPreviewPayload($data['preview_token'], $request->user()->id);
        if (! $payload || empty($payload['preview'])) {
            return redirect()->route('proposals.bulk-import.index')->with('error', 'The import preview expired. Upload the file again and revalidate it.');
        }

        $preview = $payload['preview'];
        if (($preview['valid_rows'] ?? 0) < 1) {
            return redirect()->route('proposals.bulk-import.index')->with('error', 'There are no valid rows to import. Correct the file and upload it again.');
        }

        $batchRef = 'IMP-'.now()->format('Ymd-His').'-'.strtoupper(Str::random(5));
        $result = $this->importer->importValidRows($preview, $request->user(), $batchRef);

        ProposalImportBatch::create([
            'batch_ref' => $batchRef,
            'original_filename' => $preview['original_filename'] ?? 'Historical proposal import',
            'status' => $result['failed'] > 0 ? 'Completed with errors' : 'Completed',
            'total_rows' => (int) ($preview['total_rows'] ?? 0),
            'valid_rows' => (int) ($preview['valid_rows'] ?? 0),
            'imported_rows' => (int) $result['imported'],
            'skipped_rows' => (int) ($preview['error_rows'] ?? 0),
            'failed_rows' => (int) $result['failed'],
            'metadata' => [
                'warning_rows' => (int) ($preview['warning_rows'] ?? 0),
                'failures' => array_slice($result['failures'] ?? [], 0, 25),
            ],
            'created_by' => $request->user()->id,
        ]);

        @unlink($this->previewPath($data['preview_token']));
        $request->session()->forget('proposal_bulk_import_token');

        if ($result['failed'] > 0) {
            return redirect()->route('proposals.bulk-import.index')->with(
                'warning',
                "Bulk import {$batchRef} finished: {$result['imported']} imported, {$result['failed']} failed during save, and ".($preview['error_rows'] ?? 0).' validation-error row(s) were skipped.'
            );
        }

        return redirect()->route('proposals.bulk-import.index')->with(
            'success',
            "Bulk import {$batchRef} completed. {$result['imported']} proposal(s) imported; ".($preview['error_rows'] ?? 0).' validation-error row(s) were skipped.'
        );
    }

    public function discard(Request $request)
    {
        $token = (string) $request->input('preview_token', $request->session()->get('proposal_bulk_import_token', ''));
        if ($token !== '') {
            $payload = $this->loadPreviewPayload($token, $request->user()->id);
            if ($payload) {
                @unlink($this->previewPath($token));
            }
        }
        $request->session()->forget('proposal_bulk_import_token');

        return redirect()->route('proposals.bulk-import.index')->with('info', 'Import preview discarded. No proposal records were changed.');
    }

    private function previewPath(string $token): string
    {
        $safe = preg_replace('/[^a-zA-Z0-9-]/', '', $token);

        return storage_path('app/private/proposal-imports/'.$safe.'.json');
    }

    private function loadPreviewPayload(string $token, int $userId): ?array
    {
        $path = $this->previewPath($token);
        if (! is_file($path)) {
            return null;
        }

        $payload = json_decode((string) file_get_contents($path), true);
        if (! is_array($payload) || (int) ($payload['user_id'] ?? 0) !== $userId) {
            return null;
        }

        $created = strtotime((string) ($payload['created_at'] ?? ''));
        if (! $created || $created < now()->subHours(4)->timestamp) {
            @unlink($path);

            return null;
        }

        return $payload;
    }
}
