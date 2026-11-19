<?php

namespace App\Services;

use App\Models\{Proposal, RentalRecord, RentalRecordImportBatch, RentalRecordImportFile, User};
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use RuntimeException;

class RentalRecordBulkImportService
{
    public const MAX_FILES = 200;
    public const MAX_ROWS_PER_FILE = 1000;
    public const MAX_UNCOMPRESSED_BYTES = 150000000; // 150 MB

    public function __construct(
        private RentalRecordService $rentalRecords,
        private ActivityService $activity,
    ) {}

    /**
     * A ZIP represents a batch. Each spreadsheet inside the ZIP represents one
     * proposal and can contain its complete rental schedule/payment history.
     */
    public function parseZip(string $zipPath, string $originalName, User $user): array
    {
        try {
            $archive = new \PharData($zipPath);
        } catch (\Throwable $e) {
            throw new RuntimeException('Unable to open the ZIP file. Create a standard .zip archive containing the proposal rental-record spreadsheets and retry.');
        }

        $entries = [];
        $totalBytes = 0;

        foreach (new \RecursiveIteratorIterator($archive) as $file) {
            if (! $file->isFile()) {
                continue;
            }

            $pathName = str_replace('\\', '/', (string) $file->getPathName());
            $relative = preg_replace('#^phar://.+?\.zip/#i', '', $pathName) ?: basename($pathName);
            $base = basename($relative);

            if ($base === '' || str_starts_with($base, '.') || str_starts_with($base, '._') || str_contains($relative, '__MACOSX')) {
                continue;
            }

            $extension = strtolower(pathinfo($base, PATHINFO_EXTENSION));
            if (! in_array($extension, ['xlsx', 'csv', 'txt'], true)) {
                continue;
            }

            $size = (int) $file->getSize();
            $totalBytes += $size;
            if ($totalBytes > self::MAX_UNCOMPRESSED_BYTES) {
                throw new RuntimeException('The ZIP expands beyond the 150 MB safety limit. Split the rental files into smaller ZIP batches.');
            }

            $entries[] = ['entry' => $file, 'name' => $base, 'size' => $size, 'extension' => $extension];
        }

        if (! $entries) {
            throw new RuntimeException('No XLSX or CSV rental-record files were found inside the ZIP.');
        }
        if (count($entries) > self::MAX_FILES) {
            throw new RuntimeException('This ZIP contains more than '.self::MAX_FILES.' supported files. Split it into smaller batches.');
        }

        $tmpDir = storage_path('app/private/rental-imports/'.Str::uuid());
        if (! is_dir($tmpDir) && ! mkdir($tmpDir, 0775, true) && ! is_dir($tmpDir)) {
            throw new RuntimeException('Unable to prepare a temporary import directory.');
        }

        $files = [];
        $seenProposalIds = [];

        try {
            foreach ($entries as $index => $entryInfo) {
                $safeName = sprintf('%03d-%s', $index + 1, preg_replace('/[^A-Za-z0-9._-]+/', '_', $entryInfo['name']));
                $tmpPath = $tmpDir.DIRECTORY_SEPARATOR.$safeName;
                file_put_contents($tmpPath, $entryInfo['entry']->getContent());

                try {
                    $filePreview = $this->parseProposalFile($tmpPath, $entryInfo['name'], $user);
                } catch (\Throwable $e) {
                    $filePreview = [
                        'filename' => $entryInfo['name'],
                        'proposal_id' => null,
                        'project_title' => null,
                        'proponent' => null,
                        'province' => null,
                        'rows' => [],
                        'total_rows' => 0,
                        'valid_rows' => 0,
                        'skipped_rows' => 0,
                        'error_rows' => 0,
                        'warnings' => [],
                        'errors' => [$e->getMessage()],
                        'is_matched' => false,
                        'has_importable_rows' => false,
                    ];
                }

                if ($filePreview['proposal_id']) {
                    $proposalId = (int) $filePreview['proposal_id'];
                    if (isset($seenProposalIds[$proposalId])) {
                        $message = 'The same proposal appears in more than one file in this ZIP: '.$seenProposalIds[$proposalId].' and '.$filePreview['filename'].'. Keep only one rental-record file per proposal.';
                        $filePreview['errors'][] = $message;
                        $filePreview['has_importable_rows'] = false;

                        foreach ($files as &$prior) {
                            if ((int) ($prior['proposal_id'] ?? 0) === $proposalId) {
                                $prior['errors'][] = $message;
                                $prior['has_importable_rows'] = false;
                                break;
                            }
                        }
                        unset($prior);
                    } else {
                        $seenProposalIds[$proposalId] = $filePreview['filename'];
                    }
                }

                $files[] = $filePreview;
            }
        } finally {
            $this->deleteDirectory($tmpDir);
        }

        return $this->summarizePreview($files, $originalName, 'zip');
    }

    /**
     * A single XLSX/CSV is also a valid batch. This is useful when Admin only
     * needs to migrate one proposal's historical Rental Monitoring workbook.
     */
    public function parseSpreadsheet(string $path, string $originalName, User $user): array
    {
        try {
            $file = $this->parseProposalFile($path, $originalName, $user);
        } catch (\Throwable $e) {
            $file = [
                'filename' => $originalName,
                'proposal_id' => null,
                'project_title' => null,
                'proponent' => null,
                'province' => null,
                'sector' => null,
                'rows' => [],
                'total_rows' => 0,
                'valid_rows' => 0,
                'skipped_rows' => 0,
                'error_rows' => 0,
                'warnings' => [],
                'errors' => [$e->getMessage()],
                'is_matched' => false,
                'has_importable_rows' => false,
                'ready_special_actions' => 0,
            ];
        }

        return $this->summarizePreview([$file], $originalName, 'spreadsheet');
    }

    private function summarizePreview(array $files, string $originalName, string $sourceType): array
    {
        $collection = collect($files);
        $allRows = $collection->flatMap(fn ($file) => $file['rows'] ?? []);

        return [
            'original_filename' => $originalName,
            'source_type' => $sourceType,
            'files' => $files,
            'total_files' => count($files),
            'matched_files' => $collection->where('is_matched', true)->count(),
            'importable_files' => $collection->where('has_importable_rows', true)->count(),
            'error_files' => $collection->filter(fn ($file) => count($file['errors'] ?? []) > 0)->count(),
            'warning_files' => $collection->filter(fn ($file) => count($file['warnings'] ?? []) > 0 || collect($file['rows'] ?? [])->contains(fn ($row) => count($row['warnings'] ?? []) > 0))->count(),
            'total_rows' => $allRows->count(),
            'valid_rows' => $allRows->where('is_valid', true)->count(),
            'ready_rows' => $allRows->where('status', 'ready')->count(),
            'ready_special_actions' => $collection->sum(fn ($file) => (int) ($file['ready_special_actions'] ?? 0)),
            'skipped_rows' => $allRows->where('status', 'skip')->count(),
            'error_rows' => $allRows->where('is_valid', false)->count(),
            'generated_at' => now()->toIso8601String(),
        ];
    }

