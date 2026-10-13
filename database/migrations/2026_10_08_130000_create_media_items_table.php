<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('media_items', function (Blueprint $table) {
            $table->id();
            $table->ulid('ulid')->unique();
            $table->string('title');
            $table->string('slug')->unique();
            $table->string('kind')->index();
            $table->text('alt')->nullable();
            $table->json('keywords')->nullable();
            $table->text('search_text')->nullable();
            $table->string('status')->default('awaiting_upload')->index();
            $table->unsignedTinyInteger('progress')->default(0);
            $table->text('error_message')->nullable();
            $table->string('mime')->nullable();
            $table->string('extension', 16)->nullable();
            $table->unsignedBigInteger('size')->nullable();
            $table->unsignedInteger('duration_seconds')->nullable();
            $table->unsignedSmallInteger('width')->nullable();
            $table->unsignedSmallInteger('height')->nullable();
            $table->string('hls_path')->nullable();
            $table->string('tus_id')->nullable()->index();
            // Deleting an admin keeps the media library they uploaded.
            $table->foreignId('uploaded_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->index(['status', 'created_at']);
            $table->index('created_at');
        });

        $driver = Schema::getConnection()->getDriverName();

        if ($driver === 'mysql') {
            Schema::table('media_items', function (Blueprint $table) {
                $table->fullText('search_text');
            });
        }

        if ($driver === 'pgsql') {
            Schema::table('media_items', function (Blueprint $table) {
                $table->fullText('search_text')->language('english');
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('media_items');
    }
};
