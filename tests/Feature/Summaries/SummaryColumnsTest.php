<?php

// tests/Feature/Summaries/SummaryColumnsTest.php

use App\Enums\SummaryStatus;
use App\Models\Proposal;
use Database\Seeders\RoleSeeder;
use Illuminate\Support\Carbon;

describe('summary columns', function () {

    beforeEach(fn () => test()->seed(RoleSeeder::class));

    it('defaults to no summary at all', function () {
        // Given / When — a proposal created before the job has run
        $proposal = Proposal::factory()->create();

        // Then — null, not an empty string and not a status. A proposal that
        // has never been queued is a different state from one that ran and
        // produced nothing.
        expect($proposal->summary)->toBeNull()
            ->and($proposal->summary_status)->toBeNull()
            ->and($proposal->summary_generated_at)->toBeNull();
    });

    it('casts the status to the enum and the timestamp to a date', function () {
        // Given
        $proposal = Proposal::factory()->create();

        // When
        $proposal->forceFill([
            'summary' => 'A talk about observability budgets.',
            'summary_status' => SummaryStatus::Ready,
            'summary_generated_at' => now(),
        ])->save();

        // Then
        $fresh = $proposal->fresh();
        expect($fresh->summary_status)->toBe(SummaryStatus::Ready)
            ->and($fresh->summary_generated_at)->toBeInstanceOf(Carbon::class)
            ->and($fresh->summary)->toBe('A talk about observability budgets.');
    });

    it('refuses to mass-assign any summary column', function () {
        // Given — THE test of this task. The summary is a reading aid written
        // by the system for reviewers; a speaker who could set it through the
        // proposal form would be writing their own review aid.
        $proposal = Proposal::factory()->create();

        // When
        $proposal->fill([
            'title' => 'A legitimately editable field',
            'summary' => 'Injected by the author.',
            'summary_status' => SummaryStatus::Ready->value,
        ]);

        // Then
        expect($proposal->title)->toBe('A legitimately editable field')
            ->and($proposal->summary)->toBeNull()
            ->and($proposal->summary_status)->toBeNull();
    });

    it('stores every status the enum defines', function () {
        // Given / When / Then — the column is string(16); a case that does not
        // fit would truncate silently rather than error.
        foreach (SummaryStatus::cases() as $case) {
            $proposal = Proposal::factory()->create();
            $proposal->forceFill(['summary_status' => $case])->save();

            expect($proposal->fresh()->summary_status)->toBe($case);
        }
    });
});