    public function importPreview(array $preview, User $user): array
    {
        $batchRef = 'RR-IMP-'.now()->format('Ymd-His').'-'.strtoupper(Str::random(5));
        $batch = RentalRecordImportBatch::create([
            'batch_ref' => $batchRef,
            'original_filename' => (string) ($preview['original_filename'] ?? 'rental-records-import'),
            'status' => 'Processing',
            'total_files' => (int) ($preview['total_files'] ?? 0),
            'matched_files' => (int) ($preview['matched_files'] ?? 0),
            'total_rows' => (int) ($preview['total_rows'] ?? 0),
            'metadata' => ['preview_generated_at' => $preview['generated_at'] ?? null, 'source_type' => $preview['source_type'] ?? 'zip'],
            'created_by' => $user->id,
        ]);

        $importedFiles = 0;
        $failedFiles = 0;
        $importedRows = 0;
        $skippedRows = 0;
        $failedRows = 0;
        $specialImported = 0;
        $specialSkipped = 0;
        $specialFailed = 0;

        foreach ($preview['files'] ?? [] as $file) {
            $fileImported = 0;
            $fileSkipped = 0;
            $fileFailed = 0;
            $fileSpecialImported = 0;
            $fileSpecialSkipped = 0;
            $fileSpecialFailed = 0;
            $rowResults = [];
            $specialResults = [];
            $proposal = null;

            if (! empty($file['proposal_id'])) {
                $proposal = Proposal::query()->visibleTo($user)->find((int) $file['proposal_id']);
            }

            if (! $proposal || ! empty($file['errors'])) {
                $failedFiles++;
                $fileFailed = (int) ($file['error_rows'] ?? 0);
                $failedRows += $fileFailed;
                RentalRecordImportFile::create([
                    'rental_record_import_batch_id' => $batch->id,
                    'proposal_id' => $proposal?->id,
                    'original_filename' => (string) ($file['filename'] ?? 'unknown.xlsx'),
                    'project_title_snapshot' => $file['project_title'] ?? $proposal?->project_title,
                    'proponent_snapshot' => $file['proponent'] ?? $proposal?->proponent,
                    'status' => 'Failed',
                    'total_rows' => (int) ($file['total_rows'] ?? 0),
                    'valid_rows' => (int) ($file['valid_rows'] ?? 0),
                    'failed_rows' => $fileFailed,
                    'metadata' => ['errors' => $file['errors'] ?? ['Proposal is no longer accessible.'], 'warnings' => $file['warnings'] ?? []],
                ]);
                continue;
            }

            $schedule = $this->rentalRecords->ensureSchedule($proposal);
            if (! $schedule['ready']) {
                $failedFiles++;
                $fileFailed = (int) ($file['total_rows'] ?? 0);
                $failedRows += $fileFailed;
                RentalRecordImportFile::create([
                    'rental_record_import_batch_id' => $batch->id,
                    'proposal_id' => $proposal->id,
                    'original_filename' => $file['filename'],
                    'project_title_snapshot' => $proposal->project_title,
                    'proponent_snapshot' => $proposal->proponent,
                    'status' => 'Failed',
                    'total_rows' => (int) ($file['total_rows'] ?? 0),
                    'valid_rows' => 0,
                    'failed_rows' => $fileFailed,
                    'metadata' => ['errors' => ['Funding Date and Proposal Amount are required before rental records can be imported.']],
                ]);
                continue;
            }

            try {
                DB::transaction(function () use ($file, $proposal, $user, $batchRef, &$fileImported, &$fileSkipped, &$fileFailed, &$fileSpecialImported, &$fileSpecialSkipped, &$fileSpecialFailed, &$rowResults, &$specialResults) {
                    foreach (($file['special_sections']['schedule_adjustments'] ?? []) as $special) {
                        $status = $special['status'] ?? 'skip';
                        if ($status === 'existing') {
                            $fileSpecialSkipped++;
                            $specialResults[] = [
                                'kind' => 'schedule_adjustment',
                                'status' => 'Skipped',
                                'message' => 'Schedule adjustment already exists in PATH.',
                                'adjustment_type' => $special['adjustment_type'] ?? 'Other',
                                'effective_month' => $special['effective_month'] ?? null,
                                'resume_month' => $special['resume_month'] ?? null,
                                'approved_date' => $special['approved_date'] ?? null,
                                'label' => $special['label'] ?? null,
                            ];
                            continue;
                        }
                        if ($status !== 'ready') {
                            $fileSpecialFailed++;
                            $specialResults[] = [
                                'kind' => 'schedule_adjustment',
                                'status' => 'Failed',
                                'message' => implode(' ', $special['errors'] ?? []) ?: 'Schedule adjustment could not be imported.',
                                'adjustment_type' => $special['adjustment_type'] ?? 'Other',
                                'effective_month' => $special['effective_month'] ?? null,
                                'resume_month' => $special['resume_month'] ?? null,
                                'approved_date' => $special['approved_date'] ?? null,
                                'label' => $special['label'] ?? null,
                            ];
                            continue;
                        }

                        $adjustment = $this->rentalRecords->applyScheduleAdjustment($proposal, [
                            'adjustment_type' => $special['adjustment_type'] ?? 'Other',
                            'effective_month' => $special['effective_month'],
                            'resume_month' => $special['resume_month'],
                            'approved_date' => $special['approved_date'] ?? null,
                            'revised_amount_due' => null,
                            'remarks' => $special['remarks'] ?? ($special['label'] ?? 'Historical schedule adjustment imported from Excel.'),
                        ], $user->id);

                        $fileSpecialImported++;
                        $specialResults[] = [
                            'kind' => 'schedule_adjustment',
                            'status' => 'Imported',
                            'message' => $adjustment->adjustment_type.' imported from historical Rental Monitoring Excel.',
                            'adjustment_type' => $adjustment->adjustment_type,
                            'effective_month' => $adjustment->effective_month?->format('Y-m'),
                            'resume_month' => $adjustment->resume_month?->format('Y-m'),
                            'approved_date' => $adjustment->approved_date?->format('Y-m-d'),
                            'label' => $special['label'] ?? null,
                        ];

                        $this->activity->record($proposal, $user, $adjustment->adjustment_type.' rental schedule imported through Bulk Upload', [
                            'batch_ref' => $batchRef,
                            'source_file' => $file['filename'],
                            'effective_month' => $adjustment->effective_month?->format('Y-m'),
                            'resume_month' => $adjustment->resume_month?->format('Y-m'),
                            'approved_date' => $adjustment->approved_date?->format('Y-m-d'),
                            'remarks' => $adjustment->remarks,
                        ]);
                    }

                    $otp = $file['special_sections']['otp'] ?? null;
                    if ($otp) {
                        if (($otp['status'] ?? null) === 'ready') {
                            $proposal->rental_otp_enabled = true;
                            $proposal->save();
                            $fileSpecialImported++;
                            $specialResults[] = [
                                'kind' => 'otp',
                                'status' => 'Imported',
                                'message' => 'OTP (Option to Purchase) enabled from Excel.',
                                'uploaded_amount' => $otp['uploaded_amount'] ?? null,
                                'expected_amount' => $otp['expected_amount'] ?? null,
                            ];
                            $this->activity->record($proposal, $user, 'OTP option imported through Rental Bulk Upload', [
                                'batch_ref' => $batchRef,
                                'source_file' => $file['filename'],
                                'otp_amount' => $otp['expected_amount'] ?? round(((float) $proposal->amount) * 0.005, 2),
                            ]);
                        } elseif (($otp['status'] ?? null) === 'existing') {
                            $fileSpecialSkipped++;
                            $specialResults[] = [
                                'kind' => 'otp',
                                'status' => 'Skipped',
                                'message' => 'OTP is already enabled in PATH.',
                                'uploaded_amount' => $otp['uploaded_amount'] ?? null,
                                'expected_amount' => $otp['expected_amount'] ?? null,
                            ];
                        } else {
                            $fileSpecialFailed++;
                            $specialResults[] = [
                                'kind' => 'otp',
                                'status' => 'Failed',
                                'message' => implode(' ', $otp['errors'] ?? []) ?: 'OTP section could not be imported.',
                            ];
                        }
                    }

                    foreach ($file['rows'] ?? [] as $row) {
                        if (! ($row['is_valid'] ?? false)) {
                            $fileFailed++;
                            $rowResults[] = [
                                'source_row' => $row['source_row'] ?? null,
                                'installment_no' => $row['installment_no'] ?? null,
                                'month' => $row['month'] ?? null,
                                'status' => 'Failed',
                                'message' => implode(' ', $row['errors'] ?? []) ?: 'Validation error.',
                            ];
                            continue;
                        }
                        if (($row['status'] ?? '') !== 'ready') {
                            $fileSkipped++;
                            $rowResults[] = [
                                'source_row' => $row['source_row'] ?? null,
                                'installment_no' => $row['installment_no'] ?? null,
                                'month' => $row['month'] ?? null,
                                'status' => 'Skipped',
                                'message' => $row['skip_reason'] ?? 'Not importable.',
                            ];
                            continue;
                        }

                    /** @var RentalRecord|null $record */
                    $record = $proposal->rentalRecords()->where('installment_no', (int) $row['installment_no'])->lockForUpdate()->first();
                    if (! $record) {
                        $fileFailed++;
                        $rowResults[] = [
                            'source_row' => $row['source_row'] ?? null,
                            'installment_no' => $row['installment_no'] ?? null,
                            'status' => 'Failed',
                            'message' => 'The installment no longer exists in the current PATH schedule.',
                        ];
                        continue;
                    }

                    $incoming = $row['updates'] ?? [];
                    $updates = [];
                    $conflicts = [];
                    foreach (['rental_payment', 'or_number', 'or_date', 'amount_in_or', 'remarks'] as $field) {
                        if (! array_key_exists($field, $incoming) || $incoming[$field] === null || $incoming[$field] === '') {
                            continue;
                        }
                        $current = $this->comparableValue($field, $record->{$field});
                        $next = $this->comparableValue($field, $incoming[$field]);
                        if ($this->hasSavedValue($record->{$field}) && $current !== $next) {
                            $conflicts[] = $this->fieldLabel($field);
                            continue;
                        }
                        if (! $this->hasSavedValue($record->{$field})) {
                            $updates[$field] = $incoming[$field];
                        }
                    }

                    if ($conflicts) {
                        $fileFailed++;
                        $rowResults[] = [
                            'source_row' => $row['source_row'] ?? null,
                            'installment_no' => $row['installment_no'],
                            'month' => $row['month'] ?? null,
                            'status' => 'Conflict',
                            'message' => 'Existing saved value differs for: '.implode(', ', $conflicts).'. PATH did not overwrite this row.',
                        ];
                        continue;
                    }

                    if (! $updates) {
                        $fileSkipped++;
                        $rowResults[] = [
                            'source_row' => $row['source_row'] ?? null,
                            'installment_no' => $row['installment_no'],
                            'month' => $row['month'] ?? null,
                            'status' => 'Skipped',
                            'message' => 'The uploaded values already match PATH or contain no new payment data.',
                        ];
                        continue;
                    }

                    if (array_key_exists('rental_payment', $updates)
                        && ! array_key_exists('amount_in_or', $updates)
                        && ! $this->hasSavedValue($record->amount_in_or)) {
                        $updates['amount_in_or'] = $updates['rental_payment'];
                    }

                    $record->fill($updates);
                    $record->updated_by = $user->id;
                    $record->save();
                    $fileImported++;
                    $rowResults[] = [
                        'source_row' => $row['source_row'] ?? null,
                        'installment_no' => $row['installment_no'],
                        'month' => $record->due_month?->format('Y-m'),
                        'rental_payment' => $record->rental_payment !== null ? (float) $record->rental_payment : null,
                        'or_number' => $record->or_number,
                        'or_date' => $record->or_date?->format('Y-m-d'),
                        'amount_in_or' => $record->amount_in_or !== null ? (float) $record->amount_in_or : null,
                        'remarks' => $record->remarks,
                        'status' => 'Imported',
                        'message' => 'Rental record updated.',
                    ];
                }

                    if ($fileImported > 0 || $fileSpecialImported > 0) {
                        $records = $proposal->rentalRecords()->orderBy('installment_no')->get();
                        $metrics = $this->rentalRecords->metrics($proposal, $records);
                        $this->activity->record($proposal, $user, 'Rental records imported through Bulk Upload', [
                            'batch_ref' => $batchRef,
                            'source_file' => $file['filename'],
                            'imported_rows' => $fileImported,
                            'imported_special_sections' => $fileSpecialImported,
                            'total_paid' => $metrics['total_paid'],
                            'outstanding_balance' => $metrics['outstanding_balance'],
                            'refund_rate' => $metrics['refund_rate'],
                        ]);
                    }
                });
            } catch (\Throwable $e) {
                $fileImported = 0;
                $fileSkipped = 0;
                $fileFailed = max(1, (int) ($file['total_rows'] ?? 0));
                $fileSpecialImported = 0;
                $fileSpecialSkipped = 0;
                $fileSpecialFailed = max(1, (int) ($file['ready_special_actions'] ?? 0));
                $specialResults = [[
                    'kind' => 'file',
                    'status' => 'Failed',
                    'message' => 'PATH could not save special Rental Monitoring sections: '.$e->getMessage(),
                ]];
                $rowResults = [[
                    'source_row' => null,
                    'installment_no' => null,
                    'status' => 'Failed',
                    'message' => 'PATH could not save this proposal file: '.$e->getMessage(),
                ]];
            }

            $fileImportedAnything = $fileImported > 0 || $fileSpecialImported > 0;
            $fileHadFailure = $fileFailed > 0 || $fileSpecialFailed > 0;
            $fileStatus = $fileImportedAnything ? ($fileHadFailure ? 'Partial' : 'Imported') : ($fileHadFailure ? 'Failed' : 'Skipped');
            if ($fileImportedAnything) {
                $importedFiles++;
            } elseif ($fileStatus === 'Failed') {
                $failedFiles++;
            }

            $importedRows += $fileImported;
            $skippedRows += $fileSkipped;
            $failedRows += $fileFailed;
            $specialImported += $fileSpecialImported;
            $specialSkipped += $fileSpecialSkipped;
            $specialFailed += $fileSpecialFailed;

            RentalRecordImportFile::create([
                'rental_record_import_batch_id' => $batch->id,
                'proposal_id' => $proposal->id,
                'original_filename' => $file['filename'],
                'project_title_snapshot' => $proposal->project_title,
                'proponent_snapshot' => $proposal->proponent,
                'status' => $fileStatus,
                'total_rows' => (int) ($file['total_rows'] ?? 0),
                'valid_rows' => (int) ($file['valid_rows'] ?? 0),
                'imported_rows' => $fileImported,
                'skipped_rows' => $fileSkipped,
                'failed_rows' => $fileFailed,
                'metadata' => [
                    'warnings' => $file['warnings'] ?? [],
                    'errors' => $file['errors'] ?? [],
                    'row_results' => $rowResults,
                    'special_results' => $specialResults,
                ],
            ]);
        }

        $batchHadFailure = $failedRows > 0 || $specialFailed > 0 || $failedFiles > 0;
        $batchImportedAnything = $importedRows > 0 || $specialImported > 0;
        $batch->update([
            'status' => $batchHadFailure ? ($batchImportedAnything ? 'Completed with issues' : 'Failed') : 'Completed',
            'imported_files' => $importedFiles,
            'failed_files' => $failedFiles,
            'imported_rows' => $importedRows,
            'skipped_rows' => $skippedRows,
            'failed_rows' => $failedRows,
            'metadata' => array_merge($batch->metadata ?? [], [
                'special_imported' => $specialImported,
                'special_skipped' => $specialSkipped,
                'special_failed' => $specialFailed,
            ]),
        ]);

        return [
            'batch' => $batch->fresh(),
            'imported_files' => $importedFiles,
            'failed_files' => $failedFiles,
            'imported_rows' => $importedRows,
            'skipped_rows' => $skippedRows,
            'failed_rows' => $failedRows,
            'special_imported' => $specialImported,
            'special_skipped' => $specialSkipped,
            'special_failed' => $specialFailed,
        ];
    }

