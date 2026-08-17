<?php

use App\Models\User;
use Database\Seeders\RoleSeeder;

/**
 * A private channel is authorized once, at subscribe time, so routes/channels.php is
 * an authorization surface of the same weight as a policy. These are its tests.
 *
 * The connection is switched to `reverb` below, overriding phpunit.xml, because
 * NullBroadcaster::auth() is an empty method: under it every channel returns 200 with
 * an empty body, and every assertion here would pass with channels.php deleted.
 * PusherBroadcaster runs the real callback and signs locally, needing no socket.
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

        // Channels live on the driver, not the manager: Broadcast::channel() forwards
        // to whichever driver is default when it runs, and the line above just swapped
        // in one with no channels. Without this re-run every assertion below — grants
        // included — would see 403. Requiring the production file keeps the real
        // definitions under test.
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
        $dana = User::factory()->speaker()->create();
        $maya = User::factory()->reviewer()->create();

        $response = $authorize($maya, "private-user.{$dana->id}");

        $response->assertForbidden();
    });

    it('allows a user on their own private channel', function () use ($authorize) {
        $dana = User::factory()->speaker()->create();

        $response = $authorize($dana, "private-user.{$dana->id}");

        $response->assertOk()->assertJsonStructure(['auth']);
    });

    it('refuses a speaker subscribing to the reviewer role channel', function () use ($authorize) {
        $dana = User::factory()->speaker()->create();

        $response = $authorize($dana, 'private-role.reviewer');

        $response->assertForbidden();
    });

    it('refuses a reviewer subscribing to the admin role channel', function () use ($authorize) {
        $maya = User::factory()->reviewer()->create();

        $response = $authorize($maya, 'private-role.admin');

        $response->assertForbidden();
    });

    it('allows a reviewer on the reviewer role channel', function () use ($authorize) {
        $maya = User::factory()->reviewer()->create();

        $response = $authorize($maya, 'private-role.reviewer');

        $response->assertOk()->assertJsonStructure(['auth']);
    });

    it('allows an admin on the admin role channel', function () use ($authorize) {
        $alex = User::factory()->admin()->create();

        $response = $authorize($alex, 'private-role.admin');

        $response->assertOk()->assertJsonStructure(['auth']);
    });

    it('refuses an admin on the reviewer role channel', function () use ($authorize) {
        // Roles are not a hierarchy: `role.reviewer` carries events addressed to
        // reviewers, and "authorized because they are important" is what turns a role
        // channel into a broadcast-to-anyone channel.
        $alex = User::factory()->admin()->create();

        $response = $authorize($alex, 'private-role.reviewer');

        $response->assertForbidden();
    });

    it('refuses an unauthenticated subscriber on every channel', function () use ($authorize) {
        $dana = User::factory()->speaker()->create();

        foreach (["private-user.{$dana->id}", 'private-role.reviewer', 'private-role.admin'] as $channel) {
            $authorize(null, $channel)->assertUnauthorized();
        }
    });

    it('answers the browser preflight for the SPA origin', function () {
        // /broadcasting/auth sits at the root, not under api/*, so it needs its own
        // entry in config/cors.php. Without it the browser blocks the request and Echo
        // reports a bare "Failed to fetch" — invisible to PHP, and to every other test
        // here.
        config(['cors.allowed_origins' => ['http://localhost:3000']]);

        $response = $this->call('OPTIONS', '/broadcasting/auth', server: [
            'HTTP_ORIGIN' => 'http://localhost:3000',
            'HTTP_ACCESS_CONTROL_REQUEST_METHOD' => 'POST',
            'HTTP_ACCESS_CONTROL_REQUEST_HEADERS' => 'authorization,content-type',
        ]);

        $response->assertNoContent()
            ->assertHeader('Access-Control-Allow-Origin', 'http://localhost:3000');
    });

    it('refuses a channel that is not defined at all', function () use ($authorize) {
        // The default is refusal, not admission. A typo'd or removed
        // channel name must not become an open one.
        $alex = User::factory()->admin()->create();

        $response = $authorize($alex, 'private-role.superuser');

        $response->assertForbidden();
    });
});
