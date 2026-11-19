<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('web_push_subscriptions', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->char('endpoint_hash', 64)->unique();
            $table->text('endpoint');
            $table->text('public_key');
            $table->text('auth_token');
            $table->timestamps();
        });
        Schema::create('web_push_deliveries', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('subscription_id')->constrained('web_push_subscriptions')->cascadeOnDelete();
            $table->foreignUuid('notification_id')->nullable()->constrained('notifications')->cascadeOnDelete();
            $table->timestamp('sent_at')->nullable();
            $table->timestamps();
            $table->unique(['subscription_id', 'notification_id'], 'web_push_delivery_unique');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('web_push_deliveries');
        Schema::dropIfExists('web_push_subscriptions');
    }
};
