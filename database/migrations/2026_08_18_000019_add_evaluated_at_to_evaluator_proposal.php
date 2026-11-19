<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        if (! Schema::hasColumn('evaluator_proposal', 'evaluated_at')) {
            Schema::table('evaluator_proposal', function (Blueprint $table) {
                $table->timestamp('evaluated_at')->nullable()->after('evaluation_status')->index();
            });
        }

        // Reconstruct the most accurate historical first-completion timestamp we
        // can from the evaluator audit trail. This is more reliable than pivot
        // updated_at because ordinary proposal edits can touch pivot timestamps.
        $approvalLogs = DB::table('activity_logs')
            ->where('action', 'Evaluator status changed')
            ->orderBy('created_at')
            ->get(['proposal_id', 'metadata', 'created_at'])
            ->groupBy('proposal_id');

        DB::table('evaluator_proposal')
            ->whereNull('evaluated_at')
            ->orderBy('id')
            ->get(['id', 'evaluator_id', 'proposal_id', 'evaluation_status', 'updated_at'])
            ->each(function ($pivot) use ($approvalLogs) {
                $evaluatedAt = null;

                foreach ($approvalLogs->get($pivot->proposal_id, collect()) as $log) {
                    $metadata = is_array($log->metadata)
                        ? $log->metadata
                        : json_decode((string) $log->metadata, true);

                    if (! is_array($metadata)) {
                        continue;
                    }

                    if ((int) ($metadata['evaluator_id'] ?? 0) === (int) $pivot->evaluator_id
                        && strcasecmp((string) ($metadata['to'] ?? ''), 'Approved') === 0) {
                        $evaluatedAt = $log->created_at;
                        break;
                    }
                }

                // Older records may predate activity metadata. If they are
                // currently Approved, updated_at is the best available fallback.
                if (! $evaluatedAt && strcasecmp((string) $pivot->evaluation_status, 'Approved') === 0) {
                    $evaluatedAt = $pivot->updated_at;
                }

                if ($evaluatedAt) {
                    DB::table('evaluator_proposal')
                        ->where('id', $pivot->id)
                        ->update(['evaluated_at' => $evaluatedAt]);
                }
            });
    }

    public function down(): void
    {
        if (Schema::hasColumn('evaluator_proposal', 'evaluated_at')) {
            Schema::table('evaluator_proposal', function (Blueprint $table) {
                $table->dropColumn('evaluated_at');
            });
        }
    }
};
