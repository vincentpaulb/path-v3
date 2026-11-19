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
        if (! Schema::hasTable('rental_records')) {
            Schema::create('rental_records', function (Blueprint $table) {
                $table->id();
                $table->foreignId('proposal_id')->constrained()->cascadeOnDelete();
                $table->unsignedTinyInteger('installment_no');
                $table->date('due_month')->index();
                $table->decimal('amount_due', 12, 2)->default(0);
                $table->decimal('rental_payment', 12, 2)->nullable();
                $table->string('or_number', 100)->nullable();
                $table->date('or_date')->nullable();
                $table->decimal('amount_in_or', 12, 2)->nullable();
                $table->text('remarks')->nullable();
                $table->foreignId('updated_by')->nullable()->constrained('users')->nullOnDelete();
                $table->timestamps();

                $table->unique(['proposal_id', 'installment_no']);
            });
        }

        // v3.10 installations have already run the original RBAC migration, so
        // register the new Rental Records permissions here as an additive step.
        if (Schema::hasTable('permissions')) {
            $now = now();
            $rentalPermissions = collect(PermissionCatalog::allPermissions())
                ->where('module', 'rental_records');

            foreach ($rentalPermissions as $permission) {
                DB::table('permissions')->updateOrInsert(
                    ['key' => $permission['key']],
                    $permission + ['created_at' => $now, 'updated_at' => $now]
                );
            }

            if (Schema::hasTable('roles') && Schema::hasTable('permission_role')) {
                $permissionIds = DB::table('permissions')
                    ->whereIn('key', $rentalPermissions->pluck('key'))
                    ->pluck('id', 'key');
                $roleIds = DB::table('roles')->pluck('id', 'name');

                $defaults = [
                    'Admin' => ['rental_records.view', 'rental_records.manage'],
                    'Regular' => ['rental_records.view'],
                    'TOD' => ['rental_records.view', 'rental_records.manage'],
                    'PSTO' => ['rental_records.view', 'rental_records.manage'],
                    'Head' => ['rental_records.view'],
                    'Cashier' => ['rental_records.view', 'rental_records.manage'],
                ];

                foreach ($defaults as $roleName => $keys) {
                    $roleId = $roleIds[$roleName] ?? null;
                    if (! $roleId) {
                        continue;
                    }

                    foreach ($keys as $key) {
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
            }
        }
    }

    public function down(): void
    {
        if (Schema::hasTable('permissions')) {
            DB::table('permissions')->whereIn('key', [
                'rental_records.view',
                'rental_records.manage',
            ])->delete();
        }

        Schema::dropIfExists('rental_records');
    }
};
