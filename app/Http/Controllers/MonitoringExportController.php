<?php

namespace App\Http\Controllers;

use App\Models\Proposal;
use App\Services\MonitoringPdfExportService;
use App\Services\MonitoringSpreadsheetExportService;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\Response;

class MonitoringExportController extends Controller
{
    public function __construct(
        private MonitoringController $monitoring,
        private LiquidationMonitoringController $liquidation,
        private MonitoringSpreadsheetExportService $spreadsheet,
        private MonitoringPdfExportService $pdf,
    ) {}

    public function rtec(Request $request, string $format): Response
    {
        $data = $this->monitoring->rtec($request)->getData();
        $year = (int) $data['year'];

        $report = [
            'title' => 'RTEC Monitoring',
            'subtitle' => 'Reporting period: '.$data['range']->label($year),
            'filename' => 'rtec-monitoring-'.$year,
            'sections' => [
                [
                    'title' => 'Summary',
                    'headers' => ['Metric', 'Value'],
                    'types' => ['text', 'text'],
                    'pdf_weights' => [2.1, 1],
                    'rows' => [
                        ['For RTEC Evaluation', number_format((int) $data['summary']['rtec_proposals'])],
                        ['Evaluations Completed', number_format((int) $data['summary']['completed_evaluations'])],
                        ['External Evaluations', number_format((int) $data['summary']['external_completed'])],
                        ['External Honorarium', 'PHP '.number_format((float) $data['summary']['honorarium_total'], 2)],
                        ['Proposed SETUP Fund', 'PHP '.number_format((float) $data['summary']['proposed_setup_fund'], 2)],
                        ['Approved SETUP Fund', 'PHP '.number_format((float) $data['summary']['approved_setup_fund'], 2)],
                    ],
                ],
                $this->evaluatorSection('External Evaluators', $data['externalEvaluators'], true),
                $this->evaluatorSection('Internal Evaluators', $data['internalEvaluators'], false),
                $this->provinceQuarterSection('For RTEC Evaluation by Province', $data['rtecByProvince'], false),
            ],
        ];

        foreach ($data['statusTables'] as $label => $rows) {
            $report['sections'][] = $this->provinceQuarterSection($label.' Proposals by Province', $rows, false);
        }
        $report['sections'][] = $this->provinceQuarterSection('Proposed SETUP Fund by Province', $data['proposedSetupFund'], true);
        $report['sections'][] = $this->provinceQuarterSection('Approved SETUP Fund by Province', $data['approvedSetupFund'], true);

        return $this->download($format, $report);
    }

    public function rental(Request $request, string $format): Response
    {
        $data = $this->monitoring->rental($request)->getData();
        $rows = $this->rentalRows($request);

        $filters = [];
        if ($data['range']->active()) {
            $filters[] = 'Funding dates: '.$data['range']->label();
        } elseif ($data['fundingYear']) {
            $filters[] = 'Funding year: '.$data['fundingYear'];
        } elseif ($data['showAllFundingYears']) {
            $filters[] = 'Funding years: All';
        } else {
            $filters[] = 'Funding years: '.$data['defaultYearFrom'].'-'.$data['defaultYearTo'];
        }
        if ($data['province'] !== '') {
            $filters[] = 'Province: '.$data['province'];
        }
        if ($data['sector'] !== '') {
            $filters[] = 'Sector: '.$data['sector'];
        }
        if ($data['search'] !== '') {
            $filters[] = 'Search: '.$data['search'];
        }

        $report = [
            'title' => 'Rental Monitoring',
            'subtitle' => implode(' | ', $filters),
            'filename' => 'rental-monitoring-'.now()->format('Ymd'),
            'sections' => [
                [
                    'title' => 'Project Rental Portfolio',
                    'headers' => ['Funding Year', 'Firm / Beneficiary', 'Project Title', 'Province', 'Sector', 'Fund', 'Refund', 'Pulled Out', 'Balance', 'Refund Rate'],
                    'types' => ['year', 'text', 'text', 'text', 'text', 'money', 'money', 'money', 'money', 'percent_red'],
                    'pdf_weights' => [0.7, 1.7, 1.8, 0.9, 0.7, 1, 1, 0.9, 1, 0.8],
                    'rows' => $rows->map(fn (array $row) => [
                        $row['funding_year'], $row['beneficiary'], $row['project_title'], $row['province'], $row['sector'],
                        $row['fund'], $row['refund'], $row['pulled_out'], $row['balance'], $row['refund_rate'],
                    ])->all(),
                ],
                [
                    'title' => 'Fund Summary by Year',
                    'headers' => ['Year', '# Projects', 'Fund', 'Refund', 'Pulled Out', 'Balance', 'Refund Rate'],
                    'types' => ['year', 'integer', 'money', 'money', 'money', 'money', 'percent_red'],
                    'pdf_weights' => [0.7, 0.8, 1.2, 1.2, 1.1, 1.2, 0.9],
                    'rows' => $data['yearSummary']->map(fn (array $row) => [
                        $row['year'], $row['projects'], $row['fund'], $row['refund'], $row['pulled_out'], $row['balance'], $row['refund_rate'],
                    ])->all(),
                ],
                [
                    'title' => 'Overall Summary',
                    'headers' => ['Projects', 'Fund', 'Refund', 'Pulled Out', 'Balance', 'Refund Rate'],
                    'types' => ['integer', 'money', 'money', 'money', 'money', 'percent_red'],
                    'rows' => [[
                        $data['totals']['projects'], $data['totals']['fund'], $data['totals']['refund'], $data['totals']['pulled_out'], $data['totals']['balance'], $data['totals']['refund_rate'],
                    ]],
                ],
            ],
        ];

        return $this->download($format, $report);
    }

