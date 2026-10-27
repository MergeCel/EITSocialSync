<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('engagement_stats', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('post_platform_id');
            $table->unsignedBigInteger('views')->default(0);
            $table->unsignedBigInteger('likes')->default(0);
            $table->unsignedBigInteger('comments')->default(0);
            $table->unsignedBigInteger('shares')->default(0);
            $table->unsignedBigInteger('saves')->default(0);
            $table->json('raw')->nullable();
            $table->timestamp('fetched_at')->nullable();
            $table->timestamps();

            $table->unique('post_platform_id');
            $table->foreign('post_platform_id')->references('id')->on('post_platforms')->cascadeOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('engagement_stats');
    }
};
