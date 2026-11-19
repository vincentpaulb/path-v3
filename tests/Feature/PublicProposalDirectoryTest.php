<?php

namespace Tests\Feature;

use App\Models\Proposal;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class PublicProposalDirectoryTest extends TestCase
{
    use RefreshDatabase;

    public function test_retired_map_area_links_do_not_hide_projects(): void
    {
        $this->proposal(['proponent' => 'Inside']);
        $this->proposal(['proponent' => 'Outside', 'latitude' => 12]);
        $this->proposal(['proponent' => 'No coordinates', 'latitude' => null, 'longitude' => null]);
        $response = $this->get('/?bounds=10,123,11,124')->assertOk();
        $this->assertSame(3, $response->viewData('proposals')->total());
        $this->assertCount(2, $response->viewData('locations'));
        $response->assertDontSee('Search this map area');
    }

    public function test_only_approved_highlights_and_current_verification_are_public(): void
    {
        \Illuminate\Support\Facades\Storage::fake('local');
        $proposal = $this->proposal(['location_source' => 'manual']);
        $key = hash_hmac('sha256', (string) $proposal->id, config('app.key'));
        $profile = \App\Models\PublicProjectProfile::create([
            'proposal_id' => $proposal->id, 'summary' => 'Private draft highlight',
            'photo_path' => 'public-project-highlights/test.png', 'photo_alt' => 'Private photo description',
            'approved' => false,
            'verified_coordinates' => \App\Models\PublicProjectProfile::coordinates($proposal),
        ]);
        \Illuminate\Support\Facades\Storage::disk('local')->put($profile->photo_path, base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mP8/x8AAusB9Wl6nzsAAAAASUVORK5CYII='));
        $response = $this->get('/')->assertOk()->assertDontSee('Private draft highlight')->assertDontSee('Private photo description');
        $this->assertNull($response->viewData('locations')[0]['highlight']);
        $this->assertSame('Verified by staff', $response->viewData('locations')[0]['location_accuracy']);
        $this->get('/public/projects/'.$key.'/photo')->assertNotFound();
        $profile->update(['approved' => true]);
        $this->get('/')->assertOk()->assertSee('Private draft highlight');
        $this->get('/public/projects/'.$key.'/photo')->assertOk();
        $proposal->update(['latitude' => 11]);
        $this->assertSame('Manually placed — not verified', $this->get('/')->viewData('locations')[0]['location_accuracy']);
        $proposal->update(['date_funded' => null, 'status' => 'Ongoing Evaluation']);
        $this->get('/public/projects/'.$key.'/photo')->assertNotFound();
    }

    public function test_public_reports_are_private_and_admin_review_does_not_edit_proposal(): void
    {
        $proposal = $this->proposal();
        $key = hash_hmac('sha256', (string) $proposal->id, config('app.key'));
        $this->postJson('/public/projects/'.$key.'/report', ['category' => 'location', 'message' => 'PRIVATE REPORT: please check the map pin.'])->assertCreated();
        $report = \App\Models\PublicProjectReport::firstOrFail();
        $this->assertSame('pending', $report->status);
        $this->get('/')->assertDontSee('PRIVATE REPORT');
        $this->get('/public-directory/review')->assertRedirect('/login');
        $this->actingAs($proposal->owner)->get('/public-directory/review')->assertOk()->assertSee('PRIVATE REPORT');
        $this->patch(route('public-directory.report-review', $report), ['status' => 'resolved'])->assertRedirect();
        $this->assertSame('resolved', $report->fresh()->status);
        $this->assertSame($proposal->latitude, $proposal->fresh()->latitude);
        $psto = User::create(['username' => 'report-psto', 'first_name' => 'PSTO', 'last_name' => 'Test', 'email' => 'report-psto@example.test', 'password' => 'password', 'role' => 'PSTO', 'province' => 'Cebu']);
        $this->actingAs($psto)->get('/public-directory/review')->assertForbidden();
        $this->put(route('public-directory.save', $proposal), ['approved' => 1])->assertForbidden();
    }

    public function test_admin_can_publish_and_withdraw_a_highlight(): void
    {
        $proposal = $this->proposal();
        $this->actingAs($proposal->owner)->put(route('public-directory.save', $proposal), [
            'summary' => 'Approved public outcome', 'approved' => 1, 'verified' => 1,
        ])->assertRedirect();
        $this->get('/')->assertOk()->assertSee('Approved public outcome');
        $this->assertTrue($proposal->fresh()->publicProfile->approved);
        $this->put(route('public-directory.save', $proposal), ['summary' => 'Private revised outcome'])->assertRedirect();
        $this->get('/')->assertOk()->assertDontSee('Private revised outcome');
        $this->assertFalse($proposal->fresh()->publicProfile->approved);
        $this->assertNull($proposal->fresh()->publicProfile->verified_coordinates);
        $this->put(route('public-directory.save', $proposal), [
            'photo' => \Illuminate\Http\UploadedFile::fake()->create('not-an-image.svg', 1, 'image/svg+xml'),
        ])->assertSessionHasErrors('photo');
    }

    public function test_industry_filters_apply_to_map_counts_and_pagination(): void
    {
        $industry = \App\Models\IndustrySector::create([
            'code' => 'FILTER', 'name' => 'Filter test industry', 'is_active' => true, 'sort_order' => 999,
        ]);
        for ($i = 0; $i < 13; $i++) {
            $this->proposal(['industry_sector_id' => $industry->id]);
        }
        $this->proposal(['proponent' => 'Unclassified firm']);
        $this->proposal(['industry_sector_id' => $industry->id, 'status' => 'Draft']);
        $response = $this->get('/?industry=Filter%20test%20industry')->assertOk();
        $this->assertSame(13, $response->viewData('proposals')->total());
        $this->assertCount(13, $response->viewData('locations'));
        $this->assertSame(13, $response->viewData('regions')[0]['total']);
        $this->assertStringContainsString('industry=Filter', $response->viewData('proposals')->nextPageUrl());
        $this->assertSame(1, $this->get('/?industry=__unclassified__')->assertOk()->viewData('proposals')->total());
        $this->assertSame(0, $this->get('/?industry=missing')->assertOk()->viewData('proposals')->total());
    }

    public function test_public_industries_use_saved_classifications_and_funded_counts(): void
    {
        $industry = \App\Models\IndustrySector::create([
            'code' => 'TEST', 'name' => 'Public industry classification',
            'description' => 'INTERNAL INDUSTRY NOTES', 'is_active' => true, 'sort_order' => 999,
        ]);
        $this->proposal(['industry_sector_id' => $industry->id]);
        $this->proposal(['industry_sector_id' => $industry->id, 'status' => 'Draft']);
        $this->proposal();

        $response = $this->get('/')->assertOk()
            ->assertSee('Public industry classification')->assertSee('Not classified')
            ->assertDontSee('INTERNAL INDUSTRY NOTES');
        $this->assertEqualsCanonicalizing([
            ['name' => 'Public industry classification', 'total' => 1],
            ['name' => 'Not classified', 'total' => 1],
        ], $response->viewData('industries'));
        $this->assertContains('Public industry classification', array_column($response->viewData('locations'), 'industry_sector'));
        $this->assertArrayNotHasKey('industry_sector_id', $response->viewData('locations')[0]);
    }

    public function test_homepage_routes_preserve_staff_access_and_old_public_links(): void
    {
        $this->get('/')->assertOk();
        $this->get('/dashboard')->assertRedirect('/login');
        $this->get('/public/proposals')->assertStatus(301)->assertRedirect('/');
        $this->get('/public/proposals?province=Cebu')->assertStatus(301)->assertRedirect('/?province=Cebu');
        $owner = $this->proposal()->owner;
        $this->post('/login', ['username' => $owner->username, 'password' => 'test-password'])
            ->assertRedirect('/dashboard');
        $this->get('/login')->assertRedirect('/dashboard');
        $this->get('/')->assertOk()->assertSee('Public proponent');
    }

    public function test_guest_can_open_and_retry_staff_login_from_public_directory(): void
    {
        $this->get('/')->assertOk()
            ->assertSee('id="staffSignInButton"', false)
            ->assertSee('id="staffLoginDialog"', false)
            ->assertSee(route('login.attempt'), false)
            ->assertSee('public-login-modal.js', false);

        $response = $this->from('/')->post('/login', [
            'username' => 'modal-test-user',
            'password' => 'incorrect-password',
        ])->assertRedirect('/');

        $this->followRedirects($response)->assertOk()
            ->assertSee('The credentials do not match our records.')
            ->assertSee('data-open-on-load', false);
    }

    private function proposal(array $attributes = []): Proposal
    {
        $owner = User::firstOrCreate(['username' => 'directory-owner'], [
            'first_name' => 'Private', 'last_name' => 'Owner', 'email' => 'private-owner@example.test',
            'password' => 'test-password', 'role' => 'Admin',
        ]);
        return Proposal::create(array_merge([
            'project_title' => 'Public project', 'proponent' => 'Public proponent',
            'province' => 'Cebu', 'sector' => 'SETUP', 'status' => 'Funded',
            'document_status' => 'Complete', 'quarter_submitted' => 1,
            'date_funded' => ($attributes['status'] ?? 'Funded') === 'Funded' ? '2026-01-01' : null,
            'date_submitted' => '2026-01-01', 'user_id' => $owner->id,
            'amount' => 987654.32, 'address' => 'Private street address',
            'contact_person' => 'Private contact person', 'contact_email' => 'secret@example.test',
            'contact_phone' => '09991234567', 'latitude' => 10.1234567, 'longitude' => 123.7654321,
            'proposal_link' => 'https://example.test/confidential-document',
            'proposal_code' => 'INTERNAL-CODE-SECRET-'.(Proposal::withTrashed()->count() + 1),
        ], $attributes));
    }

    public function test_guest_can_browse_only_funded_non_archived_safe_fields(): void
    {
        $this->proposal();
        $this->proposal(['project_title' => 'Unfunded secret title', 'status' => 'Submitted', 'proponent' => 'Unfunded secret proponent', 'province' => 'Unfunded province']);
        $this->proposal(['project_title' => 'Archived secret title', 'province' => 'Archived province'])->delete();

        $response = $this->get(route('public.proposals'))->assertOk()
            ->assertDontSee('Public project')->assertSee('Public proponent')->assertSee('Private street address');
        foreach (['Unfunded secret', 'Unfunded province', 'Archived secret', 'Archived province',
            'Private contact person', 'secret@example.test', '09991234567',
            '987654.32', 'confidential-document',
            'INTERNAL-CODE-SECRET', 'private-owner@example.test'] as $secret) {
            $response->assertDontSee($secret);
        }
        $rows = $response->viewData('proposals');
        $this->assertSame(1, $rows->total());
        $this->assertSame(['proponent', 'address', 'sector', 'province'], array_keys($rows->first()->getAttributes()));
        $this->assertSame('Private street address', $response->viewData('locations')[0]['address']);
        $this->assertCount(1, $response->viewData('locations'));
        $this->assertArrayNotHasKey('project_title', $response->viewData('locations')[0]);
        $this->get('/?search=Public%20project')->assertOk()->assertSee('No funded projects found');
        $this->assertSame([10.1234567, 123.7654321], $response->viewData('locations')[0]['coordinates']);
        $this->assertStringContainsString('no-store', $response->headers->get('Cache-Control'));
        $this->get('/login')->assertRedirect('/?login=1');
        $this->get('/proposals')->assertRedirect('/login');
    }

    public function test_filters_and_map_cover_all_pages_without_exposing_private_search_fields(): void
    {
        for ($i = 0; $i < 13; $i++) {
            $this->proposal(['proponent' => sprintf('Workshop %02d', $i)]);
        }
        $this->proposal(['project_title' => 'Different project', 'province' => 'Bohol']);
        $response = $this->get('/?province=Cebu&sector=SETUP&search=Workshop')->assertOk();
        $this->assertSame(13, $response->viewData('proposals')->total());
        $this->assertCount(12, $response->viewData('proposals'));
        $this->assertSame(13, $response->viewData('regions')[0]['total']);
        $this->assertCount(13, $response->viewData('locations'));
        $response->assertDontSee('Different project')->assertSee('page=2', false);
        $next = $this->get('/?province=Cebu&sector=SETUP&search=Workshop&page=2')->assertOk();
        $this->assertCount(1, $next->viewData('proposals'));
        $this->get('/?search=Private%20street')->assertOk()->assertSee('No funded projects found');
        $this->get('/?search=INTERNAL-CODE-SECRET')->assertOk()->assertSee('No funded projects found');
    }

    public function test_missing_coordinates_remain_in_list_and_titles_are_escaped(): void
    {
        $this->proposal(['latitude' => null, 'longitude' => null, 'province' => 'New province', 'project_title' => '<script>alert("xss")</script>']);
        $response = $this->get('/')->assertOk()->assertSee('0 of 1 matching projects have saved locations')
            ->assertDontSee('<script>alert("xss")</script>', false);
        $this->assertSame([], $response->viewData('locations'));
        $this->getJson('/?province[]=Cebu')->assertUnprocessable();
        $this->getJson('/?page=0')->assertUnprocessable();
    }
}
