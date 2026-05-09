<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('transactions', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->string('reference')->unique();
            $table->enum('type', [
                'wallet_funding',
                'spray',
                'escrow_hold',
                'escrow_release',
                'withdrawal',
                'fee',
                'reversal',
            ]);
            $table->enum('status', ['pending', 'success', 'failed', 'reversed'])
                ->default('pending');
            $table->decimal('amount', 19, 4);
            $table->enum('currency', ['NGN', 'USD'])->default('NGN');
            $table->uuid('wallet_id');
            $table->string('provider')->nullable();
            $table->string('narration')->nullable();
            $table->json('metadata')->nullable();
            $table->string('failure_reason')->nullable();
            $table->string('idempotency_key')->unique()->nullable();
            $table->timestamps();

            $table->foreign('wallet_id')->references('id')->on('wallets')->cascadeOnDelete();
            $table->index(['wallet_id', 'status']);
            $table->index(['reference']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('transactions');
    }
};
