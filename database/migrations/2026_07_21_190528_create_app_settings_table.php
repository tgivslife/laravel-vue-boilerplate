<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    /**
     * Admin-editable app-level settings: overrides only. A key missing from
     * this table resolves to its default in the closed registry at
     * config/settings.php (app); resetting a key to its default deletes the
     * row. Every write is recorded in the attribute-level audit trail
     * (the AppSetting model is Auditable).
     */
    public function up(): void
    {
        Schema::create('app_settings', function (Blueprint $table) {
            $table->id();
            $table->string('key')->unique();
            $table->json('value')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('app_settings');
    }
};
