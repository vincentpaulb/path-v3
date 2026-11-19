<?php

namespace Tests\Feature;

use App\Jobs\SendWebPush;
use App\Models\User;
use App\Models\WebPushDelivery;
use App\Models\WebPushSubscription;
use App\Services\WebPushSubscriptions;
use App\Services\WebPushTransport;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;
use Mockery;
use RuntimeException;
use Tests\TestCase;

class WebPushTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        config(['webpush.enabled' => true, 'webpush.public_key' => 'test-public', 'webpush.private_key' => 'test-private']);
        Queue::fake();
    }

    public function test_subscribing_requires_authentication_and_encrypts_browser_secrets(): void
    {
        $this->postJson('/notifications/push', $this->payload())->assertUnauthorized();
        $user = $this->user('owner');
        $this->actingAs($user)->postJson('/notifications/push', $this->payload())->assertOk()->assertCookie('path_push_device');
        $subscription = WebPushSubscription::firstOrFail();
        $this->assertSame($user->id, $subscription->user_id);
        $this->assertSame($this->payload()['endpoint'], $subscription->endpoint);
        $this->assertNotSame($subscription->endpoint, DB::table('web_push_subscriptions')->value('endpoint'));
        $this->withCredentials()->withCookie('path_push_device', $subscription->id)->getJson('/notifications/push')
            ->assertJsonPath('endpoint', $this->payload()['endpoint'])->assertJsonMissingPath('private_key');
    }

    public function test_private_network_endpoints_bad_keys_and_foreign_ownership_are_rejected(): void
    {
        $owner = $this->user('owner');
        $this->actingAs($owner);
        foreach (['https://127.0.0.1/push', 'http://fcm.googleapis.com/push', 'https://fcm.googleapis.com.evil.test/push', 'https://fcm.googleapis.com:8443/push', 'https://user:pass@fcm.googleapis.com/push'] as $endpoint) {
            $this->postJson('/notifications/push', array_replace($this->payload(), ['endpoint' => $endpoint]))->assertUnprocessable();
        }
        $this->postJson('/notifications/push', ['endpoint' => $this->payload()['endpoint'], 'keys' => ['p256dh' => 'invalid', 'auth' => 'invalid']])->assertUnprocessable();
        $this->postJson('/notifications/push', $this->payload())->assertOk();
        $this->actingAs($this->user('other'))->postJson('/notifications/push', $this->payload())->assertConflict();
        $this->assertDatabaseCount('web_push_subscriptions', 1);
    }

    public function test_logout_revokes_only_the_current_browser_and_pending_delivery(): void
    {
        $user = $this->user('owner');
        $current = $this->subscription($user);
        $other = $this->subscription($user, 'other');
        $delivery = WebPushDelivery::create(['subscription_id' => $current->id]);
        $this->actingAs($user)->withCookie('path_push_device', $current->id)->post('/logout')->assertRedirect('/login');
        $this->assertDatabaseMissing('web_push_subscriptions', ['id' => $current->id]);
        $this->assertDatabaseMissing('web_push_deliveries', ['id' => $delivery->id]);
        $this->assertDatabaseHas('web_push_subscriptions', ['id' => $other->id]);
    }

    public function test_another_user_cannot_revoke_or_test_a_foreign_subscription(): void
    {
        $subscription = $this->subscription($this->user('owner'));
        $this->actingAs($this->user('other'))->withCredentials()->withCookie('path_push_device', $subscription->id)
            ->getJson('/notifications/push')->assertJsonPath('endpoint', null);
        $this->deleteJson('/notifications/push')->assertOk();
        $this->postJson('/notifications/push/test')->assertUnprocessable();
        $this->assertDatabaseHas('web_push_subscriptions', ['id' => $subscription->id]);
    }

    public function test_delivery_is_enqueued_once_and_uses_a_separate_queue(): void
    {
        $user = $this->user('owner');
        $this->subscription($user);
        $notification = $user->notifications()->create(['id' => (string) Str::uuid(), 'type' => 'test', 'data' => ['proposal_title' => 'Private proposal']]);
        $subscriptions = app(WebPushSubscriptions::class);
        $subscriptions->enqueue($notification);
        $subscriptions->enqueue($notification);
        Queue::assertPushed(SendWebPush::class, 1);
        Queue::assertPushed(SendWebPush::class, fn ($job) => $job->queue === 'webpush' && $job->connection === 'database');
        $this->assertDatabaseCount('web_push_deliveries', 1);
    }

    public function test_test_delivery_status_is_scoped_to_the_current_browser_and_user(): void
    {
        $owner = $this->user('owner');
        $subscription = $this->subscription($owner);
        $response = $this->actingAs($owner)->withCredentials()->withCookie('path_push_device', $subscription->id)
            ->postJson('/notifications/push/test')->assertAccepted();
        $url = $response->json('status_url');
        $this->getJson($url)->assertOk()->assertJsonPath('accepted', false);
        WebPushDelivery::firstOrFail()->update(['sent_at' => now()]);
        $this->getJson($url)->assertJsonPath('accepted', true);
        $this->actingAs($this->user('other'))->getJson($url)->assertNotFound();
    }

    public function test_success_is_recorded_and_duplicate_jobs_do_not_send_again(): void
    {
        $subscription = $this->subscription($this->user('owner'));
        $delivery = WebPushDelivery::create(['subscription_id' => $subscription->id]);
        $transport = Mockery::mock(WebPushTransport::class);
        $transport->shouldReceive('send')->once()->withArgs(fn ($device, $payload) => $device->id === $subscription->id && $payload['title'] === 'PATH notifications enabled')
            ->andReturn(['success' => true, 'expired' => false, 'status' => 201]);
        $job = new SendWebPush($delivery->id);
        $job->handle($transport);
        $job->handle($transport);
        $this->assertNotNull($delivery->fresh()->sent_at);
    }

    public function test_expired_subscriptions_are_removed_and_transient_errors_retry(): void
    {
        $subscription = $this->subscription($this->user('owner'));
        $delivery = WebPushDelivery::create(['subscription_id' => $subscription->id]);
        $transport = Mockery::mock(WebPushTransport::class);
        $transport->shouldReceive('send')->once()->andReturn(['success' => false, 'expired' => true, 'status' => 410]);
        (new SendWebPush($delivery->id))->handle($transport);
        $this->assertDatabaseMissing('web_push_subscriptions', ['id' => $subscription->id]);
        $subscription = $this->subscription($this->user('retry'), 'retry');
        $delivery = WebPushDelivery::create(['subscription_id' => $subscription->id]);
        $transport = Mockery::mock(WebPushTransport::class);
        $transport->shouldReceive('send')->once()->andReturn(['success' => false, 'expired' => false, 'status' => 503]);
        $this->expectException(RuntimeException::class);
        (new SendWebPush($delivery->id))->handle($transport);
    }

    public function test_read_notifications_and_deleted_users_are_not_pushed(): void
    {
        $user = $this->user('owner');
        $subscription = $this->subscription($user);
        $notification = $user->notifications()->create(['id' => (string) Str::uuid(), 'type' => 'test', 'data' => [], 'read_at' => now()]);
        $delivery = WebPushDelivery::create(['subscription_id' => $subscription->id, 'notification_id' => $notification->id]);
        $transport = Mockery::mock(WebPushTransport::class);
        $transport->shouldNotReceive('send');
        (new SendWebPush($delivery->id))->handle($transport);
        $notification->update(['read_at' => null]);
        $user->delete();
        (new SendWebPush($delivery->id))->handle($transport);
        $this->assertNull($delivery->fresh()->sent_at);
    }

    private function payload(string $suffix = 'test'): array
    {
        return ['endpoint' => 'https://fcm.googleapis.com/fcm/send/'.$suffix, 'keys' => [
            'p256dh' => rtrim(strtr(base64_encode("\x04".str_repeat('x', 64)), '+/', '-_'), '='),
            'auth' => rtrim(strtr(base64_encode(str_repeat('a', 16)), '+/', '-_'), '='),
        ]];
    }

    private function subscription(User $user, string $suffix = 'test'): WebPushSubscription
    {
        $data = $this->payload($suffix);

        return WebPushSubscription::create(['user_id' => $user->id, 'endpoint_hash' => hash('sha256', $data['endpoint']), 'endpoint' => $data['endpoint'], 'public_key' => $data['keys']['p256dh'], 'auth_token' => $data['keys']['auth']]);
    }

    private function user(string $name): User
    {
        return User::create(['username' => $name, 'first_name' => $name, 'last_name' => 'Test', 'email' => $name.'@example.test', 'password' => 'test-password', 'role' => 'Admin']);
    }
}
