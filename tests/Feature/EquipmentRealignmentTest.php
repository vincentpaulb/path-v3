<?php

namespace Tests\Feature;

use App\Models\Proposal;
use App\Models\User;
use DOMDocument;
use DOMXPath;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use PhpOffice\PhpSpreadsheet\IOFactory;
use Tests\TestCase;

class EquipmentRealignmentTest extends TestCase
{
    use RefreshDatabase;

    public function test_realignment_preserves_the_original_plan_and_existing_liquidation_history(): void
    {
        Queue::fake();
        $this->actingAs($admin = $this->user('Admin'));
        $proposal = $this->fundedProposal($admin);
        $page = route('proposals.liquidation.index', $proposal);
        $realignments = route('proposals.liquidation.realignments.store', $proposal);

        foreach (['Oven' => 50000, 'Existing mill' => 2000, 'Existing scale' => 500, 'Unused mixer' => 1000] as $name => $amount) {
            $this->post(route('proposals.liquidation.budget-lines.store', $proposal), [
                'item_name' => $name, 'quantity' => 1, 'approved_amount' => $amount,
            ])->assertSessionHasNoErrors();
        }
        $oven = $proposal->budgetLines()->where('item_name', 'Oven')->firstOrFail();
        $mill = $proposal->budgetLines()->where('item_name', 'Existing mill')->firstOrFail();
        $scale = $proposal->budgetLines()->where('item_name', 'Existing scale')->firstOrFail();
        $mixer = $proposal->budgetLines()->where('item_name', 'Unused mixer')->firstOrFail();
        $purchase = [
            'budget_line_id' => $mill->id, 'actual_item_name' => 'Purchased mill', 'quantity' => 1,
            'actual_cost' => 1500, 'date_liquidated' => '2026-11-18',
        ];
        $acquisitions = route('proposals.liquidation.acquisitions.store', $proposal);
        $this->post($acquisitions, $purchase)->assertSessionHasNoErrors();
        $this->post($acquisitions, [
            'budget_line_id' => $scale->id, 'actual_item_name' => 'Purchased scale', 'quantity' => 1,
            'actual_cost' => 450, 'realigned_amount' => 475,
        ])->assertSessionHasNoErrors();
        $this->post($realignments, ['operation' => 'revise', 'budget_line_id' => $oven->id, 'revised_amount' => 45000])
            ->assertSessionHasNoErrors();
        $this->post($realignments, ['operation' => 'revise', 'budget_line_id' => $oven->id, 'revised_amount' => 43000])
            ->assertSessionHasNoErrors()->assertRedirect($page.'#approved-budget');
        $this->post($realignments, ['operation' => 'remove', 'budget_line_id' => $mixer->id])
            ->assertSessionHasNoErrors();
        $addition = ['operation' => 'add', 'item_name' => 'New sealer', 'quantity' => 2, 'approved_amount' => 2800];
        $this->post($realignments, $addition)->assertSessionHasErrorsIn('realignment', 'budget_line_id');
        $this->post($realignments, $addition + ['budget_line_id' => $oven->id])
            ->assertSessionHasNoErrors();

        $sealer = $proposal->budgetLines()->where('item_name', 'New sealer')->firstOrFail();
        $this->assertSame('50000.00', $oven->fresh()->approved_amount);
        $this->assertSame('43000.00', $oven->fresh()->realigned_amount);
        $this->assertTrue($mixer->fresh()->removed_by_realignment);
        $this->assertFalse($mill->fresh()->removed_by_realignment);
        $this->assertTrue($sealer->added_by_realignment);
        $this->assertEquals($oven->id, $sealer->realignment_source_id);
        $this->assertDatabaseCount('proposal_acquisitions', 2);
        $this->assertDatabaseHas('liquidation_records', ['proposal_id' => $proposal->id, 'amount_liquidated' => 1500]);
        $this->assertDatabaseHas('activity_logs', ['proposal_id' => $proposal->id, 'action' => 'Recorded equipment realignment']);

        $response = $this->get($page)->assertOk()->assertSee('Save Realignment')
            ->assertSee('Removed by realignment')->assertSee('Added by realignment')->assertSee('Realigned from Oven')
            ->assertViewHas('financials', fn (array $financials) =>
                $financials['approved_equipment'] === 53500.0
                && $financials['current_approved_equipment'] === 48300.0
                && $financials['realigned_amount'] === 46275.0
                && $financials['liquidated'] === 1500.0);

        $html = new DOMDocument();
        $html->loadHTML($response->getContent(), LIBXML_NOERROR | LIBXML_NOWARNING);
        $xpath = new DOMXPath($html);
        $options = $xpath->query('//dialog[@id="equipmentRealignment"]//select[@name="budget_line_id"]/option[@value!=""]');
        $this->assertCount(1, $options);
        $this->assertSame((string) $oven->id, $options->item(0)->getAttribute('value'));
        $this->assertStringContainsString('Oven · ₱50,000.00 originally approved', $options->item(0)->textContent);

        $history = $xpath->query('//*[@id="realignment-history"]//tbody/tr');
        $this->assertCount(4, $history);
        $this->assertStringContainsString('Realigned from Oven', $history->item(0)->textContent);
        $this->assertStringContainsString('Equipment added', $history->item(0)->textContent);
        $this->assertSame($admin->full_name, trim($history->item(0)->getElementsByTagName('td')->item(5)->textContent));
        $this->assertStringContainsString('Removed from plan', $history->item(1)->textContent);
        $this->assertSame('₱45,000.00', trim($history->item(2)->getElementsByTagName('td')->item(3)->textContent));
        $this->assertSame('₱43,000.00', trim($history->item(2)->getElementsByTagName('td')->item(4)->textContent));
        $this->assertSame('₱50,000.00', trim($history->item(3)->getElementsByTagName('td')->item(3)->textContent));

        $this->post($realignments, ['operation' => 'remove', 'budget_line_id' => $mill->id])
            ->assertSessionHasErrorsIn('realignment', 'budget_line_id');
        $this->post($realignments, ['operation' => 'revise', 'budget_line_id' => $scale->id, 'revised_amount' => 400])
            ->assertSessionHasErrorsIn('realignment', 'budget_line_id');
        $this->post($realignments, $addition + ['budget_line_id' => $mill->id])
            ->assertSessionHasErrorsIn('realignment', 'budget_line_id');
        $this->post($realignments, ['operation' => 'remove', 'budget_line_id' => $sealer->id])
            ->assertSessionHasErrorsIn('realignment', 'budget_line_id');
        $this->assertDatabaseCount('proposal_budget_lines', 5);
        $this->from($page)->post($acquisitions, array_replace($purchase, ['budget_line_id' => $mixer->id]))
            ->assertSessionHasErrors('budget_line_id');
        $existingPurchase = $mill->acquisitions()->firstOrFail();
        $this->put(route('proposals.liquidation.acquisitions.update', [$proposal, $existingPurchase]), $purchase)
            ->assertSessionHasNoErrors();
        $this->post($acquisitions, [
            'budget_line_id' => $sealer->id, 'actual_item_name' => 'Purchased sealer', 'quantity' => 1, 'actual_cost' => 1000,
        ])->assertSessionHasNoErrors();
        $this->post($acquisitions, [
            'budget_line_id' => $sealer->id, 'actual_item_name' => 'Second sealer', 'quantity' => 1, 'actual_cost' => 900,
        ])->assertSessionHasNoErrors();

        $download = $this->get(route('proposals.liquidation.export', $proposal))->assertOk()->assertDownload();
        $path = $download->baseResponse->getFile()->getPathname();
        $book = IOFactory::load($path);
        try {
            $rows = array_column($book->getActiveSheet()->toArray(), null, 8);
            $this->assertEquals(43000, $rows['Oven'][14]);
            $this->assertSame('Removed by realignment', $rows['Unused mixer'][22]);
            $this->assertNull($rows['Purchased mill'][22]);
            $this->assertEquals(2800, $rows['Purchased sealer'][14]);
            $this->assertEquals(900, $rows['Purchased sealer'][16]);
            $this->assertSame('Oven', $rows['Purchased sealer'][23]);
            $this->assertNull($rows['Second sealer'][14]);
            $this->assertEquals(475, $rows['Purchased scale'][14]);
        } finally {
            $book->disconnectWorksheets();
            unlink($path);
        }
    }

