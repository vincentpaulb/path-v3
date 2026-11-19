<?php

namespace Tests\Feature;

use App\Jobs\DeliverProposalActivity;
use App\Models\Permission;
use App\Models\Proposal;
use App\Models\Role;
use App\Models\User;
use App\Services\NotificationSignal;
use App\Services\NotificationStreamTicket;
use App\Services\ProposalNotificationService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;
use Tests\TestCase;

class NotificationDeliveryTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        config(['notifications.signal_path' => storage_path('framework/testing/notification-signals-'.Str::uuid())]);
        $role = Role::firstOrCreate(['name' => 'PSTO'], ['slug' => 'psto', 'data_scope' => 'province']);
        $permission = Permission::firstOrCreate(['key' => 'proposals.view'], [
            'module' => 'proposals', 'action' => 'view', 'label' => 'View proposals',
        ]);
        $role->permissions()->syncWithoutDetaching([$permission->id]);
    }

    protected function tearDown(): void
    {
        File::deleteDirectory(config('notifications.signal_path'));
        parent::tearDown();
    }

    public function test_activity_is_queued_and_retries_do_not_duplicate_notifications(): void
    {
        Queue::fake();
        $owner = $this->user('owner');
        $actor = $this->user('actor');
        $otherProvince = $this->user('other', 'Bohol');
        $proposal = $this->proposal($owner);
        $service = app(ProposalNotificationService::class);
        $service->send($proposal, $actor, 'Updated proposal', ['before' => 'secret', 'note' => 'Update']);
        $this->assertDatabaseCount('notifications', 0);
        Queue::assertPushed(DeliverProposalActivity::class, function ($job) use ($service, $owner, $actor, $otherProvince) {
            $this->assertSame('database', $job->connection);
            $this->assertSame('notifications', $job->queue);
            $this->assertTrue($job->afterCommit);
            $this->assertArrayNotHasKey('before', $job->payload['metadata']);
            $job->handle($service);
            $job->handle($service);
            $this->assertSame(1, $owner->notifications()->count());
            $this->assertSame(0, $actor->notifications()->count());
            $this->assertSame(0, $otherProvince->notifications()->count());
            $this->assertNotSame('0', app(NotificationSignal::class)->revision($owner->id));

            return true;
        });
    }

    public function test_skipped_actions_are_not_queued(): void
    {
        Queue::fake();
        $actor = $this->user('actor');
        app(ProposalNotificationService::class)->send($this->proposal($actor), $actor, 'Archived proposal');
        Queue::assertNothingPushed();
    }

    public function test_tickets_require_login_are_bound_to_user_and_reject_tampering_and_expiry(): void
    {
        $this->postJson(route('notifications.stream-ticket'))->assertUnauthorized();
        $user = $this->user('owner');
        $response = $this->actingAs($user)->postJson(route('notifications.stream-ticket'))->assertOk();
        parse_str(parse_url($response->json('url'), PHP_URL_QUERY), $query);
        $tickets = app(NotificationStreamTicket::class);
        $this->assertSame($user->id, $tickets->verify($query['ticket'])['user_id']);
        $this->assertNull($tickets->verify('999.'.$query['ticket']));
        $parts = explode('.', $query['ticket']);
        $parts[0] = (string) ($user->id + 1);
        $this->assertNull($tickets->verify(implode('.', $parts)));
        config(['notifications.ticket_lifetime' => -1]);
        $this->assertNull($tickets->verify($tickets->issue($user->id)));
    }

    public function test_status_is_private_and_marking_read_signals_other_tabs(): void
    {
        $owner = $this->user('owner');
        $other = $this->user('other');
        $id = (string) Str::uuid();
        $owner->notifications()->create([
            'id' => $id, 'type' => 'test', 'data' => ['action' => 'Private activity'],
        ]);
        $this->actingAs($other)->getJson(route('notifications.status'))
            ->assertOk()->assertJsonPath('unread_count', 0)->assertJsonCount(0, 'notifications');
        $this->actingAs($other)->post(route('notifications.open', $id))->assertNotFound();
        $this->actingAs($owner)->post(route('notifications.read-all'))->assertRedirect();
        $this->assertSame(0, $owner->unreadNotifications()->count());
        $this->assertNotSame('0', app(NotificationSignal::class)->revision($owner->id));
        $this->assertSame('0', app(NotificationSignal::class)->revision($other->id));
    }

    private function user(string $name, string $province = 'Cebu'): User
    {
        return User::create([
            'username' => $name, 'first_name' => $name, 'last_name' => 'Test',
            'email' => $name.'@example.test', 'password' => 'test-password',
            'role' => 'PSTO', 'province' => $province,
            'role_id' => Role::where('name', 'PSTO')->value('id'),
        ]);
    }

    private function proposal(User $owner): Proposal
    {
        return Proposal::create([
            'project_title' => 'Notification test', 'proponent' => 'Test proponent',
            'province' => 'Cebu', 'sector' => 'SETUP', 'amount' => 100,
            'status' => 'Submitted', 'document_status' => 'Complete',
            'quarter_submitted' => 1, 'date_submitted' => '2026-01-01', 'user_id' => $owner->id,
        ]);
    }
}
