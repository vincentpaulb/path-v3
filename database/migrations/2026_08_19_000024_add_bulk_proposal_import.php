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
        if (! Schema::hasTable('proposal_document_statuses')) {
            Schema::create('proposal_document_statuses', function (Blueprint $table) {
                $table->id();
                $table->foreignId('proposal_id')->constrained()->cascadeOnDelete();
                $table->foreignId('required_document_id')->constrained('required_documents')->cascadeOnDelete();
                $table->string('status', 30)->default('Pending')->index();
                $table->string('source', 30)->default('manual');
                $table->timestamps();
                $table->unique(['proposal_id', 'required_document_id'], 'proposal_document_status_unique');
            });
        }

        if (! Schema::hasTable('proposal_import_batches')) {
            Schema::create('proposal_import_batches', function (Blueprint $table) {
                $table->id();
                $table->string('batch_ref', 40)->unique();
                $table->string('original_filename', 500);
                $table->string('status', 30)->default('Completed')->index();
                $table->unsignedInteger('total_rows')->default(0);
                $table->unsignedInteger('valid_rows')->default(0);
                $table->unsignedInteger('imported_rows')->default(0);
                $table->unsignedInteger('skipped_rows')->default(0);
                $table->unsignedInteger('failed_rows')->default(0);
                $table->json('metadata')->nullable();
                $table->foreignId('created_by')->constrained('users')->restrictOnDelete();
                $table->timestamps();
            });
        }

        $this->backfillDocumentStatuses();
        $this->syncPermission();
    }

    private function backfillDocumentStatuses(): void
    {
        if (! Schema::hasTable('proposal_document_statuses') || ! Schema::hasTable('required_documents')) {
            return;
        }

        $documents = DB::table('required_documents')
            ->whereNull('deleted_at')
            ->orderBy('id')
            ->get(['id', 'name', 'updated_at']);

        if ($documents->isEmpty()) {
            return;
        }

        DB::table('proposals')->orderBy('id')->chunkById(100, function ($proposals) use ($documents) {
            $now = now();
            $rows = [];

            foreach ($proposals as $proposal) {
                $latestReview = DB::table('missing_documents')
                    ->where('proposal_id', $proposal->id)
                    ->orderByDesc('created_at')
                    ->first(['description', 'created_at']);

                $lacking = collect(preg_split('/\r\n|\r|\n/', (string) ($latestReview->description ?? '')))
                    ->map(fn ($name) => trim($name))
                    ->filter()
                    ->reject(fn ($name) => strcasecmp($name, 'No lacking documents') === 0)
                    ->map(fn ($name) => strtolower($name))
                    ->flip();

                foreach ($documents as $document) {
                    $changedAfterReview = $latestReview
                        && $document->updated_at
                        && strtotime((string) $document->updated_at) > strtotime((string) $latestReview->created_at);

                    if ((string) $proposal->document_status === 'Complete' && ! $changedAfterReview) {
                        $status = 'Submitted';
                    } elseif (! $latestReview || $changedAfterReview) {
                        $status = 'Pending';
                    } elseif ($lacking->has(strtolower($document->name))) {
                        $status = 'Lacking';
                    } else {
                        $status = 'Submitted';
                    }

                    $rows[] = [
                        'proposal_id' => $proposal->id,
                        'required_document_id' => $document->id,
                        'status' => $status,
                        'source' => 'legacy',
                        'created_at' => $now,
                        'updated_at' => $now,
                    ];
                }
            }

            foreach (array_chunk($rows, 500) as $chunk) {
                DB::table('proposal_document_statuses')->insertOrIgnore($chunk);
            }
        });
    }

    private function syncPermission(): void
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

        // Historical bulk imports can alter a large amount of proposal data,
        // so keep this Admin-only by default. It can still be delegated through
        // Roles & Permissions when the agency is ready.
        $adminRoleId = DB::table('roles')->where('slug', 'admin')->value('id');
        $permissionId = DB::table('permissions')->where('key', 'proposals.bulk_import')->value('id');

        if ($adminRoleId && $permissionId) {
            DB::table('permission_role')->updateOrInsert(
                ['role_id' => $adminRoleId, 'permission_id' => $permissionId],
                ['created_at' => $now, 'updated_at' => $now]
            );
        }
    }

    public function down(): void
    {
        if (Schema::hasTable('permissions')) {
            $permissionId = DB::table('permissions')->where('key', 'proposals.bulk_import')->value('id');
            if ($permissionId && Schema::hasTable('permission_role')) {
                DB::table('permission_role')->where('permission_id', $permissionId)->delete();
            }
            DB::table('permissions')->where('key', 'proposals.bulk_import')->delete();
        }

        Schema::dropIfExists('proposal_import_batches');
        Schema::dropIfExists('proposal_document_statuses');
    }
};