    private function parseProposalFile(string $path, string $filename, User $user): array
    {
        $rows = $this->readRows($path, $filename);
        while ($rows && $this->rowIsBlank($rows[array_key_last($rows)])) {
            array_pop($rows);
        }

        if (! $rows) {
            throw new RuntimeException('The spreadsheet is empty.');
        }
        if (count($rows) > self::MAX_ROWS_PER_FILE + 100) {
            throw new RuntimeException('The spreadsheet has too many rows. Rental files should contain one proposal only.');
        }

        $identity = $this->extractIdentity($rows, $filename);
        $proposal = $this->resolveProposal($identity, $user);
        if (! $proposal) {
            throw new RuntimeException($identity['error'] ?? 'PATH could not determine which proposal this file belongs to. Include Proposal ID in the sheet or rename the file like Proposal-123-Rental-Records.xlsx.');
        }

        $schedule = $this->rentalRecords->ensureSchedule($proposal);
        if (! $schedule['ready']) {
            throw new RuntimeException('Matched proposal #'.$proposal->id.' has no Funding Date or Proposal Amount, so its rental schedule is not ready.');
        }

        [$headerIndex, $headerMap] = $this->findRentalHeader($rows);
        if ($headerIndex === null) {
            throw new RuntimeException('Rental table headers were not found. Include Installment No or Month, plus payment columns such as Rental Payment, OR Number, OR Date, Amount in OR, or Remarks.');
        }

        $records = $proposal->rentalRecords()->orderBy('installment_no')->get();
        $specialSections = $this->extractSpecialSections($rows, $headerIndex, $headerMap, $proposal, $records);
        [$virtualRecords, $specialSections] = $this->simulateScheduleForSpecialSections($proposal, $records, $specialSections);

        $byInstallment = $virtualRecords->keyBy('installment_no');
        $byMonth = $virtualRecords->keyBy(fn (RentalRecord $record) => $record->due_month?->format('Y-m'));
        $parsedRows = [];
        $fileWarnings = [];
        $fileErrors = [];

        foreach ($specialSections['schedule_adjustments'] ?? [] as $adjustment) {
            foreach ($adjustment['warnings'] ?? [] as $message) {
                $fileWarnings[] = $message;
            }
            foreach ($adjustment['errors'] ?? [] as $message) {
                $fileErrors[] = $message;
            }
        }
        if (! empty($specialSections['otp'])) {
            foreach ($specialSections['otp']['warnings'] ?? [] as $message) {
                $fileWarnings[] = $message;
            }
            foreach ($specialSections['otp']['errors'] ?? [] as $message) {
                $fileErrors[] = $message;
            }
        }

        for ($i = $headerIndex + 1; $i < count($rows); $i++) {
            $source = $rows[$i];
            if ($this->rowIsBlank($source)) {
                continue;
            }

            $first = $this->cell($source, 0);
            $amountDueKey = $this->key($this->mappedCell($source, $headerMap, 'amount_due'));
            $paymentKey = $this->key($this->mappedCell($source, $headerMap, 'rental_payment'));

            // In the existing Rental Monitoring workbook, TOTAL / TOTAL REFUND /
            // REFUND RATE marks the end of the rental schedule. Everything after
            // this point is summary information, not an installment row.
            if (in_array($amountDueKey, ['total', 'totals', 'grand total'], true)
                || in_array($paymentKey, ['total refund', 'total rental payment', 'total payment'], true)) {
                break;
            }

            if (in_array($this->key($first), ['total', 'totals', 'grand total', 'summary'], true)
                || $this->shouldSkipLegacyLayoutRow($source, $headerMap)) {
                continue;
            }

            $result = $this->validateRentalRow($source, $i + 1, $headerMap, $byInstallment, $byMonth);
            if ($result !== null) {
                $parsedRows[] = $result;
            }
        }

        $collection = collect($parsedRows);
        $valid = $collection->where('is_valid', true)->count();
        $ready = $collection->where('status', 'ready')->count();
        $errorRows = $collection->where('is_valid', false)->count();
        $skipped = $collection->where('status', 'skip')->count();
        $readySpecialActions = collect($specialSections['schedule_adjustments'] ?? [])->where('status', 'ready')->count()
            + ((! empty($specialSections['otp']) && ($specialSections['otp']['status'] ?? null) === 'ready') ? 1 : 0);

        if (! $parsedRows && $readySpecialActions < 1 && empty($fileErrors)) {
            throw new RuntimeException('No rental payment rows, schedule-adjustment sections, or OTP section were found below the detected table header.');
        }

        if ($identity['source'] === 'filename') {
            $fileWarnings[] = 'Proposal matched from the filename.';
        } elseif ($identity['source'] === 'firm_amount') {
            $fileWarnings[] = 'Proposal matched using the current DOST rental format: FIRM + AMOUNT INVESTED BY SETUP.';
        }

        $fileWarnings = array_values(array_unique($fileWarnings));
        $fileErrors = array_values(array_unique($fileErrors));

        return [
            'filename' => $filename,
            'proposal_id' => $proposal->id,
            'project_title' => $proposal->project_title,
            'proponent' => $proposal->proponent,
            'province' => $proposal->province,
            'sector' => $proposal->sector,
            'identity_source' => $identity['source'],
            'rows' => $parsedRows,
            'special_sections' => $specialSections,
            'ready_special_actions' => $readySpecialActions,
            'total_rows' => count($parsedRows),
            'valid_rows' => $valid,
            'skipped_rows' => $skipped,
            'error_rows' => $errorRows,
            'warnings' => $fileWarnings,
            'errors' => $fileErrors,
            'is_matched' => true,
            'has_importable_rows' => empty($fileErrors) && ($ready > 0 || $readySpecialActions > 0),
        ];
    }

