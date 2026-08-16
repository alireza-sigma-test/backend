<?php

// tests/Feature/Realtime/BroadcastAuthorizationTest.php

use App\Models\User;
use Database\Seeders\RoleSeeder;

/**
 * A private channel is authorized ONCE, at subscribe time. Everything broadcast
 * on it afterwards reaches every subscriber for as long as they stay connected,
 * with no further check. That makes routes/channels.php an authorization
 * surface of the same weight as a policy, and these are its tests.
 *
 * Why the connection is switched to `reverb` below, when phpunit.xml
 * deliberately pins BROADCAST_CONNECTION=null:
 *
 *   NullBroadcaster::auth() has an empty method body (vendor/laravel/framework/
 *   src/Illuminate/Broadcasting/Broadcasters/NullBroadcaster.php). Under it,
 *   POST /broadcasting/auth returns 200 with an empty body for EVERY channel —
 *   grants and refusals alike. Every assertion in this file would pass with
 *   routes/channels.php deleted outright.
 *
 * `reverb` authorizes through PusherBroadcaster, which runs the real channel
 * callback and then signs the response with an HMAC computed locally. It needs
 * no socket and no network, so the suite still has no runtime dependency on a
 * running Reverb — only this file's config override, and only for this file.
 */
describe('broadcast channel authorization', function () {

    beforeEach(function () {
        $this->seed(RoleSeeder::class);

        config([
            'broadcasting.default' => 'reverb',
            'broadcasting.connections.reverb.key' => 'test-key',
            'broadcasting.connections.reverb.secret' => 'test-secret',
            'broadcasting.connections.reverb.app_id' => '1',
        ]);

        // Channels live on the *driver*, not on the manager: Broadcast::channel()
        // is a __call that forwards to whichever driver is default at the moment
        // it runs. Registration happened during boot, against `null`; the line
        // above just made a different driver default, and that one has no
        // channels at all. Without this re-run every assertion below would see
        // 403 — including the grants — and the file would look like it was
        // testing a deny-everything policy.
        //
        // This requires the production file, so what is exercised is the real
        // definitions and not a test-local copy of them.
        require base_path('routes/channels.php');
    });

    /** The socket_id is arbitrary; it only has to look like Pusher's format. */
    $authorize = fn (?User $user, string $channel) => ($user
        ? test()->actingAs($user, 'sanctum')
        : test())
        ->postJson('/broadcasting/auth', [
            'socket_id' => '1234.5678',
            'channel_name' => $channel,
        ]);

    it('refuses a user subscribing to another user private channel', function () use ($authorize) {
        // Given
        $dana = User::factory()->speaker()->create();
        $maya = User::factory()->reviewer()->create();

        // When — maya asks for dana's channel
        $response = $authorize($maya, "private-user.{$dana->id}");

        // Then
        $response->assertForbidden();
    });

    it('allows a user on their own private channel', function () use ($authorize) {
        // Given
        $dana = User::factory()->speaker()->create();

        // When
        $response = $authorize($dana, "private-user.{$dana->id}");

        // Then
        $response->assertOk()->assertJsonStructure(['auth']);
    });

    it('refuses a speaker subscribing to the reviewer role channel', function () use ($authorize) {
        // Given
        $dana = User::factory()->speaker()->create();

        // When
        $response = $authorize($dana, 'private-role.reviewer');

        // Then
        $response->assertForbidden();
    });

    it('refuses a reviewer subscribing to the admin role channel', function () use ($authorize) {
        // Given
        $maya = User::factory()->reviewer()->create();

        // When
        $response = $authorize($maya, 'private-role.admin');

        // Then
        $response->assertForbidden();
    });

    it('allows a reviewer on the reviewer role channel', function () use ($authorize) {
        // Given
        $maya = User::factory()->reviewer()->create();

        // When
        $response = $authorize($maya, 'private-role.reviewer');

        // Then
        $response->assertOk()->assertJsonStructure(['auth']);
    });

    it('allows an admin on the admin role channel', function () use ($authorize) {
        // Given
        $alex = User::factory()->admin()->create();

        // When
        $response = $authorize($alex, 'private-role.admin');

        // Then
        $response->assertOk()->assertJsonStructure(['auth']);
    });

    it('refuses an admin on the reviewer role channel', function () use ($authorize) {
        // Given — roles here are not a hierarchy. An admin outranks a reviewer
        // in the UI, but `role.reviewer` carries events addressed to reviewers,
        // and "authorized because they are important" is exactly the reasoning
        // that turns a role channel into a broadcast-to-anyone channel.
        $alex = User::factory()->admin()->create();

        // When
        $response = $authorize($alex, 'private-role.reviewer');

        // Then
        $response->assertForbidden();
    });

    it('refuses an unauthenticated subscriber on every channel', function () use ($authorize) {
        // Given
        $dana = User::factory()->speaker()->create();

        // When / Then — 401 from auth:sanctum, before any channel callback runs.
        foreach (["private-user.{$dana->id}", 'private-role.reviewer', 'private-role.admin'] as $channel) {
            $authorize(null, $channel)->assertUnauthorized();
        }
    });

    it('answers the browser preflight for the SPA origin', function () {
        // Given — /broadcasting/auth is registered at the root, not under
        // api/*, so it needs its own entry in config/cors.php's `paths`.
        // Without it the browser blocks the request before it is sent and Echo
        // reports a bare "Failed to fetch": no status code, nothing in the
        // server log, and nothing any other test in this suite can see, since
        // CORS is enforced in the browser and never reaches PHP. This one was
        // found by two real browsers, and this test exists so it stays found.
        config(['cors.allowed_origins' => ['http://localhost:3000']]);

        // When
        $response = $this->call('OPTIONS', '/broadcasting/auth', server: [
            'HTTP_ORIGIN' => 'http://localhost:3000',
            'HTTP_ACCESS_CONTROL_REQUEST_METHOD' => 'POST',
            'HTTP_ACCESS_CONTROL_REQUEST_HEADERS' => 'authorization,content-type',
        ]);

        // Then
        $response->assertNoContent()
            ->assertHeader('Access-Control-Allow-Origin', 'http://localhost:3000');
    });

    it('refuses a channel that is not defined at all', function () use ($authorize) {
        // Given — the default is refusal, not admission. A typo'd or removed
        // channel name must not become an open one.
        $alex = User::factory()->admin()->create();

        // When
        $response = $authorize($alex, 'private-role.superuser');

        // Then
        $response->assertForbidden();
    });
});
