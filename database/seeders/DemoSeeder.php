<?php

// database/seeders/DemoSeeder.php

namespace Database\Seeders;

use App\Enums\ProposalStatus;
use App\Models\Proposal;
use App\Models\ProposalStatusChange;
use App\Models\Review;
use App\Models\Tag;
use App\Models\User;
use Illuminate\Database\Seeder;

class DemoSeeder extends Seeder
{
    public function run(): void
    {
        // `make up` runs `migrate --force --seed`, and Laravel calls db:seed
        // unconditionally — it does not check whether migrations actually ran.
        // Without this guard a second `make up` against an existing volume dies
        // on the unique index over users.email.
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

        $rows = [
            ['Observability at scale without the bill', 'Dana Roth',   ProposalStatus::Pending,  ['Technology', 'Architecture']],
            ['Type-safe APIs end to end',               'Ilya Petrov', ProposalStatus::Pending,  ['Technology']],
            ['Testing the untestable',                  'Dana Roth',   ProposalStatus::Pending,  ['Testing']],
            ['Designing for slow networks',             'Ilya Petrov', ProposalStatus::Approved, ['Design', 'Technology']],
            ['Health data at the edge',                 'Nia Okafor',  ProposalStatus::Approved, ['Health', 'Architecture']],
            ['Why we left microservices',               'Nia Okafor',  ProposalStatus::Rejected, ['Architecture', 'Business']],
        ];

        foreach ($rows as [$title, $author, $status, $tagNames]) {
            $proposal = Proposal::create([
                'user_id' => $speakers[$author]->id,
                'title' => $title,
                'description' => "A concrete, numbers-first talk about {$title}. "
                    .'It names the thing we learned rather than the topic area, brings before-and-after '
                    .'figures from production, and closes with the one sentence on who benefits.',
                'status' => $status,
            ]);

            $proposal->tags()->attach($tags->only($tagNames)->pluck('id'));
        }

        // The detail screen shows this proposal with three reviews averaging 4.0.
        $flagship = Proposal::where('title', 'Observability at scale without the bill')->firstOrFail();

        // Distinct comments per reviewer: screen 04 renders all three together,
        // and three identical paragraphs read as placeholder data.
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

        // Without this, AttachmentStore, the private-disk signed URL and the
        // whole attachment feature never appear to anyone who just runs the
        // app — the seeded data has no way to exercise them. Content must
        // start with the real %PDF signature: Media Library sniffs actual
        // bytes, not the filename, so anything else is rejected by the
        // collection's acceptsMimeTypes(['application/pdf']).
        $flagship->addMediaFromString("%PDF-1.4\n%demo seed attachment\n".str_repeat('a', 200))
            ->usingFileName('observability-at-scale-slides.pdf')
            ->toMediaCollection(Proposal::ATTACHMENT_COLLECTION);

        // REVIEW_MIN_REVIEWS_TO_DECIDE defaults to 2, yet every decided
        // proposal here had zero reviews — data the app's own workflow could
        // never produce. Give one approved proposal the reviews that would
        // have supported its decision, then record the decision itself below.
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

        // The audit trail (/history, once built) is otherwise empty even
        // though two proposals already carry a decision — these are the rows
        // ChangeProposalStatus would have written for that approval and this
        // rejection.
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
