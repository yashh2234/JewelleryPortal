<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/** Module 3 — B2B retailer portal. */
return new class extends Migration
{
    public function up(): void
    {
        // Which collections a retailer may see. No rows = all published designs.
        Schema::create('collection_retailer', function (Blueprint $table) {
            $table->foreignId('collection_id')->constrained()->cascadeOnDelete();
            $table->foreignId('retailer_id')->constrained()->cascadeOnDelete();
            $table->primary(['collection_id', 'retailer_id']);
        });

        // The retailer's cart, shared by the retailer's users and kept on the server.
        Schema::create('cart_items', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained('tenants')->cascadeOnDelete();
            $table->foreignId('retailer_id')->constrained()->cascadeOnDelete();
            $table->foreignId('retailer_user_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('design_id')->constrained()->cascadeOnDelete();
            $table->foreignId('karat_id')->constrained()->cascadeOnDelete();
            $table->string('size', 20)->nullable();
            $table->unsignedInteger('qty');
            $table->decimal('target_wt_min', 12, 3)->nullable();
            $table->decimal('target_wt_max', 12, 3)->nullable();
            $table->string('remarks', 500)->nullable();
            $table->timestamps();
            $table->index(['tenant_id', 'retailer_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('cart_items');
        Schema::dropIfExists('collection_retailer');
    }
};
