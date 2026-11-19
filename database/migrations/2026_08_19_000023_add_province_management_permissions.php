<?php

use App\Support\PermissionCatalog;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    private array $keys = [
        'provinces.view',
        'provinces.create',
        'provinces.edit',
        'provinces.toggle',
        'provinces.delete',
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
                $permission + ['created_at' => $now, 'updated_at' => $now]
            );
        }

        if (! Schema::hasTable('roles') || ! Schema::hasTable('permission_role')) {
            return;
        }

        // Province master-data management is Admin-only by default, but may be
        // delegated later through the existing Roles & Permissions module.
        $adminRoleId = DB::table('roles')->where('slug', 'admin')->value('id');
        if (! $adminRoleId) {
            return;
        }

        foreach (DB::table('permissions')->whereIn('key', $this->keys)->pluck('id') as $permissionId) {
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
