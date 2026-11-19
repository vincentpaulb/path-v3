<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Database\ConnectionInterface;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use RuntimeException;
use Throwable;

class MergeLegacyPathDatabase extends Command
{
    protected $signature = 'legacy:merge
        {--source=path_legacy_import : Legacy database name on the configured MySQL server}
        {--fallback-user= : Existing path13 user ID used when a legacy owner or author cannot be resolved}
        {--dry-run : Perform the complete merge and roll it back after reporting}';

    protected $description = 'Merge the legacy PATH database into the current schema without duplicating records';

    private array $stats = [];
    private array $warnings = [];
    private array $userMap = [];
    private array $proposalMap = [];
    private array $proposalSignatures = [];
    private array $commentMap = [];
    private array $userAliases = [];
    private int $fallbackUserId;
    private string $sourceDatabase;

    public function handle(): int
    {
        $this->sourceDatabase = trim((string) $this->option('source'));
        $targetDatabase = (string) config('database.connections.mysql.database');

        if ($this->sourceDatabase === '' || strcasecmp($this->sourceDatabase, $targetDatabase) === 0) {
            $this->error('The source database must be different from the target database.');
            return self::FAILURE;
        }

        $this->configureLegacyConnection();

        foreach (['users', 'documents', 'lacking_documents', 'comments', 'replies', 'history'] as $table) {
            if (! Schema::connection('legacy')->hasTable($table)) {
                $this->error("The source database is missing the required {$table} table.");
                return self::FAILURE;
            }
        }

        $this->fallbackUserId = $this->resolveFallbackUser();
        $target = DB::connection('mysql');
        $dryRun = (bool) $this->option('dry-run');

        $this->info(($dryRun ? 'Dry-running' : 'Running').' legacy PATH merge from '.$this->sourceDatabase.' into '.$targetDatabase.'...');
        $target->beginTransaction();

        try {
            $this->mergeUsers($target);
            $this->buildUserAliases($target);
            $this->mergeProposals($target);
            $this->mergeMissingDocuments($target);
            $this->mergeComments($target);
            $this->mergeReplies($target);
            $this->mergeActivityLogs($target);

            if ($dryRun) {
                $target->rollBack();
            } else {
                $target->commit();
            }
        } catch (Throwable $exception) {
            if ($target->transactionLevel() > 0) {
                $target->rollBack();
            }
            $this->error('Merge failed and was rolled back: '.$exception->getMessage());
            return self::FAILURE;
        }

        $this->renderReport($dryRun);
        return self::SUCCESS;
    }

    private function configureLegacyConnection(): void
    {
        $connection = config('database.connections.mysql');
        $connection['database'] = $this->sourceDatabase;
        config(['database.connections.legacy' => $connection]);
        DB::purge('legacy');
    }

    private function resolveFallbackUser(): int
    {
        $requested = $this->option('fallback-user');
        if ($requested !== null && $requested !== '') {
            $userId = (int) $requested;
            if (! DB::connection('mysql')->table('users')->where('id', $userId)->exists()) {
                throw new RuntimeException("Fallback user {$userId} does not exist in the target database.");
            }
            return $userId;
        }

        $userId = DB::connection('mysql')->table('users')
            ->where('role', 'Admin')
            ->orderBy('id')
            ->value('id');

        if (! $userId) {
            throw new RuntimeException('The target database needs an Admin user or an explicit --fallback-user ID.');
        }

        return (int) $userId;
    }

    private function mergeUsers(ConnectionInterface $target): void
    {
        $roles = $target->table('roles')->pluck('id', 'name');
        $now = now();

        foreach (DB::connection('legacy')->table('users')->orderBy('id')->cursor() as $legacy) {
            $usernameMatch = $target->table('users')->whereRaw('LOWER(username) = ?', [$this->key($legacy->username)])->first();
            $emailMatch = $target->table('users')->whereRaw('LOWER(email) = ?', [$this->key($legacy->email)])->first();

            if ($usernameMatch && $emailMatch && (int) $usernameMatch->id !== (int) $emailMatch->id) {
                $this->warnings[] = "Legacy user {$legacy->id} has a username/email conflict; username match {$usernameMatch->id} was used.";
            }

            $existing = $usernameMatch ?: $emailMatch;
            if ($existing) {
                $this->userMap[(int) $legacy->id] = (int) $existing->id;
                $this->bump('users', 'reused');
                continue;
            }

            $role = $this->normalizeRole($legacy->account_type);
            $email = trim((string) $legacy->email);
            if ($email === '') {
                $email = 'legacy-user-'.$legacy->id.'@invalid.local';
            }

            $id = $target->table('users')->insertGetId([
                'username' => trim((string) $legacy->username),
                'password' => (string) $legacy->password,
                'first_name' => trim((string) $legacy->first_name),
                'middle_initial' => $this->nullIfBlank($legacy->middle_initial),
                'last_name' => trim((string) $legacy->last_name),
                'company' => $this->nullIfBlank($legacy->company),
                'mobile_number' => $this->nullIfBlank($legacy->mobile_number),
                'email' => $email,
                'job_desc' => $this->nullIfBlank($legacy->job_desc),
                'role' => $role,
                'role_id' => $roles[$role] ?? null,
                'province' => $this->normalizeProvince($legacy->province),
                'avatar_path' => null,
                'created_at' => $now,
                'updated_at' => $now,
            ]);

            $this->userMap[(int) $legacy->id] = (int) $id;
            $this->bump('users', 'created');
        }
    }

