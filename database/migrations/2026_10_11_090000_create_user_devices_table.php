<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * One row per sign-in. The row outlives its Sanctum token so the history
     * can show revoked and expired devices until they are pruned.
     */
    public function up(): void
    {
        Schema::create('user_devices', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->foreignId('token_id')->nullable()->unique()->constrained('personal_access_tokens')->nullOnDelete();
            $table->string('ip_address', 45)->nullable();
            $table->string('user_agent', 512)->nullable();
            $table->string('browser', 64)->nullable();
            $table->string('platform', 64)->nullable();
            $table->string('device_type', 16)->default('unknown');
            $table->boolean('remember')->default(false);
            $table->timestamp('last_active_at')->nullable();
            $table->timestamp('expires_at')->nullable()->index();
            $table->timestamp('revoked_at')->nullable()->index();
            $table->string('revoked_reason', 32)->nullable();
            $table->timestamps();

            $table->index(['user_id', 'created_at']);
        });

        DB::table('personal_access_tokens')
            ->where('tokenable_type', 'App\\Models\\User')
            ->orderBy('id')
            ->each(function (object $token): void {
                DB::table('user_devices')->insert([
                    'user_id' => $token->tokenable_id,
                    'token_id' => $token->id,
                    'last_active_at' => $token->last_used_at,
                    'expires_at' => $token->expires_at,
                    'created_at' => $token->created_at,
                    'updated_at' => $token->updated_at,
                ]);
            });
    }

    public function down(): void
    {
        Schema::dropIfExists('user_devices');
    }
};
