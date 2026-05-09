<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('spray_sessions', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('event_id');
            $table->string('guest_name');
            $table->string('guest_token')->unique(); // temp session token for guest
            $table->decimal('total_sprayed', 19, 4)->default(0);
            $table->enum('currency', ['NGN', 'USD'])->default('NGN');
            $table->enum('status', ['active', 'ended'])->default('active');
            $table->json('metadata')->nullable();
            $table->timestamps();

            $table->foreign('event_id')
                ->references('id')->on('events')
                ->cascadeOnDelete();

            $table->index(['event_id', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('spray_sessions');
    }
};
