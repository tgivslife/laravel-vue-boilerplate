<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    /**
     * Per-record / per-class required-permission rules.
     *
     * A row means: performing `type` on the protectable (a specific record,
     * or the whole class when protectable_id is null) requires holding the
     * referenced permission, combined per group by `mode` (all|any).
     */
    public function up(): void
    {
        Schema::create('required_permissions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('permission_id')->constrained('permissions')->cascadeOnDelete();
            $table->string('protectable_type');
            $table->unsignedBigInteger('protectable_id')->nullable();
            $table->string('type');
            $table->string('mode')->default('all');
            $table->timestamps();

            $table->index(['protectable_type', 'protectable_id', 'type']);
        });

        /*
         * SQL unique indexes treat NULLs as distinct, so a single 4-column
         * unique would not dedupe class-level rows (protectable_id null).
         * Partial indexes cover both shapes; the WHERE syntax is supported
         * by PostgreSQL and SQLite (the project's two drivers).
         */
        DB::statement(
            'CREATE UNIQUE INDEX required_permissions_class_unique
             ON required_permissions (permission_id, protectable_type, type)
             WHERE protectable_id IS NULL'
        );

        DB::statement(
            'CREATE UNIQUE INDEX required_permissions_instance_unique
             ON required_permissions (permission_id, protectable_type, protectable_id, type)
             WHERE protectable_id IS NOT NULL'
        );
    }

    public function down(): void
    {
        Schema::dropIfExists('required_permissions');
    }
};
