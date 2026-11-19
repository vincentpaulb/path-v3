<?php

namespace Tests\Feature;

use App\Models\Permission;
use App\Models\Proposal;
use App\Models\Role;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class PstoAccessTest extends TestCase
{
    use RefreshDatabase;

    public function test_psto_only_sees_proposals_from_the_assigned_province(): void
    {
        $user = $this->pstoUser();
        $local = $this->proposal('Cebu', 'CEBU PROPOSAL VISIBLE', $user);
        $other = $this->proposal('Bohol', 'BOHOL PROPOSAL HIDDEN', $user);

        $this->actingAs($user)->get(route('proposals.index'))
            ->assertOk()
            ->assertSee($local->project_title)
            ->assertDontSee($other->project_title);

        $this->actingAs($user)->get(route('proposals.show', $local))
            ->assertOk()
            ->assertSee('MOA status')
            ->assertSee('Check status')
            ->assertDontSee('Edit MOA status')
            ->assertDontSee('Edit check status');
        $this->actingAs($user)->get(route('proposals.show', $other))->assertForbidden();
    }

    public function test_psto_cannot_edit_even_if_an_old_role_assignment_contains_edit_permission(): void
    {
        $user = $this->pstoUser();
        $proposal = $this->proposal('Cebu', 'READ ONLY PROPOSAL', $user);
        $editPermission = Permission::query()->where('key', 'proposals.edit')->firstOrFail();
        $user->accessRole->permissions()->syncWithoutDetaching($editPermission);
        $user->unsetRelation('accessRole');

        $this->assertFalse($user->hasPermission('proposals.edit'));
        $this->actingAs($user)->get(route('proposals.edit', $proposal))->assertForbidden();
        $this->actingAs($user)->put(route('proposals.update', $proposal), [])->assertForbidden();
    }

    public function test_psto_can_comment_and_reply_only_on_proposals_in_the_assigned_province(): void
    {
        $user = $this->pstoUser();
        $local = $this->proposal('Cebu', 'LOCAL DISCUSSION', $user);
        $other = $this->proposal('Bohol', 'OTHER DISCUSSION', $user);

        $this->actingAs($user)->post(route('comments.store', $local), [
            'content' => 'Provincial review comment.',
        ])->assertSessionHasNoErrors();

        $comment = $local->comments()->firstOrFail();
        $this->actingAs($user)->post(route('replies.store', $comment), [
            'content' => 'Provincial follow-up reply.',
        ])->assertSessionHasNoErrors();

        $this->assertDatabaseHas('comments', ['proposal_id' => $local->id, 'user_id' => $user->id]);
        $this->assertDatabaseHas('replies', ['comment_id' => $comment->id, 'user_id' => $user->id]);

        $this->actingAs($user)->post(route('comments.store', $other), [
            'content' => 'Must not be accepted.',
        ])->assertForbidden();
    }

    public function test_psto_can_open_every_monitoring_page_with_view_only_permissions(): void
    {
        $user = $this->pstoUser();

        foreach ([
            'monitoring.rtec',
            'monitoring.rental',
            'monitoring.refund-rate',
            'monitoring.liquidation',
        ] as $route) {
            $this->actingAs($user)->get(route($route))->assertOk();
        }

        $this->assertTrue($user->hasPermission('proposals.comment'));
        $this->assertFalse($user->hasPermission('rental_records.manage'));
        $this->assertFalse($user->hasPermission('liquidation.manage'));
        $this->assertFalse($user->hasPermission('monitoring.liquidation.manage'));
    }

    private function pstoUser(): User
    {
        $role = Role::query()->where('slug', 'psto')->firstOrFail();

        return User::query()->create([
            'username' => 'psto_cebu',
            'password' => 'SafeTestPassword123!',
            'first_name' => 'Cebu',
            'last_name' => 'Officer',
            'email' => 'psto.cebu@example.test',
            'role' => 'PSTO',
            'role_id' => $role->id,
            'province' => 'Cebu',
        ]);
    }

    private function proposal(string $province, string $title, User $owner): Proposal
    {
        return Proposal::query()->create([
            'project_title' => $title,
            'proponent' => $title.' PROPONENT',
            'province' => $province,
            'sector' => 'SETUP',
            'amount' => 100000,
            'status' => 'Ongoing',
            'document_status' => 'Incomplete',
            'quarter_submitted' => 1,
            'date_submitted' => '2026-01-15',
            'user_id' => $owner->id,
        ]);
    }
}
