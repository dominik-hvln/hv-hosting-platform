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
        Schema::create('scaling_logs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('hosting_account_id')->constrained()->onDelete('cascade');
            $table->foreignId('purchased_hosting_id')->constrained()->onDelete('cascade');
            $table->integer('previous_ram'); // in MB
            $table->integer('previous_cpu'); // in percentage points
            $table->integer('new_ram'); // in MB
            $table->integer('new_cpu'); // in percentage points
            $table->integer('scaled_ram'); // change in MB
            $table->integer('scaled_cpu'); // change in percentage points
            $table->string('reason'); // autoscaling, manual, etc.
            $table->decimal('cost', 10, 2)->nullable();
            $table->string('payment_reference')->nullable();
            $table->string('payment_status')->nullable(); // paid, pending, failed
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('scaling_logs');
    }
};