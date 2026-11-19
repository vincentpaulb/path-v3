<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('required_documents')) {
            Schema::create('required_documents', function (Blueprint $table) {
                $table->id();
                $table->string('name', 500)->unique();
                $table->timestamps();
            });
        }

        if (DB::table('required_documents')->count() === 0) {
            $now = now();

            DB::table('required_documents')->insert(array_map(
                fn (string $name) => [
                    'name' => $name,
                    'created_at' => $now,
                    'updated_at' => $now,
                ],
                [
                    'RTEC Report',
                    'TNA Report Form 2',
                    'TNA Form 1 and Attachment A',
                    'Letter of Intent',
                    'Partial Budget Analysis',
                    'Project Proposal',
                    'Financial Statements with notarized Sworn Statement',
                    'Certificate of registration from BIR',
                    'Certificate of registration from DTI, SEC or CDA',
                    'Copy of official receipt of the firm',
                    "Mayor's Business Permit",
                    '3 Quotations',
                    'Sworn Affidavit of no bad debt and consanguinity or affinity',
                    'Board Resolution (For cooperatives and corporations only; sole proprietorship: N/A)',
                    'Articles of Incorporation (For cooperatives and corporations only; sole proprietorship: N/A)',
                    'GAD Scoresheet',
                    'Pre-PIS',
                ]
            ));
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('required_documents');
    }
};