    private function buildUserAliases(ConnectionInterface $target): void
    {
        $candidates = [];
        foreach ($target->table('users')->select('id', 'username', 'first_name', 'middle_initial', 'last_name')->get() as $user) {
            $fullName = trim(implode(' ', array_filter([
                $user->first_name,
                $this->nullIfBlank($user->middle_initial),
                $user->last_name,
            ])));

            foreach ([$user->username, $user->first_name, trim($user->first_name.' '.$user->last_name), $fullName] as $alias) {
                $key = $this->key($alias);
                if ($key !== '') {
                    $candidates[$key][(int) $user->id] = true;
                }
            }
        }

        foreach ($candidates as $key => $ids) {
            if (count($ids) === 1) {
                $this->userAliases[$key] = (int) array_key_first($ids);
            }
        }

        $this->userAliases['administrator'] = $this->fallbackUserId;
    }

    private function mergeProposals(ConnectionInterface $target): void
    {
        foreach ($target->table('proposals')->select('id', 'project_title', 'proponent')->orderBy('id')->get() as $proposal) {
            $signature = $this->proposalSignature($proposal->project_title, $proposal->proponent);
            $this->proposalSignatures[$signature] ??= (int) $proposal->id;
        }

        foreach (DB::connection('legacy')->table('documents')->orderBy('id')->cursor() as $legacy) {
            $signature = $this->proposalSignature($legacy->project_title, $legacy->proponent);
            $existingId = $this->proposalSignatures[$signature] ?? null;

            if ($existingId) {
                $this->proposalMap[(int) $legacy->id] = $existingId;
                $this->bump('proposals', 'reused');
                continue;
            }

            $ownerId = $this->userMap[(int) $legacy->user_id] ?? $this->fallbackUserId;
            if (! isset($this->userMap[(int) $legacy->user_id])) {
                $this->bump('proposals', 'fallback_owner');
            }

            $submittedDate = $this->date($legacy->date_submitted);
            if (! $submittedDate) {
                $this->bump('proposals', 'skipped');
                $this->warnings[] = "Legacy proposal {$legacy->id} was skipped because date_submitted is invalid.";
                continue;
            }

            $createdAt = $this->timestamp($legacy->time)
                ?? $this->date($legacy->date_updated)
                ?? $submittedDate;

            $id = $target->table('proposals')->insertGetId([
                'project_title' => trim((string) $legacy->project_title),
                'proponent' => trim((string) $legacy->proponent),
                'province' => $this->normalizeProvince($legacy->province) ?? '',
                'sector' => trim((string) $legacy->sector),
                'amount' => $legacy->amount,
                'proposal_link' => $this->nullIfBlank($legacy->proposal_link),
                'status' => $this->normalizeProposalStatus($legacy->status),
                'document_status' => $this->normalizeDocumentStatus($legacy->doc_status),
                'funding_status' => $this->normalizeFundingStatus($legacy->funded_status),
                'moa_status' => 'Pending',
                'check_status' => 'Pending',
                'quarter_submitted' => $this->quarter($legacy->quarter, $submittedDate),
                'date_submitted' => $submittedDate,
                'date_updated' => $this->date($legacy->date_updated),
                'quarter_approved' => $this->quarter($legacy->quarter_approved),
                'quarter_funded' => $this->quarter($legacy->quarter_funded),
                'date_approved' => $this->date($legacy->date_approved),
                'date_funded' => $this->date($legacy->date_funded),
                'rental_otp_enabled' => false,
                'user_id' => $ownerId,
                'created_at' => $createdAt,
                'updated_at' => $this->date($legacy->date_updated) ?? $createdAt,
            ]);

            $this->proposalMap[(int) $legacy->id] = (int) $id;
            $this->proposalSignatures[$signature] = (int) $id;
            $this->bump('proposals', 'created');
        }
    }