    /**
     * Detect historical schedule directives embedded in the agency's existing
     * Rental Monitoring workbook. These are file-level actions, not ordinary
     * rental-payment rows.
     *
     * Supported examples:
     * - "Revised start of rental from October 2025 to April 2026"
     * - AMOUNT DUE = "project extension" across Apr-Jun, with payment resuming Jul
     * - AMOUNT DUE = "Restructured July 28, 2026" followed by deferred months
     * - MONTH = "OTP" with the 0.5% option-to-purchase amount
     */
    private function extractSpecialSections(array $rows, int $headerIndex, array $map, Proposal $proposal, $records): array
    {
        $adjustments = [];
        $adjustmentKeys = [];
        $otp = null;
        $existingAdjustments = $proposal->rentalScheduleAdjustments()->orderBy('effective_month')->get();

        $addAdjustment = function (array $candidate) use (&$adjustments, &$adjustmentKeys, $existingAdjustments): void {
            $effective = $candidate['effective_month'] ?? null;
            $resume = $candidate['resume_month'] ?? null;
            if (! $effective || ! $resume) {
                if (! empty($candidate['errors'])) {
                    $candidate['status'] = 'error';
                    $candidate['warnings'] = $candidate['warnings'] ?? [];
                    $adjustments[] = $candidate;
                }
                return;
            }

            $key = ($candidate['adjustment_type'] ?? 'Other').'|'.$effective.'|'.$resume;
            if (isset($adjustmentKeys[$key])) {
                return;
            }
            $adjustmentKeys[$key] = true;

            $candidate['warnings'] = $candidate['warnings'] ?? [];
            $candidate['errors'] = $candidate['errors'] ?? [];

            try {
                $effectiveMonth = Carbon::parse($effective)->startOfMonth();
                $resumeMonth = Carbon::parse($resume)->startOfMonth();
                if ($resumeMonth->lessThanOrEqualTo($effectiveMonth)) {
                    $candidate['status'] = 'error';
                    $candidate['errors'][] = 'Schedule adjustment at sheet row '.($candidate['source_row'] ?? '—').' has an invalid resume month.';
                    $adjustments[] = $candidate;
                    return;
                }
            } catch (\Throwable) {
                $candidate['status'] = 'error';
                $candidate['errors'][] = 'Schedule adjustment at sheet row '.($candidate['source_row'] ?? '—').' contains an unreadable month.';
                $adjustments[] = $candidate;
                return;
            }

            $exact = $existingAdjustments->first(fn ($existing) =>
                $existing->effective_month?->format('Y-m') === Carbon::parse($effective)->format('Y-m')
                && $existing->resume_month?->format('Y-m') === Carbon::parse($resume)->format('Y-m')
            );

            if ($exact) {
                $candidate['status'] = 'existing';
                $candidate['existing_adjustment_id'] = $exact->id;
                $candidate['warnings'][] = 'Schedule adjustment '.$effective.' → '.$resume.' is already recorded in PATH and will not be duplicated.';
                $adjustments[] = $candidate;
                return;
            }

            $sameEffective = $existingAdjustments->first(fn ($existing) =>
                $existing->effective_month?->format('Y-m') === Carbon::parse($effective)->format('Y-m')
            );
            if ($sameEffective) {
                $candidate['status'] = 'error';
                $candidate['errors'][] = 'Excel shows a schedule adjustment beginning '.$effective.', but PATH already has a different adjustment beginning that month (resume '.$sameEffective->resume_month?->format('Y-m').'). Review the proposal before importing.';
                $adjustments[] = $candidate;
                return;
            }

            $candidate['status'] = 'ready';
            if (empty($candidate['approved_date'])) {
                $candidate['warnings'][] = 'No exact approval date was found for the historical schedule adjustment '.$effective.' → '.$resume.'. PATH will preserve the approval date as unknown rather than inventing one.';
            }
            $adjustments[] = $candidate;
        };

        $end = count($rows);
        for ($i = $headerIndex + 1; $i < $end; $i++) {
            $row = $rows[$i];
            if ($this->rowIsBlank($row)) {
                continue;
            }

            $amountDueRaw = $this->mappedCell($row, $map, 'amount_due');
            $amountDueKey = $this->key($amountDueRaw);
            $paymentKey = $this->key($this->mappedCell($row, $map, 'rental_payment'));

            if (in_array($amountDueKey, ['total', 'totals', 'grand total'], true)
                || in_array($paymentKey, ['total refund', 'total rental payment', 'total payment'], true)) {
                break;
            }

            // Direct narrative such as "Revised start of rental from October
            // 2025 to April 2026" may live in FIRM/REMARKS or another cell.
            foreach ($row as $cellValue) {
                $text = trim((string) $cellValue);
                if ($text === '') {
                    continue;
                }
                $direct = $this->adjustmentFromNarrative($text, $i + 1);
                if ($direct) {
                    $addAdjustment($direct);
                }
            }

            $monthRaw = $this->mappedCell($row, $map, 'month');
            $monthKey = $this->key($monthRaw);

            // OTP is a proposal-level option in PATH. It is intentionally kept
            // outside rental obligation/refund calculations.
            if ($monthKey === 'otp' || $monthKey === 'option to purchase'
                || collect($row)->contains(fn ($value) => in_array($this->key((string) $value), ['otp', 'option to purchase'], true))) {
                if ($otp === null) {
                    $uploadedAmount = $this->parseAmount($amountDueRaw);
                    $expectedAmount = round(((float) $proposal->amount) * 0.005, 2);
                    $otpWarnings = [];
                    if ($uploadedAmount !== null && abs($uploadedAmount - $expectedAmount) > 0.009) {
                        $otpWarnings[] = 'OTP amount in Excel is ₱'.number_format($uploadedAmount, 2).', while PATH calculates ₱'.number_format($expectedAmount, 2).' (0.5% of Proposal Amount). PATH will use its calculated OTP amount.';
                    }
                    $otp = [
                        'source_row' => $i + 1,
                        'uploaded_amount' => $uploadedAmount,
                        'expected_amount' => $expectedAmount,
                        'status' => $proposal->rental_otp_enabled ? 'existing' : 'ready',
                        'warnings' => $otpWarnings,
                        'errors' => [],
                    ];
                    if ($proposal->rental_otp_enabled) {
                        $otp['warnings'][] = 'OTP is already enabled for this proposal and will not be duplicated.';
                    }
                }
                continue;
            }

            // Legacy merged-cell markers such as "project extension" or
            // "Restructured July 28, 2026" are located in AMOUNT DUE. The
            // following numeric Amount Due row tells us when payments resume.
            if ($amountDueRaw !== '' && $this->isScheduleMarker($amountDueRaw)) {
                $effective = $this->parseMonth($monthRaw);
                $resume = $effective ? $this->findResumeMonthAfterMarker($rows, $i + 1, $map) : null;
                if ($effective && $resume) {
                    $addAdjustment([
                        'source_row' => $i + 1,
                        'adjustment_type' => $this->adjustmentTypeFromText($amountDueRaw),
                        'effective_month' => $effective->format('Y-m'),
                        'resume_month' => $resume->format('Y-m'),
                        'approved_date' => $this->extractApprovalDateFromText($amountDueRaw)?->format('Y-m-d'),
                        'remarks' => trim($amountDueRaw).' (Imported from legacy Rental Monitoring Excel.)',
                        'label' => trim($amountDueRaw),
                    ]);
                } elseif ($effective) {
                    $addAdjustment([
                        'source_row' => $i + 1,
                        'adjustment_type' => $this->adjustmentTypeFromText($amountDueRaw),
                        'effective_month' => $effective->format('Y-m'),
                        'resume_month' => null,
                        'approved_date' => $this->extractApprovalDateFromText($amountDueRaw)?->format('Y-m-d'),
                        'remarks' => trim($amountDueRaw).' (Imported from legacy Rental Monitoring Excel.)',
                        'label' => trim($amountDueRaw),
                        'status' => 'error',
                        'errors' => ['PATH detected "'.$amountDueRaw.'" at sheet row '.($i + 1).' but could not determine the month when Amount Due resumes.'],
                    ]);
                }
            }
        }

        usort($adjustments, fn ($a, $b) => strcmp((string) ($a['effective_month'] ?? ''), (string) ($b['effective_month'] ?? '')));

        return [
            'schedule_adjustments' => $adjustments,
            'otp' => $otp,
        ];
    }