    public function test_realignment_requires_permission_and_a_current_item_from_the_proposal(): void
    {
        $this->actingAs($admin = $this->user('Admin'));
        $proposal = $this->fundedProposal($admin);
        $otherProposal = $this->fundedProposal($admin);
        $this->post(route('proposals.liquidation.budget-lines.store', $otherProposal), [
            'item_name' => 'Other equipment', 'quantity' => 1, 'approved_amount' => 2000,
        ])->assertSessionHasNoErrors();
        $line = $otherProposal->budgetLines()->firstOrFail();
        $realignments = route('proposals.liquidation.realignments.store', $proposal);
        $change = ['operation' => 'revise', 'budget_line_id' => $line->id, 'revised_amount' => 0];

        $this->post($realignments, $change)->assertSessionHasErrorsIn('realignment', 'budget_line_id');
        $this->post(route('proposals.liquidation.realignments.store', $otherProposal), $change)->assertSessionHasNoErrors();
        $this->assertSame('0.00', $line->fresh()->realigned_amount);
        $this->get(route('proposals.liquidation.index', $proposal))->assertOk()
            ->assertSee('No equipment realignments have been recorded for this proposal.')
            ->assertDontSee('Other equipment');

        $this->actingAs($this->user('PSTO', 'Cebu'));
        $this->post($realignments, ['operation' => 'add', 'item_name' => 'Blocked', 'quantity' => 1, 'approved_amount' => 1])
            ->assertForbidden();
    }

    private function user(string $role, ?string $province = null): User
    {
        return User::create([
            'username' => strtolower($role), 'email' => strtolower($role).'@example.test',
            'password' => 'TestPassword123!', 'first_name' => 'Equipment', 'last_name' => 'Tester',
            'role' => $role, 'province' => $province,
        ]);
    }

    private function fundedProposal(User $owner): Proposal
    {
        return Proposal::create([
            'project_title' => 'Equipment realignment test', 'proponent' => 'Example', 'province' => 'Cebu', 'sector' => 'SETUP',
            'amount' => 100000, 'status' => 'Approved', 'document_status' => 'Complete', 'quarter_submitted' => 1,
            'date_submitted' => '2025-01-01', 'date_funded' => '2025-02-01', 'user_id' => $owner->id,
        ]);
    }
}
