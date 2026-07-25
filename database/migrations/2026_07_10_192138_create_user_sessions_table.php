<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    /**
     * Run the migrations.
     *
     * App-owned registry of which sessions belong to which user, so the
     * settings UI can list and revoke them regardless of the session
     * driver (redis stores sessions as opaque keys with no user index).
     * Stores the raw session id - the same sensitivity the framework's own
     * database sessions table already accepts - because revoking a session
     * requires handing the real id to the driver's handler.
     */
    public function up(): void
    {
        Schema::create('user_sessions', function (Blueprint $table) {
            $table->id();

            $table->foreignId('user_id')->index()->constrained()->cascadeOnDelete();
            $table->string('session_id', 100)->unique();
            $table->string('ip_address', 45)->nullable();
            $table->text('user_agent')->nullable();
            $table->integer('last_activity')->index();

            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('user_sessions');
    }
};
