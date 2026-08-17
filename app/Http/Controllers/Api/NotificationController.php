<?php

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
 * No policy and no authorize() call by design: every method scopes to
 * $request->user(), and no parameter lets a caller name someone else's. One place
 * to get the scoping right rather than two.
 */
class NotificationController extends Controller
{
    public function index(IndexNotificationRequest $request, NotificationRepository $repo): AnonymousResourceCollection
    {
        $user = $request->user();

        return NotificationResource::collection(
            $repo->paginate($user, $request->unreadOnly(), $request->perPage()),
        )->additional([
            // The badge needs the unread total, which is neither the page count nor
            // meta.total once `unread_only` is off.
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
     * Both write endpoints answer 204, so the new badge value has nowhere to go but a
     * header (name fixed in docs/design/API.md §06). Saves a follow-up GET per click.
     */
    private function noContentWithCount(int $unread): Response
    {
        return response()->noContent()->header('X-Unread-Count', (string) $unread);
    }
}
