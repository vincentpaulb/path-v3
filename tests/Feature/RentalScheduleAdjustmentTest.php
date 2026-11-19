<?php

namespace Tests\Feature;

use App\Models\Proposal;
use App\Models\User;
use App\Services\RentalRecordService;
use App\Services\RentalScheduleEditor;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

class RentalScheduleAdjustmentTest extends TestCase
{
    use RefreshDatabase;

    private function proposal(): Proposal
    {
        Queue::fake();
        $user = User::create(['username' => uniqid('rental'), 'email' => uniqid('rental').'@example.test', 'password' => 'TestPassword123!', 'first_name' => 'Rental', 'last_name' => 'Admin', 'role' => 'Admin']);
        $this->actingAs($user);
        $proposal = Proposal::create([
            'project_title' => 'Rental adjustment test', 'proponent' => 'Example', 'province' => 'Cebu', 'sector' => 'SETUP',
            'amount' => 1260000, 'status' => 'Approved', 'document_status' => 'Complete',
            'quarter_submitted' => 1, 'date_submitted' => '2025-01-01', 'date_funded' => '2025-01-01', 'user_id' => $user->id,
        ]);
        app(RentalRecordService::class)->ensureSchedule($proposal);
        return $proposal;
    }

    private function range(array $overrides = []): array
    {
        return array_replace(['mode' => 'range', 'adjustment_type' => 'Reconstruction', 'effective_month' => '2026-03', 'resume_month' => '2026-08', 'revised_amount_due' => 20000, 'approved_date' => '2026-02-01'], $overrides);
    }

    public function test_excel_download_contains_saved_records_and_final_penalty_without_summing_running_balances(): void
    {
        $p = $this->proposal();
        $p->update(['project_code' => '001-EXPORT', 'date_funded' => '2018-01-01']);
        $first = $p->rentalRecords()->orderBy('installment_no')->first();
        $first->update(['rental_payment' => 1000, 'or_number' => '000123', 'remarks' => '=1+1']);
        $response = $this->get(route('rental-records.export', $p))->assertOk()->assertDownload();
        $path = $response->baseResponse->getFile()->getPathname();
        try {
            $book = \PhpOffice\PhpSpreadsheet\IOFactory::load($path);
            $sheet = $book->getActiveSheet();
            $rows = $sheet->toArray();
            $this->assertTrue(collect($rows)->contains(fn ($row) => $row[0] === 'Project Code' && $row[1] === '001-EXPORT'));
            $payment = collect($rows)->first(fn ($row) => ($row[6] ?? null) === '000123');
            $this->assertNotNull($payment);
            $this->assertSame('=1+1', $payment[9]);
            $rowNumber = collect($rows)->search(fn ($row) => ($row[6] ?? null) === '000123') + 1;
            $this->assertSame('s', $sheet->getCell('J'.$rowNumber)->getDataType());
            $totalRow = collect($rows)->search(fn ($row) => ($row[1] ?? null) === 'TOTAL') + 1;
            $metrics = app(RentalRecordService::class)->metrics($p, $p->rentalRecords()->orderBy('installment_no')->get());
            $this->assertEquals($metrics['total_penalty'], $sheet->getCell('F'.$totalRow)->getValue());
            $this->assertEquals($metrics['outstanding_balance'], $sheet->getCell('E'.$totalRow)->getValue());
            $book->disconnectWorksheets();
        } finally {
            @unlink($path);
        }
        $viewer = User::create(['username' => 'export-other', 'email' => 'export-other@example.test', 'password' => 'TestPassword123!', 'first_name' => 'Other', 'last_name' => 'Province', 'role' => 'PSTO', 'province' => 'Bohol']);
        $this->actingAs($viewer)->get(route('rental-records.export', $p))->assertForbidden();
    }

    public function test_temporary_dues_restore_the_regular_rate_and_preserve_the_total(): void
    {
        $p = $this->proposal();
        $this->post(route('rental-records.restructure', $p), $this->range())->assertSessionHasNoErrors();
        $rows = $p->rentalRecords()->orderBy('installment_no')->get();
        $this->assertEquals(20000, $rows->first(fn ($row) => $row->due_month->toDateString() === '2026-03-01')->amount_due);
        $this->assertEquals(20000, $rows->first(fn ($row) => $row->due_month->toDateString() === '2026-08-01')->amount_due);
        $this->assertEquals(35000, $rows->first(fn ($row) => $row->due_month->toDateString() === '2026-09-01')->amount_due);
        $this->assertCount(39, $rows);
        $this->assertEquals(20000, $rows->last()->amount_due);
        $this->assertEquals(1260000, $rows->sum('amount_due'));
        $this->get(route('rental-records.index', $p))->assertOk()->assertSee('Input Unexpended Balance')->assertSee('data-edit-adjustment', false);
    }

