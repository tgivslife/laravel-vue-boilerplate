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
        Schema::create('users', function (Blueprint $table) {
            $table->id();

            $table->string('email')->unique();
            $table->timestamp('email_verified_at')->nullable();
            $table->string('first_name');
            $table->string('last_name');

            $table->string('password')->nullable();
            $table->timestamp('password_changed_at')->nullable();
            $table->boolean('require_password_reset')->default(false);

            $table->boolean('is_active')->default(true);
            $table->timestamp('banned_at')->nullable();
            $table->string('ban_reason')->nullable();

            // Deleted accounts stop holding their email in the unique index: the address is tombstoned
            // to {uuid}@deleted.invalid and only its keyed hash is kept, for membership lookups.
            $table->string('deleted_email_hash', 64)->nullable()->index();

            /*
             * TOTP two-factor state. The secret is set when enrollment starts but only counts once
             * `two_factor_confirmed_at` proves the user's authenticator produced a valid code.
             * Recovery codes are stored as a JSON array of bcrypt hashes;
             * `two_factor_last_verified_step` is the replay guard that makes a verified code single-use
             * within its 30-second time step.
             * `two_factor_required` is the administrative enrollment mandate: a flagged account may
             * authenticate but reaches nothing except the enrollment endpoints until a confirmed
             * enrollment exists.
             */
            $table->text('two_factor_secret')->nullable();
            $table->text('two_factor_recovery_codes')->nullable();
            $table->timestamp('two_factor_confirmed_at')->nullable();
            $table->unsignedBigInteger('two_factor_last_verified_step')->nullable();
            $table->boolean('two_factor_required')->default(false);

            $table->timestamp('last_login_at')->nullable();
            $table->string('last_login_ip', 45)->nullable();

            // When the inactivity pre-notice was mailed; cleared on sign-in. An account is only
            // auto-closed after this stamp has aged past the configured notice window.
            $table->timestamp('inactivity_notice_sent_at')->nullable();

            $table->rememberToken();
            $table->timestamps();
            $table->softDeletes();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('users');
    }
};
