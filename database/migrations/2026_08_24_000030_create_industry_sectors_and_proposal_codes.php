<?php

use App\Support\PermissionCatalog;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    private array $permissionKeys = [
        'industry_sectors.view',
        'industry_sectors.create',
        'industry_sectors.edit',
        'industry_sectors.toggle',
        'industry_sectors.delete',
    ];

    public function up(): void
    {
        Schema::create('industry_sectors', function (Blueprint $table) {
            $table->id();
            $table->string('code', 10)->unique();
            $table->string('name', 255);
            $table->text('description')->nullable();
            $table->boolean('is_active')->default(true)->index();
            $table->unsignedInteger('sort_order')->default(0);
            $table->timestamps();
        });

        Schema::create('proposal_code_sequences', function (Blueprint $table) {
            $table->id();
            $table->unsignedSmallInteger('approval_year');
            $table->string('region_code', 10);
            $table->unsignedInteger('next_number')->default(1);
            $table->timestamps();
            $table->unique(['approval_year', 'region_code']);
        });

        Schema::table('provinces', function (Blueprint $table) {
            $table->string('proposal_code', 4)->nullable()->after('region');
        });

        Schema::table('proposals', function (Blueprint $table) {
            $table->foreignId('industry_sector_id')->nullable()->after('sector')->constrained('industry_sectors')->restrictOnDelete();
            $table->string('proposal_code', 100)->nullable()->unique()->after('id');
            $table->unsignedInteger('approved_sequence')->nullable()->after('proposal_code');
            $table->timestamp('code_generated_at')->nullable()->after('approved_sequence');
            $table->index(['industry_sector_id', 'date_approved']);
        });

        $now = now();
        $sectors = [
            ['04A', 'Crop and animal production, hunting, and related service activities'],
            ['04B', 'Forestry and logging'],
            ['05A', 'Fishing and aquaculture'],
            ['01A', 'Food processing'],
            ['01B', 'Beverage manufacturing'],
            ['03A', 'Textile manufacturing'],
            ['03B', 'Wearing apparel manufacturing'],
            ['03C', 'Leather and related products manufacturing'],
            ['02B', 'Wood and products of wood and cork manufacturing'],
            ['04C', 'Paper and paper products manufacturing'],
            ['09B', 'Chemicals and chemical products manufacturing'],
            ['09A', 'Basic pharmaceutical products and pharmaceutical preparations manufacturing'],
            ['10', 'Rubber and plastic products manufacturing'],
            ['06D', 'Non-metallic mineral products manufacturing'],
            ['06A', 'Fabricated metal products manufacturing'],
            ['06B', 'Machinery and equipment, NEC manufacturing'],
            ['06C', 'Other transport equipment manufacturing'],
            ['02A', 'Furniture manufacturing'],
            ['08', 'Information and Communication'],
            ['07', 'Other regional priority industries approved by the Regional Development Council'],
        ];

        foreach ($sectors as $index => [$code, $name]) {
            DB::table('industry_sectors')->insert([
                'code' => $code,
                'name' => $name,
                'is_active' => true,
                'sort_order' => ($index + 1) * 10,
                'created_at' => $now,
                'updated_at' => $now,
            ]);
        }

        foreach (['Bohol' => 'B', 'Cebu' => 'C', 'Negros' => 'N', 'Negros Oriental' => 'N', 'Siquijor' => 'S'] as $province => $code) {
            DB::table('provinces')->where('name', $province)->update(['proposal_code' => $code]);
        }

        if (Schema::hasTable('permissions')) {
            foreach (PermissionCatalog::allPermissions() as $permission) {
                DB::table('permissions')->updateOrInsert(
                    ['key' => $permission['key']],
                    $permission + ['created_at' => $now, 'updated_at' => $now]
                );
            }
        }

        if (Schema::hasTable('roles') && Schema::hasTable('permission_role')) {
            $adminRoleId = DB::table('roles')->where('slug', 'admin')->value('id');
            if ($adminRoleId) {
                foreach (DB::table('permissions')->whereIn('key', $this->permissionKeys)->pluck('id') as $permissionId) {
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
        Schema::table('proposals', function (Blueprint $table) {
            $table->dropForeign(['industry_sector_id']);
            $table->dropIndex(['industry_sector_id', 'date_approved']);
            $table->dropUnique(['proposal_code']);
            $table->dropColumn(['industry_sector_id', 'proposal_code', 'approved_sequence', 'code_generated_at']);
        });

        Schema::table('provinces', fn (Blueprint $table) => $table->dropColumn('proposal_code'));
        Schema::dropIfExists('proposal_code_sequences');
        Schema::dropIfExists('industry_sectors');

        if (Schema::hasTable('permissions')) {
            $ids = DB::table('permissions')->whereIn('key', $this->permissionKeys)->pluck('id');
            if ($ids->isNotEmpty() && Schema::hasTable('permission_role')) {
                DB::table('permission_role')->whereIn('permission_id', $ids)->delete();
            }
            DB::table('permissions')->whereIn('id', $ids)->delete();
        }
    }
};