    public function test_edit_and_remove_recalculate_and_survive_page_reload(): void
    {
        $p = $this->proposal();
        $editor = app(RentalScheduleEditor::class);
        $adjustment = $editor->save($p, $this->range(), null);
        $this->put(route('rental-records.adjustments.update', [$p, $adjustment]), $this->range(['revised_amount_due' => 70000]))->assertSessionHasNoErrors();
        $this->assertEquals(30, $p->rentalRecords()->count());
        $this->delete(route('rental-records.adjustments.destroy', [$p, $adjustment]))->assertSessionHasNoErrors();
        app(RentalRecordService::class)->ensureSchedule($p->fresh());
        $this->assertEquals(36, $p->rentalRecords()->count());
        $this->assertEquals(1260000, $p->rentalRecords()->sum('amount_due'));
    }

    public function test_credit_can_recalculate_36_months_or_shorten_the_term_and_be_reversed(): void
    {
        $p = $this->proposal();
        $first = $p->rentalRecords()->first();
        $first->update(['rental_payment' => 35000, 'or_number' => 'OR-001']);
        $editor = app(RentalScheduleEditor::class);
        $data = ['mode' => 'credit', 'effective_month' => '2026-02', 'unexpended_amount' => 145000, 'repayment_option' => '36_months'];
        $adjustment = $editor->save($p, $data, null);
        $this->assertEquals(37, $p->rentalRecords()->count());
        $this->assertEquals(30000, $p->rentalRecords()->where('installment_no', 2)->first()->amount_due);
        $metrics = app(RentalRecordService::class)->metrics($p, $p->rentalRecords()->get());
        $this->assertEquals(1080000, $metrics['outstanding_balance']);
        $this->assertEquals(35000, $metrics['total_paid']);
        $editor->save($p, array_replace($data, ['repayment_option' => 'keep_due']), null, $adjustment);
        $this->assertEquals(32, $p->rentalRecords()->count());
        $this->assertEquals(30000, $p->rentalRecords()->orderByDesc('installment_no')->first()->amount_due);
        $editor->remove($p, $adjustment);
        $this->assertEquals(36, $p->rentalRecords()->count());
        $this->assertSame('OR-001', $first->fresh()->or_number);
        $this->assertEquals(35000, $first->fresh()->rental_payment);
    }

    public function test_overlap_and_changes_to_saved_history_are_rejected_atomically(): void
    {
        $p = $this->proposal();
        $editor = app(RentalScheduleEditor::class);
        $adjustment = $editor->save($p, $this->range(), null);
        $this->post(route('rental-records.restructure', $p), $this->range(['effective_month' => '2026-06']))->assertSessionHasErrors('effective_month');
        $p->rentalRecords()->whereDate('due_month', '2026-04-01')->update(['rental_payment' => 20000, 'or_number' => 'PAID']);
        $this->delete(route('rental-records.adjustments.destroy', [$p, $adjustment]))->assertSessionHasErrors('effective_month');
        $this->assertDatabaseHas('rental_schedule_adjustments', ['id' => $adjustment->id]);
        $this->assertDatabaseHas('rental_records', ['proposal_id' => $p->id, 'or_number' => 'PAID', 'rental_payment' => 20000]);
    }

    public function test_zero_due_holiday_and_later_adjustments_are_replayed(): void
    {
        $p = $this->proposal();
        $editor = app(RentalScheduleEditor::class);
        $first = $editor->save($p, $this->range(['resume_month' => '2026-03', 'revised_amount_due' => 0]), null);
        $editor->save($p, $this->range(['effective_month' => '2026-09', 'resume_month' => '2026-10', 'revised_amount_due' => 10000]), null);
        $editor->remove($p, $first);
        $this->assertEquals(35000, $p->rentalRecords()->whereDate('due_month', '2026-03-01')->first()->amount_due);
        $this->assertEquals(10000, $p->rentalRecords()->whereDate('due_month', '2026-09-01')->first()->amount_due);
        $this->assertEquals(35000, $p->rentalRecords()->whereDate('due_month', '2026-11-01')->first()->amount_due);
        $this->assertEquals(1260000, $p->rentalRecords()->sum('amount_due'));
    }

