<?php

// Merged over the package's own config via mergeConfigFrom(), so only the keys this
// application decides appear here. The provider block already reads ANTHROPIC_API_KEY.
return [

    'default' => 'anthropic',

    'summary' => [

        // An env var so a deployment can move models without a code change.
        'model' => env('AI_SUMMARY_MODEL', 'claude-opus-5'),

        // Seconds, and explicit: with no timeout a request can hold a queue worker
        // indefinitely while the job's retry budget never advances.
        'timeout' => (int) env('AI_SUMMARY_TIMEOUT', 45),

        // Documentation only — the SDK reads the #[MaxTokens] attribute on the agent.
        'max_tokens' => 400,
    ],
];
