# Changelog

## Unreleased

- OAuth (Login Kit v2) mit `state`, optionalem PKCE, Token-Speicherung (verschlüsselt) und automatischem Refresh
- Video-Upload in die Inbox per `FILE_UPLOAD` (Chunking, Streaming, Retries) und `PULL_FROM_URL`
- Foto-/Slideshow-Upload (`MEDIA_UPLOAD`) mit Titel und Beschreibung
- Status-Polling inkl. optionalem Langzeit-Polling
- Lokale Medienvalidierung, optional mit ffprobe
- Signierte Medien-URLs, `DiskUrlProvider`
- Rate-Limiter-Middleware, lokales Pending-Share-Tracking
- Queue-Jobs, Events, `PlatformDriver`-Implementierung, `tiktok:refresh-tokens`
