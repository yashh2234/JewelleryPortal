<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Module 1 — gold ledger.
 *
 * Double entry at line level: every line moves fine gold FROM one location TO
 * another, so the sum over all locations is always zero. Balances are derived from
 * lines; location_balances is only a nightly cache checked against the sums.
 * Transactions are append-only: mistakes are fixed with a reversing transaction.
 */
return new class extends Migration
{
    public function up(): void
    {
        // Where gold can be. "outside" is the world (purchases, sales, deposits);
        // "loss" collects approved wastage; "melt" and "refiner" are pass-through.
        Schema::create('metal_locations', function (Blueprint $table) {
            $table->id();
            $table->tenantColumns();
            $table->string('type', 20);           // vault | kaarigar | wo | scrap | finished | ahc | refiner | melt | loss | outside
            $table->string('name');
            $table->string('ref_type', 40)->nullable();
            $table->unsignedBigInteger('ref_id')->nullable();
            $table->timestamps();
            $table->unique(['tenant_id', 'type', 'ref_type', 'ref_id']);
            $table->index(['tenant_id', 'type']);
        });

        // A piece of work given to one kaarigar for one process. Phase 3 links it to a work-order step.
        Schema::create('job_cards', function (Blueprint $table) {
            $table->id();
            $table->tenantColumns();
            $table->string('card_no', 30);
            $table->foreignId('kaarigar_id')->constrained()->restrictOnDelete();
            $table->foreignId('process_id')->constrained()->restrictOnDelete();
            $table->foreignId('karat_id')->constrained()->restrictOnDelete();
            $table->unsignedBigInteger('wo_step_id')->nullable()->index();  // FK added in Phase 3
            $table->foreignId('design_id')->nullable()->constrained()->nullOnDelete();
            $table->string('description')->nullable();
            $table->unsignedInteger('pieces')->nullable();
            $table->date('due_date')->nullable();
            $table->string('status', 20)->default('open');                   // open | closed
            $table->string('kaarigar_status', 20)->nullable();               // started | ready (from the kaarigar app)
            // Filled when the card is closed (live figures are computed from ledger lines).
            $table->decimal('issued_fine', 12, 3)->default(0);
            $table->decimal('returned_fine', 12, 3)->default(0);
            $table->decimal('scrap_fine', 12, 3)->default(0);
            $table->decimal('loss_fine', 12, 3)->default(0);
            $table->decimal('allowed_loss_fine', 12, 3)->default(0);
            $table->decimal('excess_loss_fine', 12, 3)->default(0);
            $table->decimal('allowed_wastage_pct', 6, 3)->default(0);
            // Loss above the limit stays on the kaarigar until the owner writes it off
            // or it is recovered from labour (Phase 4).
            $table->string('excess_resolution', 20)->nullable();             // written_off | recovered
            $table->timestamp('excess_resolved_at')->nullable();
            $table->timestamp('closed_at')->nullable();
            $table->foreignId('closed_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->unique(['tenant_id', 'card_no']);
            $table->index(['tenant_id', 'kaarigar_id', 'status']);
        });

        Schema::create('metal_transactions', function (Blueprint $table) {
            $table->id();
            $table->tenantColumns();
            $table->string('voucher_no', 30);
            $table->string('type', 20);           // opening | purchase | deposit | issue | receive | melt | refine | transfer | loss_writeoff | reversal
            $table->date('date');
            $table->foreignId('job_card_id')->nullable()->constrained()->restrictOnDelete();
            $table->foreignId('kaarigar_id')->nullable()->constrained()->restrictOnDelete();
            $table->foreignId('process_id')->nullable()->constrained()->restrictOnDelete();
            $table->foreignId('retailer_id')->nullable()->constrained()->restrictOnDelete();
            $table->string('party_name')->nullable();           // supplier / refiner when not a master yet
            $table->string('reference')->nullable();            // tunch report no., bill no.
            $table->text('remarks')->nullable();
            $table->boolean('over_limit')->default(false);       // loss above allowed wastage
            $table->foreignId('reverses_id')->nullable()->constrained('metal_transactions')->restrictOnDelete();
            $table->foreignId('reversed_by_id')->nullable()->constrained('metal_transactions')->restrictOnDelete();
            $table->string('kaarigar_ack', 20)->nullable();      // issues: accepted | disputed (from the app)
            $table->timestamp('kaarigar_ack_at')->nullable();
            $table->timestamps();
            $table->unique(['tenant_id', 'voucher_no']);
            $table->index(['tenant_id', 'date']);
            $table->index(['tenant_id', 'type', 'date']);
        });

        Schema::create('metal_transaction_lines', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained('tenants')->restrictOnDelete();
            $table->foreignId('metal_transaction_id')->constrained()->restrictOnDelete();
            $table->foreignId('from_location_id')->constrained('metal_locations')->restrictOnDelete();
            $table->foreignId('to_location_id')->constrained('metal_locations')->restrictOnDelete();
            $table->foreignId('karat_id')->constrained()->restrictOnDelete();
            $table->string('kind', 20)->default('metal');        // metal | work | scrap | loss | alloy
            $table->decimal('gross_wt', 12, 3);
            $table->decimal('stone_wt', 12, 3)->default(0);
            $table->decimal('findings_wt', 12, 3)->default(0);
            $table->decimal('net_wt', 12, 3);
            $table->decimal('purity_pct', 6, 3);
            $table->decimal('fine_wt', 12, 3);
            $table->unsignedInteger('pieces')->nullable();
            $table->timestamp('created_at')->nullable();
            $table->index(['tenant_id', 'from_location_id']);
            $table->index(['tenant_id', 'to_location_id']);
        });

        // Nightly cache, rebuilt from lines and compared with them.
        Schema::create('location_balances', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained('tenants')->cascadeOnDelete();
            $table->foreignId('location_id')->constrained('metal_locations')->cascadeOnDelete();
            $table->foreignId('karat_id')->constrained()->cascadeOnDelete();
            $table->decimal('gross_wt', 14, 3);
            $table->decimal('fine_wt', 14, 3);
            $table->timestamp('as_of');
            $table->unique(['location_id', 'karat_id']);
        });

        // Daily closing: system vault vs physical count. Closing locks the day.
        Schema::create('metal_closings', function (Blueprint $table) {
            $table->id();
            $table->tenantColumns();
            $table->date('date');
            $table->decimal('system_vault_fine', 14, 3);
            $table->decimal('physical_vault_fine', 14, 3)->nullable();
            $table->decimal('difference_fine', 14, 3)->default(0);
            $table->json('summary')->nullable();               // balances by location type at close
            $table->text('remarks')->nullable();
            $table->timestamps();
            $table->unique(['tenant_id', 'date']);
        });

        // Kaarigar app actions. A request is never a ledger posting; the store approves it.
        Schema::create('kaarigar_requests', function (Blueprint $table) {
            $table->id();
            $table->tenantColumns();
            $table->foreignId('kaarigar_id')->constrained()->cascadeOnDelete();
            $table->foreignId('kaarigar_user_id')->nullable()->constrained()->nullOnDelete();
            $table->string('type', 20);                         // dispute | return | advance
            $table->foreignId('job_card_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('metal_transaction_id')->nullable()->constrained()->nullOnDelete();
            $table->json('data')->nullable();                   // weights, pieces, amount, reason
            $table->string('client_key', 100)->nullable();      // offline queue idempotency
            $table->string('status', 20)->default('pending');   // pending | approved | rejected
            $table->text('review_note')->nullable();
            $table->foreignId('reviewed_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('reviewed_at')->nullable();
            $table->foreignId('result_transaction_id')->nullable()->constrained('metal_transactions')->nullOnDelete();
            $table->timestamps();
            $table->index(['tenant_id', 'status']);
            $table->unique(['tenant_id', 'kaarigar_id', 'client_key']);
        });

        Schema::create('attachments', function (Blueprint $table) {
            $table->id();
            $table->tenantColumns();
            $table->string('attachable_type');
            $table->unsignedBigInteger('attachable_id');
            $table->string('kind', 30);                         // work_photo | scale_photo | hallmark_photo | qc_photo
            $table->string('path');
            $table->string('thumb_path')->nullable();
            $table->timestamps();
            $table->index(['attachable_type', 'attachable_id']);
        });

        Schema::table('kaarigars', function (Blueprint $table) {
            $table->string('slip_token', 40)->nullable()->unique();  // QR on paper slips opens the statement
        });
    }

    public function down(): void
    {
        Schema::table('kaarigars', fn (Blueprint $table) => $table->dropColumn('slip_token'));
        foreach (['attachments', 'kaarigar_requests', 'metal_closings', 'location_balances', 'metal_transaction_lines', 'metal_transactions', 'job_cards', 'metal_locations'] as $t) {
            Schema::dropIfExists($t);
        }
    }
};
