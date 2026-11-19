<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class CompliancePagesTest extends TestCase
{
    use RefreshDatabase;

    public function test_public_legal_pages_are_available(): void
    {
        $this->get('/privacy-policy')->assertOk()->assertSee('Privacy Policy');
        $this->get('/terms-and-conditions')->assertOk()->assertSee('Terms and Conditions');
        $this->get('/cookie-policy')->assertOk()->assertSee('Cookie Policy');
        $this->get('/accessibility')->assertOk()->assertSee('Accessibility Statement');
    }

    public function test_security_headers_are_applied(): void
    {
        $this->get('/?login=1')
            ->assertOk()
            ->assertHeader('X-Frame-Options', 'DENY')
            ->assertHeader('X-Content-Type-Options', 'nosniff')
            ->assertHeader('Referrer-Policy', 'strict-origin-when-cross-origin')
            ->assertHeader('Content-Security-Policy');
    }

    public function test_login_links_to_all_legal_notices(): void
    {
        $this->get('/?login=1')
            ->assertOk()
            ->assertSee(route('legal.privacy'), false)
            ->assertSee(route('legal.terms'), false)
            ->assertSee(route('legal.cookies'), false)
            ->assertSee(route('legal.accessibility'), false);
    }

    public function test_compliance_center_requires_authentication(): void
    {
        $this->get('/compliance')->assertRedirect('/login');
        $this->get('/compliance/risk-register')->assertRedirect('/login');
    }
}
