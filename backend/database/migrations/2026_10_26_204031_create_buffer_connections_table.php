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
        Schema::create('buffer_connections', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignId('user_id')->unique()->constrained()->cascadeOnDelete();
            $table->text('api_key');
            $table->string('api_key_fingerprint', 64)->unique();
            $table->string('buffer_account_id', 191)->unique();
            $table->string('account_name', 180)->nullable();
            $table->string('account_email', 190)->nullable();
            $table->string('organization_id', 191);
            $table->string('organization_name', 180)->nullable();
            $table->string('status', 20)->default('active')->index();
            $table->timestamp('last_synced_at')->nullable();
            $table->text('last_error')->nullable();
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('buffer_connections');
    }
};
