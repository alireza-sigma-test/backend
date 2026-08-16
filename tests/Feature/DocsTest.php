<?php

// tests/Feature/DocsTest.php

describe('generated API docs', function () {

    it('serves an OpenAPI document describing the real routes with their verbs intact', function () {
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
            '/api/public-stats',
            '/api/reviews/{review}',
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
    });

    it('keeps the route parameter and the Form Request rules that make this package worth using', function () {
        // When
        $document = $this->getJson('/docs/api.json')->json();

        // Then — the {proposal} route parameter is still typed and marked
        // required, not silently dropped from the operation.
        $parameters = collect(data_get($document, 'paths./api/proposals/{proposal}.get.parameters', []));
        expect($parameters->firstWhere('name', 'proposal'))->toMatchArray([
            'name' => 'proposal',
            'in' => 'path',
            'required' => true,
            'schema' => ['type' => 'integer'],
        ]);

        // Then — StoreProposalRequest's validation rules are still what the
        // generated schema documents, not a stale or emptied-out shape. This
        // is the whole reason the package was chosen over hand-written
        // annotations, so a regression here is a regression in the point of
        // Task 7.
        $storeRequest = data_get($document, 'components.schemas.StoreProposalRequest');
        expect($storeRequest['required'])->toContain('title', 'description');
        expect($storeRequest['properties']['title']['minLength'])->toBe(8);
    });

    it('documents the security scheme rather than implying the API is open', function () {
        // When
        $response = $this->getJson('/docs/api.json');

        // Then
        expect($response->json('components.securitySchemes'))->not->toBeEmpty();
    });

    it('marks the auth endpoints public while a protected route still requires the bearer token', function () {
        // When
        $document = $this->getJson('/docs/api.json')->json();

        // Then — a document-wide `security` requirement would make /register
        // and /login look like they need a bearer token to be called, which
        // is impossible: you don't have a token before you register. Both
        // must be documented as explicitly public (`security: []`).
        expect(data_get($document, 'paths./api/register.post.security'))->toBe([]);
        expect(data_get($document, 'paths./api/login.post.security'))->toBe([]);

        // Then — same for the one route where "documented as public" is
        // itself the security-relevant fact. `GET /api/public-stats` carries
        // no authorization at all by design, so the document must say so
        // outright rather than leave a reader to infer it from the absence
        // of a padlock.
        expect(data_get($document, 'paths./api/public-stats.get.security'))->toBe([]);

        // Then — and a genuinely protected route must not have been swept
        // into that same public override. `/api/stats` carries no per-operation
        // `security` override of its own — protection comes from the
        // document-level `security` requirement instead — so
        // `not->toBe([])` was passing on `null` without ever inspecting the
        // thing that actually protects the route. Assert the document-level
        // requirement directly.
        expect(data_get($document, 'paths./api/stats.get.security'))->toBeNull();
        expect(data_get($document, 'security'))->toBe([['http' => []]]);
    });
});
