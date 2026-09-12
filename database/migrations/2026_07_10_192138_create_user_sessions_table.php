<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    /**
     * Run the migrations.
     *
     * App-owned registry of which sessions belong to which user, so sessions can be listed and revoked on any
     * session driver (redis stores them as opaque keys with no user index).
     * The row's id is the session's identity, carried in the session payload; `session_id` is the id the browser
     * holds right now, stored raw because revoking means handing the real id to the driver's handler.
     * `remembered` marks a browser holding a remember-me cookie, whose revocation must rotate the remember token too.
     * `revoked_at` keeps a revoked row as a tombstone, so a copy a running request writes back late is signed out rather than taken for a new session.
     */
    public function up(): void
    {
        Schema::create('user_sessions', function (Blueprint $table) {
            $table->id();

            $table->foreignId('user_id')->index()->constrained()->cascadeOnDelete();
            $table->string('session_id', 100)->unique();
            $table->string('ip_address', 45)->nullable();
            $table->text('user_agent')->nullable();
            $table->boolean('remembered')->default(false);
            $table->integer('last_activity')->index();
            $table->timestamp('revoked_at')->nullable();

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
