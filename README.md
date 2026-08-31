# dashed-mobile-api

Site-bewuste mobiele REST-API voor Dashed CMS. Levert de auth-, site-context- en
device-laag waarop een mobiele app (Flutter) de webshop beheert en livechat-gesprekken
overneemt. Domein-endpoints (e-commerce, livechat) worden door de betreffende packages
zelf geregistreerd; deze package biedt de gedeelde infrastructuur.

## Installatie

Path-repository in de root `composer.json`:

```json
"repositories": {
    "dashed/dashed-mobile-api": {
        "type": "path",
        "url": "./packages/dashed/dashed-mobile-api"
    }
}
```

```bash
composer require dashed/dashed-mobile-api
```

De provider `Dashed\DashedMobileApi\DashedMobileApiServiceProvider` wordt automatisch
ge-discovered.

## Wat deze package levert

- **Auth** — `POST /api/v1/auth/token`, `POST /api/v1/auth/logout`, `GET /api/v1/me`.
  Sanctum-tokens met abilities afgeleid uit de rollen van de gebruiker (`AbilityResolver`).
- **Site-context** — middleware `mobile.site` (`EnsureSiteContext`) leest de `X-Site-Id`
  header, valideert tegen `Sites::getSites()` en zet de actieve site, zodat alle bestaande
  `thisSite()`-scopes site-correct zijn. Cross-site toegang levert 404.
- **Abilities** — Sanctum `ability`/`abilities` middleware-aliassen.
- **Devices** — `POST /api/v1/devices` registreert een FCM device-token per gebruiker.
- **App-pagina's** — modules registreren met `MobileApiRegistry::registerAppPage()` een
  pagina in het app-menu; `GET /api/v1/app-pages` levert de lijst (gefilterd op ability),
  `POST /api/v1/app-pages/{key}/open` geeft een éénmalige magic-link (60 s, MFA-guard,
  host-check) die de web-sessie inlogt en doorstuurt naar de admin-pagina. Zie
  `docs/mobile-app/module-app-paginas.md` in de repo-root voor de gids.

## Conventies

- PHP 8.4, Laravel 12, `declare(strict_types=1)`.
- Tabellen met prefix `dashed__`.
- Geen businesslogica dupliceren: domein-acties lopen via de bestaande modellen/services.
