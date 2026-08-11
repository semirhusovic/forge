<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->text('github_token')->nullable();
            $table->string('github_login')->nullable();
            $table->string('github_avatar_url')->nullable();
            $table->timestamp('github_connected_at')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn(['github_token', 'github_login', 'github_avatar_url', 'github_connected_at']);
        });
    }
};
