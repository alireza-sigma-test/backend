<?php

/*
 * Merged over vendor/laravel/ai/config/ai.php — the package calls
 * mergeConfigFrom(), so only the keys this application actually decides need
 * to appear here. The provider block itself already reads ANTHROPIC_API_KEY;
 * repeating it would give this repo a second definition to drift from.
 */
return [

    'default' => 'anthropic',

    'summary' => [

        /*
         * The model that writes proposal summaries.
         *
         * An env var rather than a literal so a deployment can move models
         * without a code change — and so this file does not quietly become
         * the reason someone is still on a retired model a year from now.
         */
        'model' => env('AI_SUMMARY_MODEL', 'claude-opus-5'),

        /*
         * Seconds. Deliberately explicit: this runs inside a queued job, and
         * a request with no timeout can hold a worker indefinitely while the
         * job's own retry budget never advances.
         */
        'timeout' => (int) env('AI_SUMMARY_TIMEOUT', 45),

        /*
         * Ceiling on generated tokens. The summary is a couple of sentences;
         * this is the backstop that keeps a runaway generation from becoming
         * a bill. Mirrored by the #[MaxTokens] attribute on the agent, which
         * is where the SDK actually reads it — see ProposalSummaryAgent.
         */
        'max_tokens' => 400,
    ],
];
