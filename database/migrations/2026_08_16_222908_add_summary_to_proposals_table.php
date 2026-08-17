<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('proposals', function (Blueprint $table) {
            $table->text('summary')->nullable()->after('description');

            // String plus a PHP enum cast, like `status`: altering a native MySQL ENUM
            // rewrites the whole table.
            $table->string('summary_status', 16)->nullable()->after('summary');

            $table->timestamp('summary_generated_at')->nullable()->after('summary_status');
        });
    }

    public function down(): void
    {
        Schema::table('proposals', function (Blueprint $table) {
            $table->dropColumn(['summary', 'summary_status', 'summary_generated_at']);
        });
    }
};
