<?php

namespace Tests\Feature;

use App\Models\IndustrySector;
use App\Models\Proposal;
use App\Models\Province;
use App\Models\Role;
use App\Models\User;
use App\Services\ProposalBulkImportService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ProposalBulkImportContactFieldsTest extends TestCase
{
    use RefreshDatabase;

    public function test_template_and_import_preserve_address_coordinates_and_contact_details(): void
    {
        $service = app(ProposalBulkImportService::class);
        $headers = $service->templateHeaders();

        foreach (['Project Code', 'Address', 'Latitude', 'Longitude', 'Contact Person', 'Contact Phone', 'Contact Email'] as $header) {
            $this->assertContains($header, $headers);
        }
        $this->assertContains('Evaluator 6', $headers);
        $this->assertNotContains('Evaluator 7', $headers);

        $province = Province::query()->firstOrCreate(
            ['name' => 'Cebu'],
            ['region' => 'Region VII', 'proposal_code' => 'CEB', 'is_active' => true, 'sort_order' => 1],
        );
        $industry = IndustrySector::query()->firstOrCreate(
            ['code' => 'TEST'],
            ['name' => 'Test Industry', 'is_active' => true, 'sort_order' => 1],
        );
        $role = Role::query()->where('slug', 'admin')->firstOrFail();
        $user = User::query()->create([
            'username' => 'bulk_import_admin',
            'password' => 'SafeTestPassword123!',
            'first_name' => 'Bulk',
            'last_name' => 'Administrator',
            'email' => 'bulk-import@example.test',
            'role' => 'Admin',
            'role_id' => $role->id,
        ]);

        $this->actingAs($user)
            ->get(route('proposals.bulk-import.template'))
            ->assertOk()
            ->assertDownload();

        $values = array_fill_keys($headers, '');
        $values = array_replace($values, [
            'Project Code' => '001-SETUP-2018',
            'Proposal Title' => 'Location-Aware Proposal',
            'Proponent / Firm' => 'Sample Proponent',
            'Address' => '65 General Maxilom Avenue, Cebu City, Cebu',
            'Latitude' => '10.3156992',
            'Longitude' => '123.8854366',
            'Contact Person' => 'Juan Dela Cruz',
            'Contact Phone' => '+63 917 123 4567',
            'Contact Email' => 'juan@example.test',
            'Sector' => 'SETUP',
            'Industry Sector' => $industry->code,
            'Province' => $province->name,
            'Proposal Amount' => '250000',
            'Proposal Status' => 'Ongoing Evaluation',
            'Date Submitted' => '2026-10-05',
        ]);

        $path = tempnam(sys_get_temp_dir(), 'path-proposal-import-');
        $handle = fopen($path, 'wb');
        fputcsv($handle, $headers);
        fputcsv($handle, array_map(fn ($header) => $values[$header], $headers));
        fclose($handle);

        try {
            $preview = $service->parseAndValidate($path, 'proposal.csv', $user);
            $this->assertSame(1, $preview['valid_rows']);
            $this->assertSame('juan@example.test', $preview['rows'][0]['contact_email']);
            $this->assertSame(10.3156992, $preview['rows'][0]['latitude']);

            $result = $service->importValidRows($preview, $user, 'TEST-BATCH');
            $this->assertSame(1, $result['imported']);
        } finally {
            @unlink($path);
        }

        $proposal = Proposal::query()->where('project_title', 'Location-Aware Proposal')->firstOrFail();
        $this->assertSame('65 General Maxilom Avenue, Cebu City, Cebu', $proposal->address);
        $this->assertSame('10.3156992', $proposal->latitude);
        $this->assertSame('123.8854366', $proposal->longitude);
        $this->assertSame('manual', $proposal->location_source);
        $this->assertSame('001-SETUP-2018', $proposal->project_code);
        $this->assertSame('Juan Dela Cruz', $proposal->contact_person);
        $this->assertSame('+63 917 123 4567', $proposal->contact_phone);
        $this->assertSame('juan@example.test', $proposal->contact_email);
    }

    public function test_coordinate_pair_and_contact_email_are_validated(): void
    {
        $service = app(ProposalBulkImportService::class);
        $headers = $service->templateHeaders();
        $province = Province::query()->first();
        $industry = IndustrySector::query()->first();
        $role = Role::query()->where('slug', 'admin')->firstOrFail();
        $user = User::query()->create([
            'username' => 'bulk_validation_admin',
            'password' => 'SafeTestPassword123!',
            'first_name' => 'Bulk',
            'last_name' => 'Validator',
            'email' => 'bulk-validation@example.test',
            'role' => 'Admin',
            'role_id' => $role->id,
        ]);

        $values = array_fill_keys($headers, '');
        $values = array_replace($values, [
            'Proposal Title' => 'Invalid Location Proposal',
            'Proponent / Firm' => 'Sample Proponent',
            'Latitude' => '10.3157',
            'Contact Email' => 'not-an-email',
            'Sector' => 'SETUP',
            'Industry Sector' => $industry->code,
            'Province' => $province->name,
            'Proposal Amount' => '1000',
            'Proposal Status' => 'Ongoing Evaluation',
            'Date Submitted' => '2026-10-05',
        ]);

        $path = tempnam(sys_get_temp_dir(), 'path-proposal-import-');
        $handle = fopen($path, 'wb');
        fputcsv($handle, $headers);
        fputcsv($handle, array_map(fn ($header) => $values[$header], $headers));
        fclose($handle);

        try {
            $preview = $service->parseAndValidate($path, 'proposal.csv', $user);
        } finally {
            @unlink($path);
        }

        $this->assertSame(0, $preview['valid_rows']);
        $this->assertContains('Latitude and Longitude must be provided together.', $preview['rows'][0]['errors']);
        $this->assertContains('Contact Email is not a valid email address.', $preview['rows'][0]['errors']);
    }
}
