<?php

namespace Tests\Feature;

use App\Models\Proposal;
use App\Models\User;
use App\Services\MonitoringPdfExportService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

class DateRangeFiltersTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();
        Queue::fake();
        $this->admin = User::create(['username' => 'dates-admin', 'email' => 'dates@example.test', 'password' => 'TestPassword123!', 'first_name' => 'Dates', 'last_name' => 'Admin', 'role' => 'Admin']);
        $this->actingAs($this->admin);
    }

    private function proposal(string $name, string $date, string $status = 'Approved', string $province = 'Cebu'): Proposal
    {
        return Proposal::create(['project_title' => $name, 'proponent' => $name, 'province' => $province, 'sector' => 'SETUP', 'amount' => 36000,
            'status' => $status, 'document_status' => 'Complete', 'quarter_submitted' => 1, 'date_submitted' => $date, 'date_approved' => $date,
            'date_funded' => $date, 'user_id' => $this->admin->id]);
    }

    public function test_dashboard_and_analytics_use_inclusive_dates_instead_of_year_and_quarter(): void
    {
        $this->proposal('Boundary start', '2024-12-31');
        $this->proposal('Boundary end', '2025-01-31');
        $this->proposal('Outside', '2025-02-01');
        $filters = ['year' => 2026, 'quarter' => 4, 'date_from' => '2024-12-31', 'date_to' => '2025-01-31'];
        $this->get(route('dashboard', $filters))->assertOk()->assertViewHas('stats', fn ($stats) => $stats['funded'] == 2 && $stats['approved'] == 0)->assertSee('name="date_from"', false);
        $this->get(route('analytics.index', $filters))->assertOk()->assertViewHas('rows', fn ($rows) => array_sum(array_column($rows, 'count')) === 2);
    }

    public function test_annual_monitoring_averages_project_rates_and_filters_both_visuals(): void
    {
        $first = $this->proposal('Annual female', '2025-01-01', 'Funded');
        $first->update(['owner_contact_sex' => 'Female']);
        $first->rentalRecords()->create(['installment_no' => 1, 'due_month' => '2025-02-01', 'amount_due' => 1000, 'rental_payment' => 18000, 'or_date' => '2025-06-01']);
        $first->rentalRecords()->create(['installment_no' => 2, 'due_month' => '2026-02-01', 'amount_due' => 1000, 'rental_payment' => 5000, 'or_date' => '2026-06-01']);
        $second = $this->proposal('Annual unknown', '2025-01-01', 'Funded');
        $second->update(['amount' => 72000]);
        $second->rentalRecords()->create(['installment_no' => 1, 'due_month' => '2025-03-01', 'amount_due' => 2000, 'rental_payment' => 0]);
        $this->proposal('Annual NIR', '2025-01-01', 'Funded', 'Siquijor');
        $this->proposal('Future funding', '2026-01-01', 'Funded');
        $this->get(route('monitoring.annual', ['year' => 2025, 'province' => 'Cebu']))->assertOk()
            ->assertViewHas('summaries', fn ($s) => $s['Central Visayas']['count'] === 2 && $s['Central Visayas']['paid'] == 18000 && $s['Central Visayas']['average'] == 25 && $s['Central Visayas & NIR']['count'] === 3)
            ->assertViewHas('statuses', fn ($s) => $s->sum() === 2)
            ->assertViewHas('sexRows', fn ($s) => $s['Female']['count'] === 1 && $s['Not recorded']['count'] === 1)
            ->assertViewHas('noRefundRows', fn ($r) => $r->count() === 1 && $r['Cebu']['Not recorded'] === 1 && $r['Cebu']['Female'] === 0)
            ->assertViewHas('statusShares', fn ($s) => $s['Central Visayas'] == 0 && $s['Central Visayas & NIR'] == 0)
            ->assertSee('Cumulative refund progress')
            ->assertSee('Stopped Payment')->assertSee('Withdrawn')
            ->assertSee('Target average');
        $this->get(route('monitoring.annual', ['year' => 2025, 'region' => 'nir', 'monitoring_status' => 'invalid']))->assertOk()
            ->assertViewHas('selectedStatus', 'Graduated')
            ->assertViewHas('noRefundRows', fn ($r) => $r->keys()->all() === ['Siquijor'])
            ->assertViewHas('statuses', fn ($s) => $s->sum() === 1);
        $this->get(route('monitoring.annual', ['year' => 2025, 'date_from' => '2025-07-01', 'date_to' => '2025-06-01']))->assertSessionHasErrors('date_to');
    }

    public function test_target_average_uses_all_started_schedules_through_today(): void
    {
        $this->travelTo(\Carbon\Carbon::parse('2026-11-17 12:00:00'));
        $first = $this->proposal('Older project', '2020-01-01', 'Funded');
        $first->update(['amount' => 10000]);
        $first->rentalRecords()->create(['installment_no' => 1, 'due_month' => '2021-01-01', 'amount_due' => 3000, 'rental_payment' => 0]);
        $first->rentalRecords()->create(['installment_no' => 2, 'due_month' => '2026-11-18', 'amount_due' => 7000, 'rental_payment' => 7000]);
        $second = $this->proposal('Current project', '2026-01-01', 'Funded', 'Bohol');
        $second->update(['amount' => 20000]);
        $second->rentalRecords()->create(['installment_no' => 1, 'due_month' => '2026-11-17', 'amount_due' => 10000, 'rental_payment' => 20000]);
        $nir = $this->proposal('NIR project', '2023-01-01', 'Funded', 'Negros');
        $nir->rentalRecords()->create(['installment_no' => 1, 'due_month' => '2024-01-01', 'amount_due' => 36000, 'rental_payment' => 0]);
        $future = $this->proposal('Not started', '2026-01-01', 'Funded');
        $future->rentalRecords()->create(['installment_no' => 1, 'due_month' => '2026-12-01', 'amount_due' => 36000]);
        $this->proposal('No schedule', '2019-01-01', 'Funded');
        foreach ([['year' => 2026], ['year' => 2020, 'date_to' => '2026-11-17']] as $filters) {
            $this->get(route('monitoring.annual', $filters))->assertOk()
                ->assertViewHas('targetAverages', fn ($rates) => abs($rates['Central Visayas'] - 40) < 0.001 && abs($rates['Central Visayas & NIR'] - 60) < 0.001)
                ->assertSee('40.00%')->assertSee('60.00%');
        }
        $this->get(route('monitoring.annual', ['year' => 2025]))->assertOk()
            ->assertViewHas('targetAverages', fn ($rates) => abs($rates['Central Visayas'] - 30) < 0.001 && abs($rates['Central Visayas & NIR'] - 65) < 0.001)
            ->assertViewHas('summaries', fn ($s) => $s['Central Visayas & NIR']['count'] === 3)
            ->assertViewHas('cutoff', fn ($date) => $date->toDateString() === '2025-12-31')
            ->assertSee('Reporting year')->assertSee('name="date_from"', false)->assertSee('name="date_to"', false)->assertDontSee('Funding year');
        $this->travelBack();
    }

    public function test_annual_excel_export_contains_filtered_dashboard_values(): void
    {
        $project = $this->proposal('Export project', '2024-01-01', 'Funded');
        $project->update(['owner_contact_sex' => 'Female']);
        $this->proposal('NIR export project', '2024-01-01', 'Funded', 'Siquijor');
        $response = $this->get(route('monitoring.annual.export', ['year' => 2025, 'province' => 'Cebu', 'monitoring_status' => 'New']))->assertOk();
        $path = $response->baseResponse->getFile()->getPathname();
        try {
            $book = \PhpOffice\PhpSpreadsheet\IOFactory::load($path);
            $rows = collect($book->getActiveSheet()->toArray(null, true, false));
            $summary = $rows->first(fn ($r) => $r[0] === 'Central Visayas & NIR');
            $this->assertEquals(72000, $summary[1]);
            $female = $rows->first(fn ($r) => $r[0] === 'Female');
            $this->assertEquals(1, $female[1]);
            $this->assertEquals(36000, $female[2]);
            $this->assertTrue($rows->contains(fn ($r) => $r[0] === 'Target average'));
            $book->disconnectWorksheets();
        } finally {
            if (is_file($path)) unlink($path);
        }
        $this->getJson(route('monitoring.annual.export', ['date_from' => '2026-02-01', 'date_to' => '2026-01-01']))->assertUnprocessable();
    }

    public function test_empty_annual_visual_filters_include_all_funded_projects(): void
    {
        $this->proposal('New funded project', '2025-01-01', 'New');
        $this->proposal('Ongoing funded project', '2025-01-01', 'Ongoing', 'Siquijor');
        foreach ([[], ['region' => '', 'province' => ''], ['region' => 'all', 'province' => '']] as $filters) {
            $this->get(route('monitoring.annual', $filters + ['year' => 2025, 'monitoring_status' => 'New']))->assertOk()
                ->assertViewHas('region', 'all')->assertViewHas('province', '')
                ->assertViewHas('statuses', fn ($s) => $s->sum() === 2 && $s['New'] === 1)
                ->assertViewHas('sexRows', fn ($s) => $s['Not recorded']['count'] === 2)
                ->assertViewHas('noRefundRows', fn ($r) => $r->count() === 2)
                ->assertViewHas('statusShares', fn ($s) => $s['Central Visayas'] == 100 && $s['Central Visayas & NIR'] == 50)
                ->assertSee('name="region" value="all"', false);
        }
    }

    public function test_lifecycle_status_changes_feed_monitoring_and_keep_funded_access(): void
    {
        $project = $this->proposal('Lifecycle project', '2025-01-01', 'Funded');
        foreach (\App\Enums\FundedProjectStatus::values() as $status) {
            $this->patch(route('proposals.summary', $project), ['field' => 'funded_project_status', 'value' => $status])->assertRedirect()->assertSessionHasNoErrors();
            $this->assertSame($status, $project->fresh()->funded_project_status);
            $this->assertSame('Approved', $project->fresh()->status);
            $this->get(route('monitoring.annual', ['year' => 2025, 'monitoring_status' => $status]))->assertOk()
                ->assertViewHas('statuses', fn ($s) => $s[$status] === 1)
                ->assertViewHas('statusShares', fn ($s) => $s['Central Visayas'] == 100);
            $this->get(route('funded.index', ['year' => 2025]))->assertOk()->assertSee('Lifecycle project');
        }
    }

    public function test_funded_status_requires_date_and_initializes_independently(): void
    {
        $project = $this->proposal('Funding transition', '2025-01-01');
        $project->update(['date_funded' => null, 'status' => 'Ongoing Evaluation']);
        $this->patch(route('proposals.summary', $project), ['field' => 'funded_project_status', 'value' => 'Ongoing'])->assertSessionHasErrors('value');
        $this->patch(route('proposals.summary', $project), ['field' => 'date_funded', 'value' => '2025-03-01'])->assertRedirect();
        $this->assertSame('New', $project->fresh()->funded_project_status);
        $this->assertSame('Approved', $project->fresh()->status);
        $project->refresh()->update(['funded_project_status' => 'Completed']);
        $project->update(['project_title' => 'Updated name']);
        $this->assertSame('Completed', $project->fresh()->funded_project_status);
        $project->update(['date_funded' => null]);
        $this->assertNull($project->fresh()->funded_project_status);
    }

    public function test_proposal_and_funded_filters_use_their_respective_dates_and_keep_pagination(): void
    {
        $this->proposal('Outside', '2026-02-01')->update(['date_funded' => null]);
        for ($i = 0; $i < 9; $i++) $this->proposal('Boundary '.$i, '2025-01-31')->update(['date_funded' => null]);
        $filters = ['year' => 2026, 'date_to' => '2025-01-31'];
        $this->get(route('proposals.index', $filters))->assertOk()->assertViewHas('proposals', fn ($p) => $p->total() === 9 && str_contains($p->url(2), 'date_to=2025-01-31'));
        $funded = $this->proposal('Funded boundary', '2023-01-01', 'Funded');
        $funded->update(['date_funded' => '2025-01-31']);
        $this->get(route('funded.index', ['date_from' => '2025-01-31', 'date_to' => '2025-01-31']))->assertOk()->assertViewHas('proposals', fn ($p) => $p->total() === 1);
    }

    public function test_monitoring_and_exports_match_the_custom_funding_range(): void
    {
        $this->proposal('Boundary', '2020-06-15', 'Funded');
        $this->proposal('Outside', '2026-06-15', 'Funded');
        $filters = ['funding_year' => 2026, 'date_from' => '2020-06-15', 'date_to' => '2020-06-15'];
        $this->get(route('monitoring.rental', $filters))->assertOk()->assertViewHas('totals', fn ($totals) => $totals['projects'] === 1 && $totals['fund'] == 36000);
        $this->get(route('monitoring.liquidation', $filters))->assertOk()->assertSee('Boundary')->assertDontSee('Outside');
        $this->mock(MonitoringPdfExportService::class)->shouldReceive('create')->once()->withArgs(function ($report) {
            $json = json_encode($report);
            return str_contains($json, 'Boundary') && ! str_contains($json, 'Outside') && str_contains($report['subtitle'], '2020-06-15');
        })->andReturn('%PDF-test');
        $this->get(route('monitoring.rental.export', array_merge(['format' => 'pdf'], $filters)))->assertOk();
    }

    public function test_refund_report_filters_receipts_and_dues_independently(): void
    {
        $p = $this->proposal('Refund', '2024-01-01', 'Funded');
        $p->rentalRecords()->create(['installment_no' => 1, 'due_month' => '2025-01-01', 'amount_due' => 1000, 'rental_payment' => 500, 'or_date' => '2025-03-31']);
        $p->rentalRecords()->create(['installment_no' => 2, 'due_month' => '2025-03-01', 'amount_due' => 2000, 'rental_payment' => 700, 'or_date' => '2025-04-01']);
        $this->get(route('monitoring.refund-rate', ['year' => 2026, 'date_from' => '2025-03-01', 'date_to' => '2025-03-31']))->assertOk()
            ->assertViewHas('collectionTotals', fn ($totals) => $totals['total'] == 500)
            ->assertViewHas('receivableTotals', fn ($totals) => $totals['total'] == 2000);
    }

    public function test_invalid_ranges_are_rejected_and_empty_dates_keep_existing_filters(): void
    {
        foreach (['dashboard', 'proposals.index', 'monitoring.rental', 'monitoring.liquidation', 'users.index'] as $route) {
            $this->getJson(route($route, ['date_from' => '2026-03-01', 'date_to' => '2026-02-01']))->assertUnprocessable()->assertJsonValidationErrors('date_to');
            $this->getJson(route($route, ['date_from' => 'not-a-date']))->assertUnprocessable()->assertJsonValidationErrors('date_from');
        }
        $this->proposal('This year', '2026-01-01');
        $this->proposal('Previous year', '2025-01-01');
        $this->get(route('dashboard', ['year' => 2026, 'date_from' => '', 'date_to' => '']))->assertOk()->assertViewHas('stats', fn ($stats) => $stats['funded'] == 1 && $stats['approved'] == 0);
    }

    public function test_public_directory_dates_filter_results_and_preserve_query_links(): void
    {
        $this->proposal('Boundary', '2025-01-01', 'Funded');
        $this->proposal('Outside', '2024-12-31', 'Funded');
        $this->get(route('public.proposals', ['date_from' => '2025-01-01']))->assertOk()->assertViewHas('proposals', fn ($p) => $p->total() === 1)
            ->assertSee('name="date_from"', false)->assertDontSee('Outside');
    }

    public function test_account_and_archive_dates_include_the_whole_end_day(): void
    {
        $this->admin->forceFill(['created_at' => '2025-01-31 23:59:59'])->save();
        $filters = ['date_from' => '2025-01-31', 'date_to' => '2025-01-31'];
        $this->get(route('users.index', $filters))->assertOk()->assertViewHas('users', fn ($users) => $users->total() === 1);
        $p = $this->proposal('Archived boundary', '2020-01-01');
        $p->forceFill(['deleted_at' => '2025-01-31 23:59:59'])->save();
        $this->get(route('archives.index', array_merge(['section' => 'proposals'], $filters)))->assertOk()->assertViewHas('items', fn ($items) => $items->total() === 1);
    }
}

