<?php

namespace App\Http\Controllers\Api;

use App\Actions\Auth\AcceptInvite;
use App\Http\Controllers\Controller;
use App\Http\Requests\AcceptInviteRequest;
use App\Http\Resources\UserResource;
use Illuminate\Http\JsonResponse;
use Illuminate\Validation\ValidationException;

class InviteController extends Controller
{
    public function accept(AcceptInviteRequest $request, AcceptInvite $action): JsonResponse
    {
        $result = $action->handle($request->toData());

        if ($result === null) {
            // One message for every cause — wrong code, unknown email, expired or
            // consumed invite. Distinguishing them reopens the enumeration oracle.
            throw ValidationException::withMessages([
                'code' => 'That invitation is not valid or has expired.',
            ]);
        }

        // Flat, not JsonResource's default `data` wrapper.
        return response()->json(['token' => $result['token'], 'user' => new UserResource($result['user'])], 201);
    }
}