    public function refundRate(Request $request, string $format): Response
    {
        $data = $this->monitoring->refundRate($request)->getData();
        $months = $data['months']->values()->all();
        $headers = array_merge(['Province'], $months, ['Total']);
        $moneyTypes = array_merge(['text'], array_fill(0, 13, 'money'));
        $rateTypes = array_merge(['text'], array_fill(0, 13, 'percent_red'));

        $collectionRows = $data['collection']->map(fn (array $row) => $this->monthlyRow($row))->all();
        $collectionRows[] = array_merge(['Total'], $this->monthlyValues($data['collectionTotals']));
        $receivableRows = $data['receivable']->map(fn (array $row) => $this->monthlyRow($row))->all();
        $receivableRows[] = array_merge(['Total'], $this->monthlyValues($data['receivableTotals']));
        $rateRows = $data['refundRates']->map(fn (array $row) => $this->monthlyRow($row))->all();
        $rateRows[] = array_merge(['MONTHLY'], $this->monthlyValues($data['monthlyRates']));

        $subtitle = 'Reporting period: '.$data['range']->label($data['year']);
        if ($data['sector'] !== '') {
            $subtitle .= ' | Sector: '.$data['sector'];
        }

        $report = [
            'title' => 'Refund Rate Monitoring',
            'subtitle' => $subtitle,
            'filename' => 'refund-rate-monitoring-'.$data['year'],
            'sections' => [
                [
                    'title' => 'Total Collection ? '.$data['range']->label($data['year']),
                    'headers' => $headers,
                    'types' => $moneyTypes,
                    'pdf_weights' => array_merge([1.1], array_fill(0, 12, 0.72), [0.9]),
                    'rows' => $collectionRows,
                ],
                [
                    'title' => 'Total Receivable ? '.$data['range']->label($data['year']),
                    'headers' => $headers,
                    'types' => $moneyTypes,
                    'pdf_weights' => array_merge([1.1], array_fill(0, 12, 0.72), [0.9]),
                    'rows' => $receivableRows,
                ],
                [
                    'title' => 'Refund Rate C.Y. '.$data['year'],
                    'headers' => $headers,
                    'types' => $rateTypes,
                    'pdf_weights' => array_merge([1.1], array_fill(0, 12, 0.72), [0.9]),
                    'rows' => $rateRows,
                ],
                [
                    'title' => 'Quarterly Refund Rate',
                    'headers' => ['Q1', 'Q2', 'Q3', 'Q4', 'Annual'],
                    'types' => ['percent_red', 'percent_red', 'percent_red', 'percent_red', 'percent_red'],
                    'rows' => [[
                        $data['quarterlyRates']['q1'], $data['quarterlyRates']['q2'], $data['quarterlyRates']['q3'], $data['quarterlyRates']['q4'], $data['quarterlyRates']['total'],
                    ]],
                ],
            ],
        ];

        return $this->download($format, $report);
    }

