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
        Schema::create('redeem_sessions', function (Blueprint $table) {
            $table->id();
            $table->string('unipin_username');
            $table->string('status')->default('running'); // running, done
            $table->string('type');                        // idmb / upgc
            $table->integer('total')->default(0);
            $table->integer('success')->default(0);
            $table->integer('failed')->default(0);
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('redeem_sessions');
    }
};