    private function adjustmentFromNarrative(string $text, int $sourceRow): ?array
    {
        $key = $this->key($text);
        if (! (str_contains($key, 'revised') || str_contains($key, 'revision')
            || str_contains($key, 'restructur') || str_contains($key, 'reconstruct')
            || str_contains($key, 'moratorium') || str_contains($key, 'extension'))) {
            return null;
        }

        $monthToken = '(?:Jan(?:uary)?|Feb(?:ruary)?|Mar(?:ch)?|Apr(?:il)?|May|Jun(?:e)?|Jul(?:y)?|Aug(?:ust)?|Sep(?:t(?:ember)?)?|Oct(?:ober)?|Nov(?:ember)?|Dec(?:ember)?)[\s.\/-]+\d{2,4}';
        if (! preg_match('/\bfrom\s+('.$monthToken.')\s+\bto\s+('.$monthToken.')/i', $text, $match)) {
            return null;
        }

        $effective = $this->parseMonth($match[1]);
        $resume = $this->parseMonth($match[2]);
        if (! $effective || ! $resume) {
            return null;
        }

        return [
            'source_row' => $sourceRow,
            'adjustment_type' => $this->adjustmentTypeFromText($text),
            'effective_month' => $effective->format('Y-m'),
            'resume_month' => $resume->format('Y-m'),
            'approved_date' => $this->extractApprovalDateFromText($text)?->format('Y-m-d'),
            'remarks' => trim($text).' (Imported from legacy Rental Monitoring Excel.)',
            'label' => trim($text),
        ];
    }

    private function isScheduleMarker(string $text): bool
    {
        $key = $this->key($text);
        return str_contains($key, 'restructur')
            || str_contains($key, 'reconstruct')
            || str_contains($key, 'revision')
            || str_contains($key, 'revised')
            || str_contains($key, 'moratorium')
            || str_contains($key, 'extension')
            || str_contains($key, 'extended')
            || str_contains($key, 'defer')
            || str_contains($key, 'suspension')
            || str_contains($key, 'grace period');
    }

    private function adjustmentTypeFromText(string $text): string
    {
        $key = $this->key($text);
        if (str_contains($key, 'restructur') || str_contains($key, 'reconstruct')) {
            return 'Reconstruction';
        }
        if (str_contains($key, 'revision') || str_contains($key, 'revised')) {
            return 'Revision';
        }
        if (str_contains($key, 'moratorium')) {
            return 'Moratorium';
        }
        if (str_contains($key, 'extension') || str_contains($key, 'extended')) {
            return 'Proposal Extension';
        }
        if (str_contains($key, 'defer') || str_contains($key, 'suspension') || str_contains($key, 'grace period')) {
            return 'Moratorium';
        }
        return 'Other';
    }

    private function findResumeMonthAfterMarker(array $rows, int $startIndex, array $map): ?Carbon
    {
        for ($i = $startIndex; $i < count($rows); $i++) {
            $row = $rows[$i];
            $amountDueRaw = $this->mappedCell($row, $map, 'amount_due');
            $paymentKey = $this->key($this->mappedCell($row, $map, 'rental_payment'));
            if (in_array($this->key($amountDueRaw), ['total', 'totals', 'grand total'], true)
                || in_array($paymentKey, ['total refund', 'total rental payment', 'total payment'], true)) {
                return null;
            }

            $monthKey = $this->key($this->mappedCell($row, $map, 'month'));
            if (in_array($monthKey, ['otp', 'option to purchase'], true)) {
                return null;
            }

            $month = $this->parseMonth($this->mappedCell($row, $map, 'month'));

            // Consecutive legacy extension blocks are separate schedule
            // adjustments. For example:
            //   Dec-25  extension of phase 1 (...)
            //   Apr-26  Project Extension
            //   Aug-26  6,200.00
            // The first block resumes where the second block begins (Apr-26),
            // then the second block resumes at the first numeric Amount Due.
            if ($month && $amountDueRaw !== '' && $this->isScheduleMarker($amountDueRaw)) {
                return $month->startOfMonth();
            }

            $amount = $this->parseAmount($amountDueRaw);
            if ($month && $amount !== null && $amount > 0) {
                return $month->startOfMonth();
            }
        }

        return null;
    }

    private function extractApprovalDateFromText(string $text): ?Carbon
    {
        $monthName = '(?:January|February|March|April|May|June|July|August|September|October|November|December|Jan|Feb|Mar|Apr|Jun|Jul|Aug|Sep|Sept|Oct|Nov|Dec)';
        if (preg_match('/\b('.$monthName.'\s+\d{1,2},?\s+\d{4})\b/i', $text, $match)) {
            return $this->parseDate(str_replace(',', '', $match[1]));
        }
        if (preg_match('/\b(\d{1,2}[.\/-]\d{1,2}[.\/-]\d{2,4})\b/', $text, $match)) {
            return $this->parseDate($match[1]);
        }
        return null;
    }

