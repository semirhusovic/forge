<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('sites', function (Blueprint $table) {
            $table->id();
            $table->string('domain')->unique();
            $table->string('repository');
            $table->string('branch')->default('main');
            $table->string('root_path');
            $table->string('web_root_suffix')->default('/public');
            $table->string('status')->default('pending');
            $table->text('deploy_script');
            $table->boolean('auto_deploy')->default(true);
            $table->string('webhook_token', 64)->unique();
            $table->text('deploy_key_public')->nullable();
            $table->boolean('ssl_enabled')->default(false);
            $table->timestamp('ssl_expires_at')->nullable();
            $table->boolean('has_scheduler')->default(false);
            $table->text('provision_log')->nullable();
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('sites');
    }
};
