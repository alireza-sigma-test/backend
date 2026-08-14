<?php
// database/seeders/DemoSeeder.php
namespace Database\Seeders;

use App\Enums\ProposalStatus;
use App\Models\{Proposal, Review, Tag, User};
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;

class DemoSeeder extends Seeder
{
    public function run(): void
    {
        $speakers = collect([
            'Dana Roth' => 'dana@example.com',
            'Ilya Petrov' => 'ilya@example.com',
            'Nia Okafor' => 'nia@example.com',
        ])->map(fn ($email, $name) => User::factory()->speaker()->create([
            'name' => $name, 'email' => $email, 'password' => Hash::make('password'),
        ]));

        $reviewers = collect([
            'Maya Kessler' => 'maya@example.com',
            'Jonas Adeyemi' => 'jonas@example.com',
            'Sofia Lindqvist' => 'sofia@example.com',
            'Theo Nakamura' => 'theo@example.com',
        ])->map(fn ($email, $name) => User::factory()->reviewer()->create([
            'name' => $name, 'email' => $email, 'password' => Hash::make('password'),
        ]));

        User::factory()->admin()->create([
            'name' => 'Alex Vance', 'email' => 'alex@example.com', 'password' => Hash::make('password'),
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

            $proposal->tags()->attach($tagNames === [] ? [] : $tags->only($tagNames)->pluck('id'));
        }

        // The detail screen shows this proposal with three reviews averaging 4.0.
        $flagship = Proposal::where('title', 'Observability at scale without the bill')->firstOrFail();

        foreach ([['Jonas Adeyemi', 4], ['Sofia Lindqvist', 5], ['Theo Nakamura', 3]] as [$name, $rating]) {
            Review::create([
                'proposal_id' => $flagship->id,
                'user_id' => $reviewers->firstWhere('name', $name)->id,
                'rating' => $rating,
                'comment' => 'Concrete, numbers-first, and the spreadsheet giveaway makes it actionable.',
            ]);
        }
    }
}
