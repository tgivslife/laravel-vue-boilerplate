<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('magic_link_tokens', function (Blueprint $table) {
            $table->id();

            // Nullable because self-provisioning tokens (magic_link.provision) are minted before the
            // account exists: they carry the target email instead, and the account is created at consumption.
            $table->foreignId('user_id')->nullable()->constrained()->cascadeOnDelete();
            $table->string('email')->nullable();

            // Separates the self-serve login door ('login') from admin invitations ('invitation'),
            // which share the token machinery but answer to their own feature switch (security.invitations).
            $table->string('purpose')->default('login');

            // Keyed HMAC of the secret; the raw token is never stored, so a
            // leaked database alone cannot recognize or forge a valid link.
            $table->string('token_hash', 64)->index();

            $table->timestamp('expires_at')->index();
            $table->timestamp('consumed_at')->nullable();

            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('magic_link_tokens');
    }
};
