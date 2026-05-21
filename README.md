# GABLVM Quran Foundation Hackathon submission

Focused snapshot of the Quran Foundation integration code from gablvm.org, prepared for the Quran Foundation Hackathon (deadline May 20 2026 PT). The full production codebase has roughly 20,000 lines across security, theme, and other features; this repository contains only the files that implement the QF API integration so judges can review the relevant work without wading through unrelated platform code.

## Live integration

- Production site: https://gablvm.org
- Sign in with Quran.com: https://gablvm.org/user/login
- My Quran Account dashboard: https://gablvm.org/quran/my-account
- Quran Player: linked from the homepage
- Tafsir Lab (hidden route): https://gablvm.org/quran/tafsir
- API documentation page: https://gablvm.org/quran/about-integration (mirrored in this repo as `API_DOCUMENTATION.md`)

## What's in this snapshot

Thirty files from the `gablvm_core` Drupal 11 module, all directly related to the Quran Foundation integration. No security module, no theme code, no Prayer Times, Hijri Calendar, or other unrelated features.

### Authentication and identity

- `src/Service/QuranFoundationOauthService.php`: OAuth2 with PKCE against `oauth2.quran.foundation`. Handles authorization-code exchange, refresh tokens, `id_token` decoding, and the Apple Private Relay carve-out for missing `email_verified` claims.
- `src/Controller/QuranFoundationOauthController.php`: Sign-in entry point, callback, identity-binding logic, three-branch hijacking-risk model (sub-match link, email-match link, new-user create).
- `css/qf-signin-button.css`: Sign in with Quran.com button styling on `/user/login`.

### Quran content and recitations

- `src/Service/QuranApiService.php`: Wraps the Quran Foundation v4 content endpoints. Handles authentication headers, request timeouts, exponential-backoff retries on 5xx, and graceful failure on 4xx.
- `src/Controller/QuranPlayerController.php`: Renders the Quran Player with twenty-plus reciter sources, English audio narration, and per-verse audio.
- `js/quran-player.js`: Player UI logic, keyboard shortcuts (J/L for seek, K for play/pause, N/P for surah, +/- for speed, M for mute, ? for help), screen-reader announcements.
- `js/quran-player-qf.js`: Reading-session tracker that flushes to QF's `/reading-sessions` (position log) and `/activity-days` (daily aggregate) on surah change, audio end, and `pagehide` via `navigator.sendBeacon`.
- `templates/gablvm-quran-player.html.twig`: Player markup with keyboard shortcut dialog and accessibility-first structure.
- `css/quran-player.css`: Player styling.
- `docs/ENGLISH_AUDIO_TRANSLATION.md`: Documentation on the English audio integration.

### My Quran Account dashboard

- `templates/gablvm-quran-account.html.twig`: Dashboard markup showing bookmarks, streaks, goals, activity days, and notes.
- `js/qf-account.js`: Bookmark remove, note add/remove, dashboard refresh logic.
- `css/qf-account.css`: Dashboard styling.

### Tafsir Lab

- `src/Controller/QuranTafsirLabController.php`: Hidden route handler at `/quran/tafsir` powering scholarly commentary navigation across three English tafsirs.
- `templates/gablvm-quran-tafsir-lab.html.twig`: Surah picker, verse-by-verse rendering with grouped consecutive verses sharing a single commentary, "no commentary available" indicator.
- `css/quran-tafsir-lab.css`: Lab styling.
- `js/listen-aloud.js`: Site-wide screen-reader-friendly text-to-speech control, used by the Tafsir Lab.
- `css/listen-aloud.css`: Listen-aloud styling.

### Module configuration

- `gablvm_core.info.yml`: Drupal module manifest.
- `gablvm_core.routing.yml`: Route definitions for all QF-related endpoints.
- `gablvm_core.services.yml`: Service container definitions.
- `gablvm_core.libraries.yml`: CSS/JS library declarations.
- `gablvm_core.links.menu.yml`: Menu links.
- `gablvm_core.permissions.yml`: Module permissions.
- `gablvm_core.module`: Hook implementations (broader than just QF; included whole for context).
- `config/schema/gablvm_core.schema.yml`: Configuration schema.
- `config/install/gablvm_core.settings.yml`: Default config values.
- `CHANGELOG.md`: Development history.

## Accessibility approach

Built by a blind developer using VoiceOver daily. Zero WCAG 2.2 AA violations across all integration surfaces. Content rules enforced throughout: no em-dashes, no markdown tables, no emojis, no box-drawing characters in any user-facing or screen-reader-read text. iOS Magic Tap gesture for play/pause via the Media Session API.

## How to read this

For a guided tour, start with `API_DOCUMENTATION.md` (or the live version at https://gablvm.org/quran/about-integration), then read `src/Service/QuranFoundationOauthService.php` and `src/Controller/QuranFoundationOauthController.php` for the auth flow, then `src/Service/QuranApiService.php` and `js/quran-player-qf.js` for the reading-session and activity-days integration.

## Author

Yahya Abdikadir, founder of GABLVM (Global Advocacy of Blind and Low Vision Muslims). Solo team. Built end to end as one person.
