<?php

use App\Support\PermissionCatalog;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    private array $keys = ['liquidation.view', 'liquidation.manage', 'liquidation.review'];

    public function up(): void
    {
        if (! Schema::hasTable('permissions')) return;
        $now = now();
        foreach (PermissionCatalog::allPermissions() as $permission) {
            if (! in_array($permission['key'], $this->keys, true)) continue;
            DB::table('permissions')->updateOrInsert(['key' => $permission['key']], $permission + ['created_at' => $now, 'updated_at' => $now]);
        }
        if (! Schema::hasTable('roles') || ! Schema::hasTable('permission_role')) return;

        $grants = [
            'Admin' => $this->keys,
            'TOD' => $this->keys,
            'PSTO' => ['liquidation.view', 'liquidation.manage'],
            'Head' => ['liquidation.view'],
            'Cashier' => ['liquidation.view'],
        ];
        $permissionIds = DB::table('permissions')->whereIn('key', $this->keys)->pluck('id', 'key');
        foreach ($grants as $roleName => $keys) {
            $roleId = DB::table('roles')->where('name', $roleName)->value('id');
            if (! $roleId) continue;
            foreach ($keys as $key) {
                if (! isset($permissionIds[$key])) continue;
                DB::table('permission_role')->updateOrInsert(
                    ['role_id' => $roleId, 'permission_id' => $permissionIds[$key]],
                    ['created_at' => $now, 'updated_at' => $now]
                );
            }
        }
    }

    public function down(): void
    {
        if (! Schema::hasTable('permissions')) return;
        $ids = DB::table('permissions')->whereIn('key', $this->keys)->pluck('id');
        if ($ids->isNotEmpty() && Schema::hasTable('permission_role')) DB::table('permission_role')->whereIn('permission_id', $ids)->delete();
        DB::table('permissions')->whereIn('id', $ids)->delete();
    }
};
