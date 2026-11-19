<?php

namespace App\Services;

use App\Enums\FundingStatus;
use App\Enums\ProposalStatus;
use App\Enums\Sector;
use App\Models\Evaluator;
use App\Models\IndustrySector;
use App\Models\Proposal;
use App\Models\ProposalDocumentStatus;
use App\Models\Province;
use App\Models\RequiredDocument;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use RuntimeException;

class ProposalBulkImportService
{
    public const MAX_EVALUATORS = 6;

    public function templateHeaders(): array
    {
        $headers = [
            'Project Code',
            'Proposal Title',
            'Proponent / Firm',
            'Address',
            'Latitude',
            'Longitude',
            'Contact Person',
            'Contact Phone',
            'Contact Email',
            'Sector',
            'Industry Sector',
            'Province',
            'Proposal Amount',
            'Proposal Link',
            'Proposal Status',
            'Funded Project Status',
            'Funding Status',
            'MOA Status',
            'Check Status',
            'Date Submitted',
            'Date Approved',
            'Date Funded',
        ];

        for ($i = 1; $i <= self::MAX_EVALUATORS; $i++) {
            $headers[] = "Evaluator {$i}";
            $headers[] = "Evaluator {$i} Status";
            $headers[] = "Evaluator {$i} Date Assigned";
            $headers[] = "Evaluator {$i} Date Evaluated";
        }

        foreach (RequiredDocument::query()->orderBy('id')->get() as $document) {
            $headers[] = 'DOC | '.$document->name;
        }

        return $headers;
    }

