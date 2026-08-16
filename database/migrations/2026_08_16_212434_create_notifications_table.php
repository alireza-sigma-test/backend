<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('notifications', function (Blueprint $table) {
            $table->uuid('id')->primary();
            // Laravel's own column: it holds the notification CLASS name and is
            // load-bearing for the framework's deserialisation. It is NOT the
            // `type` docs/design/API.md §06 documents — that is the event
            // vocabulary (`proposal.created`, …) and lives inside `data`, which
            // is why each Notification's toArray() states its own.
            $table->string('type');
            $table->morphs('notifiable');
            $table->text('data');
            $table->timestamp('read_at')->nullable();
            $table->timestamps();

            // morphs() indexes (notifiable_type, notifiable_id), which serves
            // the list. This one serves the badge: the unread count runs on
            // every page load for every signed-in user, and it is the only
            // query here with a predicate the morph index does not cover.
            $table->index(['notifiable_type', 'notifiable_id', 'read_at'], 'notifications_unread_index');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('notifications');
    }
};
