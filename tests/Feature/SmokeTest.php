<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class SmokeTest extends TestCase
{
    use RefreshDatabase;

    public function test_login_route_opens_public_modal(): void
    {
        $this->get('/login')->assertRedirect('/?login=1');
        $this->get('/?login=1')->assertOk()->assertSee('data-open-on-load', false);
    }
}
