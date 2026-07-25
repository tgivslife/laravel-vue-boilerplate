<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    /**
     * Run the migrations.
     *
     * One row per login episode (successful or failed). Rows carry their own
     * login_at/logout_at lifecycle instead of created_at/updated_at, and are
     * pruned by auth:purge-authentication-logs after the configured
     * retention period.
     */
    public function up(): void
    {
        Schema::create('authentication_logs', function (Blueprint $table) {
            $table->id();
            $table->morphs('authenticatable');
            $table->string('ip_address', 45)->nullable();
            $table->text('user_agent')->nullable();
            $table->string('device_id', 64)->nullable()->index();
            $table->string('device_name', 1024)->nullable();
            $table->timestamp('login_at')->nullable()->index();
            $table->boolean('login_successful')->default(false);
            // Which mechanism the attempt came through (LoginMethod enum values).
            // Nullable: remember-me recaller re-logins have no declared method.
            $table->string('login_method', 32)->nullable();
            $table->timestamp('logout_at')->nullable();
            $table->timestamp('last_activity_at')->nullable();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('authentication_logs');
    }
};
