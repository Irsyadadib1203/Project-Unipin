<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('redeem_codes', function (Blueprint $table) {
            $table->id();
            $table->foreignId('session_id')->constrained('redeem_sessions')->cascadeOnDelete();
            $table->string('kode');
            $table->string('pin');
            $table->string('status')->default('pending'); // pending, success, failed
            $table->string('message')->nullable();
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('redeem_codes');
    }
};
