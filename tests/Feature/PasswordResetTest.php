<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Auth\Notifications\ResetPassword;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Tests\TestCase;

class PasswordResetTest extends TestCase
{
    use RefreshDatabase;

    public function test_reset_link_can_be_requested_from_public_login_modal(): void
    {
        Notification::fake();
        $user = User::create([
            'username' => 'reset-user', 'password' => 'old-password',
            'first_name' => 'Reset', 'last_name' => 'User',
            'email' => 'reset@example.test', 'role' => 'Admin',
        ]);
        $this->from('/')->post('/forgot-password', ['email' => $user->email])->assertRedirect('/');
        Notification::assertSentTo($user, ResetPassword::class);
    }

    public function test_unknown_email_receives_the_same_public_response(): void
    {
        $this->from('/')->post('/forgot-password', ['email' => 'unknown@example.test'])
            ->assertRedirect('/')->assertSessionHas('password_reset_status');
    }
}