    public function parseAndValidate(string $path, string $originalName, User $user): array
    {
        $rawRows = $this->readRows($path, $originalName);
        if (count($rawRows) < 2) {
            throw new RuntimeException('The uploaded file has no proposal rows. Keep the first row as the column headers, then add at least one proposal below it.');
        }

        $headerRow = array_map(fn ($value) => trim((string) $value), array_shift($rawRows));
        $headerMap = $this->buildHeaderMap($headerRow);
        $this->validateRequiredHeaders($headerMap);

        $documents = RequiredDocument::query()->orderBy('id')->get();
        $documentColumns = $this->resolveDocumentColumns($headerMap, $documents);
        $evaluatorDirectory = $this->evaluatorDirectory();
        $provinceValues = collect(Province::allValues())->mapWithKeys(fn ($name) => [$this->key($name) => $name]);
        $sectorValues = collect(Sector::values())->mapWithKeys(fn ($name) => [$this->key($name) => $name]);
        $industrySectorDirectory = $this->industrySectorDirectory();
        $proposalStatuses = collect(ProposalStatus::values())->mapWithKeys(fn ($name) => [$this->key($name) => $name]);
        $proposalStatuses->put($this->key('Ongoing'), 'Ongoing Evaluation');
        $proposalStatuses->put($this->key('Funded'), 'Approved');
        $fundingStatuses = collect(FundingStatus::values())->mapWithKeys(fn ($name) => [$this->key($name) => $name]);

        $rows = [];
        $seenInFile = [];

        foreach ($rawRows as $offset => $rawRow) {
            $rowNumber = $offset + 2;
            if ($this->isBlankRow($rawRow)) {
                continue;
            }

            $values = [];
            foreach ($headerRow as $index => $header) {
                if ($header === '') {
                    continue;
                }
                $values[$this->key($header)] = trim((string) ($rawRow[$index] ?? ''));
            }

            $errors = [];
            $warnings = [];

            $projectCode = $this->value($values, 'Project Code');
            if (mb_strlen($projectCode) > 100) $errors[] = 'Project Code cannot exceed 100 characters.';
            $projectTitle = $this->valueAny($values, ['Proposal Title', 'Project Title']);
            $proponent = $this->value($values, 'Proponent / Firm');
            $address = $this->value($values, 'Address');
            $latitudeRaw = $this->value($values, 'Latitude');
            $longitudeRaw = $this->value($values, 'Longitude');
            $contactPerson = $this->value($values, 'Contact Person');
            $contactPhone = $this->valueAny($values, ['Contact Phone', 'Phone Number']);
            $contactEmail = $this->valueAny($values, ['Contact Email', 'Email Address']);
            $sectorRaw = $this->value($values, 'Sector');
            $industrySectorRaw = $this->value($values, 'Industry Sector');
            $provinceRaw = $this->value($values, 'Province');
            $amountRaw = $this->valueAny($values, ['Proposal Amount', 'Project Amount']);
            $statusRaw = $this->value($values, 'Proposal Status');
            $fundingStatusRaw = $this->value($values, 'Funding Status');
            $moaStatusRaw = $this->value($values, 'MOA Status');
            $checkStatusRaw = $this->value($values, 'Check Status');
            $dateSubmittedRaw = $this->value($values, 'Date Submitted');
            $dateApprovedRaw = $this->value($values, 'Date Approved');
            $dateFundedRaw = $this->value($values, 'Date Funded');
            $proposalLink = $this->value($values, 'Proposal Link');

            if ($projectTitle === '') {
                $errors[] = 'Proposal Title is required.';
            }
            if ($proponent === '') {
                $errors[] = 'Proponent / Firm is required.';
            }
            if (mb_strlen($address) > 1000) {
                $errors[] = 'Address cannot exceed 1,000 characters.';
            }
            if (mb_strlen($contactPerson) > 255) {
                $errors[] = 'Contact Person cannot exceed 255 characters.';
            }
            if (mb_strlen($contactPhone) > 100) {
                $errors[] = 'Contact Phone cannot exceed 100 characters.';
            }
            if (mb_strlen($contactEmail) > 255) {
                $errors[] = 'Contact Email cannot exceed 255 characters.';
            }
            if ($contactEmail !== '' && ! filter_var($contactEmail, FILTER_VALIDATE_EMAIL)) {
                $errors[] = 'Contact Email is not a valid email address.';
            }

            $latitude = $this->parseCoordinate($latitudeRaw, -90, 90);
            $longitude = $this->parseCoordinate($longitudeRaw, -180, 180);
            if (($latitudeRaw === '') xor ($longitudeRaw === '')) {
                $errors[] = 'Latitude and Longitude must be provided together.';
            }
            if ($latitudeRaw !== '' && $latitude === null) {
                $errors[] = 'Latitude must be a number from -90 to 90.';
            }
            if ($longitudeRaw !== '' && $longitude === null) {
                $errors[] = 'Longitude must be a number from -180 to 180.';
            }

            $sector = $sectorValues->get($this->key($sectorRaw));
            if (! $sector) {
                $errors[] = $sectorRaw === '' ? 'Sector is required.' : "Unknown sector: {$sectorRaw}.";
            }

            $industrySector = $this->matchIndustrySector($industrySectorRaw, $industrySectorDirectory);
            if (! $industrySector) {
                $errors[] = $industrySectorRaw === ''
                    ? 'Industry Sector is required.'
                    : "Industry Sector is not found in Industry Sector Management: {$industrySectorRaw}. Use its code (for example 01A), exact name, or Code — Name.";
            } elseif (! $industrySector->is_active) {
                $warnings[] = "Industry Sector {$industrySector->code} — {$industrySector->name} is inactive; the historical classification will still be preserved.";
            }

            $province = $provinceValues->get($this->key($provinceRaw));
            if (! $province) {
                $errors[] = $provinceRaw === '' ? 'Province is required.' : "Province is not found in Province Management: {$provinceRaw}.";
            } elseif ($user->dataScope() === 'province' && strcasecmp((string) $user->province, $province) !== 0) {
                $errors[] = "Your account is scoped to {$user->province}; this row is for {$province}.";
            }

            $amount = $this->parseAmount($amountRaw);
            if ($amount === null) {
                $errors[] = 'Proposal Amount must be a valid non-negative number.';
            }

            $proposalStatus = $proposalStatuses->get($this->key($statusRaw));
            if (! $proposalStatus) {
                $errors[] = $statusRaw === '' ? 'Proposal Status is required.' : "Unknown Proposal Status: {$statusRaw}.";
            }

            $fundingStatus = null;
            if ($fundingStatusRaw !== '') {
                $fundingStatus = $fundingStatuses->get($this->key($fundingStatusRaw));
                if (! $fundingStatus) {
                    $errors[] = "Unknown Funding Status: {$fundingStatusRaw}.";
                }
            }

            $moaStatusProvided = $moaStatusRaw !== '';
            $moaStatus = $this->normalizeOptionalStatus($moaStatusRaw, [
                'Pending', 'Forwarded to PSTO for MOA signing', 'Returned to RPMO for signing',
                'For Notarization', 'Forwarded to accounting for obligation', 'Completed',
            ]);
            if ($moaStatusProvided && ! $moaStatus) {
                $errors[] = "Unknown MOA Status: {$moaStatusRaw}.";
            }
            $moaStatus ??= 'Pending';

            $checkStatusProvided = $checkStatusRaw !== '';
            $checkStatus = $this->normalizeOptionalStatus($checkStatusRaw, [
                'Pending', 'Obligated', 'Check available for pick up', 'Completed',
            ]);
            if ($checkStatusProvided && ! $checkStatus) {
                $errors[] = "Unknown Check Status: {$checkStatusRaw}.";
            }
            $checkStatus ??= 'Pending';

            $dateSubmitted = $this->parseDate($dateSubmittedRaw);
            $dateApproved = $this->parseDate($dateApprovedRaw);
            $dateFunded = $this->parseDate($dateFundedRaw);
            $fundedProjectStatus = trim($this->value($values, 'Funded Project Status'));
            if ($fundedProjectStatus !== '' && (!in_array($fundedProjectStatus, \App\Enums\FundedProjectStatus::values(), true) || !$dateFunded)) {
                $errors[] = 'Funded Project Status requires a valid lifecycle status and Date Funded.';
            }


            if (! $dateSubmitted) {
                $errors[] = $dateSubmittedRaw === '' ? 'Date Submitted is required.' : "Invalid Date Submitted: {$dateSubmittedRaw}.";
            }
            if ($dateApprovedRaw !== '' && ! $dateApproved) {
                $errors[] = "Invalid Date Approved: {$dateApprovedRaw}.";
            }
            if ($dateFundedRaw !== '' && ! $dateFunded) {
                $errors[] = "Invalid Date Funded: {$dateFundedRaw}.";
            }

            if ($dateSubmitted && $dateApproved && $dateApproved->lt($dateSubmitted)) {
                $warnings[] = 'Date Approved is earlier than Date Submitted.';
            }
            if ($dateApproved && $dateFunded && $dateFunded->lt($dateApproved)) {
                $warnings[] = 'Date Funded is earlier than Date Approved.';
            }
            if ($proposalStatus === 'Funded' && ! $dateFunded) {
                $warnings[] = 'Proposal Status is Funded but Date Funded is blank.';
            }

            if ($proposalLink !== '' && ! filter_var($proposalLink, FILTER_VALIDATE_URL)) {
                $errors[] = 'Proposal Link is not a valid URL.';
            }

            $documentStatuses = [];
            $documentSummary = ['submitted' => 0, 'lacking' => 0, 'na' => 0, 'pending' => 0];
            foreach ($documentColumns as $documentId => $columnKey) {
                $rawStatus = trim((string) ($values[$columnKey] ?? ''));
                $status = $this->normalizeDocumentStatus($rawStatus);
                if ($status === null) {
                    $documentName = $documents->firstWhere('id', $documentId)?->name ?? "Document #{$documentId}";
                    $errors[] = "Invalid document status for {$documentName}: {$rawStatus}. Use Submitted, Lacking, N/A, Pending, or leave it blank.";

                    continue;
                }

                $documentStatuses[(int) $documentId] = $status;
                match ($status) {
                    ProposalDocumentStatus::SUBMITTED => $documentSummary['submitted']++,
                    ProposalDocumentStatus::LACKING => $documentSummary['lacking']++,
                    ProposalDocumentStatus::NOT_APPLICABLE => $documentSummary['na']++,
                    default => $documentSummary['pending']++,
                };
            }

            // Any current master document missing from the uploaded header is
            // treated as Pending, never silently marked lacking or submitted.
            foreach ($documents as $document) {
                if (! array_key_exists((int) $document->id, $documentStatuses)) {
                    $documentStatuses[(int) $document->id] = ProposalDocumentStatus::PENDING;
                    $documentSummary['pending']++;
                }
            }

            $derivedDocumentStatus = ($documentSummary['lacking'] + $documentSummary['pending']) === 0
                ? 'Complete'
                : 'Incomplete';

            $evaluators = [];
            $usedEvaluatorIds = [];
            for ($i = 1; $i <= self::MAX_EVALUATORS; $i++) {
                $evaluatorRaw = $this->value($values, "Evaluator {$i}");
                $statusValue = $this->value($values, "Evaluator {$i} Status");
                $assignedRaw = $this->value($values, "Evaluator {$i} Date Assigned");
                $evaluatedRaw = $this->value($values, "Evaluator {$i} Date Evaluated");

                if ($evaluatorRaw === '' && $statusValue === '' && $assignedRaw === '' && $evaluatedRaw === '') {
                    continue;
                }
                if ($evaluatorRaw === '') {
                    $errors[] = "Evaluator {$i} name/email is required because evaluator details were entered in this slot.";

                    continue;
                }

                $match = $this->matchEvaluator($evaluatorRaw, $evaluatorDirectory);
                if (! $match['evaluator']) {
                    $errors[] = $match['error'] ?: "Evaluator {$i} was not found: {$evaluatorRaw}.";

                    continue;
                }
                /** @var Evaluator $evaluator */
                $evaluator = $match['evaluator'];
                if (in_array((int) $evaluator->id, $usedEvaluatorIds, true)) {
                    $errors[] = "Evaluator {$i} duplicates another evaluator on this proposal: {$evaluator->full_name}.";

                    continue;
                }
                $usedEvaluatorIds[] = (int) $evaluator->id;

                if ($evaluator->trashed()) {
                    $warnings[] = "Evaluator {$i} ({$evaluator->full_name}) is archived; the historical assignment will still be preserved.";
                }

                $evaluationStatus = $this->normalizeEvaluatorStatus($statusValue ?: 'Assigned');
                if (! $evaluationStatus) {
                    $errors[] = "Invalid Evaluator {$i} Status: {$statusValue}. Use Assigned, Started, Ongoing, or Approved.";

                    continue;
                }

                $assignedAt = $assignedRaw !== '' ? $this->parseDate($assignedRaw) : ($dateSubmitted?->copy());
                if ($assignedRaw !== '' && ! $assignedAt) {
                    $errors[] = "Invalid Evaluator {$i} Date Assigned: {$assignedRaw}.";
                }

                $evaluatedAt = $evaluatedRaw !== '' ? $this->parseDate($evaluatedRaw) : null;
                if ($evaluatedRaw !== '' && ! $evaluatedAt) {
                    $errors[] = "Invalid Evaluator {$i} Date Evaluated: {$evaluatedRaw}.";
                }
                if ($evaluationStatus !== 'Approved' && $evaluatedAt) {
                    $warnings[] = "Evaluator {$i} has a Date Evaluated but status is {$evaluationStatus}; the date will be ignored unless status is Approved.";
                    $evaluatedAt = null;
                }
                if ($evaluationStatus === 'Approved' && ! $evaluatedAt) {
                    $warnings[] = "Evaluator {$i} is Approved but Date Evaluated is blank; RTEC completion-by-year will remain undated until updated.";
                }

                $evaluators[] = [
                    'id' => (int) $evaluator->id,
                    'name' => $evaluator->full_name,
                    'classification' => $evaluator->classification,
                    'evaluation_status' => $evaluationStatus,
                    'assigned_at' => $assignedAt?->format('Y-m-d'),
                    'evaluated_at' => $evaluatedAt?->format('Y-m-d'),
                ];
            }

            $duplicateKey = $this->duplicateKey($projectTitle, $proponent, $dateSubmitted?->format('Y-m-d'));
            if ($projectTitle !== '' && $proponent !== '' && $dateSubmitted) {
                if (isset($seenInFile[$duplicateKey])) {
                    $errors[] = "Duplicate of row {$seenInFile[$duplicateKey]} in this upload (same Proposal Title, Proponent/Firm, and Date Submitted).";
                } else {
                    $seenInFile[$duplicateKey] = $rowNumber;
                }

                $existing = Proposal::withTrashed()
                    ->whereRaw('LOWER(project_title) = ?', [strtolower($projectTitle)])
                    ->whereRaw('LOWER(proponent) = ?', [strtolower($proponent)])
                    ->whereDate('date_submitted', $dateSubmitted->format('Y-m-d'))
                    ->first();
                if ($existing) {
                    $errors[] = 'Existing PATH record found with the same Proposal Title, Proponent/Firm, and Date Submitted'.($existing->trashed() ? ' (currently archived).' : '.');
                } else {
                    $similar = Proposal::withTrashed()
                        ->whereRaw('LOWER(project_title) = ?', [strtolower($projectTitle)])
                        ->whereRaw('LOWER(proponent) = ?', [strtolower($proponent)])
                        ->exists();
                    if ($similar) {
                        $warnings[] = 'A proposal with the same Proposal Title and Proponent/Firm exists, but with a different Date Submitted. Verify before importing.';
                    }
                }
            }

            $rows[] = [
                'row_number' => $rowNumber,
                'project_code' => $projectCode ?: null,
                'project_title' => $projectTitle,
                'proponent' => $proponent,
                'address' => $address ?: null,
                'latitude' => $latitude,
                'longitude' => $longitude,
                'contact_person' => $contactPerson ?: null,
                'contact_phone' => $contactPhone ?: null,
                'contact_email' => $contactEmail ?: null,
                'province' => $province ?: $provinceRaw,
                'sector' => $sector ?: $sectorRaw,
                'industry_sector_id' => $industrySector?->id,
                'industry_sector_code' => $industrySector?->code,
                'industry_sector_name' => $industrySector?->name ?: $industrySectorRaw,
                'amount' => $amount,
                'proposal_link' => $proposalLink ?: null,
                'status' => $proposalStatus ?: $statusRaw,
                'funded_project_status' => $fundedProjectStatus ?: null,
                'document_status' => $derivedDocumentStatus,
                'funding_status' => $fundingStatus,
                'moa_status' => $moaStatus,
                'moa_status_provided' => $moaStatusProvided,
                'check_status' => $checkStatus,
                'check_status_provided' => $checkStatusProvided,
                'date_submitted' => $dateSubmitted?->format('Y-m-d'),
                'date_approved' => $dateApproved?->format('Y-m-d'),
                'date_funded' => $dateFunded?->format('Y-m-d'),
                'evaluators' => $evaluators,
                'document_statuses' => $documentStatuses,
                'document_summary' => $documentSummary,
                'errors' => array_values(array_unique($errors)),
                'warnings' => array_values(array_unique($warnings)),
                'is_valid' => count($errors) === 0,
            ];
        }

        if (count($rows) > 5000) {
            throw new RuntimeException('A single import is limited to 5,000 proposal rows. Split larger historical data into multiple files.');
        }

        $collection = collect($rows);

        return [
            'original_filename' => $originalName,
            'headers' => $headerRow,
            'rows' => $rows,
            'total_rows' => $collection->count(),
            'valid_rows' => $collection->where('is_valid', true)->count(),
            'warning_rows' => $collection->filter(fn ($row) => $row['is_valid'] && count($row['warnings']) > 0)->count(),
            'error_rows' => $collection->where('is_valid', false)->count(),
            'required_document_count' => $documents->count(),
            'generated_at' => now()->toIso8601String(),
        ];
    }

