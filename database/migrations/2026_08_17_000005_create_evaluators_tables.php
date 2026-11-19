<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('evaluators', function (Blueprint $table) {
            $table->id();
            $table->string('first_name', 50);
            $table->string('middle_initial', 2)->nullable();
            $table->string('last_name', 50);
            $table->string('sex', 20);
            $table->string('email', 100)->nullable()->unique();
            $table->string('contact_number', 30)->nullable();
            $table->string('designation', 150);
            $table->string('organization', 200);
            $table->timestamps();
        });

        Schema::create('evaluator_proposal', function (Blueprint $table) {
            $table->id();
            $table->foreignId('evaluator_id')->constrained()->cascadeOnDelete();
            $table->foreignId('proposal_id')->constrained()->cascadeOnDelete();
            $table->timestamps();
            $table->unique(['evaluator_id', 'proposal_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('evaluator_proposal');
        Schema::dropIfExists('evaluators');
    }
};
