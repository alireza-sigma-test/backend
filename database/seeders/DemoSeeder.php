<?php

namespace Database\Seeders;

use App\Enums\ProposalStatus;
use App\Enums\SummaryStatus;
use App\Models\Proposal;
use App\Models\ProposalStatusChange;
use App\Models\Review;
use App\Models\Tag;
use App\Models\User;
use Illuminate\Database\Seeder;

class DemoSeeder extends Seeder
{
    /*
     * Every `summary` below is hand-written demo copy, not model output — it exists so
     * the feature can be seen working without an API key. Do not read it as evidence
     * the AI path ran, and do not use it to judge summary quality.
     */
    /** @return array<int, array{0:string,1:string,2:ProposalStatus,3:array<int,string>,4:string}> */
    public static function proposalRows(): array
    {
        return [
            ['Observability at scale without the bill', 'Dana Roth',   ProposalStatus::Pending,  ['Technology', 'Architecture'],
                'Covers cardinality control, sampling strategy and the specific cuts behind a halved metrics bill. Aimed at platform engineers who already run a metrics pipeline. The cost figures and the sampling thresholds are concrete; the section on team process is described only in general terms.'],
            ['Type-safe APIs end to end',               'Ilya Petrov', ProposalStatus::Pending,  ['Technology'],
                'Walks a typed contract from an OpenAPI document through a generated client to compile-time checks in the consumer. For teams maintaining an API and at least one first-party client. The generation toolchain is named; the migration path for an existing untyped client is asserted rather than shown.'],
            ['Testing the untestable',                  'Dana Roth',   ProposalStatus::Pending,  ['Testing'],
                'A talk about getting legacy code under test without a rewrite, using seams and characterization tests. Aimed at engineers inheriting a system with no suite. The refactoring techniques are specific; the claimed defect-rate improvement is stated without a baseline.'],
            ['Designing for slow networks',             'Ilya Petrov', ProposalStatus::Approved, ['Design', 'Technology'],
                'Covers perceived-performance techniques for high-latency and intermittent connections: optimistic UI, request coalescing and offline queues. For front-end engineers and designers shipping to emerging markets. The latency budgets are concrete; the accessibility implications are mentioned but not developed.'],
            ['Health data at the edge',                 'Nia Okafor',  ProposalStatus::Approved, ['Health', 'Architecture'],
                'Describes processing patient telemetry on-device to avoid moving identifiable data, and the regulatory constraints that forced the design. For architects working under HIPAA or GDPR. The data-residency rules and the on-device model size are specific; the hardware cost analysis is only sketched.'],
            ['Why we left microservices',               'Nia Okafor',  ProposalStatus::Rejected, ['Architecture', 'Business'],
                'An account of consolidating a fourteen-service estate back into three deployables, with the operational metrics before and after. For teams weighing a similar consolidation. The deployment and on-call figures are concrete; the organisational half of the story rests on one team\'s experience.'],
        ];
    }

