<?php

// app/Http/Controllers/Api/NotificationController.php

namespace App\Http\Controllers\Api;

use App\Actions\Notifications\MarkAllNotificationsRead;
use App\Actions\Notifications\MarkNotificationRead;
use App\Http\Controllers\Controller;
use App\Http\Requests\IndexNotificationRequest;
use App\Http\Resources\NotificationResource;
use App\Repositories\Contracts\NotificationRepository;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Http\Response;

/**
 * Notifications are addressed to one person, so there is no policy here and no
 * `authorize()` call: every method scopes to `$request->user()` and there is no
 * parameter by which a caller could name someone else's. That is deliberate —
 * an id-taking endpoint with a policy would have two places to get the scoping
 * right; this has one.
 *
 * See ActivityController for the other half of the pair: notifications are
 * addressed to you, activity is everything you may see.
 */
class NotificationController extends Controller
{
    public function index(IndexNotificationRequest $request, NotificationRepository $repo): AnonymousResourceCollection
    {
        $user = $request->user();

        return NotificationResource::collection(
            $repo->paginate($user, $request->unreadOnly(), $request->perPage()),
        )->additional([
            // Merged into the paginator's own `meta` block. The badge needs the
            // unread total, which is not the page count and is not `meta.total`
            // either once `unread_only` is off.
            'meta' => ['unread_count' => $repo->unreadCount($user)],
        ]);
    }

    public function read(Request $request, string $notification, MarkNotificationRead $action): Response
    {
        $unread = $action->handle($request->user(), $notification);

        return $this->noContentWithCount($unread);
    }

    public function readAll(Request $request, MarkAllNotificationsRead $action): Response
    {
        $unread = $action->handle($request->user());

        return $this->noContentWithCount($unread);
    }

    /**
     * Both write endpoints answer 204, so the new badge value has nowhere to go
     * but a header. docs/design/API.md §06 fixes the name. It saves the client
     * a follow-up GET on every click of the bell.
     */
    private function noContentWithCount(int $unread): Response
    {
        return response()->noContent()->header('X-Unread-Count', (string) $unread);
    }
}
