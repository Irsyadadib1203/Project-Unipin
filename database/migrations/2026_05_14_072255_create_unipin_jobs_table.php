<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
 
return new class extends Migration {
    public function up(): void
    {
        Schema::create('unipin_jobs', function (Blueprint $table) {
            $table->id();
            $table->string('session_key')->index();   // identitas user (bisa email hash)
            $table->string('kode')->index();           // kode voucher
            $table->string('status')->default('pending'); // pending|proses|sukses|gagal
            $table->string('type')->nullable();        // idmb|upgc
            $table->text('log')->nullable();           // pesan hasil
            $table->string('amount')->nullable();      // nominal top up
            $table->string('no_transaksi')->nullable();
            $table->timestamps();
        });
    }
 
    public function down(): void
    {
        Schema::dropIfExists('unipin_jobs');
    }
};