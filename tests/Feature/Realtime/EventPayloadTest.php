<?php

// tests/Feature/Realtime/EventPayloadTest.php

use App\Events\ProposalBroadcast;
use App\Events\ProposalCreated;
use App\Events\ProposalStatusChanged;
use App\Events\ProposalUpdated;
use App\Events\ReviewCreated;
use App\Models\Proposal;
use App\Models\User;
use Database\Seeders\RoleSeeder;

/**
 * The regression guard against someone later "just adding one field".
 *
 * These events go to role channels. A key added here is a key delivered to
 * every reviewer or every admin, and unlike an API response there is no policy
 * between the payload and the subscriber — authorization happened once, when
 * they subscribed. So the assertion is the exact key set, not a subset: adding
 * `average_rating`, or the author's email, or a `can` block, must fail here
 * loudly rather than ship quietly.
 */
describe('broadcast payloads', function () {

    beforeEach(function () {
        $this->seed(RoleSeeder::class);
    });

    $makeEvent = function (string $class): ProposalBroadcast {
        $author = User::factory()->speaker()->create();
        $actor = User::factory()->reviewer()->create(['name' => 'Maya Okonkwo']);
        $proposal = Proposal::factory()->create(['user_id' => $author->id]);

        return new $class($proposal, $actor);
    };

    it('carries exactly the documented top-level keys', function () use ($makeEvent) {
        foreach ([ProposalCreated::class, ProposalUpdated::class, ProposalStatusChanged::class, ReviewCreated::class] as $class) {
            // Given / When
            $payload = $makeEvent($class)->broadcastWith();

            // Then
            expect(array_keys($payload))
                ->toEqualCanonicalizing(['type', 'proposal', 'actor', 'occurred_at'], $class);
        }
    });

    it('carries exactly four proposal fields and three actor fields', function () use ($makeEvent) {
        foreach ([ProposalCreated::class, ProposalUpdated::class, ProposalStatusChanged::class, ReviewCreated::class] as $class) {
            // Given / When
            $payload = $makeEvent($class)->broadcastWith();

            // Then — no description, no tags, no author block, no counts,
            // no `can`, no `my_review`.
            expect(array_keys($payload['proposal']))
                ->toEqualCanonicalizing(['id', 'ref', 'title', 'status'], $class)
                ->and(array_keys($payload['actor']))
                ->toEqualCanonicalizing(['id', 'name', 'initials'], $class);
        }
    });

    it('names each type from the API.md vocabulary', function () use ($makeEvent) {
        // Given / When / Then — the wire name and the payload's `type` are the
        // same string, so a client can bind by event name and still read it.
        $expected = [
            ProposalCreated::class => 'proposal.created',
            ProposalUpdated::class => 'proposal.updated',
            ProposalStatusChanged::class => 'proposal.status_changed',
            ReviewCreated::class => 'review.created',
        ];

        foreach ($expected as $class => $type) {
            $event = $makeEvent($class);

            expect($event->broadcastWith()['type'])->toBe($type)
                ->and($event->broadcastAs())->toBe($type);
        }
    });

    it('addresses each event to the channels API.md names', function () use ($makeEvent) {
        // Given
        $names = fn (ProposalBroadcast $e) => array_map(fn ($c) => (string) $c, $e->broadcastOn());

        // When / Then
        expect($names($makeEvent(ProposalCreated::class)))
            ->toEqualCanonicalizing(['private-role.reviewer', 'private-role.admin'])
            ->and($names($makeEvent(ProposalUpdated::class)))
            ->toEqualCanonicalizing(['private-role.reviewer']);

        // The two author-addressed events resolve the channel from the
        // proposal's owner, never from whoever triggered them.
        $author = User::factory()->speaker()->create();
        $actor = User::factory()->reviewer()->create();
        $proposal = Proposal::factory()->create(['user_id' => $author->id]);

        expect($names(new ProposalStatusChanged($proposal, $actor)))
            ->toEqualCanonicalizing(["private-user.{$author->id}"])
            ->and($names(new ReviewCreated($proposal, $actor)))
            ->toEqualCanonicalizing(["private-user.{$author->id}", 'private-role.admin']);
    });

    it('reports the actor, not the author', function () {
        // Given — an admin deciding a speaker's proposal. Getting this backwards
        // would credit the decision to the person it was made about.
        $author = User::factory()->speaker()->create(['name' => 'Dana Levy']);
        $admin = User::factory()->admin()->create(['name' => 'Alex Rivera']);
        $proposal = Proposal::factory()->create(['user_id' => $author->id]);

        // When
        $payload = (new ProposalStatusChanged($proposal, $admin))->broadcastWith();

        // Then
        expect($payload['actor']['id'])->toBe($admin->id)
            ->and($payload['actor']['name'])->toBe('Alex Rivera')
            ->and($payload['actor']['initials'])->toBe('AR');
    });
});
