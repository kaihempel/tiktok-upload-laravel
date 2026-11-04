# Laravel TikTok Upload

[![Tests](https://github.com/kaihempel/tiktok-upload-laravel/actions/workflows/tests.yml/badge.svg)](https://github.com/kaihempel/tiktok-upload-laravel/actions/workflows/tests.yml)

Laravel-Paket, das Videos und Foto-Slideshows über die **TikTok Content Posting API im Upload-Modus**
(Inbox/Entwurf) an einen TikTok-Account überträgt. Es enthält OAuth (Login Kit v2) mit Token-Speicherung
und automatischem Refresh, Chunk-Uploads, `PULL_FROM_URL` über signierte URLs, Status-Polling, lokale
Medienvalidierung, Queue-Jobs, Events und einen `PlatformDriver` für einen übergeordneten Social-Media-Manager.

- PHP ≥ 8.3
- Laravel 12 und 13

> **Upload-Modus:** Videos landen als Entwurf in der TikTok-Inbox. Der Nutzer muss den Entwurf in der
> TikTok-App öffnen und selbst posten. Video-Entwürfe können **keinen Text** enthalten. Die Caption wird
> deshalb nur als `captionForUser` zurückgegeben, etwa für eine Benachrichtigung. Foto-Slideshows
> unterstützen `title` und `description`.

## Installation

```bash
composer require kuweh/laravel-tiktok-upload

php artisan vendor:publish --tag=tiktok-upload-config
php artisan vendor:publish --tag=tiktok-upload-migrations
php artisan migrate
```

`.env`:

```dotenv
TIKTOK_CLIENT_KEY=...
TIKTOK_CLIENT_SECRET=...
TIKTOK_REDIRECT_URI=https://app.example.de/tiktok/callback
TIKTOK_MEDIA_BASE_URL=https://media.example.de   # in TikTok verifizierte Domain bzw. verifiziertes URL-Präfix
```

Empfohlen ist ein eigener Queue-Worker (Redis oder Database):

```bash
php artisan queue:work --queue=tiktok
```

Damit Tokens auch ohne Upload-Aktivität gültig bleiben, sollte der Refresh-Command im Scheduler laufen:

```php
// routes/console.php
Schedule::command('tiktok:refresh-tokens')->hourly();
```

## TikTok Developer Portal

1. App unter developers.tiktok.com anlegen, Plattform „Web“
2. Produkte **Login Kit** und **Content Posting API** hinzufügen
3. Scopes: `user.info.basic`, `video.upload`
4. Redirect-URI eintragen
5. URL-Properties verifizieren: AGB, Datenschutz, Web-URL und die Domain bzw. das URL-Präfix, von dem TikTok Medien abruft (z. B. `https://media.example.de/tiktok/media/`)
6. Datenschutzerklärung um einen TikTok-Abschnitt ergänzen
7. Für die Entwicklung reicht eine Sandbox-App mit Testnutzern, siehe [docs/sandbox-testing.md](docs/sandbox-testing.md)

## Account verbinden

Das Paket bringt optionale Routen mit (`routes.enabled`, Prefix `tiktok`, Middleware `web` + `auth`):

| Methode | Pfad | Zweck |
|---|---|---|
| GET | `/tiktok/connect` | Redirect zu TikTok (`state` in der Session) |
| GET | `/tiktok/callback` | Code-Tausch, Profil laden, Account speichern |
| POST | `/tiktok/accounts/{id}/disconnect` | Token bei TikTok widerrufen (`delete=1` löscht alle Daten) |
| GET | `/tiktok/media/{token}.{ext}` | Signierte Medienauslieferung für `PULL_FROM_URL` (ohne Auth) |

Standardmäßig gehört ein Account dem eingeloggten User. Für Teams o. ä. lässt sich der Owner anpassen:

```php
use KaiHempel\TikTokUpload\Facades\TikTok;

TikTok::resolveOwnerUsing(fn (Request $request) => $request->user()->currentTeam);
```

Ohne die mitgelieferten Routen:

```php
$authorization = TikTok::oauth()->createAuthorizationRequest(); // url, state, codeVerifier
$account = TikTok::oauth()->connect($code, $owner, $codeVerifier);
TikTok::forAccount($account->id)->disconnect(deleteData: true);
```

## Uploads

```php
use KaiHempel\TikTokUpload\Facades\TikTok;
use KaiHempel\TikTokUpload\Jobs\UploadToTikTokInbox;
use KaiHempel\TikTokUpload\Media\PhotoSet;
use KaiHempel\TikTokUpload\Media\VideoFile;

// Video synchron (z. B. in einem eigenen Job)
$result = TikTok::forAccount($accountId)->uploadVideo(
    VideoFile::fromDisk('local', 'videos/clip.mp4'),
    source: 'file',                      // 'file' (FILE_UPLOAD, Chunks) oder 'url' (PULL_FROM_URL)
    reference: $postTargetId,
    caption: 'Ein ruhiger Morgen #herbst',
);

$result->publishId;       // "v_inbox_file~v2.123…"
$result->status;          // PublishStatus::ProcessingUpload
$result->captionForUser;  // "Ein ruhiger Morgen #herbst"

// Foto-Slideshow (immer PULL_FROM_URL)
$result = TikTok::forAccount($accountId)->uploadPhotos(
    PhotoSet::fromDisk('public', ['a.webp', 'b.webp'])
        ->title('Herbst am See')
        ->description('Ein ruhiger Morgen #herbst #natur')
        ->cover(0),
);

// Status
$status = TikTok::forAccount($accountId)->status($result->publishId);

// Asynchron (empfohlen): Upload, danach automatisch Status-Polling
UploadToTikTokInbox::dispatch($accountId, $media, reference: $postTargetId, caption: $caption);
```

`reference` ist ein frei wählbarer String, etwa die ID des PostTargets im Manager. Er wird in allen
Upload-Events mitgegeben.

### Chunking

`FILE_UPLOAD` streamt die Datei per `readStream()` chunkweise von der Laravel-Disk, auch von S3. Die Datei
wird also nie komplett in den Speicher geladen.

- Dateien < 5 MB (oder ≤ `chunk_size`) werden in **einem** Chunk übertragen.
- Sonst gilt `chunk_size` zwischen 5 und 64 MB (Standard: 10 MB) und `total_chunk_count = floor(size / chunk_size)`. Der letzte Chunk nimmt den Rest auf.
- Jeder Chunk wird bei Netzwerkfehlern und 5xx bis zu 3-mal mit exponentiellem Backoff wiederholt.
- Ist die `upload_url` abgelaufen (Zeitüberschreitung oder HTTP 403/404/410), wird der Upload neu initialisiert.

### Medien-URLs (`PULL_FROM_URL`)

| Provider | Beschreibung |
|---|---|
| `SignedRouteUrlProvider` (Standard) | Liefert Dateien über `/tiktok/media/{token}.{ext}` mit `URL::temporarySignedRoute` aus. Disk und Pfad sind verschlüsselt im Token, die TTL steht in `media_url.ttl_minutes`. URLs können nach `SEND_TO_USER_INBOX` sofort invalidiert werden (`media_url.invalidate_after_inbox`). |
| `DiskUrlProvider` | Nutzt `Storage::temporaryUrl()`, z. B. für S3 hinter einer verifizierten CDN-Domain. |

Eigene Provider implementieren `KaiHempel\TikTokUpload\Contracts\MediaUrlProvider`.

## Validierung

Vor jedem Request werden die Medien gegen die TikTok-Vorgaben geprüft. Alle Verstöße landen gesammelt in
`MediaValidationException::$violations`.

- **Video:** Container MP4/WebM/MOV, MIME-Type, max. 4 GB. Mit `probe.enabled = true` prüft ffprobe zusätzlich Codec (H.264/H.265/VP8/VP9), 23–60 fps, Dauer und Auflösung.
- **Fotos:** JPEG/WebP (kein PNG), max. 20 MB, max. 1080p, Anzahl (`photo.max_photos`), Cover-Index, `title` ≤ 90 und `description` ≤ 4000 **UTF-16-Code-Units** (Emoji zählen doppelt).

Alle Grenzwerte lassen sich in `config/tiktok-upload.php` anpassen. Über `transformer` kann ein eigener
`MediaTransformer` vor der Validierung laufen, z. B. ein ffmpeg-Transcoding oder eine PNG→JPEG-Konvertierung.

## Status-Polling

`PollTikTokPublishStatus` gibt sich per `release()` alle `polling.interval_seconds` (15 s) erneut frei, bis
ein Endzustand erreicht ist oder `polling.max_attempts` (80, also ca. 20 Minuten) erschöpft sind.

| TikTok-Status | Reaktion |
|---|---|
| `PROCESSING_UPLOAD`, `PROCESSING_DOWNLOAD` | weiter pollen |
| `SEND_TO_USER_INBOX` | Event `TikTokUploadSentToInbox`, Polling beendet |
| `PUBLISH_COMPLETE` | Event `TikTokPostPublished` |
| `FAILED` | Event `TikTokUploadFailed` mit `fail_reason` |
| Zeitüberschreitung | Event `TikTokUploadTimedOut` |

Mit `polling.long_term.enabled` startet nach `SEND_TO_USER_INBOX` ein zweites, niederfrequentes Polling,
das `PUBLISH_COMPLETE` erkennt, falls TikTok es im Upload-Modus meldet.

## Events

| Event | Payload |
|---|---|
| `TikTokAccountConnected` | accountId, openId |
| `TikTokAccountDisconnected` | accountId |
| `TikTokAccountReauthorizationRequired` | accountId, reason |
| `TikTokTokenRefreshed` | accountId |
| `TikTokUploadInitiated` | accountId, publishId, reference, mediaType |
| `TikTokUploadSentToInbox` | accountId, publishId, reference, captionForUser |
| `TikTokPostPublished` | accountId, publishId, reference, publicPostIds |
| `TikTokUploadFailed` | accountId, publishId?, reference, errorCode, message |
| `TikTokUploadTimedOut` | accountId, publishId, reference |

## Rate-Limits und Quoten

- **6 Requests/Minute pro Account:** Die Job-Middleware `RateLimitedPerTikTokAccount` gibt Jobs verzögert frei.
- **5 offene Inbox-Entwürfe in 24 h:** TikTok antwortet mit `spam_risk_too_many_pending_share`. Daraus wird eine `PendingShareLimitReachedException`, der Job schlägt sofort fehl und wird nicht endlos wiederholt. Zusätzlich zählt das Paket lokal mit (`pending_shares`) und warnt vorab. Mit `block = true` bricht es schon vor dem Request ab.
- **Tägliches Post-Limit:** `spam_risk_too_many_posts` wird zu `DailyPostLimitReachedException`.

## Fehlerbehandlung

```
TikTokException (errorCode, logId, httpStatus)
├── AuthenticationException          access_token_invalid, Refresh fehlgeschlagen
├── ScopeNotAuthorizedException      scope_not_authorized
├── RateLimitException               rate_limit_exceeded (retryAfter)
├── PendingShareLimitReachedException spam_risk_too_many_pending_share
├── DailyPostLimitReachedException   spam_risk_too_many_posts
├── UrlOwnershipUnverifiedException  url_ownership_unverified
├── MediaValidationException         lokale Validierung (violations)
├── UploadFailedException            FAILED / Chunk-Upload fehlgeschlagen
├── ServerException                  5xx (retrybar)
└── UnexpectedResponseException      unerwartete Antwort
```

Netzwerkfehler, 5xx und `rate_limit_exceeded` gelten als retrybar: `UploadToTikTokInbox` wiederholt sie
bis zu 5-mal mit Backoff. Alle anderen Fehler lassen den Job sofort scheitern und lösen `TikTokUploadFailed`
aus. Die `log_id` wird immer geloggt (Kanal über `TIKTOK_LOG_CHANNEL`). Der `Authorization`-Header wird dabei
maskiert, Tokens tauchen nie im Log auf.

## Treiber für den Manager

```php
use KaiHempel\TikTokUpload\Data\PublishRequest;

$driver = TikTok::driver();            // implementiert Contracts\PlatformDriver
$driver->key();                        // 'tiktok'
$driver->capabilities();               // directPublish: false, draftUpload: true, captionInDraft: false (Videos)

$result = $driver->publish(new PublishRequest($accountId, $media, caption: $text, reference: $targetId));
$result->remoteId;                     // "<accountId>:<publishId>"
$result->requiresUserAction;           // true: Nutzer muss in der App posten

$driver->status($result->remoteId);    // PublishState::Pending | Draft | Published | Failed
```

Bei Fotos wird `caption` als `description` übernommen, der Titel kann über `options: ['title' => '…']` gesetzt werden.

## Eigener Token-Store

`token_repository` in der Config kann auf eine eigene Implementierung von
`KaiHempel\TikTokUpload\Contracts\TokenRepository` zeigen, z. B. für Vault. Die Standardimplementierung speichert
in `tiktok_accounts` und verschlüsselt `access_token` und `refresh_token` mit dem `encrypted`-Cast.

## Entwicklung

```bash
composer install
composer test       # Pest
composer analyse    # PHPStan Level 8 (Larastan)
composer lint       # Pint
```

Die GitHub Action unter `.github/workflows/tests.yml` führt die Tests auf PHP 8.3/8.4 mit Laravel 12/13
(jeweils `prefer-lowest` und `prefer-stable`) aus, dazu PHPStan und Pint.

## Offene Punkte

Einige Werte sind gegen die aktuelle TikTok-Dokumentation zu prüfen und deshalb konfigurierbar (Spezifikation §15):

- Chunk-Regeln (min. 5 MB, max. 64 MB, max. 1000 Chunks) und Gültigkeit der `upload_url`
- PKCE für Web-Apps. Es ist deshalb standardmäßig **aus** (`TIKTOK_PKCE=true` aktiviert es; TikTok dokumentiert für Desktop einen hex-kodierten SHA-256-Challenge, einstellbar über `pkce.challenge_encoding`).
- Max. Videodauer und Auflösung im Upload-Modus, max. Anzahl Fotos pro Post
- Ob `PUBLISH_COMPLETE` nach manuellem Posten gemeldet wird (deshalb optionales Langzeit-Polling)
- Ob Chunk-PUTs zum 6-Requests/Minute-Limit zählen. Die Middleware zählt derzeit einen Request pro Job-Ausführung.
- Direct Post ist nicht implementiert. `VideoUploader` und `PhotoUploader` kapseln Endpoint und Payload (`INIT_PATH`, `initPayload()`), damit ein späterer `DirectPostUploader` ergänzt werden kann, ohne die öffentliche API zu brechen.

## Lizenz

MIT
