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
        if (! Schema::hasTable('rental_record_import_batches')) {
            Schema::create('rental_record_import_batches', function (Blueprint $table) {
                $table->id();
                $table->string('batch_ref', 50)->unique();
                $table->string('original_filename', 500);
                $table->string('status', 30)->default('Completed')->index();
                $table->unsignedInteger('total_files')->default(0);
                $table->unsignedInteger('matched_files')->default(0);
                $table->unsignedInteger('imported_files')->default(0);
                $table->unsignedInteger('failed_files')->default(0);
                $table->unsignedInteger('total_rows')->default(0);
                $table->unsignedInteger('imported_rows')->default(0);
                $table->unsignedInteger('skipped_rows')->default(0);
                $table->unsignedInteger('failed_rows')->default(0);
                $table->json('metadata')->nullable();
                $table->foreignId('created_by')->constrained('users')->restrictOnDelete();
                $table->timestamps();
            });
        }

        if (! Schema::hasTable('rental_record_import_files')) {
            Schema::create('rental_record_import_files', function (Blueprint $table) {
                $table->id();
                $table->foreignId('rental_record_import_batch_id')
                    ->constrained('rental_record_import_batches')
                    ->cascadeOnDelete();
                $table->foreignId('proposal_id')->nullable()->constrained()->nullOnDelete();
                $table->string('original_filename', 500);
                $table->string('project_title_snapshot', 500)->nullable();
                $table->string('proponent_snapshot', 500)->nullable();
                $table->string('status', 30)->default('Imported')->index();
                $table->unsignedInteger('total_rows')->default(0);
                $table->unsignedInteger('valid_rows')->default(0);
                $table->unsignedInteger('imported_rows')->default(0);
                $table->unsignedInteger('skipped_rows')->default(0);
                $table->unsignedInteger('failed_rows')->default(0);
                $table->json('metadata')->nullable();
                $table->timestamps();
            });
        }

        if (Schema::hasTable('permissions')) {
            $now = now();
            foreach (PermissionCatalog::allPermissions() as $permission) {
                DB::table('permissions')->updateOrInsert(
                    ['key' => $permission['key']],
                    $permission + ['created_at' => $now, 'updated_at' => $now]
                );
            }

            // Bulk rental import can update many financial records in a single
            // action, so keep it Admin-only by default. It remains delegable via
            // Roles & Permissions when the agency is ready.
            if (Schema::hasTable('roles') && Schema::hasTable('permission_role')) {
                $adminRoleId = DB::table('roles')->where('slug', 'admin')->value('id');
                $permissionId = DB::table('permissions')->where('key', 'rental_records.bulk_import')->value('id');
                if ($adminRoleId && $permissionId) {
                    DB::table('permission_role')->updateOrInsert(
                        ['role_id' => $adminRoleId, 'permission_id' => $permissionId],
                        ['created_at' => $now, 'updated_at' => $now]
                    );
                }
            }
        }
    }

    public function down(): void
    {
        if (Schema::hasTable('permissions')) {
            $permissionId = DB::table('permissions')->where('key', 'rental_records.bulk_import')->value('id');
            if ($permissionId && Schema::hasTable('permission_role')) {
                DB::table('permission_role')->where('permission_id', $permissionId)->delete();
            }
            DB::table('permissions')->where('key', 'rental_records.bulk_import')->delete();
        }

        Schema::dropIfExists('rental_record_import_files');
        Schema::dropIfExists('rental_record_import_batches');
    }
};
