<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('seo_pages', function (Blueprint $table) {
            $table->id();
            $table->string('path')->unique();
            $table->string('label', 120);
            $table->string('page_type', 30)->default('other');
            $table->boolean('is_system')->default(false);

            $table->string('meta_title')->nullable();
            $table->text('meta_description')->nullable();
            $table->json('keywords')->nullable();
            $table->string('author', 120)->nullable();
            $table->string('canonical_url', 2048)->nullable();
            $table->boolean('robots_index')->default(true);
            $table->boolean('robots_follow')->default(true);
            $table->string('max_image_preview', 16)->default('large');
            $table->string('og_title')->nullable();
            $table->text('og_description')->nullable();
            $table->string('og_image', 2048)->nullable();
            $table->string('og_image_alt')->nullable();
            $table->string('og_type', 20)->nullable();
            $table->string('og_locale', 20)->nullable();
            $table->string('twitter_card', 30)->nullable();
            $table->string('twitter_title')->nullable();
            $table->text('twitter_description')->nullable();
            $table->string('twitter_image', 2048)->nullable();
            $table->string('schema_type', 40)->nullable();
            $table->boolean('in_sitemap')->default(true);
            $table->decimal('sitemap_priority', 2, 1)->nullable();
            $table->string('sitemap_changefreq', 10)->nullable();
            $table->string('title_affix_mode', 10)->default('inherit');
            $table->string('title_prefix', 120)->nullable();
            $table->string('title_suffix', 120)->nullable();

            $table->unsignedSmallInteger('last_status')->nullable();
            $table->string('redirect_to', 2048)->nullable();
            $table->timestamp('last_checked_at')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('seo_pages');
    }
};