    public function importValidRows(array $preview, User $user, string $batchRef): array
    {
        $imported = 0;
        $failed = 0;
        $failures = [];

        $documentIds = collect($preview['rows'] ?? [])
            ->flatMap(fn ($row) => array_keys($row['document_statuses'] ?? []))
            ->map(fn ($id) => (int) $id)
            ->filter()
            ->unique()
            ->values();
        $documentNames = RequiredDocument::withTrashed()
            ->whereIn('id', $documentIds)
            ->pluck('name', 'id');

        foreach ($preview['rows'] ?? [] as $row) {
            if (! ($row['is_valid'] ?? false)) {
                continue;
            }

            try {
                DB::transaction(function () use ($row, $user, $batchRef, $documentNames) {
                    $submitted = Carbon::parse($row['date_submitted']);
                    $approved = ! empty($row['date_approved']) ? Carbon::parse($row['date_approved']) : null;
                    $funded = ! empty($row['date_funded']) ? Carbon::parse($row['date_funded']) : null;

                    $alreadyExists = Proposal::withTrashed()
                        ->whereRaw('LOWER(project_title) = ?', [strtolower($row['project_title'])])
                        ->whereRaw('LOWER(proponent) = ?', [strtolower($row['proponent'])])
                        ->whereDate('date_submitted', $submitted->format('Y-m-d'))
                        ->lockForUpdate()
                        ->exists();

                    if ($alreadyExists) {
                        throw new RuntimeException('A matching proposal was created or restored after preview. This row was not imported.');
                    }

                    $proposal = Proposal::create([
                        'project_code' => $row['project_code'] ?? null,
                        'project_title' => $row['project_title'],
                        'proponent' => $row['proponent'],
                        'address' => $row['address'] ?? null,
                        'latitude' => $row['latitude'] ?? null,
                        'longitude' => $row['longitude'] ?? null,
                        'location_source' => isset($row['latitude'], $row['longitude']) ? 'manual' : null,
                        'contact_person' => $row['contact_person'] ?? null,
                        'contact_phone' => $row['contact_phone'] ?? null,
                        'contact_email' => $row['contact_email'] ?? null,
                        'province' => $row['province'],
                        'sector' => $row['sector'],
                        'industry_sector_id' => $row['industry_sector_id'] ?? null,
                        'amount' => $row['amount'],
                        'proposal_link' => $row['proposal_link'],
                        'status' => $row['status'],
                        'funded_project_status' => $row['funded_project_status'] ?? null,
                        'document_status' => $row['document_status'],
                        'funding_status' => $row['funding_status'],
                        'moa_status' => $row['moa_status'],
                        'moa_status_updated_at' => ! empty($row['moa_status_provided']) ? ($funded ?: $approved ?: $submitted) : null,
                        'check_status' => $row['check_status'],
                        'check_status_updated_at' => ! empty($row['check_status_provided']) ? ($funded ?: $approved ?: $submitted) : null,
                        'quarter_submitted' => (int) ceil($submitted->month / 3),
                        'date_submitted' => $submitted->format('Y-m-d'),
                        'date_updated' => ($funded ?: $approved ?: $submitted)->format('Y-m-d'),
                        'quarter_approved' => $approved ? (int) ceil($approved->month / 3) : null,
                        'quarter_funded' => $funded ? (int) ceil($funded->month / 3) : null,
                        'date_approved' => $approved?->format('Y-m-d'),
                        'date_funded' => $funded?->format('Y-m-d'),
                        'user_id' => $user->id,
                    ]);

                    foreach ($row['document_statuses'] ?? [] as $documentId => $status) {
                        ProposalDocumentStatus::create([
                            'proposal_id' => $proposal->id,
                            'required_document_id' => (int) $documentId,
                            'status' => $status,
                            'source' => 'bulk_import',
                        ]);
                    }

                    $lackingNames = RequiredDocument::query()
                        ->whereIn('id', collect($row['document_statuses'] ?? [])->filter(fn ($status) => $status === ProposalDocumentStatus::LACKING)->keys())
                        ->orderBy('id')
                        ->pluck('name');

                    if (collect($row['document_statuses'] ?? [])->isNotEmpty()) {
                        $evaluationStatusText = collect($row['evaluators'] ?? [])->isNotEmpty()
                            ? collect($row['evaluators'])->map(fn ($evaluator) => sprintf(
                                '%s (%s): %s',
                                $evaluator['name'],
                                $evaluator['classification'] ?: 'Evaluator',
                                $evaluator['evaluation_status']
                            ))->implode("\n")
                            : 'No evaluator assigned';

                        $proposal->missingDocuments()->create([
                            'description' => $lackingNames->isEmpty() ? 'No lacking documents' : $lackingNames->implode("\n"),
                            'evaluation_status' => $evaluationStatusText,
                            'moa_check' => null,
                            'created_by' => $user->id,
                        ]);
                    }

                    foreach ($row['evaluators'] ?? [] as $evaluator) {
                        $assignedAt = ! empty($evaluator['assigned_at'])
                            ? Carbon::parse($evaluator['assigned_at'])->startOfDay()
                            : $submitted->copy()->startOfDay();
                        $evaluatedAt = ! empty($evaluator['evaluated_at'])
                            ? Carbon::parse($evaluator['evaluated_at'])->startOfDay()
                            : null;

                        DB::table('evaluator_proposal')->insert([
                            'evaluator_id' => (int) $evaluator['id'],
                            'proposal_id' => $proposal->id,
                            'evaluation_status' => $evaluator['evaluation_status'],
                            'evaluated_at' => $evaluatedAt,
                            'created_at' => $assignedAt,
                            'updated_at' => $evaluatedAt ?: $assignedAt,
                        ]);
                    }

                    DB::table('activity_logs')->insert([
                        'proposal_id' => $proposal->id,
                        'user_id' => $user->id,
                        'action' => 'Proposal imported through Bulk Upload',
                        'sector' => $proposal->sector,
                        'metadata' => json_encode([
                            'batch_ref' => $batchRef,
                            'source_row' => $row['row_number'],
                            'historical_import' => true,
                            'import_snapshot' => [
                                'project_code' => $row['project_code'] ?? null,
                        'project_title' => $row['project_title'],
                                'proponent' => $row['proponent'],
                                'address' => $row['address'] ?? null,
                                'latitude' => $row['latitude'] ?? null,
                                'longitude' => $row['longitude'] ?? null,
                                'contact_person' => $row['contact_person'] ?? null,
                                'contact_phone' => $row['contact_phone'] ?? null,
                                'contact_email' => $row['contact_email'] ?? null,
                                'province' => $row['province'],
                                'sector' => $row['sector'],
                                'industry_sector_id' => $row['industry_sector_id'] ?? null,
                                'industry_sector_code' => $row['industry_sector_code'] ?? null,
                                'industry_sector_name' => $row['industry_sector_name'] ?? null,
                                'amount' => $row['amount'],
                                'proposal_link' => $row['proposal_link'],
                                'status' => $row['status'],
                        'funded_project_status' => $row['funded_project_status'] ?? null,
                                'funding_status' => $row['funding_status'],
                                'moa_status' => $row['moa_status'],
                                'check_status' => $row['check_status'],
                                'date_submitted' => $row['date_submitted'],
                                'date_approved' => $row['date_approved'],
                                'date_funded' => $row['date_funded'],
                                'evaluators' => collect($row['evaluators'] ?? [])->map(fn ($evaluator) => [
                                    'name' => $evaluator['name'],
                                    'classification' => $evaluator['classification'] ?? null,
                                    'evaluation_status' => $evaluator['evaluation_status'],
                                    'assigned_at' => $evaluator['assigned_at'],
                                    'evaluated_at' => $evaluator['evaluated_at'],
                                ])->values()->all(),
                                'document_statuses' => collect($row['document_statuses'] ?? [])->map(fn ($status, $documentId) => [
                                    'name' => $documentNames[(int) $documentId] ?? ('Required document #'.(int) $documentId),
                                    'status' => $status,
                                ])->values()->all(),
                            ],
                        ]),
                        'created_at' => now(),
                    ]);
                });

                $imported++;
            } catch (\Throwable $e) {
                $failed++;
                $failures[] = [
                    'row_number' => $row['row_number'] ?? null,
                    'project_title' => $row['project_title'] ?? '',
                    'message' => $e->getMessage(),
                ];
            }
        }

        return [
            'imported' => $imported,
            'failed' => $failed,
            'failures' => $failures,
        ];
    }