    public function run(): void
    {
        // `make up` calls db:seed unconditionally, so without this guard a second run
        // against an existing volume dies on the unique index over users.email.
        if (User::query()->exists()) {
            return;
        }

        $speakers = collect([
            'Dana Roth' => 'dana@example.com',
            'Ilya Petrov' => 'ilya@example.com',
            'Nia Okafor' => 'nia@example.com',
        ])->map(fn ($email, $name) => User::factory()->speaker()->create([
            'name' => $name, 'email' => $email,
        ]));

        $reviewers = collect([
            'Maya Kessler' => 'maya@example.com',
            'Jonas Adeyemi' => 'jonas@example.com',
            'Sofia Lindqvist' => 'sofia@example.com',
            'Theo Nakamura' => 'theo@example.com',
        ])->map(fn ($email, $name) => User::factory()->reviewer()->create([
            'name' => $name, 'email' => $email,
        ]));

        $admin = User::factory()->admin()->create([
            'name' => 'Alex Vance', 'email' => 'alex@example.com',
        ]);

        $tags = collect(['Technology', 'Architecture', 'Health', 'Business', 'Design', 'Testing'])
            ->mapWithKeys(fn (string $name) => [$name => Tag::create(['name' => $name])]);

        $rows = self::proposalRows();

        foreach ($rows as [$title, $author, $status, $tagNames, $summary]) {
            $proposal = Proposal::create([
                'user_id' => $speakers[$author]->id,
                'title' => $title,
                'description' => "A concrete, numbers-first talk about {$title}. "
                    .'It names the thing we learned rather than the topic area, brings before-and-after '
                    .'figures from production, and closes with the one sentence on who benefits.',
                'status' => $status,
            ]);

            // forceFill: the summary columns are deliberately not fillable.
            $proposal->forceFill([
                'summary' => $summary,
                'summary_status' => SummaryStatus::Ready,
                'summary_generated_at' => now(),
            ])->save();

            $proposal->tags()->attach($tags->only($tagNames)->pluck('id'));
        }

        // The detail screen shows this proposal with three reviews averaging 4.0.
        $flagship = Proposal::where('title', 'Observability at scale without the bill')->firstOrFail();

        // Distinct per reviewer: screen 04 renders all three together.
        $reviewRows = [
            ['Jonas Adeyemi', 4, 'Strong and specific. The cost breakdown is the part I would put on the main stage; the opening could be tighter.'],
            ['Sofia Lindqvist', 5, 'Concrete, numbers-first, and the spreadsheet giveaway makes it actionable. Main stage.'],
            ['Theo Nakamura', 3, 'Good material, but the second half restates the first. Needs a sharper closing claim.'],
        ];

        foreach ($reviewRows as [$name, $rating, $comment]) {
            Review::create([
                'proposal_id' => $flagship->id,
                'user_id' => $reviewers->firstWhere('name', $name)->id,
                'rating' => $rating,
                'comment' => $comment,
            ]);
        }

        // Without this the attachment feature is unexercised by seeded data. The %PDF
        // signature is required: Media Library sniffs bytes, not the filename.
        $flagship->addMediaFromString("%PDF-1.4\n%demo seed attachment\n".str_repeat('a', 200))
            ->usingFileName('observability-at-scale-slides.pdf')
            ->toMediaCollection(Proposal::ATTACHMENT_COLLECTION);

        // A decided proposal with zero reviews is data the app's own workflow could
        // never produce, so give this one the reviews that would have supported it.
        $approved = Proposal::where('title', 'Designing for slow networks')->firstOrFail();

        $approvedReviewRows = [
            ['Maya Kessler', 4, 'Solid, practical patterns for constrained connections. Ready for the schedule.'],
            ['Sofia Lindqvist', 5, 'The offline-first walkthrough alone is worth the slot.'],
        ];

        foreach ($approvedReviewRows as [$name, $rating, $comment]) {
            Review::create([
                'proposal_id' => $approved->id,
                'user_id' => $reviewers->firstWhere('name', $name)->id,
                'rating' => $rating,
                'comment' => $comment,
            ]);
        }

        // The rows ChangeProposalStatus would have written, so /history is not empty
        // for the two proposals that already carry a decision.
        $rejected = Proposal::where('title', 'Why we left microservices')->firstOrFail();

        ProposalStatusChange::create([
            'proposal_id' => $approved->id,
            'from' => ProposalStatus::Pending,
            'to' => ProposalStatus::Approved,
            'changed_by' => $admin->id,
        ]);

        ProposalStatusChange::create([
            'proposal_id' => $rejected->id,
            'from' => ProposalStatus::Pending,
            'to' => ProposalStatus::Rejected,
            'note' => 'Overlaps heavily with an already-accepted talk on distributed tracing.',
            'changed_by' => $admin->id,
        ]);
    }
}
