<?php

use App\Support\PermissionCatalog;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('roles') || ! Schema::hasTable('permissions') || ! Schema::hasTable('permission_role')) {
            return;
        }

        $roleId = DB::table('roles')->where('slug', 'psto')->value('id');
        if (! $roleId) {
            return;
        }

        DB::table('roles')->where('id', $roleId)->update([
            'data_scope' => 'province',
            'updated_at' => now(),
        ]);

        $restrictedIds = DB::table('permissions')
            ->whereIn('key', PermissionCatalog::pstoRestrictedPermissions())
            ->pluck('id');

        DB::table('permission_role')
            ->where('role_id', $roleId)
            ->whereIn('permission_id', $restrictedIds)
            ->delete();

        $requiredIds = DB::table('permissions')
            ->whereIn('key', PermissionCatalog::pstoRequiredPermissions())
            ->pluck('id');

        foreach ($requiredIds as $permissionId) {
            DB::table('permission_role')->updateOrInsert(
                ['role_id' => $roleId, 'permission_id' => $permissionId],
                ['created_at' => now(), 'updated_at' => now()],
            );
        }
    }

    public function down(): void
    {
        // This migration closes an access-control gap. Rolling it back must not
        // silently restore write permissions to provincial accounts.
    }
};