    private function readRows(string $path, string $originalName): array
    {
        $extension = strtolower(pathinfo($originalName, PATHINFO_EXTENSION));

        return match ($extension) {
            'csv', 'txt' => $this->readCsv($path),
            'xlsx' => $this->readXlsx($path),
            default => throw new RuntimeException('Unsupported file type. Upload a .csv or .xlsx file.'),
        };
    }

    private function readCsv(string $path): array
    {
        $handle = fopen($path, 'rb');
        if (! $handle) {
            throw new RuntimeException('Unable to read the uploaded CSV file.');
        }

        $rows = [];
        while (($row = fgetcsv($handle)) !== false) {
            if (isset($row[0])) {
                $row[0] = preg_replace('/^\xEF\xBB\xBF/', '', (string) $row[0]);
            }
            $rows[] = $row;
        }
        fclose($handle);

        return $rows;
    }

    /**
     * Lightweight XLSX reader for the first worksheet. It intentionally avoids
     * adding a Composer spreadsheet dependency to PATH and supports the plain
     * value/string/date cells produced by Excel/LibreOffice for this template.
     */
    private function readXlsx(string $path): array
    {
        try {
            $archive = new \PharData($path);
        } catch (\Throwable $e) {
            throw new RuntimeException('Unable to open the XLSX file. If this file was generated by another application, save it again as XLSX or CSV and retry.');
        }

        if (! isset($archive['xl/worksheets/sheet1.xml'])) {
            throw new RuntimeException('The XLSX file does not contain a readable first worksheet.');
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
            // Match self-closing cells first. Excel writes intentionally blank
            // cells as <c r="F2" .../>. Treating that tag as a normal opening
            // cell causes the parser to consume the next </c> and shifts values
            // into the wrong headers (surfacing internal indexes/date serials).
            if (preg_match_all(
                '/<[^:>]*:?c\b([^>]*?)\s*\/>|<[^:>]*:?c\b([^>]*)>(.*?)<\/[^:>]*:?c>/si',
                $rowXml,
                $cellMatches,
                PREG_SET_ORDER
            )) {
                foreach ($cellMatches as $cell) {
                    $attrs = trim((string) (($cell[1] ?? '') !== '' ? $cell[1] : ($cell[2] ?? '')));
                    $body = (string) ($cell[3] ?? '');

                    if (! preg_match('/\br="([A-Z]+)\d+"/i', $attrs, $refMatch)) {
                        continue;
                    }
                    $index = $this->columnIndex($refMatch[1]);
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

                    $row[$index] = $value;
                }
            }

            if ($row) {
                $max = max(array_keys($row));
                $normalized = [];
                for ($i = 0; $i <= $max; $i++) {
                    $normalized[] = $row[$i] ?? '';
                }
                $rows[] = $normalized;
            }
        }

        return $rows;
    }