    public function liquidation(Request $request, string $format): Response
    {
        $data = $this->liquidation->index($request)->getData();
        $rows = $this->liquidationRows($request, $data);

        $filters = [];
        if ($data['hasDateRange']) {
            $filters[] = 'Funding date: '.($data['dateFrom'] ?: 'Beginning').' to '.($data['dateTo'] ?: 'Present');
        } elseif ($data['fundingYear']) {
            $filters[] = 'Funding year: '.$data['fundingYear'];
        } elseif ($data['showAllFundingYears']) {
            $filters[] = 'Funding years: All';
        } else {
            $filters[] = 'Funding years: '.$data['defaultYearFrom'].'-'.$data['defaultYearTo'];
        }
        if ($data['province'] !== '') {
            $filters[] = 'Province: '.$data['province'];
        }
        if ($data['sector'] !== '') {
            $filters[] = 'Sector: '.$data['sector'];
        }
        if ($data['search'] !== '') {
            $filters[] = 'Search: '.$data['search'];
        }

        $yearSummaries = $data['yearSummaries'];
        $released = (float) $yearSummaries->sum('released');
        $amountLiquidated = (float) $yearSummaries->sum('amount_liquidated');
        $notYet = max(0, $released - $amountLiquidated);
        $percentage = $released > 0 ? ($amountLiquidated / $released) * 100 : 0;

        $report = [
            'title' => 'Liquidation Monitoring',
            'subtitle' => implode(' | ', $filters),
            'filename' => 'liquidation-monitoring-'.now()->format('Ymd'),
            'sections' => [
                [
                    'title' => 'Table 1 - Liquidation Details',
                    'headers' => ['Year', 'Firm / Beneficiary', 'Project Title', 'Province', 'Sector', 'Amount Req.', 'Amount Liquidated', 'Reported Unexpended Bal.', 'Unexpended Remarks', 'Unliquidated Amount', 'Remarks'],
                    'types' => ['year', 'text', 'text', 'text', 'text', 'money', 'money', 'money', 'text', 'money', 'text'],
                    'pdf_weights' => [0.55, 1.55, 1.65, 0.8, 0.65, 0.9, 0.9, 1, 1.05, 0.9, 1.35],
                    'rows' => $rows->map(fn (array $row) => [
                        $row['funding_year'], $row['beneficiary'], $row['project_title'], $row['province'], $row['sector'],
                        $row['released'], $row['amount_liquidated'], $row['reported_unexpended'], $row['unexpended_remarks'], $row['unliquidated'], $row['remarks'] !== '' ? $row['remarks'] : $row['generated_remarks'],
                    ])->all(),
                ],
                [
                    'title' => 'Table 1 Overall Summary',
                    'headers' => ['Total Amount Released', 'Amount Not Yet Liquidated', 'Amount Liquidated', 'Percentage Liquidated'],
                    'types' => ['money', 'money', 'money', 'percent'],
                    'rows' => [[$released, $notYet, $amountLiquidated, $percentage]],
                ],
                [
                    'title' => 'Table 2 - Liquidation Summary by Funding Year',
                    'headers' => ['Year', 'Released', '# Projects', 'Liquidated', '# Liquidated', '% Liquidated', 'Unliquidated', '# Unliquidated', '% Unliquidated', 'Fully Liquidated', '# Fully', '% Fully Liquidated'],
                    'types' => ['year', 'money', 'integer', 'money', 'integer', 'percent', 'money', 'integer', 'percent', 'money', 'integer', 'percent'],
                    'pdf_weights' => [0.6, 1, 0.65, 1, 0.65, 0.8, 1, 0.7, 0.8, 1, 0.65, 0.8],
                    'rows' => $yearSummaries->map(fn (array $s) => [
                        $s['year'], $s['released'], $s['released_projects'], $s['liquidated'], $s['liquidated_projects'], $s['percentage_liquidated'],
                        $s['unliquidated'], $s['unliquidated_projects'], $s['percentage_unliquidated'], $s['fully_liquidated'], $s['fully_liquidated_projects'], $s['percentage_fully_liquidated'],
                    ])->all(),
                ],
                [
                    'title' => 'Fund Utilization Summary',
                    'headers' => ['Year', 'Released', '# Projects', 'No Report on Fund Utilization', '# No Report', '% Unutilized'],
                    'types' => ['year', 'money', 'integer', 'money', 'integer', 'percent'],
                    'rows' => $yearSummaries->map(fn (array $s) => [
                        $s['year'], $s['released'], $s['released_projects'], $s['no_report_amount'], $s['no_report_projects'], $s['percentage_unutilized'],
                    ])->all(),
                ],
                [
                    'title' => 'Reported Unexpended Balance / Refund Summary',
                    'headers' => ['Year', 'Reported Unexpended', '# Projects', 'Refunded', '# Refunded', '% Refund', 'No Proof of Refund', '# No Proof', '% Non-Refund'],
                    'types' => ['year', 'money', 'integer', 'money', 'integer', 'percent', 'money', 'integer', 'percent'],
                    'rows' => $yearSummaries->map(fn (array $s) => [
                        $s['year'], $s['reported_unexpended'], $s['reported_unexpended_projects'], $s['refunded'], $s['refunded_projects'], $s['percentage_refund'],
                        $s['no_proof'], $s['no_proof_projects'], $s['percentage_non_refund'],
                    ])->all(),
                ],
            ],
        ];

        return $this->download($format, $report);
    }

