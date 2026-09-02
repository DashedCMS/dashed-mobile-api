<?php

declare(strict_types=1);

namespace Dashed\DashedMobileApi\Http\Controllers\Api\V1;

use Illuminate\Support\Str;
use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;
use Dashed\DashedCore\Models\User;
use Illuminate\Routing\Controller;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Cache;
use Dashed\DashedCore\Models\Customsetting;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Validation\ValidationException;
use Dashed\DashedMobileApi\Support\AbilityResolver;
use Dashed\DashedMobileApi\Http\Resources\UserResource;
use Filament\Auth\MultiFactor\App\AppAuthentication;
use Filament\Auth\MultiFactor\Email\EmailAuthentication;

class AuthController extends Controller
{
    /** Challenge-levensduur (stap 1 → stap 2) in seconden. */
    private const MFA_CHALLENGE_TTL = 600;

    /** Maximaal aantal code-pogingen per challenge. */
    private const MFA_MAX_ATTEMPTS = 5;

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

        // Alleen back-office gebruikers mogen een beheer-app-token krijgen.
        // Zelf-geregistreerde webshop-klanten worden geweigerd.
        if (! (in_array($user->role, ['superadmin', 'admin'], true) || $user->roles->isNotEmpty())) {
            throw ValidationException::withMessages([
                // Bewust dezelfde melding als bij foute inloggegevens, om account-enumeratie te voorkomen.
                'email' => ['De inloggegevens zijn onjuist.'],
            ]);
        }

        // Tweestapsverificatie: zelfde bronnen als het CMS-paneel. Met 2FA op het
        // account stopt de flow hier met een challenge; het token komt pas na een
        // geldige code (tokenMfa). Verplichte MFA zonder ingestelde methode →
        // eerst instellen in het CMS (de app biedt geen 2FA-setup).
        $methods = $this->mfaMethodsFor($user);
        if ($methods === [] && Customsetting::get('force_mfa')) {
            throw ValidationException::withMessages([
                'email' => ['Tweestapsverificatie is verplicht. Stel deze eerst in via het CMS.'],
            ]);
        }
        if ($methods !== []) {
            $challenge = Str::random(48);
            Cache::put('mobile-mfa:' . $challenge, [
                'user_id' => $user->id,
                'device_name' => $data['device_name'],
                'attempts' => 0,
            ], self::MFA_CHALLENGE_TTL);

            if (in_array('email', $methods, true)) {
                $this->sendEmailCode($user, $challenge);
            }

            return response()->json([
                'mfa_required' => true,
                'methods' => $methods,
                'challenge' => $challenge,
            ]);
        }