    private function buildHeaderMap(array $headers): array
    {
        $map = [];
        foreach ($headers as $index => $header) {
            $key = $this->key($header);
            if ($key === '') {
                continue;
            }
            if (isset($map[$key])) {
                throw new RuntimeException("Duplicate column header found: {$header}.");
            }
            $map[$key] = $index;
        }

        return $map;
    }

    private function validateRequiredHeaders(array $headerMap): void
    {
        $required = [
            'Proposal Title' => ['Proposal Title', 'Project Title'],
            'Proponent / Firm' => ['Proponent / Firm'],
            'Sector' => ['Sector'],
            'Industry Sector' => ['Industry Sector'],
            'Province' => ['Province'],
            'Proposal Amount' => ['Proposal Amount', 'Project Amount'],
            'Proposal Status' => ['Proposal Status'],
            'Date Submitted' => ['Date Submitted'],
        ];
        $missing = collect($required)
            ->filter(fn ($aliases) => collect($aliases)->every(fn ($header) => ! array_key_exists($this->key($header), $headerMap)))
            ->keys()
            ->values();
        if ($missing->isNotEmpty()) {
            throw new RuntimeException('Missing required column(s): '.$missing->implode(', ').'. Download a fresh PATH template and keep its column headers unchanged.');
        }
    }

