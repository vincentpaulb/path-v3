<?php

use App\Support\PermissionCatalog;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('roles')) {
            Schema::create('roles', function (Blueprint $table) {
                $table->id();
                $table->string('name', 100)->unique();
                $table->string('slug', 100)->unique();
                $table->string('description', 500)->nullable();
                $table->string('data_scope', 30)->default('global');
                $table->boolean('is_system')->default(false);
                $table->boolean('is_protected')->default(false);
                $table->timestamps();
            });
        }

        if (! Schema::hasTable('permissions')) {
            Schema::create('permissions', function (Blueprint $table) {
                $table->id();
                $table->string('key', 150)->unique();
                $table->string('module', 100)->index();
                $table->string('action', 100);
                $table->string('label', 250);
                $table->unsignedInteger('sort_order')->default(0);
                $table->timestamps();
            });
        }

        if (! Schema::hasTable('permission_role')) {
            Schema::create('permission_role', function (Blueprint $table) {
                $table->foreignId('role_id')->constrained('roles')->cascadeOnDelete();
                $table->foreignId('permission_id')->constrained('permissions')->cascadeOnDelete();
                $table->timestamps();
                $table->primary(['role_id', 'permission_id']);
            });
        }

        if (Schema::hasTable('users') && ! Schema::hasColumn('users', 'role_id')) {
            Schema::table('users', function (Blueprint $table) {
                $table->unsignedBigInteger('role_id')->nullable()->after('role')->index();
                $table->foreign('role_id')->references('id')->on('roles')->nullOnDelete();
            });
        }

        $now = now();

        foreach (PermissionCatalog::roleDefinitions() as $role) {
            DB::table('roles')->updateOrInsert(
                ['slug' => $role['slug']],
                $role + ['updated_at' => $now, 'created_at' => $now]
            );
        }

        foreach (PermissionCatalog::allPermissions() as $permission) {
            DB::table('permissions')->updateOrInsert(
                ['key' => $permission['key']],
                $permission + ['updated_at' => $now, 'created_at' => $now]
            );
        }

        $roleIds = DB::table('roles')->pluck('id', 'name');
        $permissionIds = DB::table('permissions')->pluck('id', 'key');

        foreach (PermissionCatalog::defaultRolePermissions() as $roleName => $permissionKeys) {
            $roleId = $roleIds[$roleName] ?? null;
            if (! $roleId) {
                continue;
            }

            foreach ($permissionKeys as $key) {
                $permissionId = $permissionIds[$key] ?? null;
                if (! $permissionId) {
                    continue;
                }

                DB::table('permission_role')->updateOrInsert(
                    ['role_id' => $roleId, 'permission_id' => $permissionId],
                    ['created_at' => $now, 'updated_at' => $now]
                );
            }
        }

        if (Schema::hasTable('users') && Schema::hasColumn('users', 'role_id')) {
            foreach ($roleIds as $name => $id) {
                DB::table('users')
                    ->where('role', $name)
                    ->whereNull('role_id')
                    ->update(['role_id' => $id]);
            }
        }
    }

    public function down(): void
    {
        if (Schema::hasTable('users') && Schema::hasColumn('users', 'role_id')) {
            Schema::table('users', function (Blueprint $table) {
                try {
                    $table->dropForeign(['role_id']);
                } catch (\Throwable) {
                    // Keep rollback tolerant of installations where the FK was not created.
                }
                $table->dropColumn('role_id');
            });
        }

        Schema::dropIfExists('permission_role');
        Schema::dropIfExists('permissions');
        Schema::dropIfExists('roles');
    }
};
