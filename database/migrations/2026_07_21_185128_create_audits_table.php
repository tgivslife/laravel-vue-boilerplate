<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    /**
     * Attribute-level audit trail (owen-it/laravel-auditing): created/updated/deleted/restored
     * diffs for any model carrying the Auditable trait, complementing the intent-level
     * access_audit_logs trail.
     *
     * The package stub plus one extra column: impersonator_id records the administrator whose
     * session was borrowed when a write happened mid-impersonation - the target stays the user
     * morph, so the trail names both parties instead of borrowing the actor.
     */
    public function up(): void
    {
        Schema::create('audits', function (Blueprint $table) {
            $table->id();
            $table->string('user_type')->nullable();
            $table->unsignedBigInteger('user_id')->nullable();
            $table->foreignId('impersonator_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('event');
            $table->morphs('auditable');
            $table->json('old_values')->nullable();
            $table->json('new_values')->nullable();
            $table->text('url')->nullable();
            $table->ipAddress('ip_address')->nullable();
            $table->string('user_agent', 1023)->nullable();
            $table->string('tags')->nullable();
            $table->timestamps();

            $table->index(['user_id', 'user_type']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('audits');
    }
};
