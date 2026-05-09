<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('events', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('host_user_id');
            $table->uuid('escrow_wallet_id')->nullable();
            $table->string('title');
            $table->string('slug')->unique();
            $table->text('description')->nullable();
            $table->enum('status', ['draft', 'active', 'ended', 'cancelled'])->default('draft');
            $table->string('qr_code_url')->nullable();
            $table->string('join_url')->nullable();
            $table->timestamp('starts_at')->nullable();
            $table->timestamp('ends_at')->nullable();
            $table->json('metadata')->nullable();
            $table->timestamps();

            $table->foreign('host_user_id')
                ->references('id')->on('users')
                ->cascadeOnDelete();

            $table->foreign('escrow_wallet_id')
                ->references('id')->on('wallets')
                ->nullOnDelete();

            $table->index(['host_user_id', 'status']);
            $table->index('slug');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('events');
    }
};
