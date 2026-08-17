<?php

namespace App\Notifications;

use App\Models\Proposal;
use App\Models\User;
use Illuminate\Notifications\Notification;

/**
 * Shared shape for the four persisted notification types (docs/design/API.md §06).
 *
 * `database` only, never `broadcast`: App\Events\* is the live push, this table is
 * the record. Adding the broadcast channel would deliver everything twice.
 *
 * type() is the API.md vocabulary, not the `notifications.type` column — Laravel
 * fills that with this class's FQCN and needs it for deserialisation.
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

    /** `“Title” — Actor Name`, per API.md §06. Typographic quotes match the app's copy. */
    protected function quoted(): string
    {
        return sprintf('“%s” — %s', $this->proposal->title, $this->actor->name);
    }
}
