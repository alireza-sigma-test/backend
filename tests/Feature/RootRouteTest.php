<?php

// tests/Feature/RootRouteTest.php

describe('root route', function () {

    it('points a browser at the API instead of serving the stock welcome page', function () {
        // When
        $response = $this->getJson('/');

        // Then
        $response->assertOk()
            ->assertJsonStructure(['name', 'api', 'docs', 'health'])
            ->assertJsonPath('api', url('/api'))
            ->assertJsonPath('health', url('/up'));
    });
});
