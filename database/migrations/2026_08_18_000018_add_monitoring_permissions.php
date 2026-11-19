<?php

use App\Support\PermissionCatalog;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('permissions')) {
            return;
        }

        $now = now();

        // Re-sync catalog metadata so sort order remains consistent after adding
        // the Monitoring module between Analytics and the other system modules.
        foreach (PermissionCatalog::allPermissions() as $permission) {
            DB::table('permissions')->updateOrInsert(
                ['key' => $permission['key']],
                $permission + ['updated_at' => $now, 'created_at' => $now]
            );
        }

        if (! Schema::hasTable('roles') || ! Schema::hasTable('permission_role')) {
            return;
        }

        // Monitoring starts as an Admin-only workspace while its datasets are
        // still being designed. Admin can delegate any of the three submodules
        // later through Roles & Permissions.
        $adminRoleId = DB::table('roles')->where('slug', 'admin')->value('id');
        if (! $adminRoleId) {
            return;
        }

        $permissionIds = DB::table('permissions')
            ->whereIn('key', [
                'monitoring.rtec.view',
                'monitoring.rental.view',
                'monitoring.refund_rate.view',
            ])
            ->pluck('id');

        foreach ($permissionIds as $permissionId) {
            DB::table('permission_role')->updateOrInsert(
                ['role_id' => $adminRoleId, 'permission_id' => $permissionId],
                ['created_at' => $now, 'updated_at' => $now]
            );
        }
    }

    public function down(): void
    {
        if (! Schema::hasTable('permissions')) {
            return;
        }

        $ids = DB::table('permissions')
            ->whereIn('key', [
                'monitoring.rtec.view',
                'monitoring.rental.view',
                'monitoring.refund_rate.view',
            ])
            ->pluck('id');

        if ($ids->isNotEmpty() && Schema::hasTable('permission_role')) {
            DB::table('permission_role')->whereIn('permission_id', $ids)->delete();
        }

        DB::table('permissions')->whereIn('id', $ids)->delete();
    }
};
