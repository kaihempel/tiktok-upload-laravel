<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('tiktok_accounts', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->morphs('owner');
            $table->string('open_id')->unique();
            $table->string('display_name')->nullable();
            $table->string('avatar_url', 2048)->nullable();
            $table->json('scopes');
            $table->text('access_token');
            $table->timestamp('access_token_expires_at');
            $table->text('refresh_token');
            $table->timestamp('refresh_token_expires_at');
            $table->timestamp('revoked_at')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('tiktok_accounts');
    }
};