    private function download(string $format, array $report): Response
    {
        $format = strtolower($format);
        abort_unless(in_array($format, ['xlsx', 'pdf'], true), 404);
        $filename = Str::slug((string) ($report['filename'] ?? 'monitoring-export'));

        if ($format === 'xlsx') {
            $path = $this->spreadsheet->createXlsx($report);
            $contents = @file_get_contents($path);
            @unlink($path);

            if ($contents === false) {
                abort(500, 'Unable to prepare monitoring Excel export.');
            }

            return response($contents, 200, [
                'Content-Type' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
                'Content-Disposition' => 'attachment; filename="'.$filename.'.xlsx"',
                'Cache-Control' => 'no-store, no-cache, must-revalidate',
            ]);
        }

        if ($format === 'pdf') {
            return response($this->pdf->create($report), 200, [
                'Content-Type' => 'application/pdf',
                'Content-Disposition' => 'attachment; filename="'.$filename.'.pdf"',
                'Cache-Control' => 'no-store, no-cache, must-revalidate',
            ]);
        }

        abort(404);
    }

    private function evaluatorSection(string $title, Collection $evaluators, bool $includeHonorarium): array
    {
        $headers = ['Evaluator', 'Q1', 'Q2', 'Q3', 'Q4', 'Assigned', 'Completed'];
        $types = ['text', 'integer', 'integer', 'integer', 'integer', 'integer', 'integer'];
        $weights = [2.2, 0.6, 0.6, 0.6, 0.6, 0.75, 0.8];
        if ($includeHonorarium) {
            $headers[] = 'Honorarium';
            $types[] = 'money';
            $weights[] = 1;
        }

        return [
            'title' => $title,
            'headers' => $headers,
            'types' => $types,
            'pdf_weights' => $weights,
            'rows' => $evaluators->map(function (array $row) use ($includeHonorarium) {
                $values = [$row['name'], $row['q1'], $row['q2'], $row['q3'], $row['q4'], $row['assigned'], $row['completed']];
                if ($includeHonorarium) {
                    $values[] = $row['honorarium'];
                }
                return $values;
            })->all(),
        ];
    }

    private function provinceQuarterSection(string $title, Collection $rows, bool $money): array
    {
        return [
            'title' => $title,
            'headers' => ['Province', 'Q1', 'Q2', 'Q3', 'Q4', 'Total'],
            'types' => ['text', ...array_fill(0, 5, $money ? 'money' : 'integer')],
            'pdf_weights' => [1.8, 1, 1, 1, 1, 1],
            'rows' => $rows->map(fn (array $row) => [$row['province'], $row['q1'], $row['q2'], $row['q3'], $row['q4'], $row['total']])->all(),
        ];
    }

