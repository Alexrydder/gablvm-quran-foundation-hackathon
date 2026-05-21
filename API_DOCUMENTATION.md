# GABLVM Quran Foundation Integration Documentation

This document is also published live at https://gablvm.org/quran/about-integration. It explains how GABLVM integrates with the Quran Foundation API to power Sign in with Quran.com, the My Quran Account dashboard, the Quran Player, the Tafsir Lab, and the reading-session plus activity-day tracking.

## Overview

GABLVM integrates with the Quran Foundation API to power several key features including Sign in with Quran.com, the My Quran Account dashboard, the Quran Player, and the Tafsir Lab. The integration uses the official Quran Foundation developer platform and production OAuth2 endpoints at `oauth2.quran.foundation` and `apis.quran.foundation`.

## API Categories and Usage

### Content APIs

**Verses and Recitations**

The system renders Quranic text and audio through the Quran Player, streaming twenty-plus reciter sources from verified endpoints. Both verse-by-verse and chapter playback modes are supported. The live dropdown serves as the authoritative reciter count.

**Tafsir**

The Tafsir Lab is powered by three English tafsirs: Tafsir Ibn Kathir (abridged), Maarif Al-Quran, and Tazkirul Quran. The interface transparently handles consecutive verses that share a single commentary entry and explicitly indicates when a verse lacks commentary in the selected tafsir.

**English Audio Narration**

Real spoken audio translations accompany Arabic recitation, prioritizing clarity and pacing over text-to-speech synthesis.

### User APIs

**Bookmarks (Collections)**

Bookmarked verses synchronize to the user's default Quran.com collection immediately upon creation on GABLVM and appear on subsequent page loads from the Quran.com mobile app. Bookmark side-metadata (reciter ID, playback mode) is stored in the Drupal user record per-uid and does not currently sync across devices.

**Reading Sessions**

Sessions exceeding eight seconds log to Quran.com via two endpoints: `/reading-sessions` for the position log (drives the Continue Reading panel) and `/activity-days` for the daily aggregate (drives the streak engine and Today's Reading panel). The activity-day payload uses the reverse-engineered schema `{type: "QURAN", seconds: N, ranges: ["S:V-S:V"], mushafId: 1}`.

**Streaks**

Active or most recent reading streaks display on the My Quran Account page, computed by the Quran.com streak engine and rendered with screen-reader-friendly markup.

**Goals (Daily Reading Plan)**

Real-time progress against Quran.com mobile app goals renders on the dashboard.

**Activity Days**

Current session statistics (pages, verses, seconds read) surface immediately to confirm logging. The tracker flushes on surah change, audio end, and pagehide via `navigator.sendBeacon`.

**Notes**

Verse notes remain private to the user's Quran.com account while syncing bidirectionally between platforms.

## Authentication

The system uses OAuth2 authorization_code flow with PKCE against `oauth2.quran.foundation` in two modes:

**Sign in with Quran.com**

Anonymous visitors access `/user/login`, select the Quran.com option, complete the OAuth handshake, and return as authenticated users without requiring a separate GABLVM password.

**Post-Hoc Connection**

Existing GABLVM users can link their Quran.com account from `/quran/my-account`, binding the QF identity to their existing user record. Identity binding uses the contributed `externalauth` module.

User API calls employ `x-auth-token` and `x-client-id` authentication schemes. Tokens are stored encrypted at rest in Drupal user data.

The `id_token` is decoded for identity verification. Apple sign-ins omit the `email_verified` claim entirely (claim absent from `profile_keys`, not just set to false); the integration handles this with a carve-out for `@privaterelay.appleid.com` addresses (intrinsically verified by Apple) and only enforces `email_verified === true` on the hijacking-risk email-match-link branch, not on sub-already-linked or new-user-create branches.

## Resilience and Performance

The My Quran Account dashboard executes six per-user API calls concurrently as Guzzle promises rather than sequentially, reducing worst-case render time from approximately sixty seconds to approximately twelve seconds.

Following the May 7-8, 2026 Neon database outage at Quran Foundation, the integration implements:

- Request timeouts of ten seconds per call
- Retry logic for server-side errors (5xx) up to two times with exponential backoff and jitter
- No retry for client-side errors (4xx)
- Graceful "temporarily unavailable" placeholders for failed sections while maintaining dashboard usability

## Accessibility

All integration surfaces meet WCAG 2.2 AA with zero violations and are designed screen-reader-first. The developer behind this work is blind and uses VoiceOver as the primary input modality.

**Key Features**

- iOS Magic Tap gesture support (two-finger double-tap) for play/pause via the iOS Media Session API
- Keyboard-only navigation across all controls (J/L for seek, K for play/pause, N/P for surah navigation, plus or minus for speed, M for mute, question-mark for shortcut help)
- Tuned screen reader announcements avoiding double-reads, hidden decorative content, and non-focus-stealing progress updates
- Clean narration with VoiceOver, JAWS, and NVDA across all flows
- Content rules: no em-dashes, no markdown tables, no emojis, no box-drawing characters in any user-facing or screen-reader-read text

## Issue Reporting

Integration issues should be reported to support@gablvm.org. Security disclosures follow the published security policy at https://gablvm.org/.well-known/security.txt.

---

Platform: GABLVM (Global Advocacy of Blind and Low Vision Muslims). Code license: see individual module files. Documentation last updated May 21 2026 for the Quran Foundation Hackathon submission.
