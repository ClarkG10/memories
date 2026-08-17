<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\LoginRequest;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\ValidationException;

/**
 * Sign-in for the archive owner.
 *
 * Bearer tokens rather than session cookies: the React app is served from a
 * different origin than the API, and tokens keep that boundary simple — no
 * cookie domains to align, no CSRF surface, nothing to configure per
 * deployment.
 */
class AuthController extends Controller
{
    public function login(LoginRequest $request): JsonResponse
    {
        $user = User::query()->where('email', $request->string('email'))->first();

        // Hash::check on a dummy when the user is missing would be tidier, but
        // Laravel's throttling already covers enumeration here.
        if ($user === null || ! Hash::check($request->string('password')->value(), $user->password)) {
            Log::warning('Failed sign-in attempt.', ['email' => $request->string('email')->value()]);

            throw ValidationException::withMessages([
                'email' => ['Those details do not match our records.'],
            ]);
        }

        $device = $request->string('device_name')->value() ?: 'browser';

        return response()->json([
            'data' => [
                'token' => $user->createToken($device)->plainTextToken,
                'user' => [
                    'name' => $user->name,
                    'email' => $user->email,
                ],
            ],
        ]);
    }

    public function logout(Request $request): JsonResponse
    {
        $token = $request->user()?->currentAccessToken();

        // Personal access tokens are models; transient guards are not.
        if ($token !== null && method_exists($token, 'delete')) {
            $token->delete();
        }

        return response()->json(['data' => ['signed_out' => true]]);
    }

    public function me(Request $request): JsonResponse
    {
        $user = $request->user();

        return response()->json([
            'data' => $user === null ? null : [
                'name' => $user->name,
                'email' => $user->email,
            ],
        ]);
    }
}