    /**
     * Preview must understand the schedule after the detected legacy
     * adjustments without modifying the database. Clone the current records and
     * shift only the virtual due months. The real adjustments are applied during
     * commit, before the payment rows are saved.
     */
    private function simulateScheduleForSpecialSections(Proposal $proposal, $records, array $specialSections): array
    {
        $virtual = $records->map(function (RentalRecord $record) {
            $copy = clone $record;
            $copy->due_month = $record->due_month?->copy()->startOfMonth();
            return $copy;
        })->values();

        $adjustments = $specialSections['schedule_adjustments'] ?? [];
        usort($adjustments, fn ($a, $b) => strcmp((string) ($a['effective_month'] ?? ''), (string) ($b['effective_month'] ?? '')));

        foreach ($adjustments as $index => &$adjustment) {
            if (($adjustment['status'] ?? null) !== 'ready') {
                continue;
            }

            $effective = Carbon::parse($adjustment['effective_month'])->startOfMonth();
            $resume = Carbon::parse($adjustment['resume_month'])->startOfMonth();
            $target = $virtual->first(fn (RentalRecord $record) => $record->due_month?->isSameMonth($effective));

            if (! $target) {
                $adjustment['status'] = 'error';
                $adjustment['errors'][] = 'PATH cannot apply the Excel schedule adjustment '.$adjustment['effective_month'].' → '.$adjustment['resume_month'].' because the effective month is not in the current unpaid schedule after earlier adjustments.';
                continue;
            }

            $tail = $virtual->filter(fn (RentalRecord $record) => $record->installment_no >= $target->installment_no)->values();
            $hasOperationalData = $tail->contains(fn (RentalRecord $record) =>
                $this->hasSavedValue($record->rental_payment)
                || $this->hasSavedValue($record->or_number)
                || $this->hasSavedValue($record->or_date)
                || $this->hasSavedValue($record->amount_in_or)
                || $this->hasSavedValue($record->remarks)
            );

            if ($hasOperationalData) {
                $adjustment['status'] = 'error';
                $adjustment['errors'][] = 'Excel contains a historical schedule adjustment '.$adjustment['effective_month'].' → '.$adjustment['resume_month'].', but PATH already has saved rental activity in the affected schedule tail. PATH will not silently move existing payments. Clear/correct the test rental activity or record the schedule adjustment first, then revalidate the ZIP.';
                continue;
            }

            $suspensionMonths = (int) $effective->diffInMonths($resume);
            foreach ($tail as $record) {
                $record->due_month = $record->due_month->copy()->addMonthsNoOverflow($suspensionMonths)->startOfMonth();
            }
            $adjustment['suspension_months'] = $suspensionMonths;
        }
        unset($adjustment);

        $specialSections['schedule_adjustments'] = $adjustments;
        return [$virtual, $specialSections];
    }

    private function shouldSkipLegacyLayoutRow(array $row, array $map): bool
    {
        $installment = $this->mappedCell($row, $map, 'installment_no');
        $month = $this->mappedCell($row, $map, 'month');
        $monthKey = $this->key($month);
        $amountDueKey = $this->key($this->mappedCell($row, $map, 'amount_due'));
        $paymentKey = $this->key($this->mappedCell($row, $map, 'rental_payment'));

        if (in_array($monthKey, ['otp', 'option to purchase'], true)) {
            return true;
        }

        if (in_array($amountDueKey, ['total', 'totals', 'grand total'], true)
            || in_array($paymentKey, ['total refund', 'total rental payment', 'total payment'], true)) {
            return true;
        }

        if ($installment === '' && $month === '') {
            $activity = [
                $this->mappedCell($row, $map, 'rental_payment'),
                $this->mappedCell($row, $map, 'or_number'),
                $this->mappedCell($row, $map, 'or_date'),
                $this->mappedCell($row, $map, 'amount_in_or'),
                $this->mappedCell($row, $map, 'remarks'),
            ];

            if (collect($activity)->every(fn ($value) => trim((string) $value) === '')) {
                return true;
            }
        }

        return false;
    }

    private function validateRentalRow(array $row, int $sourceRow, array $map, $byInstallment, $byMonth): ?array
    {
        $errors = [];
        $warnings = [];
        $installmentRaw = $this->mappedCell($row, $map, 'installment_no');
        $monthRaw = $this->mappedCell($row, $map, 'month');
        $record = null;
        $installment = null;

        if ($installmentRaw !== '') {
            if (! is_numeric($installmentRaw) || (int) $installmentRaw < 1) {
                $errors[] = 'Invalid Installment No.';
            } else {
                $installment = (int) $installmentRaw;
                $record = $byInstallment->get($installment);
                if (! $record) {
                    $errors[] = 'Installment #'.$installment.' does not exist in the current PATH rental schedule.';
                }
            }
        }

        $month = $this->parseMonth($monthRaw);
        if (! $record && $month) {
            $record = $byMonth->get($month->format('Y-m'));
            if ($record) {
                $installment = (int) $record->installment_no;
            }
        }

        if (! $record && ! $errors) {
            $errors[] = 'Provide a valid Installment No or Month so PATH can match this row.';
        }

        if ($record && $month && ! $record->due_month?->isSameMonth($month)) {
            $warnings[] = 'Uploaded Month '.$month->format('Y-m').' differs from PATH schedule '.$record->due_month?->format('Y-m').'. PATH will keep its current schedule month.';
        }

        $amountDueRaw = $this->mappedCell($row, $map, 'amount_due');
        if ($record && $amountDueRaw !== '') {
            $amountDue = $this->parseAmount($amountDueRaw);
            if ($amountDue === null) {
                $adjustmentMarker = $this->key($amountDueRaw);
                if ($this->isScheduleMarker($amountDueRaw)) {
                    $warnings[] = 'Schedule adjustment marker detected: "'.$amountDueRaw.'". PATH detected this as a legacy schedule-adjustment row; it is handled separately from ordinary rental-payment rows.';
                } else {
                    $warnings[] = 'Amount Due is not numeric and will be ignored.';
                }
            } elseif (abs($amountDue - (float) $record->amount_due) > 0.009) {
                $warnings[] = 'Uploaded Amount Due ₱'.number_format($amountDue, 2).' differs from PATH ₱'.number_format((float) $record->amount_due, 2).'. PATH will keep its current schedule amount.';
            }
        }

        $updates = [];
        $paymentRaw = $this->mappedCell($row, $map, 'rental_payment');
        if ($paymentRaw !== '') {
            $payment = $this->parseAmount($paymentRaw);
            if ($payment === null) {
                $errors[] = 'Rental Payment must be a non-negative amount.';
            } elseif ($payment > 0) {
                $updates['rental_payment'] = $payment;
            } else {
                $warnings[] = 'A zero Rental Payment is treated as unpaid/blank and will not be stored.';
            }
        }

        $orNumber = trim($this->mappedCell($row, $map, 'or_number'));
        if ($orNumber !== '') {
            if (mb_strlen($orNumber) > 100) {
                $errors[] = 'OR Number is longer than 100 characters.';
            } else {
                $updates['or_number'] = $orNumber;
            }
        }

        $orDateRaw = $this->mappedCell($row, $map, 'or_date');
        if ($orDateRaw !== '') {
            $orDate = $this->parseDate($orDateRaw);
            if (! $orDate) {
                $errors[] = 'OR Date is invalid.';
            } else {
                $updates['or_date'] = $orDate->format('Y-m-d');
            }
        }

        $amountOrRaw = $this->mappedCell($row, $map, 'amount_in_or');
        if ($amountOrRaw !== '') {
            $amountOr = $this->parseAmount($amountOrRaw);
            if ($amountOr === null) {
                $errors[] = 'Amount in OR must be a non-negative amount.';
            } elseif ($amountOr > 0) {
                $updates['amount_in_or'] = $amountOr;
            } else {
                $warnings[] = 'A zero Amount in OR is treated as blank and will not be stored.';
            }
        }

        $remarks = trim($this->mappedCell($row, $map, 'remarks'));
        if ($remarks !== '') {
            if (mb_strlen($remarks) > 1000) {
                $errors[] = 'Remarks is longer than 1000 characters.';
            } else {
                $updates['remarks'] = $remarks;
            }
        }

        // Rows that contain only schedule reference columns carry no new rental
        // activity. Keep them visible in preview as skipped rather than errors.
        if (! $updates && ! $errors) {
            return [
                'source_row' => $sourceRow,
                'installment_no' => $installment,
                'month' => $record?->due_month?->format('Y-m'),
                'updates' => [],
                'is_valid' => true,
                'status' => 'skip',
                'skip_reason' => 'No payment/OR/remarks data in this row.',
                'warnings' => $warnings,
                'errors' => [],
            ];
        }

        if ($record && ! $errors) {
            $conflicts = [];
            $newFields = 0;
            foreach ($updates as $field => $value) {
                if ($this->hasSavedValue($record->{$field})) {
                    if ($this->comparableValue($field, $record->{$field}) !== $this->comparableValue($field, $value)) {
                        $conflicts[] = $this->fieldLabel($field);
                    }
                } else {
                    $newFields++;
                }
            }

            if ($conflicts) {
                $errors[] = 'Existing PATH values differ for: '.implode(', ', $conflicts).'. Bulk import will not overwrite saved rental data.';
            } elseif ($newFields === 0) {
                return [
                    'source_row' => $sourceRow,
                    'installment_no' => $installment,
                    'month' => $record->due_month?->format('Y-m'),
                    'updates' => $updates,
                    'is_valid' => true,
                    'status' => 'skip',
                    'skip_reason' => 'Uploaded values already match PATH.',
                    'warnings' => $warnings,
                    'errors' => [],
                ];
            }
        }

        return [
            'source_row' => $sourceRow,
            'installment_no' => $installment,
            'month' => $record?->due_month?->format('Y-m'),
            'updates' => $updates,
            'is_valid' => empty($errors),
            'status' => empty($errors) ? 'ready' : 'error',
            'warnings' => $warnings,
            'errors' => $errors,
        ];
    }