    private function resolveDocumentColumns(array $headerMap, Collection $documents): array
    {
        $columns = [];
        foreach ($documents as $document) {
            $key = $this->key('DOC | '.$document->name);
            if (array_key_exists($key, $headerMap)) {
                $columns[(int) $document->id] = $key;
            }
        }

        return $columns;
    }

    private function industrySectorDirectory(): array
    {
        $directory = [];
        foreach (IndustrySector::query()->ordered()->get() as $sector) {
            foreach ([
                $sector->code,
                $sector->name,
                $sector->code.' — '.$sector->name,
                $sector->code.' - '.$sector->name,
            ] as $label) {
                $directory[$this->key((string) $label)] = $sector;
            }
        }

        return $directory;
    }

    private function matchIndustrySector(string $value, array $directory): ?IndustrySector
    {
        if (trim($value) === '') {
            return null;
        }

        return $directory[$this->key($value)] ?? null;
    }

    private function evaluatorDirectory(): array
    {
        $byEmail = [];
        $byName = [];
        foreach (Evaluator::withTrashed()->orderBy('last_name')->orderBy('first_name')->get() as $evaluator) {
            if ($evaluator->email) {
                $byEmail[$this->key($evaluator->email)] = $evaluator;
            }
            $byName[$this->key($evaluator->full_name)][] = $evaluator;
        }

        return ['email' => $byEmail, 'name' => $byName];
    }

