<?php

namespace Tests\Feature;

use App\Models\Proposal;
use App\Models\RentalRecord;
use App\Services\RentalPenaltyService;
use Carbon\Carbon;
use Tests\TestCase;

class RentalPenaltyTest extends TestCase
{
    public function test_penalty_uses_full_monthly_due_even_after_partial_payment(): void
    {
        $service = new RentalPenaltyService;
        $proposal = new Proposal(['date_funded' => '2018-12-31']);
        $row = new RentalRecord(['due_month' => '2026-03-01', 'amount_due' => 35000, 'rental_payment' => 5000]);
        $result = $service->calculate($proposal, $row, Carbon::parse('2026-06-15'));
        $this->assertSame(1, $result['months']);
        $this->assertEquals(700, $result['amount']);
        $row->rental_payment = 0;
        $this->assertEquals(700, $service->calculate($proposal, $row, Carbon::parse('2026-06-15'))['amount']);
        $this->assertEquals(700, $service->calculate($proposal, $row, Carbon::parse('2026-04-15'))['amount']);
        $row->rental_payment = 35000;
        $this->assertEquals(0, $service->calculate($proposal, $row, Carbon::parse('2026-06-15'))['amount']);
    }

    public function test_newer_unfunded_future_and_zero_due_rows_have_no_penalty(): void
    {
        $service = new RentalPenaltyService;
        $row = new RentalRecord(['due_month' => '2026-03-01', 'amount_due' => 35000]);
        foreach (['2019-01-01', null] as $funded) {
            $this->assertEquals(0, $service->calculate(new Proposal(['date_funded' => $funded]), $row, Carbon::parse('2026-11-16'))['amount']);
        }
        $proposal = new Proposal(['date_funded' => '2017-01-01']);
        $this->assertEquals(0, $service->calculate($proposal, $row, Carbon::parse('2026-02-01'))['amount']);
        $row->amount_due = 0;
        $this->assertEquals(0, $service->calculate($proposal, $row, Carbon::parse('2026-11-16'))['amount']);
    }
    public function test_each_unpaid_month_adds_one_charge_to_the_running_total(): void
    {
        $proposal = new Proposal(['date_funded' => '2018-01-01']);
        $records = collect();
        foreach (['2019-02-01', '2019-03-01', '2019-04-01'] as $i => $month) {
            $row = new RentalRecord(['due_month' => $month, 'amount_due' => 23611.11]);
            $row->id = $i + 1;
            $records->push($row);
        }
        $service = new RentalPenaltyService;
        $result = $service->schedule($proposal, $records, Carbon::parse('2026-11-16'));
        $this->assertEquals([472.22, 944.44, 1416.66], $result->pluck('amount')->all());
        $records[1]->rental_payment = 23611.11;
        $result = $service->schedule($proposal, $records, Carbon::parse('2026-11-16'));
        $this->assertEquals([472.22, 472.22, 944.44], $result->pluck('amount')->all());
        $result = $service->schedule($proposal, $records, Carbon::parse('2019-02-27'));
        $this->assertEquals([0, 0, 0], $result->pluck('amount')->all());
        $result = $service->schedule($proposal, $records, Carbon::parse('2019-02-28'));
        $this->assertEquals([472.22, 472.22, 472.22], $result->pluck('amount')->all());
    }
}
