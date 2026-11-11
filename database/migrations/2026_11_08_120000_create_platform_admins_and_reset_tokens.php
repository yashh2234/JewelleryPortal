<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // You (the SaaS operator). Separate from tenant staff; logs in on the central domain.
        Schema::create('platform_admins', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->string('email')->unique();
            $table->string('password');
            $table->rememberToken();
            $table->timestamps();
        });

        // Password reset tokens per workspace and login type (the same email can exist
        // in two workspaces, or as staff and retailer). Only a hash of the token is stored.
        Schema::create('auth_reset_tokens', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained('tenants')->cascadeOnDelete();
            $table->string('user_type', 20);             // staff | retailer
            $table->string('email');
            $table->char('token_hash', 64);
            $table->timestamp('expires_at');
            $table->timestamp('created_at')->nullable();
            $table->index(['tenant_id', 'email']);
        });

        Schema::dropIfExists('password_reset_tokens');
    }

    public function down(): void
    {
        Schema::dropIfExists('auth_reset_tokens');
        Schema::dropIfExists('platform_admins');
        Schema::create('password_reset_tokens', function (Blueprint $table) {
            $table->string('email')->primary();
            $table->string('token');
            $table->timestamp('created_at')->nullable();
        });
    }
};
