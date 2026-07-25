<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    /**
     * Run the migrations.
     *
     * Links between local accounts and external identity providers (OIDC).
     * Only the opaque `sub` claim is persisted - never CNP, addresses, or
     * other identity-document claims the providers transmit: data
     * minimization is deliberate, and the subject is all that matching
     * requires.
     */
    public function up(): void
    {
        Schema::create('user_identities', function (Blueprint $table) {
            $table->id();

            $table->foreignId('user_id')->index()->constrained()->cascadeOnDelete();
            $table->string('provider', 32);
            $table->string('subject');
            $table->timestamp('last_used_at')->nullable();

            $table->unique(['provider', 'subject']);

            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('user_identities');
    }
};
