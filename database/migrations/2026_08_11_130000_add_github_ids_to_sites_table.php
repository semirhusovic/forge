<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('sites', function (Blueprint $table) {
            // Null means the panel did not create the resource — an existing
            // site, or a provisioning step that failed. Teardown skips those.
            $table->unsignedBigInteger('github_key_id')->nullable();
            $table->unsignedBigInteger('github_hook_id')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('sites', function (Blueprint $table) {
            $table->dropColumn(['github_key_id', 'github_hook_id']);
        });
    }
};
