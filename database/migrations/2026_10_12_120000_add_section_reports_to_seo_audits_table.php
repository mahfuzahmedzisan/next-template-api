<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('seo_audits', function (Blueprint $table) {
            $table->string('scope', 16)->default('full')->after('trigger');
            $table->json('site_checks')->nullable()->after('summary');
            $table->json('robots_report')->nullable()->after('site_checks');
            $table->json('sitemap_report')->nullable()->after('robots_report');
        });
    }

    public function down(): void
    {
        Schema::table('seo_audits', function (Blueprint $table) {
            $table->dropColumn(['scope', 'site_checks', 'robots_report', 'sitemap_report']);
        });
    }
};
