<?php

use App\Support\PermissionCatalog;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    private string $key = 'proposals.manage_evaluator_status';

    public function up(): void
    {
        if (! Schema::hasTable('permissions')) {
            return;
        }

        $now = now();
        $permission = collect(PermissionCatalog::allPermissions())->firstWhere('key', $this->key);

        if ($permission) {
            DB::table('permissions')->updateOrInsert(
                ['key' => $this->key],
                $permission + ['created_at' => $now, 'updated_at' => $now]
            );
        }

        if (! Schema::hasTable('roles') || ! Schema::hasTable('permission_role')) {
            return;
        }

        $permissionId = DB::table('permissions')->where('key', $this->key)->value('id');
        if (! $permissionId) {
            return;
        }

        // Proposal administrators that already assign evaluators receive this
        // permission by default. Admin can delegate/revoke it independently
        // through Roles & Permissions after migration.
        $roleIds = DB::table('roles')
            ->whereIn('slug', ['admin', 'tod', 'psto'])
            ->pluck('id');

        foreach ($roleIds as $roleId) {
            DB::table('permission_role')->updateOrInsert(
                ['role_id' => $roleId, 'permission_id' => $permissionId],
                ['created_at' => $now, 'updated_at' => $now]
            );
        }
    }

    public function down(): void
    {
        if (! Schema::hasTable('permissions')) {
            return;
        }

        $permissionId = DB::table('permissions')->where('key', $this->key)->value('id');
        if ($permissionId && Schema::hasTable('permission_role')) {
            DB::table('permission_role')->where('permission_id', $permissionId)->delete();
        }

        DB::table('permissions')->where('key', $this->key)->delete();
    }
};
