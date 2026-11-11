<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Module 2 (orders, work orders, production) and Module 6 (hallmarking), plus
 * finished pieces and dispatch.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('orders', function (Blueprint $table) {
            $table->id();
            $table->tenantColumns();
            $table->string('order_no', 30);
            $table->foreignId('retailer_id')->constrained()->restrictOnDelete();
            $table->string('source', 10)->default('staff');          // staff | portal
            $table->string('status', 25)->default('placed');         // placed | confirmed | in_production | ready | partially_dispatched | dispatched | closed | on_hold | cancelled
            $table->string('status_before_hold', 25)->nullable();
            $table->date('due_date')->nullable();
            $table->text('remarks')->nullable();
            $table->text('cancel_reason')->nullable();
            $table->timestamp('confirmed_at')->nullable();
            $table->timestamps();
            $table->unique(['tenant_id', 'order_no']);
            $table->index(['tenant_id', 'status']);
        });

        Schema::create('order_lines', function (Blueprint $table) {
            $table->id();
            $table->tenantColumns();
            $table->foreignId('order_id')->constrained()->cascadeOnDelete();
            $table->foreignId('design_id')->constrained()->restrictOnDelete();
            $table->foreignId('karat_id')->constrained()->restrictOnDelete();
            $table->string('size', 20)->nullable();
            $table->unsignedInteger('qty');
            $table->decimal('target_wt_min', 12, 3)->nullable();     // per piece
            $table->decimal('target_wt_max', 12, 3)->nullable();
            $table->string('stone_details')->nullable();
            $table->text('remarks')->nullable();
            $table->string('reference_image')->nullable();
            $table->unsignedInteger('qty_dispatched')->default(0);
            $table->timestamps();
        });

        Schema::create('work_orders', function (Blueprint $table) {
            $table->id();
            $table->tenantColumns();
            $table->string('wo_no', 30);
            $table->foreignId('design_id')->constrained()->restrictOnDelete();
            $table->foreignId('karat_id')->constrained()->restrictOnDelete();
            $table->unsignedInteger('qty');
            $table->json('sizes')->nullable();                       // {"2.4": 20, "2.6": 30}
            $table->decimal('target_wt_min', 12, 3)->nullable();
            $table->decimal('target_wt_max', 12, 3)->nullable();
            $table->date('due_date')->nullable();
            $table->string('priority', 10)->default('normal');       // low | normal | high | urgent
            $table->string('status', 20)->default('open');           // open | in_progress | completed | cancelled
            $table->foreignId('rework_of_id')->nullable()->constrained('work_orders')->nullOnDelete();
            $table->text('remarks')->nullable();
            $table->timestamp('completed_at')->nullable();
            $table->timestamps();
            $table->unique(['tenant_id', 'wo_no']);
            $table->index(['tenant_id', 'status']);
        });

        Schema::create('wo_order_lines', function (Blueprint $table) {
            $table->foreignId('work_order_id')->constrained()->cascadeOnDelete();
            $table->foreignId('order_line_id')->constrained()->cascadeOnDelete();
            $table->unsignedInteger('qty');
            $table->primary(['work_order_id', 'order_line_id']);
        });

        Schema::create('wo_steps', function (Blueprint $table) {
            $table->id();
            $table->tenantColumns();
            $table->foreignId('work_order_id')->constrained()->cascadeOnDelete();
            $table->foreignId('process_id')->constrained()->restrictOnDelete();
            $table->unsignedSmallInteger('sequence');
            $table->string('status', 20)->default('pending');        // pending | in_progress | done | skipped
            $table->timestamp('started_at')->nullable();
            $table->timestamp('done_at')->nullable();
            $table->timestamps();
            $table->unique(['work_order_id', 'sequence']);
        });

        Schema::table('job_cards', function (Blueprint $table) {
            $table->foreign('wo_step_id')->references('id')->on('wo_steps')->nullOnDelete();
        });

        Schema::create('ahcs', function (Blueprint $table) {
            $table->id();
            $table->tenantColumns();
            $table->string('name');
            $table->string('bis_recognition_no', 50)->nullable();
            $table->string('city')->nullable();
            $table->string('contact')->nullable();
            $table->decimal('charge_per_piece', 10, 2)->default(0);
            $table->boolean('is_active')->default(true);
            $table->timestamps();
        });

        Schema::create('finished_items', function (Blueprint $table) {
            $table->id();
            $table->tenantColumns();
            $table->string('tag_no', 30);                            // printed on the tag
            $table->foreignId('work_order_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('order_line_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('design_id')->constrained()->restrictOnDelete();
            $table->foreignId('karat_id')->constrained()->restrictOnDelete();
            $table->string('size', 20)->nullable();
            $table->decimal('gross_wt', 12, 3);
            $table->decimal('stone_wt', 12, 3)->default(0);
            $table->decimal('net_wt', 12, 3);
            $table->decimal('purity_pct', 6, 3);
            $table->decimal('fine_wt', 12, 3);
            $table->string('huid', 6)->nullable();
            $table->string('qc_status', 10)->default('pending');     // pending | passed | failed
            $table->text('qc_remarks')->nullable();
            $table->string('status', 20)->default('in_stock');       // in_stock | at_ahc | rework | dispatched
            $table->timestamps();
            $table->unique(['tenant_id', 'tag_no']);
            $table->unique(['tenant_id', 'huid']);
            $table->index(['tenant_id', 'status']);
        });

        Schema::create('hallmark_lots', function (Blueprint $table) {
            $table->id();
            $table->tenantColumns();
            $table->string('lot_no', 30);
            $table->foreignId('ahc_id')->constrained()->restrictOnDelete();
            $table->foreignId('karat_id')->constrained()->restrictOnDelete();   // declared purity
            $table->unsignedInteger('pieces')->default(0);
            $table->decimal('gross_wt', 12, 3)->default(0);
            $table->string('status', 20)->default('draft');          // draft | sent | returned
            $table->string('manak_request_no', 50)->nullable();
            $table->decimal('charges', 12, 2)->default(0);
            $table->timestamp('sent_at')->nullable();
            $table->timestamp('returned_at')->nullable();
            $table->foreignId('send_transaction_id')->nullable()->constrained('metal_transactions')->nullOnDelete();
            $table->foreignId('return_transaction_id')->nullable()->constrained('metal_transactions')->nullOnDelete();
            $table->timestamps();
            $table->unique(['tenant_id', 'lot_no']);
        });

        Schema::create('hallmark_lot_items', function (Blueprint $table) {
            $table->id();
            $table->tenantColumns();
            $table->foreignId('hallmark_lot_id')->constrained()->cascadeOnDelete();
            $table->foreignId('finished_item_id')->constrained()->restrictOnDelete();
            $table->decimal('sent_wt', 12, 3);
            $table->decimal('returned_wt', 12, 3)->nullable();
            $table->string('result', 10)->nullable();                // pass | fail
            $table->string('huid', 6)->nullable();
            $table->text('remarks')->nullable();
            $table->timestamps();
            $table->unique(['hallmark_lot_id', 'finished_item_id']);
        });

        Schema::create('dispatches', function (Blueprint $table) {
            $table->id();
            $table->tenantColumns();
            $table->string('challan_no', 30);
            $table->foreignId('retailer_id')->constrained()->restrictOnDelete();
            $table->foreignId('order_id')->nullable()->constrained()->nullOnDelete();
            $table->date('date');
            $table->unsignedInteger('pieces');
            $table->decimal('gross_wt', 12, 3);
            $table->decimal('net_wt', 12, 3);
            $table->decimal('fine_wt', 12, 3);
            $table->foreignId('metal_transaction_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('invoice_voucher_id')->nullable();     // Module 4
            $table->text('remarks')->nullable();
            $table->timestamps();
            $table->unique(['tenant_id', 'challan_no']);
        });

        Schema::create('dispatch_lines', function (Blueprint $table) {
            $table->id();
            $table->tenantColumns();
            $table->foreignId('dispatch_id')->constrained()->cascadeOnDelete();
            $table->foreignId('finished_item_id')->constrained()->restrictOnDelete();
            $table->foreignId('order_line_id')->nullable()->constrained()->nullOnDelete();
            $table->timestamps();
            $table->unique('finished_item_id');
        });

        Schema::table('retailers', function (Blueprint $table) {
            // Hallmarking is mandatory at first sale in notified districts (most of India).
            $table->boolean('hallmark_mandatory')->default(true);
        });

        Schema::table('metal_transactions', function (Blueprint $table) {
            $table->foreignId('work_order_id')->nullable()->after('job_card_id')->constrained()->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('metal_transactions', function (Blueprint $table) {
            $table->dropConstrainedForeignId('work_order_id');
        });
        Schema::table('retailers', fn (Blueprint $table) => $table->dropColumn('hallmark_mandatory'));
        Schema::table('job_cards', fn (Blueprint $table) => $table->dropForeign(['wo_step_id']));
        foreach (['dispatch_lines', 'dispatches', 'hallmark_lot_items', 'hallmark_lots', 'finished_items', 'ahcs', 'wo_steps', 'wo_order_lines', 'work_orders', 'order_lines', 'orders'] as $t) {
            Schema::dropIfExists($t);
        }
    }
};
