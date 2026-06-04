<?php

declare(strict_types=1);

namespace Dashed\DashedMobileApi\Http\Controllers\Api\V1;

use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;
use Dashed\DashedCore\Models\User;
use Illuminate\Routing\Controller;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\ValidationException;
use Dashed\DashedMobileApi\Support\AbilityResolver;
use Dashed\DashedMobileApi\Http\Resources\UserResource;

class AuthController extends Controller
{
    public function token(Request $request, AbilityResolver $resolver): JsonResponse
    {
        $data = $request->validate([
            'email' => ['required', 'email'],
            'password' => ['required', 'string'],
            'device_name' => ['required', 'string', 'max:255'],
        ]);

        $user = User::where('email', $data['email'])->first();

        if (! $user || ! Hash::check($data['password'], (string) $user->password)) {
            throw ValidationException::withMessages([
                'email' => ['De inloggegevens zijn onjuist.'],
            ]);
        }

        $abilities = $resolver->abilitiesFor($user);
        $token = $user->createToken($data['device_name'], $abilities);

        return response()->json([
            'token' => $token->plainTextToken,
            'abilities' => $abilities,
            'user' => new UserResource($user),
            'poll_interval_seconds' => (int) config('dashed-mobile-api.poll_interval_seconds', 5),
        ]);
    }

    public function logout(Request $request): JsonResponse
    {
        $request->user()->currentAccessToken()->delete();

        return response()->json(['message' => 'Uitgelogd.']);
    }

    public function me(Request $request): UserResource
    {
        return new UserResource($request->user());
    }
}
