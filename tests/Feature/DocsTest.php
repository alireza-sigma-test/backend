<?php

describe('generated API docs', function () {

    it('serves an OpenAPI document describing the real routes with their verbs intact', function () {
        $response = $this->getJson('/docs/api.json');

        $response->assertOk();
        $paths = $response->json('paths');

        // Every route this tier added must appear, or the document is stale.
        expect($paths)->toHaveKeys([
            '/api/proposals',
            '/api/proposals/{proposal}',
            '/api/proposals/{proposal}/history',
            '/api/stats',
            '/api/public-stats',
            '/api/reviews/{review}',
            '/api/notifications',
            '/api/notifications/read-all',
            '/api/notifications/{notification}/read',
            '/api/activity',
        ]);

        // A path key surviving isn't proof the operation did: pin the verb
        // per route too, so a route that silently lost its method (e.g. an
        // update documented only as GET) still fails here.
        expect($paths['/api/proposals'])->toHaveKeys(['post', 'get']);
        expect($paths['/api/proposals/{proposal}'])->toHaveKeys(['get', 'patch', 'delete']);
        expect($paths['/api/proposals/{proposal}/history'])->toHaveKeys(['get']);
        expect($paths['/api/reviews/{review}'])->toHaveKeys(['patch', 'delete']);
        expect($paths['/api/stats'])->toHaveKeys(['get']);
        expect($paths['/api/public-stats'])->toHaveKeys(['get']);
        expect($paths['/api/notifications'])->toHaveKeys(['get']);
        expect($paths['/api/notifications/read-all'])->toHaveKeys(['post']);
        expect($paths['/api/notifications/{notification}/read'])->toHaveKeys(['post']);
        expect($paths['/api/activity'])->toHaveKeys(['get']);
    });

    it('keeps the route parameter and the Form Request rules that make this package worth using', function () {
        $document = $this->getJson('/docs/api.json')->json();

        // The {proposal} route parameter is still typed and marked
        // required, not silently dropped from the operation.
        $parameters = collect(data_get($document, 'paths./api/proposals/{proposal}.get.parameters', []));
        expect($parameters->firstWhere('name', 'proposal'))->toMatchArray([
            'name' => 'proposal',
            'in' => 'path',
            'required' => true,
            'schema' => ['type' => 'integer'],
        ]);

        // The generated schema still reflects StoreProposalRequest's real rules —
        // deriving them is why the package was chosen over hand-written annotations.
        $storeRequest = data_get($document, 'components.schemas.StoreProposalRequest');
        expect($storeRequest['required'])->toContain('title', 'description');
        expect($storeRequest['properties']['title']['minLength'])->toBe(8);
    });

    it('documents the security scheme rather than implying the API is open', function () {
        $response = $this->getJson('/docs/api.json');

        expect($response->json('components.securitySchemes'))->not->toBeEmpty();
    });

    it('marks the auth endpoints public while a protected route still requires the bearer token', function () {
        $document = $this->getJson('/docs/api.json')->json();

        // A document-wide `security` requirement would imply /register and /login need
        // a bearer token, which is impossible. Both must be explicitly `security: []`.
        expect(data_get($document, 'paths./api/register.post.security'))->toBe([]);
        expect(data_get($document, 'paths./api/login.post.security'))->toBe([]);

        // public-stats carries no authorization by design, so the document must say so
        // outright rather than leave it to be inferred.
        expect(data_get($document, 'paths./api/public-stats.get.security'))->toBe([]);

        // A protected route must not be swept into that override. /api/stats has no
        // per-operation `security` — protection is the document-level requirement — so
        // that is what gets asserted, rather than a `not->toBe([])` that passes on null.
        expect(data_get($document, 'paths./api/stats.get.security'))->toBeNull();
        expect(data_get($document, 'security'))->toBe([['http' => []]]);
    });
});
