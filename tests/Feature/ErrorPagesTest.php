<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ErrorPagesTest extends TestCase
{
    use RefreshDatabase;

    public function test_unknown_page_uses_the_branded_404_page(): void
    {
        $this->get('/this-page-does-not-exist')
            ->assertNotFound()
            ->assertSee('We could not find that page.')
            ->assertSee('Open public directory')
            ->assertSee('Staff sign in');
    }

    public function test_common_error_templates_are_available(): void
    {
        foreach ([403, 404, 419, 429, 500, 503] as $status) {
            $this->assertTrue(view()->exists("errors.{$status}"));
        }
    }
}
