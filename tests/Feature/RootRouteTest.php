<?php

describe('root route', function () {

    it('points a browser at the API instead of serving the stock welcome page', function () {
        $response = $this->getJson('/');

        $response->assertOk()
            ->assertJsonStructure(['name', 'api', 'docs', 'health'])
            ->assertJsonPath('api', url('/api'))
            ->assertJsonPath('health', url('/up'));
    });
});