    private function matchEvaluator(string $value, array $directory): array
    {
        $key = $this->key($value);
        if (isset($directory['email'][$key])) {
            return ['evaluator' => $directory['email'][$key], 'error' => null];
        }

        $matches = $directory['name'][$key] ?? [];
        if (count($matches) === 1) {
            return ['evaluator' => $matches[0], 'error' => null];
        }
        if (count($matches) > 1) {
            return ['evaluator' => null, 'error' => "Evaluator name is ambiguous: {$value}. Use the evaluator's email address instead."];
        }

        return ['evaluator' => null, 'error' => null];
    }

    private function normalizeDocumentStatus(string $value): ?string
    {
        $key = $this->key($value);
        if ($key === '') {
            return ProposalDocumentStatus::PENDING;
        }

        return match ($key) {
            'submitted', 'received', 'complete', 'complied', 'yes', 'y' => ProposalDocumentStatus::SUBMITTED,
            'lacking', 'missing', 'incomplete', 'no', 'n' => ProposalDocumentStatus::LACKING,
            'n/a', 'na', 'not applicable', 'not-applicable' => ProposalDocumentStatus::NOT_APPLICABLE,
            'pending', 'unknown', 'not checked', 'for review' => ProposalDocumentStatus::PENDING,
            default => null,
        };
    }

