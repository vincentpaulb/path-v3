<?php

namespace Tests\Feature;

use App\Models\ComplianceRequirement;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class ComplianceWorkflowTest extends TestCase
{
    use RefreshDatabase;

    public function test_signed_in_users_can_view_requirements_but_only_admins_can_manage_them(): void
    {
        $user = $this->user('PSTO', 'viewer');
        $requirement = ComplianceRequirement::query()->firstOrFail();

        $this->actingAs($user)->get('/compliance')
            ->assertOk()
            ->assertSee($requirement->title)
            ->assertDontSee('data-compliance-edit', false);

        $this->actingAs($user)->get('/compliance/risk-register')->assertForbidden();
        $this->actingAs($user)->put(route('compliance.requirements.update', $requirement), [
            'status' => 'under_review',
        ])->assertForbidden();
    }

    public function test_approval_requires_the_approver_dates_and_evidence(): void
    {
        $admin = $this->user('Admin', 'admin');
        $requirement = ComplianceRequirement::query()->firstOrFail();

        $this->actingAs($admin)->from('/compliance')->put(route('compliance.requirements.update', $requirement), [
            'requirement_id' => $requirement->id,
            'status' => 'approved',
        ])->assertRedirect('/compliance')->assertSessionHasErrors([
            'responsible_office', 'reviewed_at', 'approved_at', 'evidence',
        ]);
    }

    public function test_admin_can_approve_with_evidence_and_the_change_is_audited(): void
    {
        Storage::fake('local');
        $admin = $this->user('Admin', 'approver');
        $requirement = ComplianceRequirement::query()->firstOrFail();

        $this->actingAs($admin)->put(route('compliance.requirements.update', $requirement), [
            'requirement_id' => $requirement->id,
            'status' => 'approved',
            'responsible_office' => 'Data Protection Office',
            'reviewed_at' => '2026-09-07',
            'approved_at' => '2026-09-08',
            'remarks' => 'Approved after formal review.',
            'evidence' => UploadedFile::fake()->create('signed-approval.pdf', 120, 'application/pdf'),
        ])->assertSessionHasNoErrors();

        $requirement->refresh();
        $evidence = $requirement->evidence()->firstOrFail();

        $this->assertSame('approved', $requirement->status);
        $this->assertSame($admin->id, $requirement->updated_by);
        $this->assertSame(1, $requirement->history()->count());
        $this->assertSame('signed-approval.pdf', $evidence->name);
        Storage::disk('local')->assertExists($evidence->path);

        $this->actingAs($admin)->get(route('compliance.index'))
            ->assertOk()
            ->assertSee('Save compliance update')
            ->assertSee('data-compliance-edit', false);
        $this->actingAs($admin)->get(route('compliance.evidence.download', $evidence))->assertOk();
        $this->actingAs($admin)->get(route('compliance.risks'))
            ->assertOk()
            ->assertSee('signed-approval.pdf')
            ->assertSee('Updated requirement and uploaded evidence');
    }

    private function user(string $role, string $suffix): User
    {
        return User::query()->create([
            'username' => 'compliance_'.$suffix,
            'password' => 'SafeTestPassword123!',
            'first_name' => 'Compliance',
            'last_name' => ucfirst($suffix),
            'email' => $suffix.'@example.test',
            'role' => $role,
        ]);
    }
}
