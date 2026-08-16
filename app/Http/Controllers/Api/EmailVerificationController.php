<?php

// app/Http/Controllers/Api/EmailVerificationController.php

namespace App\Http\Controllers\Api;

use App\Actions\Auth\ResendVerificationCode;
use App\Actions\Auth\VerifyEmailCode;
use App\Http\Controllers\Controller;
use App\Http\Requests\VerifyEmailRequest;
use App\Http\Resources\UserResource;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Validation\ValidationException;

class EmailVerificationController extends Controller
{
    public function verify(VerifyEmailRequest $request, VerifyEmailCode $action): JsonResponse
    {
        if (! $action->handle($request->user(), $request->string('code')->value())) {
            // A validation error, not a 403: the code is bad input, and this
            // puts the message on the field the client rendered.
            throw ValidationException::withMessages([
                'code' => 'That code is not valid or has expired.',
            ]);
        }

        return response()->json(new UserResource($request->user()->fresh()->load('roles')));
    }

    public function resend(Request $request, ResendVerificationCode $action): Response
    {
        $action->handle($request->user());

        return response()->noContent();
    }
}
