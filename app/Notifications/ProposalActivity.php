<?php

// app/Notifications/ProposalActivity.php

namespace App\Notifications;

use App\Models\Proposal;
use App\Models\User;
use Illuminate\Notifications\Notification;

/**
 * Shared shape for the four persisted notification types, whose payload is
 * fixed in docs/design/API.md §06.
 *
 * **`database` only, never `broadcast`.** The live push is already handled by
 * the App\Events\* classes; adding the broadcast channel here would deliver
 * every event to the client twice, once as an event and once as a notification,
 * and the two would race. The division is: events are the push, this table is
 * the record.
 *
 * The `type` below is the API.md event vocabulary. It deliberately does not
 * come from the `notifications.type` column, which Laravel fills with this
 * class's own FQCN and needs for its deserialisation — see the migration.
 */
abstract class ProposalActivity extends Notification
{
    public function __construct(
        protected readonly Proposal $proposal,
        protected readonly User $actor,
    ) {}

    /** @return array<int, string> */
    public function via(object $notifiable): array
    {
        return ['database'];
    }

    /** The API.md §06 vocabulary. */
    abstract public function type(): string;

    abstract protected function title(): string;

    abstract protected function body(): string;

    /** @return array<string, mixed> */
    final public function toArray(object $notifiable): array
    {
        return [
            'type' => $this->type(),
            'title' => $this->title(),
            'body' => $this->body(),
            'proposal_id' => $this->proposal->id,
        ];
    }

    /**
     * `“Title” — Actor Name`, the body format API.md §06 gives as its example.
     *
     * Typographic quotes, not the straight ones API.md happens to escape into
     * its own markdown: this string is rendered as prose beside the app's own
     * copy, which uses curly quotes and apostrophes throughout (and so does
     * the screen 06 mockup this panel is built from).
     */
    protected function quoted(): string
    {
        return sprintf('“%s” — %s', $this->proposal->title, $this->actor->name);
    }
}
