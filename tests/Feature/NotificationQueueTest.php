<?php

namespace Tests\Feature;

use App\Jobs\DeliverProposalActivity;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

class NotificationQueueTest extends TestCase
{
    public function test_real_database_queue_waits_for_commit_and_discards_rolled_back_events(): void
    {
        // Only the queue schema is needed; avoid unrelated application migration rollbacks.
        $migration = require database_path('migrations/2026_08_17_000004_create_cache_jobs_tables.php');
        $migration->up();
        DB::beginTransaction();
        DeliverProposalActivity::dispatch((string) Str::uuid(), ['proposal_id' => 1]);
        $this->assertDatabaseCount('jobs', 0);
        DB::rollBack();
        $this->assertDatabaseCount('jobs', 0);

        DB::beginTransaction();
        DeliverProposalActivity::dispatch((string) Str::uuid(), ['proposal_id' => 2]);
        $this->assertDatabaseCount('jobs', 0);
        DB::commit();
        $this->assertDatabaseCount('jobs', 1);
        $this->assertDatabaseHas('jobs', ['queue' => 'notifications', 'attempts' => 0]);
    }
}
