<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('compliance_requirements', function (Blueprint $table) {
            $table->id();
            $table->string('slug', 100)->unique();
            $table->string('title');
            $table->text('description');
            $table->unsignedSmallInteger('sort_order')->default(0);
            $table->string('status', 30)->default('pending')->index();
            $table->string('responsible_office')->nullable();
            $table->date('reviewed_at')->nullable();
            $table->date('approved_at')->nullable();
            $table->text('remarks')->nullable();
            $table->foreignId('updated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
        });

        Schema::create('compliance_evidence', function (Blueprint $table) {
            $table->id();
            $table->foreignId('compliance_requirement_id')->constrained()->cascadeOnDelete();
            $table->foreignId('uploaded_by')->nullable()->constrained('users')->nullOnDelete();
            $table->string('name');
            $table->string('path');
            $table->string('mime_type')->nullable();
            $table->unsignedBigInteger('size')->default(0);
            $table->timestamps();
        });

        Schema::create('compliance_requirement_history', function (Blueprint $table) {
            $table->id();
            $table->foreignId('compliance_requirement_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('action');
            $table->string('from_status', 30)->nullable();
            $table->string('to_status', 30);
            $table->json('metadata')->nullable();
            $table->timestamp('created_at')->useCurrent();
        });

        $now = now();
        DB::table('compliance_requirements')->insert([
            ['slug' => 'dpo-policy-approval', 'title' => 'DPO privacy-policy approval', 'description' => 'Confirm that the privacy notice accurately reflects DOST VII personal-data processing and has formal DPO approval.', 'sort_order' => 10, 'status' => 'pending', 'created_at' => $now, 'updated_at' => $now],
            ['slug' => 'legal-terms-approval', 'title' => 'Legal terms approval', 'description' => 'Review and formally approve PATH Terms and Conditions against agency mandates, policies, and applicable law.', 'sort_order' => 20, 'status' => 'pending', 'created_at' => $now, 'updated_at' => $now],
            ['slug' => 'privacy-impact-assessment', 'title' => 'Privacy Impact Assessment', 'description' => 'Document data inventory, purposes, flows, recipients, risks, safeguards, retention, and incident handling.', 'sort_order' => 30, 'status' => 'pending', 'created_at' => $now, 'updated_at' => $now],
            ['slug' => 'records-retention-mapping', 'title' => 'Records retention mapping', 'description' => 'Map PATH records to approved retention and disposal authorities and document applicable legal holds.', 'sort_order' => 40, 'status' => 'pending', 'created_at' => $now, 'updated_at' => $now],
            ['slug' => 'production-security-review', 'title' => 'Production security review', 'description' => 'Verify production configuration, access, encryption, logging, backups, recovery, patching, and remediation.', 'sort_order' => 50, 'status' => 'pending', 'created_at' => $now, 'updated_at' => $now],
            ['slug' => 'accessibility-audit', 'title' => 'Formal accessibility audit', 'description' => 'Perform and document keyboard, screen-reader, zoom, reflow, form, alternative-text, and contrast testing.', 'sort_order' => 60, 'status' => 'pending', 'created_at' => $now, 'updated_at' => $now],
            ['slug' => 'copyright-authorization', 'title' => 'Logo/license authorization', 'description' => 'Retain written agency ownership, permission, or appropriate license evidence for visual and third-party assets.', 'sort_order' => 70, 'status' => 'pending', 'created_at' => $now, 'updated_at' => $now],
        ]);
    }

    public function down(): void
    {
        Schema::dropIfExists('compliance_requirement_history');
        Schema::dropIfExists('compliance_evidence');
        Schema::dropIfExists('compliance_requirements');
    }
};
