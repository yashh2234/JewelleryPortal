<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Platform tables. These are the only tables without a tenant_id.
 * Runs before the users migration because users belong to a tenant.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('plans', function (Blueprint $table) {
            $table->id();
            $table->string('code')->unique();
            $table->string('name');
            $table->unsignedInteger('max_users')->nullable();      // null = unlimited
            $table->unsignedInteger('max_retailers')->nullable();
            $table->unsignedInteger('max_designs')->nullable();
            $table->decimal('price', 12, 2)->default(0);           // per billing cycle, INR
            $table->string('billing_cycle')->default('monthly');   // monthly | yearly
            $table->boolean('is_active')->default(true);
            $table->timestamps();
        });

        Schema::create('tenants', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->string('subdomain')->unique();
            $table->string('gstin', 15)->nullable();
            $table->string('state_code', 2)->nullable();
            $table->string('phone')->nullable();
            $table->string('email')->nullable();
            $table->text('address')->nullable();
            $table->string('logo_path')->nullable();
            $table->string('primary_color', 7)->default('#7c5c1e');
            $table->foreignId('plan_id')->nullable()->constrained('plans')->nullOnDelete();
            $table->string('status')->default('trial');            // trial | active | suspended | cancelled
            $table->timestamp('trial_ends_at')->nullable();
            $table->json('settings')->nullable();
            $table->timestamps();
        });

        Schema::create('subscriptions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained('tenants')->cascadeOnDelete();
            $table->foreignId('plan_id')->constrained('plans');
            $table->string('status')->default('trialing');         // trialing | active | past_due | cancelled
            $table->date('current_period_start')->nullable();
            $table->date('current_period_end')->nullable();
            $table->timestamp('cancelled_at')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('subscriptions');
        Schema::dropIfExists('tenants');
        Schema::dropIfExists('plans');
    }
};
