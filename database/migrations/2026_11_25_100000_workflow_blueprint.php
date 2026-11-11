<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Scope the product to the "Jewellery Manufacturing ERP – Workflow Blueprint".
 *
 * Removed (not in the blueprint): BIS hallmarking / HUID, daily gold closing.
 *
 * Added (blueprint §2–§3):
 *  - head kaarigar → sub-kaarigar hierarchy;
 *  - kaarigar-to-kaarigar custody handoffs: a child job card under the head's card,
 *    and a handoff voucher that records which card the gold left;
 *  - stage details on a job card: start / ready times and the kaarigar's note;
 *  - no automatic wastage: every loss waits for an approval with a reason code and note.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::dropIfExists('hallmark_lot_items');
        Schema::dropIfExists('hallmark_lots');
        Schema::dropIfExists('ahcs');
        Schema::dropIfExists('metal_closings');

        Schema::table('finished_items', function (Blueprint $table) {
            $table->dropUnique(['tenant_id', 'huid']);
        });
        Schema::table('finished_items', fn (Blueprint $table) => $table->dropColumn('huid'));
        Schema::table('retailers', fn (Blueprint $table) => $table->dropColumn('hallmark_mandatory'));
        Schema::table('invoice_lines', fn (Blueprint $table) => $table->dropColumn(['huid', 'hallmark_charge']));

        Schema::table('kaarigars', function (Blueprint $table) {
            $table->foreignId('head_kaarigar_id')->nullable()->after('type')->constrained('kaarigars')->nullOnDelete();
        });

        Schema::table('job_cards', function (Blueprint $table) {
            $table->foreignId('parent_job_card_id')->nullable()->after('wo_step_id')->constrained('job_cards')->nullOnDelete();
            $table->timestamp('started_at')->nullable();
            $table->timestamp('ready_at')->nullable();
            $table->text('kaarigar_note')->nullable();
            // Loss on close is never written off on its own (blueprint §3 control rule).
            $table->string('loss_status', 20)->nullable();            // pending | approved
            $table->string('loss_reason_code', 40)->nullable();
            $table->text('loss_note')->nullable();
            $table->foreignId('loss_approved_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('loss_approved_at')->nullable();
            $table->decimal('handed_in_fine', 12, 3)->default(0);
            $table->decimal('handed_out_fine', 12, 3)->default(0);
        });

        Schema::table('metal_transactions', function (Blueprint $table) {
            // Handoffs: the card the gold left (job_card_id is the card it went to).
            $table->foreignId('from_job_card_id')->nullable()->after('job_card_id')->constrained('job_cards')->restrictOnDelete();
            // A head kaarigar can post a handoff from the app; staff rows use created_by.
            $table->foreignId('posted_by_kaarigar_user_id')->nullable()->constrained('kaarigar_users')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('metal_transactions', function (Blueprint $table) {
            $table->dropConstrainedForeignId('posted_by_kaarigar_user_id');
            $table->dropConstrainedForeignId('from_job_card_id');
        });
        Schema::table('job_cards', function (Blueprint $table) {
            $table->dropConstrainedForeignId('loss_approved_by');
            $table->dropConstrainedForeignId('parent_job_card_id');
            $table->dropColumn(['started_at', 'ready_at', 'kaarigar_note', 'loss_status', 'loss_reason_code', 'loss_note', 'loss_approved_at', 'handed_in_fine', 'handed_out_fine']);
        });
        Schema::table('kaarigars', fn (Blueprint $table) => $table->dropConstrainedForeignId('head_kaarigar_id'));
        // Hallmarking and daily closing are not restored; recover them from the git branch pre-pdf-scope.
    }
};