    private function rentalRows(Request $request): Collection
    {

        $range = new \App\Support\DateRangeFilter($request);        $user = $request->user();
        $search = trim((string) $request->query('search', ''));
        $province = trim((string) $request->query('province', ''));
        $sector = trim((string) $request->query('sector', ''));
        $fundingYearValue = trim((string) $request->query('funding_year', ''));
        $showAllFundingYears = $fundingYearValue === 'all';
        $fundingYear = ctype_digit($fundingYearValue) ? (int) $fundingYearValue : null;
        $defaultYearFrom = now()->year - 4;
        $defaultYearTo = now()->year;

        $query = Proposal::query()->visibleTo($user)->whereNotNull('date_funded')->where('amount', '>', 0);
        $query->when($province !== '', fn ($q) => $q->where('province', $province));
        $query->when($sector !== '', fn ($q) => $q->where('sector', $sector));
        if ($range->active()) {
            $range->apply($query, 'date_funded');
        } elseif ($fundingYear !== null) {
            $query->whereYear('date_funded', $fundingYear);
        } elseif (! $showAllFundingYears) {
            $query->whereBetween('date_funded', ["{$defaultYearFrom}-01-01", "{$defaultYearTo}-12-31"]);
        }
        if ($search !== '') {
            $like = '%'.$search.'%';
            $query->where(function ($q) use ($like, $search) {
                $q->where('proponent', 'like', $like)->orWhere('project_title', 'like', $like)->orWhere('province', 'like', $like)->orWhere('sector', 'like', $like);
                if (preg_match('/^\d{4}$/', $search)) {
                    $q->orWhereYear('date_funded', (int) $search);
                }
            });
        }

        return $query->withSum('rentalRecords as refund_total', 'rental_payment')
                ->withSum('rentalScheduleAdjustments as unexpended_total', 'unexpended_amount')
            ->orderBy('date_funded')->orderBy('proponent')
            ->get(['id', 'project_title', 'proponent', 'province', 'sector', 'amount', 'status', 'date_funded'])
            ->map(function (Proposal $proposal) {
                $fund = (float) $proposal->amount;
                $refund = (float) ($proposal->refund_total ?? 0);
                $balance = max(0, $fund - $refund - (float) ($proposal->unexpended_total ?? 0));
                return [
                    'funding_year' => (int) $proposal->date_funded->year,
                    'beneficiary' => $proposal->proponent,
                    'project_title' => $proposal->project_title,
                    'province' => $proposal->province,
                    'sector' => $proposal->sector,
                    'fund' => $fund,
                    'refund' => $refund,
                    'pulled_out' => 0.0,
                    'balance' => $balance,
                    'refund_rate' => $fund > 0 ? round(($refund / $fund) * 100, 2) : 0.0,
                ];
            });
    }

    private function liquidationRows(Request $request, array $data): Collection
    {
        $query = Proposal::query()->visibleTo($request->user())->whereNotNull('date_funded')->where('amount', '>', 0);
        $targetProposalId = (int) $request->integer('proposal_id');
        $query->when($targetProposalId > 0, fn ($q) => $q->whereKey($targetProposalId));
        $query->when($data['province'] !== '', fn ($q) => $q->where('province', $data['province']));
        $query->when($data['sector'] !== '', fn ($q) => $q->where('sector', $data['sector']));

        if ($data['hasDateRange']) {
            if ($data['dateFrom'] !== '') {
                $query->whereDate('date_funded', '>=', $data['dateFrom']);
            }
            if ($data['dateTo'] !== '') {
                $query->whereDate('date_funded', '<=', $data['dateTo']);
            }
        } elseif ($data['fundingYear'] !== null) {
            $query->whereYear('date_funded', $data['fundingYear']);
        } elseif (! $data['showAllFundingYears']) {
            $query->whereBetween('date_funded', ["{$data['defaultYearFrom']}-01-01", "{$data['defaultYearTo']}-12-31"]);
        }

        if ($data['search'] !== '') {
            $like = '%'.$data['search'].'%';
            $query->where(fn ($q) => $q->where('proponent', 'like', $like)->orWhere('project_title', 'like', $like)->orWhere('province', 'like', $like)->orWhere('sector', 'like', $like));
        }

        return $query
            ->with('liquidationRecord')
            ->withCount(['acquisitions', 'liquidationTransactions'])
            ->withSum(['acquisitions as equipment_liquidated_sum' => fn ($query) => $query->whereNotNull('date_liquidated')], 'actual_cost')
            ->withSum(['liquidationTransactions as ledger_disbursement_sum' => fn ($query) => $query->where('status', 'Posted')], 'disbursement_amount')
            ->withSum(['liquidationTransactions as ledger_refund_sum' => fn ($query) => $query->where('status', 'Posted')], 'refund_amount')
            ->orderBy('date_funded')
            ->orderBy('proponent')
            ->get(['id', 'project_title', 'proponent', 'province', 'sector', 'amount', 'status', 'date_funded'])
            ->map(fn (Proposal $proposal) => $this->liquidationRow($proposal));
    }

