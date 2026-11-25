<?php

declare(strict_types=1);

namespace Dashed\DashedMobileApi\Support;

use Illuminate\Http\Request;
use Dashed\DashedCore\Models\User;

/**
 * Read-only token for the iOS home-screen widget.
 *
 * The widget lives outside the app's sandbox (App Group + Keychain), so it gets
 * its own token instead of the app's session token. Route abilities in this
 * package follow the user's role, not the token, so the real boundary is
 * {@see self::allows()}, enforced in EnsureSiteContext: a widget token only
 * reaches the two read endpoints the widget calls. It expires on its own and is
 * revoked together with the app session it was issued from.
 */
final class WidgetToken
{
    public const ABILITY = 'widget.read';

    private const NAME_PREFIX = 'mobile-widget:';

    private const TTL_DAYS = 30;

    /** GET endpoints (path below api/v1) the widget may call. */
    private const ALLOWED_PATHS = ['dashboard', 'visitors-live'];

    public static function isWidget(?object $token): bool
    {
        if ($token === null) {
            return false;
        }

        return in_array(self::ABILITY, (array) ($token->abilities ?? []), true);
    }

    public static function allows(Request $request): bool
    {
        if (! $request->isMethod('GET')) {
            return false;
        }

        $path = trim($request->path(), '/');
        foreach (self::ALLOWED_PATHS as $allowed) {
            if ($path === 'api/v1/' . $allowed) {
                return true;
            }
        }

        return false;
    }

    /**
     * Issue a widget token for the given app session; an earlier widget token of
     * that same session is replaced.
     *
     * @return array{token: string, expires_at: string}
     */
    public static function issue(User $user, int $parentTokenId): array
    {
        self::revokeFor($user, $parentTokenId);

        $expiresAt = now()->addDays(self::TTL_DAYS);
        $token = $user->createToken(self::NAME_PREFIX . $parentTokenId, [self::ABILITY], $expiresAt);

        return ['token' => $token->plainTextToken, 'expires_at' => $expiresAt->toIso8601String()];
    }

    /** Revoke the widget token(s) issued from the given app session. */
    public static function revokeFor(User $user, int $parentTokenId): void
    {
        $user->tokens()->where('name', self::NAME_PREFIX . $parentTokenId)->delete();
    }
}
