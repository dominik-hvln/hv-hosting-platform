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
        Schema::create('hosting_accounts', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->onDelete('cascade');
            $table->foreignId('purchased_hosting_id')->constrained()->onDelete('cascade');
            $table->string('username');
            $table->string('domain');
            $table->integer('server_id')->nullable();
            $table->string('status'); // active, suspended, pending, terminated
            $table->integer('current_ram'); // in MB
            $table->integer('current_cpu'); // in percentage points
            $table->integer('current_storage'); // in MB
            $table->integer('current_bandwidth'); // in MB
            $table->string('cloudlinux_id')->nullable();
            $table->string('directadmin_username')->nullable();
            $table->boolean('is_autoscaling_enabled')->default(true);
            $table->boolean('auto_backup_enabled')->default(true);
            $table->timestamp('last_login_at')->nullable();
            $table->boolean('is_suspended')->default(false);
            $table->string('suspension_reason')->nullable();
            $table->timestamps();
            $table->softDeletes();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('hosting_accounts');
    }
};