    public function test_full_credit_leaves_no_schedule_and_over_deductions_are_rejected(): void
    {
        $p = $this->proposal();
        $data = ['mode' => 'credit', 'effective_month' => '2026-01', 'unexpended_amount' => 1260001, 'repayment_option' => 'keep_due'];
        $this->post(route('rental-records.restructure', $p), $data)->assertSessionHasErrors('unexpended_amount');
        $this->assertEquals(36, $p->rentalRecords()->count());
        $this->post(route('rental-records.restructure', $p), array_replace($data, ['unexpended_amount' => 1260000]))->assertSessionHasNoErrors();
        app(RentalRecordService::class)->ensureSchedule($p->fresh());
        $this->assertEquals(0, $p->rentalRecords()->count());
        $this->get(route('rental-records.index', $p))->assertOk();
    }

    public function test_foreign_adjustment_cannot_be_edited_or_deleted(): void
    {
        $p = $this->proposal();
        $adjustment = app(RentalScheduleEditor::class)->save($p, $this->range(), null);
        $other = $this->proposal();
        $this->put(route('rental-records.adjustments.update', [$other, $adjustment]), $this->range())->assertNotFound();
        $this->delete(route('rental-records.adjustments.destroy', [$other, $adjustment]))->assertNotFound();
    }

    public function test_legacy_pause_can_be_changed_to_a_temporary_range_and_removed(): void
    {
        $p = $this->proposal();
        $editor = app(RentalScheduleEditor::class);
        $pause = $editor->save($p, $this->range(['mode' => 'pause', 'resume_month' => '2026-09']), null);
        $this->assertFalse($p->rentalRecords()->whereDate('due_month', '2026-03-01')->exists());
        $this->assertEquals(20000, $p->rentalRecords()->whereDate('due_month', '2026-09-01')->first()->amount_due);
        $editor->save($p, $this->range(), null, $pause);
        $this->assertEquals(20000, $p->rentalRecords()->whereDate('due_month', '2026-03-01')->first()->amount_due);
        $this->assertEquals(35000, $p->rentalRecords()->whereDate('due_month', '2026-09-01')->first()->amount_due);
        $editor->remove($p, $pause);
        $this->assertEquals(36, $p->rentalRecords()->count());
    }

    public function test_earlier_unpaid_dues_are_not_duplicated_and_monitoring_includes_credit(): void
    {
        $p = $this->proposal();
        $first = $p->rentalRecords()->first();
        $first->update(['rental_payment' => 10000]);
        $editor = app(RentalScheduleEditor::class);
        $editor->save($p, ['mode' => 'credit', 'effective_month' => '2026-02', 'unexpended_amount' => 145000, 'repayment_option' => '36_months'], null);
        $this->assertEquals(30000, $p->rentalRecords()->where('installment_no', 2)->first()->amount_due);
        $this->assertEquals(1115000, $p->rentalRecords()->sum('amount_due'));
        $this->get(route('monitoring.rental', ['funding_year' => 2025]))->assertOk()
            ->assertViewHas('totals', fn ($totals) => $totals['balance'] == 1105000 && $totals['refund'] == 10000);
    }

    public function test_read_only_users_cannot_mutate_adjustments(): void
    {
        $p = $this->proposal();
        $adjustment = app(RentalScheduleEditor::class)->save($p, $this->range(), null);
        $viewer = User::create(['username' => 'rentalviewer', 'email' => 'rentalviewer@example.test', 'password' => 'TestPassword123!', 'first_name' => 'Viewer', 'last_name' => 'User', 'role' => 'PSTO', 'province' => 'Cebu']);
        $this->actingAs($viewer);
        $this->post(route('rental-records.restructure', $p), $this->range())->assertForbidden();
        $this->put(route('rental-records.adjustments.update', [$p, $adjustment]), $this->range())->assertForbidden();
        $this->delete(route('rental-records.adjustments.destroy', [$p, $adjustment]))->assertForbidden();
    }
}