    private function liquidationRow(Proposal $proposal): array
    {
        $record = $proposal->liquidationRecord;
        $released = $record?->amount_released !== null ? (float) $record->amount_released : (float) $proposal->amount;
        $hasEquipmentDatabase = (int) ($proposal->acquisitions_count ?? 0) > 0;
        $hasDetailedLedger = (int) ($proposal->liquidation_transactions_count ?? 0) > 0;
        $liquidated = $hasEquipmentDatabase
            ? max(0, (float) ($proposal->equipment_liquidated_sum ?? 0))
            : ($hasDetailedLedger
                ? max(0, (float) ($proposal->ledger_disbursement_sum ?? 0))
                : (float) ($record?->amount_liquidated ?? 0));
        $reported = (float) ($record?->reported_unexpended_balance ?? 0);
        $refunded = $hasDetailedLedger
            ? max(0, (float) ($proposal->ledger_refund_sum ?? 0))
            : (float) ($record?->refunded_unexpended_amount ?? 0);
        $proof = (bool) ($record?->proof_of_refund_received ?? false);
        $status = (string) ($record?->liquidation_report_status ?? 'No Report');
        $attachments = (bool) ($record?->attachments_complete ?? false);
        $statusReport = (bool) ($record?->status_report_submitted ?? false);
        $countedRefund = $proof ? min($refunded, $reported) : 0.0;
        $effective = min($released, max(0, $liquidated + $countedRefund));
        $unliquidated = max(0, $released - $effective);

        $unexpendedRemark = $reported <= 0.005
            ? '—'
            : ((! $proof || $refunded <= 0.005) ? 'No proof of refund' : (($refunded + 0.005 >= $reported) ? 'Refunded' : 'Partially refunded'));
        $generatedRemark = match (true) {
            $status === 'No Report' => 'No report on fund utilization',
            $status === 'Complete' && $attachments && $unliquidated <= 0.005 => $statusReport ? 'Fully liquidated w/ status report' : 'Fully liquidated & no status report',
            $status === 'Complete' => $statusReport ? 'Liquidation report complete - balance remains' : 'Liquidation report complete - no status report',
            default => $statusReport ? 'Partial liquidation & status report' : 'Partial liquidation & no status report',
        };

        return [
            'funding_year' => (int) $proposal->date_funded->year,
            'beneficiary' => $proposal->proponent,
            'project_title' => $proposal->project_title,
            'province' => $proposal->province,
            'sector' => $proposal->sector,
            'released' => $released,
            'amount_liquidated' => $liquidated,
            'reported_unexpended' => $reported,
            'unexpended_remarks' => $unexpendedRemark,
            'unliquidated' => $unliquidated,
            'remarks' => (string) ($record?->remarks ?? ''),
            'generated_remarks' => $generatedRemark,
        ];
    }

    private function monthlyRow(array $row): array
    {
        return array_merge([$row['province']], $this->monthlyValues($row));
    }

    private function monthlyValues(array $row): array
    {
        $values = [];
        for ($month = 1; $month <= 12; $month++) {
            $values[] = (float) ($row['m'.$month] ?? 0);
        }
        $values[] = (float) ($row['total'] ?? 0);
        return $values;
    }
}
