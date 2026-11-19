<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class GeocodingSearchTest extends TestCase
{
    use RefreshDatabase;

    public function test_street_results_are_labeled_and_invalid_coordinates_are_rejected(): void
    {
        $user = User::create([
            'username' => 'map-admin', 'first_name' => 'Map', 'last_name' => 'Admin',
            'email' => 'map@example.test', 'password' => 'password', 'role' => 'Admin',
        ]);
        Http::fake(['*' => Http::response([
            ['display_name' => 'General Maxilom Avenue, Cebu City', 'lat' => '10.31', 'lon' => '123.89', 'class' => 'highway'],
            ['display_name' => 'Invalid', 'lat' => 'invalid', 'lon' => '123.89'],
            ['display_name' => 'Outside range', 'lat' => '100', 'lon' => '123.89'],
        ], 200)]);
        $this->actingAs($user)->getJson('/geocoding/search?'.http_build_query(['q' => '65 General Maxilom Ave, Cebu City']))
            ->assertOk()->assertJsonCount(1, 'results')
            ->assertJsonPath('results.0.match_note', 'Street match — move the pin to the actual building.')
            ->assertJsonPath('suggestions.0', 'General Maxilom Avenue, Cebu City');
    }

    public function test_empty_search_offers_simpler_queries_without_extra_provider_requests(): void
    {
        $user = User::create([
            'username' => 'map-admin', 'first_name' => 'Map', 'last_name' => 'Admin',
            'email' => 'map@example.test', 'password' => 'password', 'role' => 'Admin',
        ]);
        Http::fake(['*' => Http::response([], 200)]);
        $url = '/geocoding/search?'.http_build_query([
            'q' => 'Ongtiak Business Center, 65 General Maxilom Ave, Cebu City, 6000 Cebu',
        ]);
        $this->actingAs($user)->getJson($url)->assertOk()
            ->assertJsonPath('results', [])
            ->assertJsonPath('suggestions.0', 'Ongtiak Business Center, Cebu City, Cebu')
            ->assertJsonPath('suggestions.1', 'General Maxilom Avenue, Cebu City, Cebu');
        $this->getJson($url)->assertOk()->assertJsonPath('cached', true);
        Http::assertSentCount(1);
        $this->travel(6)->minutes();
        $this->getJson($url)->assertOk()->assertJsonPath('cached', false);
        Http::assertSentCount(2);
    }
}
