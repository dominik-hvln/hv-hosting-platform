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
        Schema::create('purchased_hostings', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->onDelete('cascade');
            $table->foreignId('hosting_plan_id')->constrained()->onDelete('restrict');
            $table->timestamp('start_date');
            $table->timestamp('end_date');
            $table->string('status'); // active, expired, suspended
            $table->decimal('price_paid', 10, 2);
            $table->string('payment_method');
            $table->string('payment_reference')->nullable();
            $table->integer('whmcs_service_id')->nullable();
            $table->timestamp('renewal_date')->nullable();
            $table->boolean('is_auto_renew')->default(true);
            $table->boolean('is_autoscaling_enabled')->default(true);
            $table->foreignId('promo_code_id')->nullable()->constrained()->onDelete('set null');
            $table->decimal('discount_amount', 10, 2)->default(0);
            $table->timestamps();
            $table->softDeletes();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('purchased_hostings');
    }
};