    private function extractIdentity(array $rows, string $filename): array
    {
        $id = null;
        $title = null;
        $proponent = null;
        $projectAmount = null;

        foreach (array_slice($rows, 0, 30) as $row) {
            for ($i = 0; $i < count($row); $i++) {
                $label = $this->key((string) ($row[$i] ?? ''));
                $value = trim((string) ($row[$i + 1] ?? ''));
                if ($value === '') continue;

                if (in_array($label, ['proposal id', 'path proposal id', 'project id'], true) && is_numeric($value)) {
                    $id = (int) $value;
                } elseif (in_array($label, ['project title', 'proposal title'], true)) {
                    $title = $value;
                } elseif (in_array($label, ['proponent', 'proponent / firm', 'proponent firm'], true)) {
                    $proponent = $value;
                } elseif (in_array($label, ['project amount', 'amount invested by setup', 'amount invested'], true)) {
                    $projectAmount = $this->parseAmount($value);
                }
            }
        }

        // Existing DOST rental-monitoring workbook:
        // FIRM | AMOUNT INVESTED BY SETUP | MONTH | ...
        foreach (array_slice($rows, 0, 15, true) as $headerIndex => $row) {
            $firmColumn = null;
            $amountColumn = null;

            foreach ($row as $column => $value) {
                $key = $this->key((string) $value);
                if ($key === 'firm') {
                    $firmColumn = $column;
                } elseif (in_array($key, ['amount invested by setup', 'amount invested', 'project amount'], true)) {
                    $amountColumn = $column;
                }
            }

            if ($firmColumn === null || $amountColumn === null) continue;

            for ($next = $headerIndex + 1; $next < min(count($rows), $headerIndex + 8); $next++) {
                $firmValue = trim((string) ($rows[$next][$firmColumn] ?? ''));
                if ($firmValue === '') continue;

                $proponent = $proponent ?: $firmValue;
                $amountValue = trim((string) ($rows[$next][$amountColumn] ?? ''));
                if ($projectAmount === null && $amountValue !== '') {
                    $projectAmount = $this->parseAmount($amountValue);
                }
                break;
            }
            break;
        }

        if ($id) {
            return ['id' => $id, 'title' => $title, 'proponent' => $proponent, 'project_amount' => $projectAmount, 'source' => 'sheet'];
        }

        if (preg_match('/(?:proposal|path|project)[-_\s#]*(\d{1,10})/i', pathinfo($filename, PATHINFO_FILENAME), $match)) {
            return ['id' => (int) $match[1], 'title' => $title, 'proponent' => $proponent, 'project_amount' => $projectAmount, 'source' => 'filename'];
        }

        if ($title && $proponent) {
            return ['id' => null, 'title' => $title, 'proponent' => $proponent, 'project_amount' => $projectAmount, 'source' => 'title_proponent'];
        }

        if ($proponent && $projectAmount !== null) {
            return ['id' => null, 'title' => $title, 'proponent' => $proponent, 'project_amount' => $projectAmount, 'source' => 'firm_amount'];
        }

        return [
            'id' => null,
            'title' => $title,
            'proponent' => $proponent,
            'project_amount' => $projectAmount,
            'source' => 'unknown',
            'error' => 'Proposal could not be identified. For the current rental format, PATH needs the exact FIRM and AMOUNT INVESTED BY SETUP. If more than one proposal can match, rename the file like Proposal-123-Rental-Records.xlsx.',
        ];
    }

    private function resolveProposal(array &$identity, User $user): ?Proposal
    {
        if (! empty($identity['id'])) {
            $proposal = Proposal::query()->visibleTo($user)->find((int) $identity['id']);
            if (! $proposal) {
                $identity['error'] = 'Proposal ID #'.$identity['id'].' was not found or is outside your data scope.';
            }
            return $proposal;
        }

        if (! empty($identity['title']) && ! empty($identity['proponent'])) {
            $matches = Proposal::query()
                ->visibleTo($user)
                ->whereRaw('LOWER(project_title) = ?', [strtolower(trim($identity['title']))])
                ->whereRaw('LOWER(proponent) = ?', [strtolower(trim($identity['proponent']))])
                ->limit(2)
                ->get();

            if ($matches->count() === 1) return $matches->first();

            $identity['error'] = $matches->count() > 1
                ? 'Proposal Title + Proponent matches more than one PATH proposal. Add Proposal ID to the spreadsheet.'
                : 'No PATH proposal exactly matches the Proposal Title + Proponent in this file.';
            return null;
        }

        if (! empty($identity['proponent']) && $identity['project_amount'] !== null) {
            $amount = (float) $identity['project_amount'];
            $matches = Proposal::query()
                ->visibleTo($user)
                ->whereRaw('LOWER(proponent) = ?', [strtolower(trim($identity['proponent']))])
                ->whereBetween('amount', [$amount - 0.01, $amount + 0.01])
                ->limit(2)
                ->get();

            if ($matches->count() === 1) return $matches->first();

            $identity['error'] = $matches->count() > 1
                ? 'FIRM + AMOUNT INVESTED BY SETUP matches more than one PATH proposal. Rename this file with its Proposal ID, for example Proposal-123-Rental-Records.xlsx.'
                : 'No PATH proposal exactly matches FIRM "'.$identity['proponent'].'" with Amount Invested ₱'.number_format($amount, 2).'. Check the firm name/proposal amount or include the Proposal ID in the filename.';
        }

        return null;
    }

    private function findRentalHeader(array $rows): array
    {
        foreach (array_slice($rows, 0, 60, true) as $index => $row) {
            $map = [];
            foreach ($row as $column => $value) {
                $key = $this->key((string) $value);
                if ($key === '') continue;
                $canonical = $this->canonicalHeader($key);
                if ($canonical) {
                    $map[$canonical] = $column;
                }
            }

            $hasLocator = isset($map['installment_no']) || isset($map['month']);
            $hasData = collect(['rental_payment', 'or_number', 'or_date', 'amount_in_or', 'remarks'])
                ->contains(fn ($key) => isset($map[$key]));
            if ($hasLocator && $hasData) {
                return [$index, $map];
            }
        }

        return [null, []];
    }

    private function canonicalHeader(string $key): ?string
    {
        return match ($key) {
            'firm', 'beneficiary', 'proponent', 'proponent / firm' => 'firm',
            'amount invested by setup', 'amount invested', 'project amount', 'amount invested by set-up' => 'project_amount',
            '#', 'no', 'no.', 'installment', 'installment no', 'installment no.', 'installment number', 'payment no', 'payment number' => 'installment_no',
            'month', 'due month', 'payment month', 'rental month', 'period' => 'month',
            'amount due', 'monthly amount due', 'scheduled amount', 'scheduled amount due' => 'amount_due',
            'rental payment', 'actual rental payment', 'payment', 'refund', 'collection', 'amount paid' => 'rental_payment',
            'outstanding balance', 'balance' => 'outstanding_balance',
            'or number', 'or no', 'or no.', 'official receipt number', 'official receipt no' => 'or_number',
            'or date', 'or date (mm.dd.yy)', 'or date (mm/dd/yy)', 'official receipt date', 'date of or', 'date paid' => 'or_date',
            'amount in or', 'or amount', 'amount per or', 'official receipt amount' => 'amount_in_or',
            'remarks', 'remark', 'notes', 'note' => 'remarks',
            default => null,
        };
    }

