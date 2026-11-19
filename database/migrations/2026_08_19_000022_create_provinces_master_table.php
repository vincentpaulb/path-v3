<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('provinces')) {
            Schema::create('provinces', function (Blueprint $table) {
                $table->id();
                $table->string('name', 100)->unique();
                $table->string('region', 120)->nullable();
                $table->boolean('is_active')->default(true)->index();
                $table->unsignedInteger('sort_order')->default(0);
                $table->timestamps();
            });
        }

        // Normalize the old shorthand while preserving every historical record.
        if (Schema::hasTable('proposals') && Schema::hasColumn('proposals', 'province')) {
            DB::table('proposals')->where('province', 'Negros')->update(['province' => 'Negros Oriental']);
        }
        if (Schema::hasTable('users') && Schema::hasColumn('users', 'province')) {
            DB::table('users')->where('province', 'Negros')->update(['province' => 'Negros Oriental']);
        }

        $now = now();
        $defaults = [
            ['name' => 'Cebu', 'region' => 'Region VII', 'is_active' => true, 'sort_order' => 10],
            ['name' => 'Bohol', 'region' => 'Region VII', 'is_active' => true, 'sort_order' => 20],
            ['name' => 'Negros Oriental', 'region' => 'Negros Island Region (NIR)', 'is_active' => false, 'sort_order' => 30],
            ['name' => 'Siquijor', 'region' => 'Negros Island Region (NIR)', 'is_active' => false, 'sort_order' => 40],
        ];

        foreach ($defaults as $province) {
            DB::table('provinces')->updateOrInsert(
                ['name' => $province['name']],
                $province + ['created_at' => $now, 'updated_at' => $now]
            );
        }

        // Never strand legacy/custom values that may already exist in a deployed
        // database. Import them into the master list as inactive historical values.
        $existingValues = collect();
        if (Schema::hasTable('proposals')) {
            $existingValues = $existingValues->merge(DB::table('proposals')->whereNotNull('province')->distinct()->pluck('province'));
        }
        if (Schema::hasTable('users')) {
            $existingValues = $existingValues->merge(DB::table('users')->whereNotNull('province')->distinct()->pluck('province'));
        }

        foreach ($existingValues->map(fn ($value) => trim((string) $value))->filter()->unique() as $value) {
            if (! DB::table('provinces')->where('name', $value)->exists()) {
                DB::table('provinces')->insert([
                    'name' => $value,
                    'region' => 'Legacy / Other',
                    'is_active' => false,
                    'sort_order' => 1000 + (int) DB::table('provinces')->count(),
                    'created_at' => $now,
                    'updated_at' => $now,
                ]);
            }
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('provinces');
    }
};
