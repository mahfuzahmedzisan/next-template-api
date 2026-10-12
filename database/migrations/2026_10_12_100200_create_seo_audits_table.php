<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('seo_audits', function (Blueprint $table) {
            $table->id();
            $table->string('status', 20)->default('queued')->index();
            $table->string('trigger', 20)->default('manual');
            $table->foreignId('triggered_by')->nullable()->constrained('users')->nullOnDelete();
            $table->string('site_url', 255)->nullable();
            $table->string('environment', 20)->nullable();
            $table->boolean('include_noindex')->default(false);
            $table->unsignedTinyInteger('score')->nullable();
            $table->unsignedInteger('pages_total')->default(0);
            $table->unsignedInteger('pages_scanned')->default(0);
            $table->unsignedInteger('pages_failed')->default(0);
            $table->unsignedInteger('checks_passed')->default(0);
            $table->unsignedInteger('checks_failed')->default(0);
            $table->json('summary')->nullable();
            $table->text('error')->nullable();
            $table->timestamp('started_at')->nullable();
            $table->timestamp('finished_at')->nullable();
            $table->timestamps();
        });

        Schema::create('seo_audit_pages', function (Blueprint $table) {
            $table->id();
            $table->foreignId('seo_audit_id')->constrained('seo_audits')->cascadeOnDelete();
            $table->string('kind', 10);
            $table->string('page_type', 60);
            $table->string('label', 255);
            $table->string('url', 2048);
            $table->foreignId('seo_page_id')->nullable()->constrained('seo_pages')->nullOnDelete();
            $table->string('seoable_type', 60)->nullable();
            $table->unsignedBigInteger('seoable_id')->nullable();
            $table->boolean('indexable')->default(true);
            $table->unsignedSmallInteger('status_code')->nullable();
            $table->unsignedTinyInteger('score')->nullable();
            $table->unsignedSmallInteger('passed')->default(0);
            $table->unsignedSmallInteger('failed')->default(0);
            $table->unsignedInteger('ttfb_ms')->nullable();
            $table->unsignedInteger('html_bytes')->nullable();
            $table->json('checks')->nullable();
            $table->text('error')->nullable();
            $table->timestamps();

            $table->index(['seo_audit_id', 'score']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('seo_audit_pages');
        Schema::dropIfExists('seo_audits');
    }
};
