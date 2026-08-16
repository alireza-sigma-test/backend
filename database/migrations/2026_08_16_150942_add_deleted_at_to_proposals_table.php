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
        Schema::table('proposals', function (Blueprint $table): void {
            $table->softDeletes();
        });
    }

    /**
     * Reverse the migrations.
     *
     * Not a no-op on a populated database: dropping the column makes every
     * soft-deleted proposal visible again across the whole API, so rolling
     * this back is a content change, not just a schema one. (`up()` is safe
     * — MySQL 8.4 adds a trailing nullable column INSTANT.)
     */
    public function down(): void
    {
        Schema::table('proposals', function (Blueprint $table): void {
            $table->dropSoftDeletes();
        });
    }
};