    private function mergeMissingDocuments(ConnectionInterface $target): void
    {
        foreach (DB::connection('legacy')->table('lacking_documents')->orderBy('id')->cursor() as $legacy) {
            $proposalId = $this->proposalMap[(int) $legacy->doc_id] ?? null;
            if (! $proposalId) {
                $this->bump('missing_documents', 'skipped_orphan');
                continue;
            }

            $createdAt = $this->timestamp($legacy->date_updated) ?? now();
            $attributes = [
                'proposal_id' => $proposalId,
                'description' => (string) $legacy->lacking_documents,
                'evaluation_status' => (string) $legacy->eval_status,
                'moa_check' => $this->nullIfBlank($legacy->moa),
                'created_by' => $this->fallbackUserId,
                'created_at' => $createdAt,
                'updated_at' => $createdAt,
            ];

            $exists = $target->table('missing_documents')
                ->where('proposal_id', $proposalId)
                ->where('description', $attributes['description'])
                ->where('created_at', $createdAt)
                ->exists();

            if ($exists) {
                $this->bump('missing_documents', 'reused');
            } else {
                $target->table('missing_documents')->insert($attributes);
                $this->bump('missing_documents', 'created');
            }
        }
    }

    private function mergeComments(ConnectionInterface $target): void
    {
        foreach (DB::connection('legacy')->table('comments')->orderBy('id')->cursor() as $legacy) {
            $proposalId = $this->proposalMap[(int) $legacy->document_id] ?? null;
            if (! $proposalId) {
                $this->bump('comments', 'skipped_orphan');
                continue;
            }

            $userId = $this->resolveAuthor($legacy->author, true);
            $createdAt = $this->timestamp($legacy->timestamp) ?? now();
            $existing = $target->table('comments')
                ->where('proposal_id', $proposalId)
                ->where('user_id', $userId)
                ->where('content', (string) $legacy->content)
                ->where('created_at', $createdAt)
                ->first();

            if ($existing) {
                $id = (int) $existing->id;
                $this->bump('comments', 'reused');
            } else {
                $id = (int) $target->table('comments')->insertGetId([
                    'proposal_id' => $proposalId,
                    'user_id' => $userId,
                    'content' => (string) $legacy->content,
                    'created_at' => $createdAt,
                    'updated_at' => $createdAt,
                ]);
                $this->bump('comments', 'created');
            }

            $this->commentMap[(int) $legacy->id] = $id;
        }
    }

    private function mergeReplies(ConnectionInterface $target): void
    {
        foreach (DB::connection('legacy')->table('replies')->orderBy('id')->cursor() as $legacy) {
            $commentId = $this->commentMap[(int) $legacy->parent_id] ?? null;
            if (! $commentId) {
                $this->bump('replies', 'skipped_orphan');
                continue;
            }

            $userId = $this->resolveAuthor($legacy->author, true);
            $createdAt = $this->timestamp($legacy->timestamp) ?? now();
            $attributes = [
                'comment_id' => $commentId,
                'user_id' => $userId,
                'content' => (string) $legacy->content,
                'created_at' => $createdAt,
                'updated_at' => $createdAt,
            ];

            $exists = $target->table('replies')
                ->where('comment_id', $commentId)
                ->where('user_id', $userId)
                ->where('content', $attributes['content'])
                ->where('created_at', $createdAt)
                ->exists();

            if ($exists) {
                $this->bump('replies', 'reused');
            } else {
                $target->table('replies')->insert($attributes);
                $this->bump('replies', 'created');
            }
        }
    }

