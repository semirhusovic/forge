<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Certbot output previously shared the provision_log column with the
     * install pipeline, so the SSL tab rendered git clone / composer output
     * alongside (or instead of) certificate output.
     */
    public function up(): void
    {
        Schema::table('sites', function (Blueprint $table) {
            $table->text('ssl_log')->nullable()->after('provision_log');
        });
    }

    public function down(): void
    {
        Schema::table('sites', function (Blueprint $table) {
            $table->dropColumn('ssl_log');
        });
    }
};
