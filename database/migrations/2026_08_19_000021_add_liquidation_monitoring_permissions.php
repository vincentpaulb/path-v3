<?php

use App\Support\PermissionCatalog;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    private array $keys = [
        'monitoring.liquidation.view',
        'monitoring.liquidation.manage',
    ];

    public function up(): void
    {
        if (! Schema::hasTable('permissions')) {
            return;
        }

        $now = now();

        foreach (PermissionCatalog::allPermissions() as $permission) {
            DB::table('permissions')->updateOrInsert(
                ['key' => $permission['key']],
                $permission + ['updated_at' => $now, 'created_at' => $now]
            );
        }

        if (! Schema::hasTable('roles') || ! Schema::hasTable('permission_role')) {
            return;
        }

        // Keep new financial monitoring controls Admin-only by default. They can
        // be delegated later from Roles & Permissions.
        $adminRoleId = DB::table('roles')->where('slug', 'admin')->value('id');
        if (! $adminRoleId) {
            return;
        }

        $permissionIds = DB::table('permissions')->whereIn('key', $this->keys)->pluck('id');

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

        $ids = DB::table('permissions')->whereIn('key', $this->keys)->pluck('id');

        if ($ids->isNotEmpty() && Schema::hasTable('permission_role')) {
            DB::table('permission_role')->whereIn('permission_id', $ids)->delete();
        }

        DB::table('permissions')->whereIn('id', $ids)->delete();
    }
};
