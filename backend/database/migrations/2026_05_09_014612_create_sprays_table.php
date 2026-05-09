<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('sprays', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('event_id');
            $table->uuid('spray_session_id');
            $table->uuid('guest_wallet_id');
            $table->uuid('escrow_wallet_id');
            $table->uuid('transaction_id');
            $table->string('guest_name');
            $table->decimal('amount', 19, 4);
            $table->enum('currency', ['NGN', 'USD'])->default('NGN');
            $table->string('note_type')->default('100'); // denomination: 100, 200, 500, 1000
            $table->string('message')->nullable();
            $table->timestamps();

            $table->foreign('event_id')->references('id')->on('events')->cascadeOnDelete();
            $table->foreign('spray_session_id')->references('id')->on('spray_sessions')->cascadeOnDelete();
            $table->index(['event_id', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('sprays');
    }
};
