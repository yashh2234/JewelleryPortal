<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Masters for V1 Foundation.
 *
 * Every table gets tenant_id + created_by via the Blueprint::tenantColumns() macro
 * (see AppServiceProvider). Weights are grams DECIMAL(12,3); purity is DECIMAL(6,3) %.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('karats', function (Blueprint $table) {
            $table->id();
            $table->tenantColumns();
            $table->string('label', 10);                    // 22K
            $table->decimal('purity_pct', 6, 3);            // 91.600
            $table->unsignedSmallInteger('sort')->default(0);
            $table->boolean('is_active')->default(true);
            $table->timestamps();
            $table->unique(['tenant_id', 'label']);
        });

        Schema::create('processes', function (Blueprint $table) {
            $table->id();
            $table->tenantColumns();
            $table->string('name');
            $table->unsignedSmallInteger('sequence')->default(0);
            $table->decimal('default_wastage_pct', 6, 3)->default(0);
            $table->boolean('is_active')->default(true);
            $table->timestamps();
            $table->unique(['tenant_id', 'name']);
        });

        Schema::create('process_wastage_rules', function (Blueprint $table) {
            $table->id();
            $table->tenantColumns();
            $table->foreignId('process_id')->constrained()->cascadeOnDelete();
            $table->foreignId('karat_id')->constrained()->cascadeOnDelete();
            $table->decimal('allowed_wastage_pct', 6, 3);
            $table->timestamps();
            $table->unique(['process_id', 'karat_id']);
        });

        Schema::create('kaarigars', function (Blueprint $table) {
            $table->id();
            $table->tenantColumns();
            $table->string('name');
            $table->string('phone', 20)->nullable();
            $table->string('type', 20)->default('in_house');    // in_house | outside (karkhana)
            $table->text('address')->nullable();
            $table->boolean('is_active')->default(true);
            $table->timestamps();
            $table->index(['tenant_id', 'is_active']);
        });

        // Login identities for the kaarigar app (phone + OTP). An outside karkhana
        // has one master login that can add its own workers; gold stays on the karkhana.
        Schema::create('kaarigar_users', function (Blueprint $table) {
            $table->id();
            $table->tenantColumns();
            $table->foreignId('kaarigar_id')->constrained()->cascadeOnDelete();
            $table->string('name');
            $table->string('phone', 20);
            $table->boolean('is_master')->default(true);
            $table->string('language', 5)->default('hi');
            $table->string('otp_hash')->nullable();
            $table->timestamp('otp_expires_at')->nullable();
            $table->unsignedTinyInteger('otp_attempts')->default(0);
            $table->boolean('is_active')->default(true);
            $table->timestamp('last_seen_at')->nullable();
            $table->rememberToken();
            $table->timestamps();
            $table->unique(['tenant_id', 'phone']);
        });

        Schema::create('categories', function (Blueprint $table) {
            $table->id();
            $table->tenantColumns();
            $table->string('name');
            $table->foreignId('parent_id')->nullable()->constrained('categories')->nullOnDelete();
            $table->unsignedSmallInteger('sort')->default(0);
            $table->timestamps();
            $table->index(['tenant_id', 'parent_id']);
        });

        Schema::create('collections', function (Blueprint $table) {
            $table->id();
            $table->tenantColumns();
            $table->string('name');                         // New arrivals, Temple, Bridal
            $table->string('slug');
            $table->unsignedSmallInteger('sort')->default(0);
            $table->timestamps();
            $table->unique(['tenant_id', 'slug']);
        });

        Schema::create('making_slabs', function (Blueprint $table) {
            $table->id();
            $table->tenantColumns();
            $table->string('name');
            $table->string('basis', 20);                    // per_gram | per_piece | percent
            $table->decimal('rate', 12, 3);                 // ₹/g, ₹/pc, or % of metal value
            $table->boolean('is_default')->default(false);
            $table->timestamps();
        });

        Schema::create('designs', function (Blueprint $table) {
            $table->id();
            $table->tenantColumns();
            $table->string('code', 50);
            $table->string('name');
            $table->foreignId('category_id')->nullable()->constrained()->nullOnDelete();
            $table->decimal('approx_weight_min', 12, 3)->nullable();
            $table->decimal('approx_weight_max', 12, 3)->nullable();
            $table->json('sizes')->nullable();
            $table->decimal('making_charge', 12, 3)->nullable();
            $table->string('making_type', 20)->nullable();  // per_gram | per_piece | percent
            $table->text('description')->nullable();
            $table->boolean('is_published')->default(false);
            $table->timestamps();
            $table->unique(['tenant_id', 'code']);
            $table->index(['tenant_id', 'is_published']);
        });

        Schema::create('design_karat', function (Blueprint $table) {
            $table->foreignId('design_id')->constrained()->cascadeOnDelete();
            $table->foreignId('karat_id')->constrained()->cascadeOnDelete();
            $table->primary(['design_id', 'karat_id']);
        });

        Schema::create('collection_design', function (Blueprint $table) {
            $table->foreignId('collection_id')->constrained()->cascadeOnDelete();
            $table->foreignId('design_id')->constrained()->cascadeOnDelete();
            $table->primary(['collection_id', 'design_id']);
        });

        Schema::create('design_images', function (Blueprint $table) {
            $table->id();
            $table->tenantColumns();
            $table->foreignId('design_id')->constrained()->cascadeOnDelete();
            $table->string('path');
            $table->string('thumb_path')->nullable();
            $table->unsignedSmallInteger('sort')->default(0);
            $table->timestamps();
        });

        Schema::create('route_templates', function (Blueprint $table) {
            $table->id();
            $table->tenantColumns();
            $table->string('name');
            $table->foreignId('category_id')->nullable()->constrained()->nullOnDelete();
            $table->boolean('is_default')->default(false);
            $table->timestamps();
        });

        Schema::create('route_template_steps', function (Blueprint $table) {
            $table->id();
            $table->tenantColumns();
            $table->foreignId('route_template_id')->constrained()->cascadeOnDelete();
            $table->foreignId('process_id')->constrained()->restrictOnDelete();
            $table->unsignedSmallInteger('sequence');
            $table->timestamps();
            $table->unique(['route_template_id', 'sequence']);
        });

        Schema::create('gold_rates', function (Blueprint $table) {
            $table->id();
            $table->tenantColumns();
            $table->date('date');
            $table->foreignId('karat_id')->constrained()->cascadeOnDelete();
            $table->decimal('rate_per_g', 12, 2);
            $table->timestamps();
            $table->unique(['tenant_id', 'date', 'karat_id']);
        });

        Schema::create('retailers', function (Blueprint $table) {
            $table->id();
            $table->tenantColumns();
            $table->string('shop_name');
            $table->string('contact_name')->nullable();
            $table->string('gstin', 15)->nullable();
            $table->string('state_code', 2)->nullable();
            $table->string('city')->nullable();
            $table->text('address')->nullable();
            $table->string('phone', 20)->nullable();
            $table->string('email')->nullable();
            $table->decimal('credit_limit', 14, 2)->default(0);
            $table->foreignId('making_slab_id')->nullable()->constrained()->nullOnDelete();
            $table->boolean('metal_account_enabled')->default(false);
            $table->string('status', 20)->default('pending'); // pending | active | suspended | rejected
            $table->foreignId('approved_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('approved_at')->nullable();
            $table->timestamps();
            $table->index(['tenant_id', 'status']);
        });

        Schema::create('retailer_users', function (Blueprint $table) {
            $table->id();
            $table->tenantColumns();
            $table->foreignId('retailer_id')->constrained()->cascadeOnDelete();
            $table->string('name');
            $table->string('email');
            $table->string('phone', 20)->nullable();
            $table->string('password');
            $table->string('role', 20)->default('owner');   // owner | staff
            $table->boolean('is_active')->default(true);
            $table->rememberToken();
            $table->timestamps();
            $table->unique(['tenant_id', 'email']);
        });

        // Per-tenant, per-financial-year document numbering (ORD/26-27/0001).
        Schema::create('number_sequences', function (Blueprint $table) {
            $table->id();
            $table->tenantColumns();
            $table->string('series', 20);                    // ORD, WO, ISS, RCV, INV…
            $table->string('financial_year', 5);             // 26-27
            $table->unsignedInteger('last_number')->default(0);
            $table->timestamps();
            $table->unique(['tenant_id', 'series', 'financial_year']);
        });

        // Idempotency-Key replay store. tenant_id is 0 for platform (central) requests,
        // so the unique index works on MySQL (NULLs never collide there).
        Schema::create('idempotency_keys', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('tenant_id')->default(0);
            $table->string('actor', 60);                     // "staff:12", "retailer:4", "guest"
            $table->string('key', 100);
            $table->string('method', 10);
            $table->string('path');
            $table->char('request_hash', 64);
            $table->string('status', 12)->default('processing'); // processing | done
            $table->unsignedSmallInteger('response_status')->nullable();
            $table->longText('response_body')->nullable();
            $table->timestamps();
            $table->unique(['tenant_id', 'actor', 'key']);
            $table->index('created_at');
        });

        Schema::table('activity_log', function (Blueprint $table) {
            $table->unsignedBigInteger('tenant_id')->nullable()->after('id')->index();
            $table->string('ip_address', 45)->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('activity_log', function (Blueprint $table) {
            $table->dropIndex(['tenant_id']);
            $table->dropColumn(['tenant_id', 'ip_address']);
        });

        foreach ([
            'idempotency_keys', 'number_sequences', 'retailer_users', 'retailers', 'gold_rates',
            'route_template_steps', 'route_templates', 'design_images', 'collection_design',
            'design_karat', 'designs', 'making_slabs', 'collections', 'categories',
            'kaarigar_users', 'kaarigars', 'process_wastage_rules', 'processes', 'karats',
        ] as $table) {
            Schema::dropIfExists($table);
        }
    }
};