    private function mergeActivityLogs(ConnectionInterface $target): void
    {
        foreach (DB::connection('legacy')->table('history')->orderBy('id')->cursor() as $legacy) {
            $proposalId = $this->proposalMap[(int) $legacy->proposal_id] ?? null;
            if (! $proposalId) {
                $this->bump('activity_logs', 'skipped_orphan');
                continue;
            }

            $metadata = [
                'legacy_source' => $this->sourceDatabase,
                'legacy_table' => 'history',
                'legacy_id' => (int) $legacy->id,
                'legacy_user' => $this->nullIfBlank($legacy->user),
            ];

            $exists = $target->table('activity_logs')
                ->where('metadata->legacy_source', $this->sourceDatabase)
                ->where('metadata->legacy_table', 'history')
                ->where('metadata->legacy_id', (int) $legacy->id)
                ->exists();

            if ($exists) {
                $this->bump('activity_logs', 'reused');
                continue;
            }

            $target->table('activity_logs')->insert([
                'proposal_id' => $proposalId,
                'user_id' => $this->resolveAuthor($legacy->user, false),
                'action' => trim((string) $legacy->action),
                'sector' => trim((string) $legacy->sector) ?: 'SETUP',
                'metadata' => json_encode($metadata, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR),
                'created_at' => $this->timestamp($legacy->timestamp) ?? now(),
            ]);
            $this->bump('activity_logs', 'created');
        }
    }

    private function resolveAuthor(mixed $author, bool $required): ?int
    {
        $key = $this->key($author);
        if ($key !== '' && isset($this->userAliases[$key])) {
            return $this->userAliases[$key];
        }

        $this->bump('authors', 'unresolved');
        return $required ? $this->fallbackUserId : null;
    }

    private function normalizeRole(mixed $value): string
    {
        return match ($this->key($value)) {
            'admin', 'administrator' => 'Admin',
            'tod' => 'TOD',
            'psto' => 'PSTO',
            'cashier' => 'Cashier',
            'head' => 'Head',
            'evaluator' => 'Evaluator',
            default => 'Regular',
        };
    }

    private function normalizeProposalStatus(mixed $value): string
    {
        return match ($this->key($value)) {
            'approved' => 'Approved',
            'disapproved' => 'Disapproved',
            'funded' => 'Funded',
            default => 'Ongoing Evaluation',
        };
    }

    private function normalizeDocumentStatus(mixed $value): string
    {
        return $this->key($value) === 'complete' ? 'Complete' : 'Incomplete';
    }

    private function normalizeProvince(mixed $value): ?string
    {
        $province = $this->nullIfBlank($value);
        return in_array($this->key($province), ['negros', 'negros oriental'], true) ? 'Negros' : $province;
    }

    private function normalizeFundingStatus(mixed $value): ?string
    {
        return match ($this->key($value)) {
            'cheque released to client', 'cheque received', 'cheque released' => 'Cheque Released',
            'ongoing processing', 'cheque ready for releasing' => 'Ongoing Processing',
            default => null,
        };
    }

    private function quarter(mixed $value, ?string $fallbackDate = null): ?int
    {
        $quarter = match ($this->key($value)) {
            '1', 'quarter one', 'q1' => 1,
            '2', 'quarter two', 'q2' => 2,
            '3', 'quarter three', 'q3' => 3,
            '4', 'quarter four', 'q4' => 4,
            default => null,
        };

        if ($quarter === null && $fallbackDate) {
            $quarter = (int) ceil(((int) substr($fallbackDate, 5, 2)) / 3);
        }

        return $quarter;
    }

    private function date(mixed $value): ?string
    {
        $value = trim((string) $value);
        if ($value === '' || str_starts_with($value, '0000-00-00')) {
            return null;
        }
        return substr($value, 0, 10);
    }

    private function timestamp(mixed $value): ?string
    {
        $value = trim((string) $value);
        if ($value === '' || str_starts_with($value, '0000-00-00')) {
            return null;
        }
        return $value;
    }

    private function nullIfBlank(mixed $value): ?string
    {
        $value = trim((string) $value);
        return $value === '' ? null : $value;
    }

    private function key(mixed $value): string
    {
        return mb_strtolower(trim(preg_replace('/\s+/u', ' ', (string) $value) ?? ''));
    }

    private function proposalSignature(mixed $title, mixed $proponent): string
    {
        return $this->key($title).'|'.$this->key($proponent);
    }

    private function bump(string $table, string $result): void
    {
        $this->stats[$table][$result] = ($this->stats[$table][$result] ?? 0) + 1;
    }

    private function renderReport(bool $dryRun): void
    {
        $rows = [];
        foreach ($this->stats as $table => $results) {
            foreach ($results as $result => $count) {
                $rows[] = [$table, $result, $count];
            }
        }
        $this->table(['Area', 'Result', 'Count'], $rows);

        foreach ($this->warnings as $warning) {
            $this->warn($warning);
        }

        $this->newLine();
        $this->info($dryRun ? 'Dry run complete; all target changes were rolled back.' : 'Legacy merge committed successfully.');
    }
}
