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
        Schema::create('backups', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignId('hosting_account_id')->constrained()->onDelete('cascade');
            $table->string('name');
            $table->string('path');
            $table->bigInteger('size')->nullable(); // in bytes
            $table->string('type')->default('full'); // full, incremental, etc.
            $table->string('status'); // pending, completed, failed
            $table->text('notes')->nullable();
            $table->string('external_path')->nullable();
            $table->boolean('is_external_available')->default(false);
            $table->string('created_by')->default('system'); // system, user, auto
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('backups');
    }
};