    private function normalizeEvaluatorStatus(string $value): ?string
    {
        $allowed = ['Assigned', 'Started', 'Ongoing', 'Approved'];

        return $this->normalizeOptionalStatus($value, $allowed);
    }

    private function normalizeOptionalStatus(string $value, array $allowed): ?string
    {
        if (trim($value) === '') {
            return null;
        }
        foreach ($allowed as $item) {
            if (strcasecmp(trim($value), $item) === 0) {
                return $item;
            }
        }

        return null;
    }

    private function parseAmount(string $value): ?float
    {
        $clean = trim(str_replace([',', '₱', 'PHP', 'php', ' '], '', $value));
        if ($clean === '' || ! is_numeric($clean)) {
            return null;
        }
        $number = (float) $clean;

        return $number >= 0 && $number <= 9999999999.99 ? round($number, 2) : null;
    }

    private function parseCoordinate(string $value, float $minimum, float $maximum): ?float
    {
        $value = trim($value);
        if ($value === '' || ! is_numeric($value)) {
            return null;
        }

        $coordinate = (float) $value;

        return $coordinate >= $minimum && $coordinate <= $maximum
            ? round($coordinate, 7)
            : null;
    }

    private function parseDate(string $value): ?Carbon
    {
        $value = trim($value);
        if ($value === '') {
            return null;
        }

        if (is_numeric($value)) {
            $serial = (float) $value;
            if ($serial > 0 && $serial < 100000) {
                return Carbon::create(1899, 12, 30)->addDays((int) floor($serial));
            }
        }

        foreach (['Y-m-d', 'm/d/Y', 'n/j/Y', 'm/d/y', 'n/j/y', 'd/m/Y', 'd-m-Y', 'M d Y', 'M d, Y'] as $format) {
            try {
                $date = Carbon::createFromFormat($format, $value);
                if ($date && $date->format($format) === $value) {
                    return $date->startOfDay();
                }
            } catch (\Throwable) {
            }
        }

        try {
            return Carbon::parse($value)->startOfDay();
        } catch (\Throwable) {
            return null;
        }
    }

    private function duplicateKey(string $title, string $proponent, ?string $date): string
    {
        return $this->key($title).'|'.$this->key($proponent).'|'.($date ?? '');
    }

    private function value(array $values, string $header): string
    {
        return trim((string) ($values[$this->key($header)] ?? ''));
    }

    private function valueAny(array $values, array $headers): string
    {
        foreach ($headers as $header) {
            $key = $this->key($header);
            if (array_key_exists($key, $values)) {
                return trim((string) $values[$key]);
            }
        }

        return '';
    }

    private function key(string $value): string
    {
        return strtolower(trim(preg_replace('/\s+/', ' ', $value)));
    }

    private function isBlankRow(array $row): bool
    {
        foreach ($row as $value) {
            if (trim((string) $value) !== '') {
                return false;
            }
        }

        return true;
    }

    private function columnIndex(string $letters): int
    {
        $letters = strtoupper($letters);
        $index = 0;
        for ($i = 0, $length = strlen($letters); $i < $length; $i++) {
            $index = ($index * 26) + (ord($letters[$i]) - 64);
        }

        return $index - 1;
    }
}
