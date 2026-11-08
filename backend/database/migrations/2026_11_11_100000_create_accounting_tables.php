<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Module 4 — accounting. Double entry with two columns on every line: money (₹)
 * and metal (fine grams). A voucher must balance on both.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('financial_years', function (Blueprint $table) {
            $table->id();
            $table->tenantColumns();
            $table->string('label', 5);                       // 26-27
            $table->date('start_date');
            $table->date('end_date');
            $table->date('locked_until')->nullable();         // no voucher on or before this date
            $table->timestamps();
            $table->unique(['tenant_id', 'label']);
        });

        Schema::create('accounts', function (Blueprint $table) {
            $table->id();
            $table->tenantColumns();
            $table->string('code', 10);
            $table->string('name');
            $table->string('nature', 10);                     // asset | liability | equity | income | expense
            $table->string('group', 40);                      // report grouping: Cash, Bank, Debtors, Duties & taxes…
            $table->string('system_key', 40)->nullable();     // cash, bank, debtors, metal_control… (used by auto postings)
            $table->boolean('is_active')->default(true);
            $table->timestamps();
            $table->unique(['tenant_id', 'code']);
            $table->unique(['tenant_id', 'system_key']);
        });

        Schema::create('parties', function (Blueprint $table) {
            $table->id();
            $table->tenantColumns();
            $table->string('type', 20);                       // retailer | supplier | kaarigar | refiner | ahc | other
            $table->string('ref_type', 40)->nullable();
            $table->unsignedBigInteger('ref_id')->nullable();
            $table->string('name');
            $table->string('gstin', 15)->nullable();
            $table->string('state_code', 2)->nullable();
            $table->string('phone', 20)->nullable();
            $table->text('address')->nullable();
            $table->boolean('is_active')->default(true);
            $table->timestamps();
            $table->unique(['tenant_id', 'ref_type', 'ref_id']);
            $table->index(['tenant_id', 'type']);
        });

        Schema::create('vouchers', function (Blueprint $table) {
            $table->id();
            $table->tenantColumns();
            $table->string('voucher_no', 30);
            $table->string('type', 20);   // sales | purchase | receipt | payment | metal_receipt | metal_payment | rate_cut | labour | journal | contra | credit_note | debit_note | reversal
            $table->date('date');
            $table->foreignId('financial_year_id')->constrained()->restrictOnDelete();
            $table->foreignId('party_id')->nullable()->constrained()->restrictOnDelete();
            $table->text('narration')->nullable();
            $table->string('source_type', 40)->nullable();    // what posted it automatically
            $table->unsignedBigInteger('source_id')->nullable();
            $table->string('status', 15)->default('posted');  // posted | cancelled
            $table->foreignId('reverses_id')->nullable()->constrained('vouchers')->nullOnDelete();
            $table->foreignId('cancelled_by_id')->nullable()->constrained('vouchers')->nullOnDelete();
            $table->decimal('amount', 14, 2)->default(0);     // headline money figure
            $table->decimal('fine_wt', 12, 3)->default(0);    // headline metal figure
            $table->json('meta')->nullable();                 // invoice terms, rate-cut rate, e-invoice IRN…
            $table->timestamps();
            $table->unique(['tenant_id', 'voucher_no']);
            $table->index(['tenant_id', 'date']);
            $table->index(['tenant_id', 'source_type', 'source_id']);
        });

        Schema::create('voucher_entries', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained('tenants')->restrictOnDelete();
            $table->foreignId('voucher_id')->constrained()->cascadeOnDelete();
            $table->foreignId('account_id')->constrained()->restrictOnDelete();
            $table->foreignId('party_id')->nullable()->constrained()->restrictOnDelete();
            $table->decimal('debit', 14, 2)->default(0);
            $table->decimal('credit', 14, 2)->default(0);
            $table->decimal('fine_dr', 12, 3)->default(0);
            $table->decimal('fine_cr', 12, 3)->default(0);
            $table->string('memo')->nullable();
            $table->index(['tenant_id', 'account_id']);
            $table->index(['tenant_id', 'party_id']);
        });

        Schema::create('invoice_lines', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained('tenants')->restrictOnDelete();
            $table->foreignId('voucher_id')->constrained()->cascadeOnDelete();
            $table->foreignId('finished_item_id')->nullable()->constrained()->nullOnDelete();
            $table->string('description');
            $table->string('hsn', 10);
            $table->string('huid', 6)->nullable();
            $table->decimal('gross_wt', 12, 3)->default(0);
            $table->decimal('net_wt', 12, 3)->default(0);
            $table->decimal('purity_pct', 6, 3)->default(0);
            $table->decimal('fine_wt', 12, 3)->default(0);
            $table->decimal('rate', 12, 2)->default(0);
            $table->decimal('metal_value', 14, 2)->default(0);
            $table->decimal('making', 14, 2)->default(0);
            $table->decimal('stone_charge', 14, 2)->default(0);
            $table->decimal('hallmark_charge', 14, 2)->default(0);
            $table->decimal('taxable', 14, 2)->default(0);
            $table->decimal('gst_rate', 5, 2)->default(0);
            $table->decimal('cgst', 14, 2)->default(0);
            $table->decimal('sgst', 14, 2)->default(0);
            $table->decimal('igst', 14, 2)->default(0);
            $table->decimal('total', 14, 2)->default(0);
        });

        Schema::create('tax_settings', function (Blueprint $table) {
            $table->id();
            $table->tenantColumns();
            $table->string('hsn', 10);
            $table->string('description');
            $table->decimal('gst_rate', 5, 2);
            $table->date('effective_from');
            $table->timestamps();
            $table->index(['tenant_id', 'hsn', 'effective_from']);
        });

        Schema::create('labour_rates', function (Blueprint $table) {
            $table->id();
            $table->tenantColumns();
            $table->foreignId('process_id')->constrained()->cascadeOnDelete();
            $table->foreignId('kaarigar_id')->nullable()->constrained()->cascadeOnDelete();
            $table->string('basis', 10);                      // per_gram | per_piece
            $table->decimal('rate', 12, 2);
            $table->timestamps();
            $table->unique(['process_id', 'kaarigar_id']);
        });

        Schema::table('job_cards', function (Blueprint $table) {
            $table->foreignId('labour_voucher_id')->nullable()->constrained('vouchers')->nullOnDelete();
        });

        Schema::table('kaarigar_requests', function (Blueprint $table) {
            $table->foreignId('voucher_id')->nullable()->constrained()->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('kaarigar_requests', fn (Blueprint $t) => $t->dropConstrainedForeignId('voucher_id'));
        Schema::table('job_cards', fn (Blueprint $t) => $t->dropConstrainedForeignId('labour_voucher_id'));
        foreach (['labour_rates', 'tax_settings', 'invoice_lines', 'voucher_entries', 'vouchers', 'parties', 'accounts', 'financial_years'] as $t) {
            Schema::dropIfExists($t);
        }
    }
};
