<?php

// tests/Feature/Summaries/SummaryVisibilityTest.php

use App\Enums\SummaryStatus;
use App\Models\Proposal;
use App\Models\User;
use Database\Seeders\RoleSeeder;

describe('summary visibility', function () {

    beforeEach(function () {
        $this->seed(RoleSeeder::class);

        $this->author = User::factory()->speaker()->create();
        $this->proposal = Proposal::factory()->create(['user_id' => $this->author->id]);
        $this->proposal->forceFill([
            'summary' => 'Covers cardinality control and sampling; the cost figures are concrete.',
            'summary_status' => SummaryStatus::Ready,
            'summary_generated_at' => now(),
        ])->save();
    });

    it('shows the summary to a reviewer', function () {
        // Given
        $maya = User::factory()->reviewer()->create();

        // When
        $response = $this->actingAs($maya)->getJson("/api/proposals/{$this->proposal->id}");

        // Then
        $response->assertOk()
            ->assertJsonPath('summary', 'Covers cardinality control and sampling; the cost figures are concrete.')
            ->assertJsonPath('summary_status', 'ready')
            ->assertJsonPath('can.view_summary', true);
    });

    it('shows the summary to an admin', function () {
        // Given
        $alex = User::factory()->admin()->create();

        // When / Then
        $this->actingAs($alex)->getJson("/api/proposals/{$this->proposal->id}")
            ->assertOk()
            ->assertJsonPath('summary_status', 'ready')
            ->assertJsonPath('can.view_summary', true);
    });

    it('hides the summary from the proposal author', function () {
        // Given — THE test of this task. The summary is a reading aid for the
        // people evaluating the proposal. Showing it to the author invites
        // them to write for the summarizer instead of for the reviewer.

        // When
        $response = $this->actingAs($this->author)->getJson("/api/proposals/{$this->proposal->id}");

        // Then — the keys are ABSENT, not null. A null `summary` would still
        // tell the author that a summary of their proposal exists, which is
        // most of what this is protecting.
        $response->assertOk()
            ->assertJsonMissingPath('summary')
            ->assertJsonMissingPath('summary_status')
            ->assertJsonPath('can.view_summary', false);
    });

    it('hides the summary from an unverified reviewer', function () {
        // Given — consistent with every other gate in this policy: an
        // unverified account has cleared nothing yet, and this is not the
        // exception that lets it read staff-only material.
        $unverified = User::factory()->unverified()->reviewer()->create();

        // When / Then
        $this->actingAs($unverified)->getJson("/api/proposals/{$this->proposal->id}")
            ->assertOk()
            ->assertJsonMissingPath('summary')
            ->assertJsonMissingPath('summary_status');
    });

    it('hides the summary from a reviewer who wrote the proposal', function () {
        // Given — reviewers can submit talks too, and the author rule has to
        // beat the staff rule rather than the other way round.
        $reviewerAuthor = User::factory()->reviewer()->create();
        $own = Proposal::factory()->create(['user_id' => $reviewerAuthor->id]);
        $own->forceFill(['summary' => 'x', 'summary_status' => SummaryStatus::Ready])->save();

        // When / Then
        $this->actingAs($reviewerAuthor)->getJson("/api/proposals/{$own->id}")
            ->assertOk()
            ->assertJsonMissingPath('summary')
            ->assertJsonPath('can.view_summary', false);
    });

    it('omits the keys in the list endpoint too, not only on the detail page', function () {
        // Given — the same resource renders both. A gate applied on one and
        // forgotten on the other is the classic version of this bug.

        // When
        $response = $this->actingAs($this->author)->getJson('/api/proposals');

        // Then
        $response->assertOk()->assertJsonMissingPath('data.0.summary');

        // And the staff view does carry it, so the assertion above is not
        // passing because the list simply never includes summaries.
        $maya = User::factory()->reviewer()->create();
        $this->actingAs($maya)->getJson('/api/proposals')
            ->assertOk()
            ->assertJsonPath('data.0.summary_status', 'ready');
    });

    it('reports a pending or unavailable status to staff without a summary', function () {
        // Given — the client needs to tell "being summarized" from "switched
        // off", and both arrive with summary === null.
        $maya = User::factory()->reviewer()->create();
        $this->proposal->forceFill([
            'summary' => null,
            'summary_status' => SummaryStatus::Unavailable,
        ])->save();

        // When / Then
        $this->actingAs($maya)->getJson("/api/proposals/{$this->proposal->id}")
            ->assertOk()
            ->assertJsonPath('summary', null)
            ->assertJsonPath('summary_status', 'unavailable');
    });
});
