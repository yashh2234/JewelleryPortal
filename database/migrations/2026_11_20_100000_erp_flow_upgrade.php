<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * ERP flow upgrade (spec v2): full order lifecycle, order money, catalogue fields,
 * wishlist, CRM log, retailer queries, shipment tracking, QC checklist, stage
 * assignees, kaarigar gold limit and in-app notifications.
 */
return new class extends Migration
{
    public function up(): void
    {
        // ── Orders: 13-step lifecycle + money ───────────────────────────────
        Schema::table('orders', function (Blueprint $table) {
            $table->decimal('estimated_value', 14, 2)->nullable()->after('remarks');
            $table->decimal('advance_amount', 14, 2)->default(0)->after('estimated_value');
            $table->string('payment_status', 15)->default('unpaid')->after('advance_amount'); // unpaid | advance | partly_paid | paid
            $table->timestamp('approved_at')->nullable()->after('confirmed_at');
            $table->foreignId('approved_by')->nullable()->after('approved_at')->constrained('users')->nullOnDelete();
            $table->timestamp('delivered_at')->nullable()->after('approved_by');
            $table->timestamp('closed_at')->nullable()->after('delivered_at');
        });

        // Old statuses → new names (placed → new, confirmed → approved, ready → ready_for_dispatch).
        DB::table('orders')->where('status', 'placed')->update(['status' => 'new']);
        DB::table('orders')->where('status', 'confirmed')->update(['status' => 'approved']);
        DB::table('orders')->where('status', 'ready')->update(['status' => 'ready_for_dispatch']);
        DB::table('orders')->where('status_before_hold', 'placed')->update(['status_before_hold' => 'new']);
        DB::table('orders')->where('status_before_hold', 'confirmed')->update(['status_before_hold' => 'approved']);
        DB::table('orders')->where('status_before_hold', 'ready')->update(['status_before_hold' => 'ready_for_dispatch']);
        DB::table('orders')->whereNotNull('confirmed_at')->update(['approved_at' => DB::raw('confirmed_at')]);

        Schema::table('order_lines', function (Blueprint $table) {
            $table->text('customization')->nullable()->after('remarks');
        });

        // ── Catalogue ───────────────────────────────────────────────────────
        Schema::table('designs', function (Blueprint $table) {
            $table->string('stone_details')->nullable()->after('making_type');
            $table->text('specifications')->nullable()->after('stone_details');
            $table->unsignedInteger('min_order_qty')->default(1)->after('specifications');
            $table->unsignedSmallInteger('production_days')->nullable()->after('min_order_qty');
            $table->string('availability', 20)->default('made_to_order')->after('production_days'); // in_stock | made_to_order | unavailable
        });

        Schema::create('wishlist_items', function (Blueprint $table) {
            $table->id();
            $table->tenantColumns();
            $table->foreignId('retailer_id')->constrained()->cascadeOnDelete();
            $table->foreignId('design_id')->constrained()->cascadeOnDelete();
            $table->timestamps();
            $table->unique(['retailer_id', 'design_id']);
        });

        // ── CRM ─────────────────────────────────────────────────────────────
        Schema::table('retailers', function (Blueprint $table) {
            $table->foreignId('salesperson_id')->nullable()->after('making_slab_id')->constrained('users')->nullOnDelete();
            $table->unsignedSmallInteger('payment_terms_days')->nullable()->after('credit_limit');
        });

        Schema::create('crm_notes', function (Blueprint $table) {
            $table->id();
            $table->tenantColumns();
            $table->foreignId('retailer_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('channel', 15)->default('note');   // call | visit | whatsapp | email | note
            $table->text('body');
            $table->date('follow_up_on')->nullable();
            $table->timestamps();
            $table->index(['tenant_id', 'retailer_id']);
        });

        Schema::create('retailer_queries', function (Blueprint $table) {
            $table->id();
            $table->tenantColumns();
            $table->string('ticket_no', 30);
            $table->foreignId('retailer_id')->constrained()->cascadeOnDelete();
            $table->foreignId('retailer_user_id')->nullable()->constrained('retailer_users')->nullOnDelete();
            $table->foreignId('order_id')->nullable()->constrained()->nullOnDelete();
            $table->string('type', 20)->default('query');       // query | customization | complaint
            $table->string('subject');
            $table->string('status', 15)->default('open');       // open | answered | closed
            $table->timestamps();
            $table->unique(['tenant_id', 'ticket_no']);
            $table->index(['tenant_id', 'status']);
        });

        Schema::create('retailer_query_messages', function (Blueprint $table) {
            $table->id();
            $table->foreignId('retailer_query_id')->constrained()->cascadeOnDelete();
            $table->string('author_type', 10);                   // staff | retailer
            $table->unsignedBigInteger('author_id')->nullable();
            $table->string('author_name')->nullable();
            $table->text('body');
            $table->timestamps();
        });

        // ── Shipment tracking ───────────────────────────────────────────────
        Schema::table('dispatches', function (Blueprint $table) {
            $table->string('shipment_status', 20)->default('dispatched')->after('remarks'); // packing | dispatched | in_transit | out_for_delivery | delivered | failed
            $table->string('courier', 60)->nullable()->after('shipment_status');
            $table->string('tracking_no', 60)->nullable()->after('courier');
            $table->string('tracking_url')->nullable()->after('tracking_no');
            $table->timestamp('shipped_at')->nullable()->after('tracking_url');
            $table->timestamp('delivered_at')->nullable()->after('shipped_at');
            $table->string('delivery_remarks')->nullable()->after('delivered_at');
        });

        // ── QC & finished goods ─────────────────────────────────────────────
        Schema::table('finished_items', function (Blueprint $table) {
            $table->json('qc_checklist')->nullable()->after('qc_remarks');
            $table->string('qc_defects')->nullable()->after('qc_checklist');
            $table->foreignId('qc_by')->nullable()->after('qc_defects')->constrained('users')->nullOnDelete();
            $table->timestamp('qc_at')->nullable()->after('qc_by');
            $table->string('storage_location', 60)->nullable()->after('status');
        });

        // ── Production stages ───────────────────────────────────────────────
        Schema::table('wo_steps', function (Blueprint $table) {
            $table->foreignId('assigned_user_id')->nullable()->after('status')->constrained('users')->nullOnDelete();
            $table->text('remarks')->nullable()->after('assigned_user_id');
        });

        // ── Kaarigar gold limit ─────────────────────────────────────────────
        Schema::table('kaarigars', function (Blueprint $table) {
            $table->decimal('max_fine_balance', 12, 3)->nullable();  // null = no limit
        });

        // ── In-app notifications ────────────────────────────────────────────
        Schema::create('app_notifications', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained('tenants')->cascadeOnDelete();
            $table->string('user_type', 10);                     // staff | retailer | kaarigar
            $table->unsignedBigInteger('user_id');
            $table->string('event', 40)->nullable();
            $table->string('title');
            $table->string('body', 500)->nullable();
            $table->string('url')->nullable();
            $table->timestamp('read_at')->nullable();
            $table->timestamp('created_at')->nullable();
            $table->index(['tenant_id', 'user_type', 'user_id', 'read_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('app_notifications');
        Schema::dropIfExists('retailer_query_messages');
        Schema::dropIfExists('retailer_queries');
        Schema::dropIfExists('crm_notes');
        Schema::dropIfExists('wishlist_items');

        Schema::table('kaarigars', fn (Blueprint $t) => $t->dropColumn('max_fine_balance'));
        Schema::table('wo_steps', function (Blueprint $t) {
            $t->dropConstrainedForeignId('assigned_user_id');
            $t->dropColumn('remarks');
        });
        Schema::table('finished_items', function (Blueprint $t) {
            $t->dropConstrainedForeignId('qc_by');
            $t->dropColumn(['qc_checklist', 'qc_defects', 'qc_at', 'storage_location']);
        });
        Schema::table('dispatches', fn (Blueprint $t) => $t->dropColumn(['shipment_status', 'courier', 'tracking_no', 'tracking_url', 'shipped_at', 'delivered_at', 'delivery_remarks']));
        Schema::table('retailers', function (Blueprint $t) {
            $t->dropConstrainedForeignId('salesperson_id');
            $t->dropColumn('payment_terms_days');
        });
        Schema::table('designs', fn (Blueprint $t) => $t->dropColumn(['stone_details', 'specifications', 'min_order_qty', 'production_days', 'availability']));
        Schema::table('order_lines', fn (Blueprint $t) => $t->dropColumn('customization'));

        DB::table('orders')->whereIn('status', ['new', 'pending_approval'])->update(['status' => 'placed']);
        DB::table('orders')->whereIn('status', ['approved', 'work_order_created'])->update(['status' => 'confirmed']);
        DB::table('orders')->whereIn('status', ['material_issued', 'under_qc', 'rework_required', 'production_completed'])->update(['status' => 'in_production']);
        DB::table('orders')->where('status', 'ready_for_dispatch')->update(['status' => 'ready']);
        DB::table('orders')->where('status', 'delivered')->update(['status' => 'dispatched']);
        Schema::table('orders', function (Blueprint $t) {
            $t->dropConstrainedForeignId('approved_by');
            $t->dropColumn(['estimated_value', 'advance_amount', 'payment_status', 'approved_at', 'delivered_at', 'closed_at']);
        });
    }
};
