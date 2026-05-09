<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('wallets', function (Blueprint $table) {
            $table->enum('type', ['personal', 'escrow', 'guest'])
                ->default('personal')
                ->after('user_id');
        });

        // Backfill existing wallets
        DB::table('wallets')->whereNull('type')->update(['type' => 'personal']);
    }

    public function down(): void
    {
        Schema::table('wallets', function (Blueprint $table) {
            $table->dropColumn('type');
        });
    }
};
