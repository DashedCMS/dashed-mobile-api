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
use Dashed\DashedCore\Classes\MfaFreshness;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Validation\ValidationException;
use Dashed\DashedMobileApi\Support\AbilityResolver;
use Dashed\DashedMobileApi\Http\Resources\UserResource;
use Filament\Auth\MultiFactor\App\AppAuthentication;
use Filament\Auth\MultiFactor\Email\EmailAuthentication;

class AuthController extends Controller
{
    /** Challenge-levensduur (stap 1 → stap 2) in seconden — absoluut, wordt nooit verlengd. */
    private const MFA_CHALLENGE_TTL = 600;

    /** Maximaal aantal code-pogingen per challenge. */
    private const MFA_MAX_ATTEMPTS = 5;

    /** Maximaal aantal code-pogingen per gebruiker per 10 minuten (over challenges heen). */
    private const MFA_MAX_USER_ATTEMPTS = 15;

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

        $this->ensureBackofficeUser($user);

        // Tweestapsverificatie: dezelfde providers en instellingen als het
        // CMS-paneel (methode kan daar per site uitgezet zijn). Met 2FA op het
        // account stopt de flow hier met een challenge; het token komt pas na
        // een geldige code (tokenMfa). Verplichte MFA zonder ingestelde methode
        // → eerst instellen in het CMS (de app biedt geen 2FA-setup).
        $methods = $this->mfaMethodsFor($user);
        if ($methods === [] && $this->mfaIsRequired()) {
            throw ValidationException::withMessages([
                'email' => ['Tweestapsverificatie is verplicht. Stel deze eerst in via het CMS.'],
            ]);
        }
        if ($methods !== []) {
            $challenge = Str::random(48);
            // Eén lopende challenge per gebruiker: een nieuwe login trekt de
            // vorige in (anders stapelen de pogingen-budgetten op).
            $previous = Cache::pull('mobile-mfa-user:' . $user->id);
            if (is_string($previous)) {
                Cache::forget('mobile-mfa:' . $previous);
                Cache::forget('mobile-mfa-email:' . $previous);
            }
            Cache::put('mobile-mfa-user:' . $user->id, $challenge, self::MFA_CHALLENGE_TTL);
            Cache::put('mobile-mfa:' . $challenge, [
                'user_id' => $user->id,
                'device_name' => $data['device_name'],
                'attempts' => 0,
                // Absolute vervaltijd: pogingen verlengen de challenge niet.
                'expires_at' => now()->addSeconds(self::MFA_CHALLENGE_TTL)->timestamp,
            ], self::MFA_CHALLENGE_TTL);

            $emailSent = null;
            if (in_array('email', $methods, true)) {
                $emailSent = $this->sendEmailCode($user, $challenge);
            }

            return response()->json([
                'mfa_required' => true,
                'methods' => $methods,
                'challenge' => $challenge,
                // false = rate-limit (de app toont dan dat de code zo opnieuw
                // opgevraagd kan worden i.p.v. "we hebben gemaild").
                'email_sent' => $emailSent,
            ]);
        }

