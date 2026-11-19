<?php

namespace Tests\Feature;

use App\Models\Evaluator;
use App\Models\Permission;
use App\Models\Proposal;
use App\Models\Role;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
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

    public function test_psto_and_tod_can_reply_to_evaluator_remarks_within_their_proposal_scope(): void
    {
        Queue::fake();
        $psto = $this->pstoUser();
        $tod = User::create([
            'username' => 'tod-replies', 'email' => 'tod-replies@example.test', 'password' => 'SafeTestPassword123!',
            'first_name' => 'TOD', 'last_name' => 'Officer', 'role' => 'TOD',
        ]);
        $reviewer = User::create([
            'username' => 'remark-reviewer', 'email' => 'remark-reviewer@example.test', 'password' => 'SafeTestPassword123!',
            'first_name' => 'Proposal', 'last_name' => 'Reviewer', 'role' => 'Evaluator',
        ]);
        $evaluator = Evaluator::create([
            'first_name' => $reviewer->first_name, 'last_name' => $reviewer->last_name, 'email' => $reviewer->email,
            'sex' => 'Female', 'designation' => 'Evaluator', 'organization' => 'DOST',
        ]);
        $local = $this->proposal('Cebu', 'LOCAL REVIEW', $psto);
        $other = $this->proposal('Bohol', 'OTHER REVIEW', $psto);
        $local->evaluators()->attach($evaluator->id);
        $attributes = ['evaluator_id' => $evaluator->id, 'user_id' => $reviewer->id, 'action' => 'Return', 'remark' => 'Please update the checklist.'];
        $remark = $local->evaluatorRemarks()->create($attributes);
        $otherRemark = $other->evaluatorRemarks()->create($attributes);
        $route = 'proposals.evaluator-remarks.replies.store';
        $localReplyUrl = route($route, [$local, $remark]);
        $localPage = route('proposals.show', $local);
        $anchor = '#evaluator-remark-'.$remark->id;

        $this->actingAs($psto)->get($localPage)->assertOk()->assertSee($localReplyUrl, false);
        $this->post($localReplyUrl, ['content' => ''])->assertRedirect($localPage.$anchor)
            ->assertSessionHasErrorsIn('evaluatorRemarkReply'.$remark->id, 'content');
        $this->post($localReplyUrl, ['content' => 'PSTO updated the checklist.'])->assertRedirect($localPage.$anchor);
        $this->post(route($route, [$other, $otherRemark]), ['content' => 'Outside province'])->assertForbidden();
        $this->post(route($route, [$local, $otherRemark]), ['content' => 'Wrong proposal'])->assertNotFound();

        $this->actingAs($tod)->post($localReplyUrl, ['content' => 'TOD confirmed the update.'])->assertRedirect($localPage.$anchor);
        $this->post(route($route, [$other, $otherRemark]), ['content' => 'TOD requested the attachment.'])
            ->assertRedirect(route('proposals.show', $other).'#evaluator-remark-'.$otherRemark->id);
        $this->assertDatabaseHas('evaluator_remark_replies', ['evaluator_remark_id' => $remark->id, 'user_id' => $psto->id]);
        $this->assertDatabaseHas('evaluator_remark_replies', ['evaluator_remark_id' => $remark->id, 'user_id' => $tod->id]);
        $this->assertDatabaseCount('evaluator_remark_replies', 3);

        $this->actingAs($reviewer)->get(route('evaluator.proposals.show', $local))->assertOk()
            ->assertSeeInOrder(['Please update the checklist.', 'PSTO updated the checklist.', 'TOD confirmed the update.'])
            ->assertDontSee($localReplyUrl, false);
        $this->post($localReplyUrl, ['content' => 'Evaluator reply'])->assertForbidden();

        $reply = $remark->replies()->where('user_id', $psto->id)->firstOrFail();
        $todReply = $remark->replies()->where('user_id', $tod->id)->firstOrFail();
        $replyRoute = 'proposals.evaluator-remarks.replies.update';
        $editReplyUrl = route($replyRoute, [$local, $remark, $reply]);
        $editTodReplyUrl = route($replyRoute, [$local, $remark, $todReply]);
        $editRemarkUrl = route('evaluator.proposals.remarks.update', [$local, $remark]);
        $evaluatorPage = route('evaluator.proposals.show', $local);

        $this->get($evaluatorPage)->assertOk()->assertSee($editRemarkUrl, false)->assertDontSee('action="'.$editReplyUrl.'"', false);
        $this->patch($editReplyUrl, ['content' => 'Cannot edit a staff reply.'])->assertForbidden();
        $local->evaluators()->updateExistingPivot($evaluator->id, ['evaluation_status' => 'Approved']);
        $this->patch($editRemarkUrl, ['remark' => 'Please update the signed checklist.', 'action' => 'Forward'])
            ->assertRedirect($evaluatorPage.$anchor);
        $this->assertDatabaseHas('evaluator_remarks', [
            'id' => $remark->id, 'action' => 'Return', 'remark' => 'Please update the signed checklist.',
        ]);
        $this->assertDatabaseHas('evaluator_proposal', ['proposal_id' => $local->id, 'evaluation_status' => 'Approved']);

        $this->actingAs($psto)->get($localPage)->assertOk()->assertSee('action="'.$editReplyUrl.'"', false)
            ->assertDontSee('action="'.$editTodReplyUrl.'"', false)->assertDontSee($editRemarkUrl, false);
        $this->patch($editRemarkUrl, ['remark' => 'Cannot edit an evaluator remark.'])->assertForbidden();
        $this->patch($editTodReplyUrl, ['content' => 'Cannot edit the TOD reply.'])->assertForbidden();
        $this->patch($editReplyUrl, ['content' => 'PSTO attached the revised checklist.'])
            ->assertRedirect($localPage.'#evaluator-remark-reply-'.$reply->id);
        $this->assertSame('PSTO attached the revised checklist.', $reply->fresh()->content);

        $this->actingAs($tod)->patch($editTodReplyUrl, ['content' => 'TOD verified the signed checklist.'])
            ->assertRedirect($localPage.'#evaluator-remark-reply-'.$todReply->id);
        $this->assertSame('TOD verified the signed checklist.', $todReply->fresh()->content);
        $this->patch(route($replyRoute, [$other, $otherRemark, $todReply]), ['content' => 'Wrong remark'])
            ->assertNotFound();

        $nestedRoute = 'proposals.evaluator-remarks.replies.nested.store';
        $nestedUrl = route($nestedRoute, [$local, $remark, $reply]);
        $this->get($localPage)->assertOk()->assertSee($nestedUrl, false);
        $this->post($nestedUrl, ['content' => 'TOD requested the signed attachment.'])->assertRedirect();
        $nestedReply = $remark->replies()->where('parent_reply_id', $reply->id)->firstOrFail();
        $this->assertSame($tod->id, $nestedReply->user_id);
        $this->post(route($nestedRoute, [$other, $otherRemark, $reply]), ['content' => 'Wrong remark'])->assertNotFound();
        $this->actingAs($psto)->post(route($nestedRoute, [$local, $remark, $nestedReply]), [
            'content' => 'PSTO uploaded the signed attachment.',
        ])->assertRedirect();
        $this->get($localPage)->assertOk()->assertSeeInOrder([
            'PSTO attached the revised checklist.', 'TOD requested the signed attachment.',
            'PSTO uploaded the signed attachment.', 'TOD verified the signed checklist.',
        ]);
        $this->actingAs($reviewer)->post($nestedUrl, ['content' => 'Evaluator reply'])->assertForbidden();
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
