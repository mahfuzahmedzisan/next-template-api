<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('projects', function (Blueprint $table) {
            $table->id();
            $table->string('title');
            $table->string('slug', 160)->unique();
            $table->text('summary');
            $table->longText('body');
            $table->string('cover', 2048)->nullable();
            $table->json('tags')->nullable();
            $table->json('stack')->nullable();
            $table->string('client', 120)->nullable();
            $table->unsignedSmallInteger('year')->nullable();
            $table->string('live_url', 2048)->nullable();
            $table->string('source_url', 2048)->nullable();
            $table->boolean('featured')->default(false);
            $table->timestamp('published_at')->nullable();
            $table->integer('sort')->default(0);
            $table->timestamps();

            $table->index(['published_at', 'sort']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('projects');
    }
};
