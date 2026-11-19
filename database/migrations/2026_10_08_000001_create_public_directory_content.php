<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('public_project_profiles', function (Blueprint $table) {
            $table->id();
            $table->foreignId('proposal_id')->unique()->constrained()->cascadeOnDelete();
            $table->text('summary')->nullable();
            $table->string('photo_path')->nullable();
            $table->string('photo_alt')->nullable();
            $table->boolean('approved')->default(false);
            $table->string('verified_coordinates')->nullable();
            $table->timestamps();
        });
        Schema::create('public_project_reports', function (Blueprint $table) {
            $table->id();
            $table->foreignId('proposal_id')->constrained()->cascadeOnDelete();
            $table->string('category');
            $table->text('message');
            $table->string('status')->default('pending')->index();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('public_project_reports');
        Schema::dropIfExists('public_project_profiles');
    }
};