    private function readRows(string $path, string $name): array
    {
        return match (strtolower(pathinfo($name, PATHINFO_EXTENSION))) {
            'csv', 'txt' => $this->readCsv($path),
            'xlsx' => $this->readXlsx($path),
            default => throw new RuntimeException('Unsupported spreadsheet type.'),
        };
    }

    private function readCsv(string $path): array
    {
        $handle = fopen($path, 'rb');
        if (! $handle) throw new RuntimeException('Unable to read CSV file.');
        $rows = [];
        while (($row = fgetcsv($handle)) !== false) {
            if (isset($row[0])) $row[0] = preg_replace('/^\xEF\xBB\xBF/', '', (string) $row[0]);
            $rows[] = $row;
        }
        fclose($handle);
        return $rows;
    }

    private function readXlsx(string $path): array
    {
        try {
            $archive = new \PharData($path);
        } catch (\Throwable) {
            throw new RuntimeException('Unable to open XLSX file. Save it again as standard XLSX or CSV and retry.');
        }

        if (! isset($archive['xl/worksheets/sheet1.xml'])) {
            throw new RuntimeException('XLSX first worksheet is not readable.');
        }

        $sharedStrings = [];
        if (isset($archive['xl/sharedStrings.xml'])) {
            $sharedXml = $archive['xl/sharedStrings.xml']->getContent();
            if (preg_match_all('/<[^:>]*:?si\b[^>]*>(.*?)<\/[^:>]*:?si>/si', $sharedXml, $items)) {
                foreach ($items[1] as $item) {
                    $text = '';
                    if (preg_match_all('/<[^:>]*:?t\b[^>]*>(.*?)<\/[^:>]*:?t>/si', $item, $texts)) {
                        foreach ($texts[1] as $part) {
                            $text .= html_entity_decode(strip_tags($part), ENT_QUOTES | ENT_XML1, 'UTF-8');
                        }
                    }
                    $sharedStrings[] = $text;
                }
            }
        }

        $xml = $archive['xl/worksheets/sheet1.xml']->getContent();
        $rows = [];
        if (! preg_match_all('/<[^:>]*:?row\b[^>]*>(.*?)<\/[^:>]*:?row>/si', $xml, $rowMatches)) {
            return [];
        }

        foreach ($rowMatches[1] as $rowXml) {
            $row = [];
            if (preg_match_all('/<[^:>]*:?c\b([^>]*?)\s*\/>|<[^:>]*:?c\b([^>]*)>(.*?)<\/[^:>]*:?c>/si', $rowXml, $cellMatches, PREG_SET_ORDER)) {
                foreach ($cellMatches as $cell) {
                    $attrs = trim((string) (($cell[1] ?? '') !== '' ? $cell[1] : ($cell[2] ?? '')));
                    $body = (string) ($cell[3] ?? '');
                    if (! preg_match('/\br="([A-Z]+)\d+"/i', $attrs, $refMatch)) continue;
                    $column = $this->columnIndex($refMatch[1]);
                    $type = preg_match('/\bt="([^"]+)"/i', $attrs, $typeMatch) ? strtolower($typeMatch[1]) : '';
                    $value = '';
                    if ($type === 'inlinestr') {
                        if (preg_match_all('/<[^:>]*:?t\b[^>]*>(.*?)<\/[^:>]*:?t>/si', $body, $texts)) {
                            $value = implode('', array_map(fn ($part) => html_entity_decode(strip_tags($part), ENT_QUOTES | ENT_XML1, 'UTF-8'), $texts[1]));
                        }
                    } elseif (preg_match('/<[^:>]*:?v\b[^>]*>(.*?)<\/[^:>]*:?v>/si', $body, $valueMatch)) {
                        $raw = html_entity_decode(strip_tags($valueMatch[1]), ENT_QUOTES | ENT_XML1, 'UTF-8');
                        $value = $type === 's' ? ($sharedStrings[(int) $raw] ?? '') : $raw;
                    }
                    $row[$column] = $value;
                }
            }
            if ($row) {
                $max = max(array_keys($row));
                $normalized = [];
                for ($i = 0; $i <= $max; $i++) $normalized[] = $row[$i] ?? '';
                $rows[] = $normalized;
            }
        }
        return $rows;
    }

    private function parseDate(string $value): ?Carbon
    {
        $value = trim($value);
        if ($value === '') return null;

        // Existing rental files use MM.DD.YY text (example: 07.31.24).
        // Parse the two-digit year explicitly so it becomes 2024, not year 24.
        if (preg_match('/^(\d{1,2})\.(\d{1,2})\.(\d{2})$/', $value, $match)) {
            $year = (int) $match[3];
            $year += $year <= 69 ? 2000 : 1900;
            try {
                return Carbon::create($year, (int) $match[1], (int) $match[2])->startOfDay();
            } catch (\Throwable) {
                return null;
            }
        }

        if (is_numeric($value)) {
            $serial = (float) $value;
            if ($serial > 0 && $serial < 100000) {
                return Carbon::create(1899, 12, 30)->addDays((int) floor($serial))->startOfDay();
            }
        }
        foreach (['Y-m-d', 'm/d/Y', 'n/j/Y', 'm/d/y', 'n/j/y', 'm.d.Y', 'n.j.Y', 'm.d.y', 'n.j.y', 'd/m/Y', 'd-m-Y', 'M d Y', 'M d, Y'] as $format) {
            try {
                $date = Carbon::createFromFormat($format, $value);
                if ($date) return $date->startOfDay();
            } catch (\Throwable) {}
        }
        try { return Carbon::parse($value)->startOfDay(); } catch (\Throwable) { return null; }
    }

    private function parseMonth(string $value): ?Carbon
    {
        $value = trim($value);
        if ($value === '') return null;
        if (is_numeric($value)) return $this->parseDate($value)?->startOfMonth();
        foreach (['Y-m', 'Y-m-d', 'm/Y', 'n/Y', 'm/d/Y', 'n/j/Y', 'M Y', 'M-y', 'M y', 'F Y'] as $format) {
            try {
                $date = Carbon::createFromFormat($format, $value);
                if ($date) return $date->startOfMonth();
            } catch (\Throwable) {}
        }
        try { return Carbon::parse($value)->startOfMonth(); } catch (\Throwable) { return null; }
    }

    private function parseAmount(string $value): ?float
    {
        $clean = trim(str_replace([',', '₱', 'PHP', 'php', ' '], '', $value));
        if ($clean === '' || ! is_numeric($clean)) return null;
        $amount = (float) $clean;
        if ($amount < 0 || $amount > 9999999999.99) return null;
        return round($amount, 2);
    }

    private function mappedCell(array $row, array $map, string $key): string
    {
        return isset($map[$key]) ? trim((string) ($row[$map[$key]] ?? '')) : '';
    }

    private function cell(array $row, int $index): string
    {
        return trim((string) ($row[$index] ?? ''));
    }

    private function rowIsBlank(array $row): bool
    {
        foreach ($row as $value) if (trim((string) $value) !== '') return false;
        return true;
    }

    private function key(string $value): string
    {
        $value = preg_replace('/\s+/u', ' ', trim($value));
        return strtolower((string) $value);
    }

    private function columnIndex(string $letters): int
    {
        $letters = strtoupper($letters);
        $index = 0;
        foreach (str_split($letters) as $letter) $index = ($index * 26) + (ord($letter) - 64);
        return $index - 1;
    }

    private function hasSavedValue(mixed $value): bool
    {
        if ($value === null) return false;
        return ! is_string($value) || trim($value) !== '';
    }

    private function comparableValue(string $field, mixed $value): string
    {
        if ($value === null || $value === '') return '';
        if (in_array($field, ['rental_payment', 'amount_in_or'], true)) return number_format((float) $value, 2, '.', '');
        if ($field === 'or_date') {
            try { return Carbon::parse($value)->format('Y-m-d'); } catch (\Throwable) { return (string) $value; }
        }
        return trim((string) $value);
    }

    private function fieldLabel(string $field): string
    {
        return match ($field) {
            'rental_payment' => 'Rental Payment',
            'or_number' => 'OR Number',
            'or_date' => 'OR Date',
            'amount_in_or' => 'Amount in OR',
            'remarks' => 'Remarks',
            default => $field,
        };
    }

    private function deleteDirectory(string $directory): void
    {
        if (! is_dir($directory)) return;
        $items = array_diff(scandir($directory) ?: [], ['.', '..']);
        foreach ($items as $item) {
            $path = $directory.DIRECTORY_SEPARATOR.$item;
            is_dir($path) ? $this->deleteDirectory($path) : @unlink($path);
        }
        @rmdir($directory);
    }
}
