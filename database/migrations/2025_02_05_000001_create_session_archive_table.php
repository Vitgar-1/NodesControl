<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('session_archive', function (Blueprint $table) {
            $table->string('session_id', 255)->primary();
            $table->integer('clicks')->default(0);
            $table->timestamp('archived_at')->nullable();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('session_archive');
    }
};
