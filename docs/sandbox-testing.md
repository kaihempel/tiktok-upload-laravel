# Manuelle Tests mit einer TikTok-Sandbox-App

Diese Checkliste beschreibt den manuellen End-to-End-Test gegen die echte TikTok-API.
Automatisierte Tests (`vendor/bin/pest`) arbeiten ausschließlich mit `Http::fake()`.

## Vorbereitung

- [ ] Sandbox-App unter developers.tiktok.com angelegt (Plattform „Web“)
- [ ] Produkte **Login Kit** und **Content Posting API** hinzugefügt
- [ ] Scopes `user.info.basic` und `video.upload` aktiviert
- [ ] Redirect-URI eingetragen, z. B. `https://app.example.de/tiktok/callback`
- [ ] URL-Präfix für Medien verifiziert (z. B. `https://media.example.de/tiktok/media/`)
- [ ] Privater Testaccount als Sandbox-Testnutzer eingetragen
- [ ] TikTok-App auf dem Testgerät mindestens Version 31.8 (für Fotos)
- [ ] `.env` gesetzt: `TIKTOK_CLIENT_KEY`, `TIKTOK_CLIENT_SECRET`, `TIKTOK_REDIRECT_URI`, `TIKTOK_MEDIA_BASE_URL`
- [ ] Migration ausgeführt, Queue-Worker läuft: `php artisan queue:work --queue=tiktok`

## OAuth

- [ ] `/tiktok/connect` aufrufen → Weiterleitung zu TikTok, Scopes werden angezeigt
- [ ] Nach Zustimmung → Redirect auf `success_redirect`, Account in `tiktok_accounts`
- [ ] `display_name` und `avatar_url` sind gefüllt
- [ ] `access_token` / `refresh_token` sind in der Datenbank verschlüsselt
- [ ] Callback mit manipuliertem `state` → HTTP 403
- [ ] Abbruch im TikTok-Dialog → Redirect auf `failure_redirect` mit Fehlermeldung
- [ ] Optional: `TIKTOK_PKCE=true` testen (Web-Unterstützung ist zu verifizieren)

## Token-Refresh

- [ ] `access_token_expires_at` in der DB auf „jetzt + 5 Minuten“ setzen, Upload starten → Token wird vorher erneuert
- [ ] Prüfen, ob TikTok einen neuen Refresh-Token liefert (Rotation) und dieser gespeichert wird
- [ ] `php artisan tiktok:refresh-tokens --minutes=1500` erneuert alle Tokens
- [ ] Ungültiger Refresh-Token → Event `TikTokAccountReauthorizationRequired`

## Video-Upload

- [ ] MP4 < 5 MB per `FILE_UPLOAD` (ein Chunk)
- [ ] MP4 > 64 MB per `FILE_UPLOAD` (mehrere Chunks, letzter Chunk größer)
- [ ] Video per `PULL_FROM_URL` über die signierte Route
- [ ] Status wechselt zu `SEND_TO_USER_INBOX`, Event `TikTokUploadSentToInbox` mit Caption
- [ ] Inbox-Benachrichtigung erscheint in der TikTok-App, Entwurf lässt sich posten
- [ ] Wird `PUBLISH_COMPLETE` nach dem manuellen Posten gemeldet? (Langzeit-Polling aktivieren)
- [ ] Nicht verifizierte Domain → `UrlOwnershipUnverifiedException`

## Foto-Upload

- [ ] Slideshow mit 2–3 JPEG/WebP-Bildern, Titel und Beschreibung inkl. Emoji und Hashtags
- [ ] Titel/Beschreibung erscheinen im TikTok-Editor
- [ ] `cover(1)` setzt das zweite Bild als Cover
- [ ] PNG wird lokal abgelehnt

## Limits

- [ ] Sechster offener Entwurf innerhalb von 24 h → `PendingShareLimitReachedException`, Job wird nicht wiederholt
- [ ] Mehr als 6 Requests/Minute → Jobs werden verzögert (Rate-Limiter-Middleware)
- [ ] Zählen Chunk-PUTs zum 6-Requests/Minute-Limit? (Ergebnis dokumentieren)

## Disconnect

- [ ] `POST /tiktok/accounts/{id}/disconnect` → Token bei TikTok widerrufen, `revoked_at` gesetzt
- [ ] Mit `delete=1` werden alle Daten gelöscht (DSGVO)
- [ ] App-Autorisierung ist in den TikTok-Einstellungen nicht mehr sichtbar

## Ergebnisse zu offenen Punkten (Spezifikation §15)

| Punkt | Ergebnis | Datum |
|---|---|---|
| Chunk-Regeln / Gültigkeit der `upload_url` | | |
| PKCE für Web | | |
| Max. Videodauer / Auflösung / Fotoanzahl | | |
| `PUBLISH_COMPLETE` nach manuellem Posten | | |
| Einschränkungen nicht auditierter Clients | | |
| Token-Laufzeiten und Refresh-Rotation | | |
| Chunk-PUTs und Rate-Limit | | |
