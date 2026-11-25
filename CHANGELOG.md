# Changelog

## v4.23.0 - 2026-11-25

### Changed
- App-rechten worden afgeleid van de CMS-rechten via `registerAbilityPermissions()`; superadmin krijgt alles, een klant komt de app niet in. De koppeling op rolnaam (`registerRoleAbilities`) en de speciale behandeling van `admin` zijn weg. Dagoverzicht, assistent en copilot tonen alleen wat de gebruiker in het CMS mag zien. Nieuw: `PushNotification::toUsers()` voor pushes naar gekozen gebruikers, met voorkeuren, stille uren en inbox (vereist dashed-core ^4.124).

