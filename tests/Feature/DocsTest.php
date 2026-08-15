<?php

// tests/Feature/DocsTest.php

describe('generated API docs', function () {

    it('serves an OpenAPI document describing the real routes', function () {
        // When
        $response = $this->getJson('/docs/api.json');

        // Then
        $response->assertOk();
        $paths = $response->json('paths');

        // Every route this tier added must appear, or the document is stale.
        expect($paths)->toHaveKeys([
            '/api/proposals',
            '/api/proposals/{proposal}',
            '/api/proposals/{proposal}/history',
            '/api/stats',
            '/api/reviews/{review}',
        ]);
    });

    it('documents the security scheme rather than implying the API is open', function () {
        // When
        $response = $this->getJson('/docs/api.json');

        // Then
        expect($response->json('components.securitySchemes'))->not->toBeEmpty();
    });
});