        return $this->issueToken($user, $data['device_name'], $resolver);
    }

    /**
     * Stap 2 van het inloggen: verifieer de 2FA-code (TOTP, herstelcode of
     * e-mailcode) en geef dan pas het token uit — met de extra ability
     * `mfa.passed`, waarmee o.a. de magic-link voor module-pagina's open mag.
     */
    public function tokenMfa(Request $request, AbilityResolver $resolver): JsonResponse
    {
        $data = $request->validate([
            'challenge' => ['required', 'string'],
            'code' => ['required', 'string', 'max:64'],
            'method' => ['required', 'string', 'in:app,email'],
        ]);

        $key = 'mobile-mfa:' . $data['challenge'];
        $payload = Cache::get($key);
        $user = is_array($payload) ? User::find($payload['user_id'] ?? null) : null;
        if ($user === null || ! in_array($data['method'], $this->mfaMethodsFor($user), true)) {
            throw ValidationException::withMessages([
                'code' => ['Deze aanmeldpoging is verlopen. Log opnieuw in.'],
            ]);
        }

        // Pogingen-teller vóór de verificatie ophogen: ook een gefaalde poging telt.
        $payload['attempts'] = (int) ($payload['attempts'] ?? 0) + 1;
        if ($payload['attempts'] > self::MFA_MAX_ATTEMPTS) {
            Cache::forget($key);
            Cache::forget('mobile-mfa-email:' . $data['challenge']);
            throw ValidationException::withMessages([
                'code' => ['Te veel pogingen. Log opnieuw in.'],
            ]);
        }
        Cache::put($key, $payload, self::MFA_CHALLENGE_TTL);

        $valid = $data['method'] === 'app'
            ? $this->verifyAppCode($user, trim($data['code']))
            : $this->verifyEmailCode($data['challenge'], trim($data['code']));

        if (! $valid) {
            throw ValidationException::withMessages([
                'code' => ['De code is onjuist of verlopen.'],
            ]);
        }

        Cache::forget($key);
        Cache::forget('mobile-mfa-email:' . $data['challenge']);

        return $this->issueToken($user, (string) $payload['device_name'], $resolver, mfaPassed: true);
    }

    /** Stuur (opnieuw) een e-mailcode voor een lopende challenge. */
    public function tokenMfaResend(Request $request): JsonResponse
    {
        $data = $request->validate(['challenge' => ['required', 'string']]);

        $payload = Cache::get('mobile-mfa:' . $data['challenge']);
        $user = is_array($payload) ? User::find($payload['user_id'] ?? null) : null;
        if ($user === null || ! in_array('email', $this->mfaMethodsFor($user), true)) {
            throw ValidationException::withMessages([
                'challenge' => ['Deze aanmeldpoging is verlopen. Log opnieuw in.'],
            ]);
        }

        if (! $this->sendEmailCode($user, $data['challenge'])) {
            throw ValidationException::withMessages([
                'challenge' => ['Even wachten voordat we een nieuwe code sturen.'],
            ]);
        }

        return response()->json(['message' => 'Code verstuurd.']);
    }

    /**
     * Vernieuw het token met de huidige rechten. Sanctum bakt de abilities in
     * bij het aanmaken; na het toevoegen van een nieuwe ability (pos.use,
     * forms.read, …) mist een oud token die. De app roept dit bij het opstarten
     * aan zodat nieuwe rechten meteen gelden zonder opnieuw inloggen.
     */
    public function refresh(Request $request, AbilityResolver $resolver): JsonResponse
    {
        $user = $request->user();
        $current = $user->currentAccessToken();
        $name = $current->name ?? 'app';

        $abilities = $resolver->abilitiesFor($user);
        // 2FA-status reist mee: refresh draait bij elke app-start en zou de
        // eerder bewezen tweede factor anders meteen weer kwijtraken.
        if ($current && $current->can('mfa.passed')) {
            $abilities[] = 'mfa.passed';
        }
        $token = $user->createToken($name, $abilities);

        if ($current && method_exists($current, 'delete')) {
            $current->delete();
        }

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

    private function issueToken(User $user, string $deviceName, AbilityResolver $resolver, bool $mfaPassed = false): JsonResponse
    {
        $abilities = $resolver->abilitiesFor($user);
        if ($mfaPassed) {
            $abilities[] = 'mfa.passed';
        }
        $token = $user->createToken($deviceName, $abilities);

        return response()->json([
            'token' => $token->plainTextToken,
            'abilities' => $abilities,
            'user' => new UserResource($user),
            'poll_interval_seconds' => (int) config('dashed-mobile-api.poll_interval_seconds', 5),
        ]);
    }

    /** @return array<int, string> Ingestelde 2FA-methodes ('app' en/of 'email'). */
    private function mfaMethodsFor(User $user): array
    {
        $methods = [];
        if (filled($user->app_authentication_secret)) {
            $methods[] = 'app';
        }
        if ($user->hasEmailAuthentication()) {
            $methods[] = 'email';
        }

        return $methods;
    }

    /** TOTP-code of (bij een niet-cijferige invoer) een herstelcode. */
    private function verifyAppCode(User $user, string $code): bool
    {
        if (ctype_digit($code) && strlen($code) === 6) {
            return AppAuthentication::make()->verifyCode(
                $code,
                $user->getAppAuthenticationSecret(),
                shouldPreventCodeReuse: true,
            );
        }

        // Herstelcode: gehashte lijst (Filament slaat ze met Hash::make op);
        // een gebruikte code vervalt direct.
        $codes = $user->getAppAuthenticationRecoveryCodes() ?? [];
        foreach ($codes as $i => $hash) {
            if (Hash::check($code, $hash)) {
                unset($codes[$i]);
                $user->saveAppAuthenticationRecoveryCodes(array_values($codes));

                return true;
            }
        }

        return false;
    }

    /**
     * E-mailcode voor de app-login. Filament's EmailAuthentication bewaart codes
     * in de sessie en is daarmee onbruikbaar voor deze stateless API; we
     * hergebruiken wél z'n codegenerator, mail-notificatie en verlooptijd, en
     * bewaren de hash zelf in de cache, gebonden aan de challenge.
     */
    private function sendEmailCode(User $user, string $challenge): bool
    {
        $rateLimitingKey = 'mobile-mfa-email-send:' . $user->id;
        if (RateLimiter::tooManyAttempts($rateLimitingKey, maxAttempts: 2)) {
            return false;
        }
        RateLimiter::hit($rateLimitingKey);

        $provider = EmailAuthentication::make();
        $code = $provider->generateCode();
        $minutes = $provider->getCodeExpiryMinutes();

        Cache::put('mobile-mfa-email:' . $challenge, Hash::make($code), $minutes * 60);

        $user->notify(app($provider->getCodeNotification(), [
            'code' => $code,
            'codeExpiryMinutes' => $minutes,
        ]));

        return true;
    }

    private function verifyEmailCode(string $challenge, string $code): bool
    {
        $hash = Cache::get('mobile-mfa-email:' . $challenge);

        return is_string($hash) && Hash::check($code, $hash);
    }
}
