<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('proposals', function (Blueprint $table): void {
            $table->softDeletes();
        });
    }

    /**
     * Not a no-op on a populated database: dropping the column makes every
     * soft-deleted proposal visible again, so rolling back is a content change.
     */
    public function down(): void
    {
        Schema::table('proposals', function (Blueprint $table): void {
            $table->dropSoftDeletes();
        });
    }
};