        return $this->issueToken($user, $data['device_name'], $resolver);
    }

    /**
     * Stap 2 van het inloggen: verifieer de 2FA-code (TOTP, herstelcode of
     * e-mailcode) en geef dan pas het token uit — met de extra abilities
     * `mfa.passed` + `mfa.at:<timestamp>`, waarmee o.a. de magic-link voor
     * module-pagina's open mag (zolang de verificatie vers genoeg is).
     */
    public function tokenMfa(Request $request, AbilityResolver $resolver): JsonResponse
    {
        $data = $request->validate([
            'challenge' => ['required', 'string', 'size:48', 'regex:/^[A-Za-z0-9]+$/'],
            'code' => ['required', 'string', 'max:64'],
            'method' => ['required', 'string', 'in:app,email'],
        ]);

        $key = 'mobile-mfa:' . $data['challenge'];

        // Pogingen-teller atomair bijwerken (lock): parallelle verzoeken mogen
        // het budget niet omzeilen. De TTL wordt bewust NIET ververst — de
        // absolute expires_at uit de payload is leidend.
        $payload = Cache::lock('mobile-mfa-lock:' . $data['challenge'], 5)->block(3, function () use ($key, $data) {
            $payload = Cache::get($key);
            if (! is_array($payload)) {
                return null;
            }
            if ((int) ($payload['expires_at'] ?? 0) < now()->timestamp) {
                Cache::forget($key);
                Cache::forget('mobile-mfa-email:' . $data['challenge']);

                return null;
            }
            $payload['attempts'] = (int) ($payload['attempts'] ?? 0) + 1;
            if ($payload['attempts'] > self::MFA_MAX_ATTEMPTS) {
                Cache::forget($key);
                Cache::forget('mobile-mfa-email:' . $data['challenge']);

                return null;
            }
            $remaining = max(1, (int) ($payload['expires_at'] ?? 0) - now()->timestamp);
            Cache::put($key, $payload, $remaining);

            return $payload;
        });

        $user = is_array($payload) ? User::find($payload['user_id'] ?? null) : null;
        if ($user === null || ! in_array($data['method'], $this->mfaMethodsFor($user), true)) {
            throw ValidationException::withMessages([
                'code' => ['Deze aanmeldpoging is verlopen. Log opnieuw in.'],
            ]);
        }
        $this->ensureBackofficeUser($user);

        // Per-gebruiker budget over challenges heen: een IP-pool of een reeks
        // verse challenges levert geen extra gokruimte op.
        $userAttemptsKey = 'mobile-mfa-attempts:' . $user->id;
        if (RateLimiter::tooManyAttempts($userAttemptsKey, self::MFA_MAX_USER_ATTEMPTS)) {
            throw ValidationException::withMessages([
                'code' => ['Te veel pogingen. Probeer het later opnieuw.'],
            ]);
        }
        RateLimiter::hit($userAttemptsKey, self::MFA_CHALLENGE_TTL);

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
        Cache::forget('mobile-mfa-user:' . $user->id);
        RateLimiter::clear($userAttemptsKey);

        return $this->issueToken($user, (string) $payload['device_name'], $resolver, mfaPassed: true);
    }

    /** Stuur (opnieuw) een e-mailcode voor een lopende challenge. */
    public function tokenMfaResend(Request $request): JsonResponse
    {
        $data = $request->validate([
            'challenge' => ['required', 'string', 'size:48', 'regex:/^[A-Za-z0-9]+$/'],
        ]);

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
        $name = $current?->name ?? 'app';

        $abilities = $resolver->abilitiesFor($user);
        // 2FA-status reist mee, inclusief het oorspronkelijke verificatie-
        // moment (mfa.at:<ts>): refresh draait bij elke app-start en mag de
        // versheid niet oprekken — de magic-link toetst tegen die timestamp.
        foreach (($current?->abilities ?? []) as $ability) {
            if ($ability === 'mfa.passed' || str_starts_with((string) $ability, 'mfa.at:')) {
                $abilities[] = $ability;
            }
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

    /** Alleen back-office gebruikers; zelf-geregistreerde webshop-klanten geweigerd. */
    private function ensureBackofficeUser(User $user): void
    {
        if (! (in_array($user->role, ['superadmin', 'admin'], true) || $user->roles->isNotEmpty())) {
            throw ValidationException::withMessages([
                // Bewust dezelfde melding als bij foute inloggegevens, om account-enumeratie te voorkomen.
                'email' => ['De inloggegevens zijn onjuist.'],
            ]);
        }
    }

    private function issueToken(User $user, string $deviceName, AbilityResolver $resolver, bool $mfaPassed = false): JsonResponse
    {
        $abilities = $resolver->abilitiesFor($user);
        if ($mfaPassed) {
            $abilities[] = 'mfa.passed';
            $abilities[] = 'mfa.at:' . now()->timestamp;
        }
        $token = $user->createToken($deviceName, $abilities);

        return response()->json([
            'token' => $token->plainTextToken,
            'abilities' => $abilities,
            'user' => new UserResource($user),
            'poll_interval_seconds' => (int) config('dashed-mobile-api.poll_interval_seconds', 5),
        ]);
    }

    /**
     * Ingestelde én in het CMS ingeschakelde 2FA-methodes ('app' en/of 'email').
     * Via dezelfde providerlijst als het paneel, zodat een methode die daar is
     * uitgezet hier niet alsnog geëist of geaccepteerd wordt.
     *
     * @return array<int, string>
     */
    private function mfaMethodsFor(User $user): array
    {
        $methods = [];
        foreach (MfaFreshness::enabledProviders($user) as $provider) {
            $id = $provider->getId();
            if ($id === 'app') {
                $methods[] = 'app';
            } elseif ($id === 'email_code') {
                $methods[] = 'email';
            }
        }

        return $methods;
    }

    /** Zelfde bron als het paneel (o.a. uit in local-omgevingen). */
    private function mfaIsRequired(): bool
    {
        return method_exists(cms(), 'mfaIsRequired') ? cms()->mfaIsRequired() : false;
    }

    /** TOTP-code of (bij een niet-6-cijferige invoer) een herstelcode. */
    private function verifyAppCode(User $user, string $code): bool
    {
        if (ctype_digit($code) && strlen($code) === 6) {
            return AppAuthentication::make()->verifyCode(
                $code,
                $user->getAppAuthenticationSecret(),
                shouldPreventCodeReuse: true,
            );
        }

        // Herstelcode via Filament zelf: die verwijdert de gebruikte code
        // atomair (lock + transactie), zodat een parallel CMS-gebruik geen
        // codes terugzet of dubbel accepteert.
        if (! filled($user->getAppAuthenticationRecoveryCodes())) {
            return false;
        }

        return AppAuthentication::make()->verifyRecoveryCode($code, $user);
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
