<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Existing sites were provisioned before per-site PHP versions existed;
     * they backfill to the panel default. Their already-written vhosts, cron
     * files, and worker units are untouched and keep working — the version
     * only affects artifacts generated from now on.
     */
    public function up(): void
    {
        Schema::table('sites', function (Blueprint $table) {
            $table->string('php_version', 8)->default(config('forge.default_php_version'));
        });
    }

    public function down(): void
    {
        Schema::table('sites', function (Blueprint $table) {
            $table->dropColumn('php_version');
        });
    }
};
