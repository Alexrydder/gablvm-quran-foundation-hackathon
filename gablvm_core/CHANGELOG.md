# GABLVM Core Module - Changelog

## 2.1.14 — 2026-05-05 (preserve bookmark meta across disconnect/reconnect)

### Fixed
- **`disconnectUser()` no longer wipes `qf_bookmark_meta`.** That map is local-only listening context (reciter + mode per bookmark, keyed by `surah:verse`) and was being deleted alongside the OAuth tokens on disconnect. After reconnecting to the same Quran.com account, the bookmarks came back from QF but the jump URLs lost their `&reciter=` and `&english=` params — the reciter dropdown rendered empty on `/quran-listen` and the auto-load did not fire (it gates on `reciterSelect.value`). Bookmark meta is local-only and should survive disconnect/reconnect cycles by design. Existing meta wiped on previous disconnects is gone permanently; users will need to re-bookmark affected verses to repopulate the listening context. Going forward, the meta persists.

## 2.1.13 — 2026-05-05 (today's reading panel, real QF endpoint paths)

### Added
- **Today's reading panel on /quran/my-account.** Shows real-time activity tracking ("Logged today: N verses, M seconds") plus today's goal progress when set. Closes the gap where the streaks panel only updated at midnight UTC and users had no immediate feedback that their reading session was logged. Fetched via `getTodaysActivityDay()` (filters `/auth/v1/activity-days?from=YYYY-MM-DD&to=YYYY-MM-DD`) and `getTodaysGoalPlan()` (`/auth/v1/goals/get-todays-plan?type=QURAN_PAGES&mushafId=1`).

### Fixed
- **Discovered the correct QF endpoint paths.** Earlier 403 errors against `/goals/today`, `/today-goal-plan`, and `/activity-days/today` were not scope issues — those paths simply don't exist (Quran Foundation returns 403 for unknown paths instead of 404). Per Basit's clarification on May 5: real path for today's plan is `/auth/v1/goals/get-todays-plan` (with `type` and `mushafId` query params), and there is no per-day path for activity-days; filter the list endpoint by `from`/`to` instead.

## 2.1.12 — 2026-05-04 (note delete + auto-inject on save)

### Added
- **Each note in /quran/my-account now has a Remove button.** Click removes from the dashboard immediately and from your Quran.com account via QF's `DELETE /notes/{id}`. Mirrors the bookmark-remove pattern. New service method `QuranFoundationOauthService::removeNote()`, new controller `noteRemove()`, new route `gablvm_core.qf_note_remove` at `/quran/api/note/remove`.
- **Saving a note auto-injects the new entry into the on-page list** without a full browser refresh. The note-create AJAX response now includes the new note's id, body, and ranges; the JS prepends a fresh `<li>` to `#qf-notes-list` and hides the "No notes yet" empty state. Status message changed from "Note saved. Reload to see it in your list." to "Note saved." since the list updates inline.

qf_account library 1.0 → 1.1.

## 2.1.11 — 2026-05-04 (note save + surah-mismatch guard + dead-row cleanup)

### Fixed
- **Note save now works.** Quran Foundation's POST `/notes` endpoint added a required `saveToQR` field (Quran Reflect cross-post toggle) sometime before 2026-05-04. Without it, every note submission returned 422 ValidationError "saveToQR is required" — user saw "Unable to save note. Please try again." in the UI. `QuranFoundationOauthService::addNote()` now sends `saveToQR: false` to keep notes private to the user's Quran.com account. Verified via drush eval roundtrip.
- **Surah-mismatch guard in `maybeSeekChapterBookmark`.** Edge case from open-loop list: if a user clicks a bookmark, the player loads, but they change the surah dropdown before pressing play, the seek would fire against the new surah's audio with the old surah's target verse number — could land at an arbitrary position. Now bails when `surahSelect.value !== window.gablvmQuranInitialVerseTarget.surah` and nulls the target. quran_player_qf library 2.0 → 2.1.

### Cleaned up
- **238 bogus uptime_history rows deleted** for `support-portal` and `status-page` services in the gablvm-support-db D1 database. These were status='down' rows from before April 19 when the probe was self-referential (the worker probing itself). The render-side fix (commit 12c2d830) had hidden them from display, but they were dead weight in the DB. Removed via `DELETE FROM uptime_history WHERE service_id IN ('support-portal','status-page')` through the Cloudflare connector. 238 rows confirmed in the response (changes=238).

## 2.1.10 — 2026-05-04 (bookmark boundary grace)

### Changed
- **Bookmark capture in chapter mode now anticipates the next verse when at a verse boundary.** Previously, if a user clicked "Bookmark this verse" during the last fraction of a second of verse N — which feels like "right after it ended" to a listener — the system captured verse N (still technically playing). On resume the player rewound to verse N's start, forcing the user to listen to the verse they thought they had already finished. Now `currentVerseFromTime()` in `quran-player-qf.js` applies a 500ms grace at the end of each verse: if `timeMs >= cumulative_end_ms - 500`, the bookmark target is verse N+1 instead of N. Verse 12 in Surah 12 is 6.86s long, so this affects the last 7% of the verse — bookmarks during the meat of the verse still capture the right verse. Resume seek logic unchanged. quran_player_qf library 1.9 → 2.0.

## 2.1.9 — 2026-05-04 (UX polish)

### Changed
- **Chapter-mode bookmark deep-link now jumps straight to the bookmarked verse with no Bismillah and no verse-1 preroll.** Previously the player loaded normally (Bismillah → start of surah → seek to verse 13 once timings arrived ~1 second later), which played a confusing "Bismillah, Alif Lam Ra, [jump to verse 13]" sequence. The DOMContentLoaded auto-load now prefetches `/api/quran/chapter-timings/{surah}/{reciter}` BEFORE calling `loadSurah(...)`, computes the target second offset, and passes it as `seekToTime`. That triggers the existing skip-Bismillah branch at `loadSurah:1448` (which sets `cachedBismillahUrl=null` and `activeAudio.src = data.audioUrl`). User clicks bookmark → brief load (~500ms while timings fetch) → audio is positioned at the bookmarked verse → user presses play → hears verse 13 starting immediately, no preroll. Falls back to normal load if timings fetch fails or doesn't include the target verse. Verse-by-verse English mode is unaffected (handled by `loadSurahVerseByVerse`'s playlist-jump logic). quran_player library 1.8 → 1.9.

## 2.1.8 — 2026-05-04 (THE actual root cause)

### Fixed
- **Chapter-mode bookmark jump-to-verse — actually working now.** The previous fix attempts (timings race, Bismillah guard, debug logging, query-string preservation) were all valid in isolation but couldn't fix the core problem because the bookmark target was NEVER getting set in the first place. Drupal's `drupalSettings` JSON blob renders around line 2900+ of the HTML body; the inline player IIFE that reads it sits at line ~770. The IIFE runs at script-tag-parse time, before `window.drupalSettings` is populated. The check `if (qf_qs.currentVerse && qf_qs.currentSurah)` always failed silently because `qf_qs` was `{}` (default for missing key) and both fields were `undefined`. The diagnostic logging in 2.1.6 surfaced the issue: every visit logged `No bookmark target in drupalSettings (currentVerse=undefined, currentSurah=undefined)`. Fix: defer the bookmark-target read to DOMContentLoaded by wrapping it in a function and registering as a listener if the document is still loading. The verse-by-verse path (loadSurahVerseByVerse line 760+) and chapter-mode path (maybeSeekChapterBookmark in quran-player-qf.js) both work correctly once the target is actually set. quran_player library 1.7 → 1.8.

## 2.1.7 — 2026-05-04

### Fixed
- **`/quran/my-account?debug=1` now preserves `?debug=1` across the login redirect.** Previously, anonymous visitors hitting `/quran/my-account?debug=1` were redirected to `/user/login?destination=/quran/my-account` — the query string was hardcoded out, so after login the page rendered without debug mode and the bookmark URLs were emitted without `&debug=1`. Fix: use `Request::getRequestUri()` (path + query string) as the destination, so the round-trip through login preserves any diagnostic params.

## 2.1.6 — 2026-05-04 (diagnostics)

### Changed
- **Chapter-mode bookmark seek now logs to the on-page debug panel under `?debug=1`.** The QF library previously only used `console.log` for its decisions, which doesn't surface in the inline debug panel that VoiceOver users can read. The inline player now exposes its `log()` function as `window.gablvmDebugLog`; the QF script's `qflog()` wrapper prefers it over `console.log` when present. Every entry/exit point of `maybeSeekChapterBookmark` now logs a clear reason (e.g. `seek defer (Bismillah guard): quran-audio-a duration=2.7s, target=144.0s` or `SEEK FIRED: quran-audio-a.currentTime = 144.0s (verse 13, reason=loadedmetadata-quran-audio-a)`). Plus a log line when the bookmark target is set on page init. quran_player library 1.6 → 1.7, quran_player_qf 1.8 → 1.9.

## 2.1.5 — 2026-05-04

### Fixed
- **Chapter-mode bookmark jump-to-verse now works for surahs with Bismillah preroll** (i.e., 112 of 114 surahs — every surah except Al-Fatiha and At-Tawbah). The previous fix added a retry hook for the timings race, but missed that the active audio at first `loadedmetadata` is the ~3-second Bismillah preroll, not the surah audio. The seek logic clamped the bookmark target (e.g. 144 seconds for Surah 12 verse 13) to `Bismillah.duration - 0.1` ≈ 2.9 seconds, succeeded on the wrong audio, and nulled `window.gablvmQuranInitialVerseTarget`. When Bismillah ended and the player loaded the surah audio (`gablvm-quran-player.html.twig:1251`: `activeAudio.src = surahUrl; activeAudio.load();`), no second seek attempt fired — surah played from 0:00. Fix: in `maybeSeekChapterBookmark`, bail if `rawSeekSec >= active.duration - 0.5`. Target stays set, fires again on the post-Bismillah surah audio's own `loadedmetadata`. quran_player_qf library 1.7 → 1.8.

## 2.1.4 — 2026-05-03

### Fixed
- **Chapter-mode bookmark jump-to-verse no longer falls back to verse 1.** Race condition: when the gapless audio's `loadedmetadata`/`canplay` events fired BEFORE the chapter-timings fetch (`/api/quran/chapter-timings/{surah}/{reciter}`) completed, the seek logic bailed at the empty-timings check and never re-attempted, leaving the audio at currentTime=0. The fetchTimings success handler in `js/quran-player-qf.js` now calls `maybeSeekChapterBookmark()` after timings land, so whichever piece (audio or timings) finishes last triggers the seek correctly. No-op when the seek already succeeded since `window.gablvmQuranInitialVerseTarget` is nulled on first success. quran_player_qf library 1.6 → 1.7.

## 2.1.3 — 2026-05-02

### Fixed
- **Arabic Hijri compound month names render with the correct space.** The Aladhan API returns `ذوالقعدة` and `ذوالحجة` without the space between the two words. Standard Arabic spelling is `ذو القعدة` (Dhu al-Qa'dah, month 11) and `ذو الحجة` (Dhu al-Hijjah, month 12). `PrayerTimesController::buildHijriDateMarkup()` now normalizes the Arabic month string with a `str_replace` before rendering the `lang="ar"` span, so VoiceOver / NVDA / JAWS read the standard Arabic spelling. Other 10 Hijri months are single words and unaffected.

## 2.1.2 — 2026-04-28

### Fixed
- **Sunrise and Sunset rows on `/prayer-times` are visually distinguished from prayer rows.** They had been listed under the "Prayer" column header even though they're solar event markers, not prayers. Each solar row now carries a `solar-event-row` class (warm-ivory background, italic warm-muted-gray text) and an inline italic descriptor — "(Solar event, not a prayer)" for Sunrise; "(Solar event, not a prayer; Maghrib begins at sunset)" for Sunset, making explicit that Maghrib is the prayer at sunset. Colors come from the illuminated-manuscript palette (`--gablvm-bg`, `--gablvm-text-muted`, `--gablvm-gold-text`). prayer_times library 2.2 → 2.3.

## 2.1.1 — 2026-04-27 → 2026-04-28

### Fixed
- **Anonymous visitors clicking "Connect your Quran.com account" no longer hit the standard Drupal access-denied page.** The OAuth login route is now open access; the controller redirects anon visitors through `/user/login` with a destination chain that brings them back into the OAuth flow once they have a Drupal session. Same fix applied to `/quran/my-account`.
- **`?destination=` query string no longer overrides the OAuth redirect target.** Drupal's `RedirectResponseSubscriber` rewrites any `RedirectResponse`'s target to the inbound `?destination=` value — including `LocalRedirectResponse` and `TrustedRedirectResponse` subclasses, despite a misleading docblock. Both the anonymous and authenticated branches of the OAuth login controller now strip `?destination=` from the request before returning, so the redirect actually lands at /user/login (anon) or oauth2.quran.foundation (authenticated).
- **Bookmark deep-link from /quran/my-account starts the player at the saved verse,** not always verse 1. Verse-by-verse mode finds the verse in the playlist by `verse_key`. Chapter (gapless) mode now uses a cumulative per-verse-duration timeline (fetched from QF's `recitations/{reciter}/by_chapter/{surah}?fields=segments`) to seek the gapless audio to where the bookmarked verse starts. Approximate ~5% — chapter MP3 and per-verse MP3s have slightly different recording pacing — but lands in the right neighborhood.
- **"Bookmark this verse" button captures the actually-playing verse,** not always verse 1. Inline player publishes `window.gablvmCurrentSurahVerse` from `playCurrentVerse()` in verse-by-verse mode and from a timeupdate-driven mapper against the cumulative timing array in chapter mode. The bookmark button reads the live value.
- **Reciter and English-mode are restored when jumping to a bookmark.** When a bookmark is created, the reciter dropdown value and english-checkbox state are saved to `user.data:qf_bookmark_meta` keyed by `surah:verse`. The /quran/my-account jump URL surfaces them as `?reciter=N&english=1` and the player controller honors them as default-reciter override and englishOn flag.
- **Bookmark deep-link state is rendered into the HTML markup,** not synthesized from JavaScript after page load. Twig now writes `selected` on the matching reciter `<option>` and `checked` on the English checkbox based on `reciter_preselected` and `english_on` template vars. The previous `Drupal.behaviors`-with-setTimeout approach raced against the inline player's synchronous init and silently dropped users into chapter mode even when `?english=1` was set.
- **Inline-player auto-load is deferred to DOMContentLoaded.** End-of-IIFE auto-load fired BEFORE DOMContentLoaded, which meant the `englishTranslationCheckbox` variable (assigned inside another DOMContentLoaded handler in the same script) was still `null` at the auto-load point. `loadSurahWithMode` read `null.checked` as falsy and routed every deep-link to chapter mode regardless of the URL parameter. Wrapping the auto-load itself in DOMContentLoaded fixes the race.

### Added
- **`?debug=1` propagation from /quran/my-account.** The Quran Player already supports `?debug=1` for its inline debug log panel. The account dashboard now reads `?debug=1` and appends it to every jump-to-verse URL it emits (bookmarks list and recent-reading list), so a single `?debug=1` on the dashboard propagates to all downstream player loads — useful for triangulating bookmark playback issues in the field.

## 2.1.0 — 2026-04-27

### Added
- **Connect your Quran.com account at /quran/my-account.** Bookmarks, notes, reading streaks, and reading-session positions now sync between gablvm.org and the Quran.com app and website. Sign in with Apple via Quran.com is the default flow; the OAuth handshake uses authorization_code with PKCE and refresh-token rotation, with tokens stored per-Drupal-user in `user.data`.
- **"Bookmark this verse" button on /quran-listen** captures the currently playing verse and saves it to the user's Quran.com Favorites collection (POST `/auth/v1/collections/__default__/bookmarks`). Confirmation status reads "Bookmarked verse 2:255" so the user knows which verse landed.
- **My Bookmarks dashboard at /quran/my-account.** Lists all bookmarked verses with jump-to-verse deep links, per-verse remove buttons, an inline form for per-verse notes, a streak panel (current days + active/broken status), and a "Continue reading" panel of recent reading positions.
- **Reading-session auto-log during playback.** The player POSTs to `/auth/v1/reading-sessions` ~8 seconds after playback begins, debounced per surah change. Quran Foundation's API additionally dedupes within ~20 minutes server-side.
- **Production cutover behind a config flag.** `gablvm_core.settings:quran_api.environment` flips between `preproduction` and `production`. The OAuth service reads it and selects the appropriate auth/API base URL plus client_id/secret pair, so we can revert in seconds via `drush config-set` if anything breaks.

### Changed
- **Quran Foundation integration cut over from pre-production to production endpoints** (`oauth2.quran.foundation`, `apis.quran.foundation`).

### Notes
- Pre-production user data does not carry over to production. Visitors who tested the connection during the pre-prod phase will need to reconnect on production and will see an empty bookmarks/notes/streaks state.
- Daily reading-goal panel deferred. The Quran Foundation `/auth/v1/goals/*` and `/auth/v1/activity-days/today` endpoints return 403 even with parent `goal` and `activity_day` scopes granted; they likely require `goal.estimate` and `activity_day.estimate` sub-scope grants on the client. Will revisit when shipping the goals UI.

## 2.0.10 — 2026-04-26

### Changed
- **External-link interstitial dialog now opens with focus on the title heading.** Previously `openModal()` focused the "Stay on gablvm.org" cancel button after a 20ms delay. Same problem as the location dialogs — VoiceOver users heard "Stay on gablvm.org, button" without context. The `<h2 id="gablvm-extlink-title">` heading now has `tabindex="-1"` and the focus call targets it, with the cancel button retained as fallback. The existing focus trap (`[tabindex]:not([tabindex="-1"])`) already excludes the heading, so Tab cycling is unchanged. Library bumped 1.3→1.4. Folded into public release v2.10 alongside the location-dialog fix.


## 2.0.9 — 2026-04-26

### Changed
- **Location dialog opens with focus on the heading, not the Continue button.** Previously `dialog.showModal()` was followed by `continueBtn.focus()` after a 100ms delay. VoiceOver users heard "Continue, button" with no context. Both dialog headings now have `tabindex="-1"` and the focus call targets the heading instead, so VoiceOver reads "Location Access" and the explanation paragraph before the user reaches the action buttons. Continue is retained as a fallback if the heading element is ever missing. The 100ms delay stays — it's still required for Safari iOS VoiceOver to acknowledge the modal state. Same change applied to the Qibla Finder dialog. Library versions bumped (prayer_times 2.0→2.1, qibla_finder 1.x→1.1) to bust aggregated-asset caches.


## 2.0.8 — 2026-04-23

### Changed
- **Removed `aria-describedby` from location dialog.** Per Yahya: VoiceOver was re-reading the body paragraph each time focus moved between the Continue and No Thanks buttons. The body paragraph is still reachable by swiping/scrolling after the heading; it just no longer gets attached to each button via the describedby relationship. `aria-labelledby` (pointing at the h3 heading) is kept.


## 2.0.7 — 2026-04-23

### Changed
- **Removed `aria-label` overrides on the location-dialog Continue and No Thanks buttons.** Dialog body already explains what Continue does; VoiceOver now reads the plain visible button text.

### Added
- **Diagnostic logging around `navigator.geolocation.getCurrentPosition()` calls in both paths** (auto-request and button-click). Logs the lat/lng/accuracy on success or the error code+message on failure, so `?debug=1` reveals whether Safari is silently granting with a cached position, silently denying at the OS level, or timing out — previously the browser swallowed this information and users saw "nothing happen."


## 2.0.6 — 2026-04-23

### Fixed
- **Strip `?resetLocation` from URL after running, to stop the refresh loop.** Previously, once a user visited `/prayer-times?resetLocation=1`, every subsequent refresh re-triggered the reset, re-clearing the denied flag, and the dialog re-appeared. Now we `history.replaceState()` to remove the param after running it once. One reset, no loop.
- **Safari VoiceOver now reliably announces and focuses the Continue button in the location dialog.** Calling `continueBtn.focus()` synchronously right after `dialog.showModal()` did not give Safari iOS VoiceOver enough time to sync with the modal state, so screen-reader users would sometimes land on Cancel or stay outside the dialog and only find "No Thanks." Fix: 100ms delayed `focus()`. Also added explicit `role="dialog"`, `aria-modal="true"`, and `aria-describedby` to the dialog markup, plus more explicit `aria-label`s on both buttons so VoiceOver speaks the full intent ("Continue and allow location access" / "No thanks, I will enter location manually"). Same focus-delay fix applied to Qibla Finder.


## 2.0.5 — 2026-04-23

### Added
- **`?resetLocation=1` URL param to force-clear our dialog's acceptance flags.** Useful for testing (especially in Safari private browsing, where localStorage persists across all private tabs in the same private session until every private tab is closed — a prior acceptance leaks into what feels like a "fresh" private session). Also useful for users who want to revoke our in-app acceptance and be re-prompted. The param clears `gablvm_location_explained`, `gablvm_location_denied`, and the per-session asked flag. It does NOT revoke Safari's native geolocation permission — that still lives in Safari's settings.


## 2.0.4 — 2026-04-23

### Fixed
- **Auto-request location dialog now appears on first visit even when server pre-fills lat/lng in URL.** Previously `autoRequestLocation()` bailed out if the URL already contained `?lat=X&lng=Y`, assuming user had granted previously. But those params can come from server-side IP geolocation fallback, which is imprecise — so first-time visitors (including private-browsing users whose cookies/localStorage reset each session) never saw the dialog and never got asked for accurate GPS. Replaced the URL-param check with a localStorage `gablvm_location_explained` check: if the user has explicitly accepted the dialog in any past session on this device, skip; otherwise prompt, regardless of what's in the URL. Matches Yahya's private-browsing Safari test case.


## 2.0.3 — 2026-04-23

### Fixed
- **Safari: Continue button in location dialog now triggers the native permission prompt.** 2.0.2 fixed Safari so the dialog appears, but clicking Continue silently failed to request location. Root cause: Safari iOS consumes the user-gesture activation when `dialog.close()` runs synchronously, so by the time `callback()` reached `navigator.geolocation.getCurrentPosition()` the activation window was gone and Safari dropped the native prompt. Reordered `onContinue` to fire the callback BEFORE closing the dialog, guaranteeing geolocation is called while activation is still fresh. Same fix applied to Qibla Finder.


## 2.0.2 — 2026-04-23

### Fixed
- **Location permission dialog now works on Safari iOS 16+.** Previously the dialog relied on `navigator.permissions.query({name:'geolocation'})` which Safari now resolves (instead of throwing as older iOS did), causing `dialog.showModal()` to be called from a Promise continuation outside the user-gesture context Safari requires — the dialog never appeared on Safari, though Chrome was lenient. Fix: always open the dialog synchronously from the click handler and remember acceptance in localStorage (`gablvm_location_explained`) so repeat visitors aren't re-prompted. Same fix applied to Qibla Finder (`gablvm_qibla_location_explained`). No regression on Chrome/Firefox.


## [2026-04-16] - Security audit (no code changes)

### Drupal 11.3.7 advisory review

Drupal core 11.3.6 was upgraded to 11.3.7 in response to three core security advisories. After applying the core upgrade, both custom modules in this repository were audited against the affected APIs to confirm we are not exposed through our own code. No custom module code was changed by this audit.

- **SA-CORE-2026-001 (CVE-2026-6365)** — Critical XSS via insufficient sanitization of options passed to Drupal's jQuery AJAX modal dialog API. Audit grep across `gablvm_core` and `gablvm_security` for `use-ajax`, `data-dialog-type`, `DialogLibrary`, `openModalDialog`, and `drupal.dialog`. Zero matches. Neither module uses Drupal's AJAX modal dialog API. Not affected.
- **SA-CORE-2026-002 (CVE-2026-6366)** — Moderately critical gadget chain via `unserialize()` requiring a separate vulnerability to deliver untrusted data. Audit grep for `unserialize(`. One match in `gablvm_security/src/ThreatDetectorService.php` line 570. The call already passes `['allowed_classes' => FALSE]` as the second argument, which is the PHP-recommended mitigation: it prevents PHP from instantiating any object during deserialization, so attacker-controlled gadget chains cannot fire. The defensive comment on the prior two lines documents this intent. Already mitigated, no change needed.
- **SA-CORE-2026-003 (CVE-2026-6367)** — Moderately critical stored XSS via the CKEditor 5 entity suggestion autocomplete when adding links. Audit grep for `entity_suggestion`, `entityAutocomplete`, and `ckeditor5`. Zero matches in custom module code. We use Drupal's stock CKEditor 5 integration with no customization of the entity suggestion subsystem. Not affected.

**Verdict.** Both custom modules were unaffected by all three advisories. The Drupal core upgrade closes the underlying issues in core itself. Per the dual public/internal version policy in `versioning.md`, no internal `.info.yml` version was bumped because no module code changed. The audit is recorded here so future security reviews have a starting point.

## [2026-02-16] - Resource Pages Redesign, URL Aliases, Cloudflare Cache Fix + Bug Fixes

### Added - Ramadan 2026 Resource Playlists
- Yaqeen Institute: "The Name I Need" (Dr. Omar Suleiman) — 30 for 30 daily series
- Bayyinah: "Surah Al-Muddaththir" (Ustadh Nouman Ali Khan) — Quran & Tafsir
- Mufti Menk: "Life of the Final Messenger" — daily Ramadan series

### Added - Resource Pages View Display & Styling
- Created `core.entity_view_display.node.resource.default.yml` — individual resource pages now show category, description, external link, and file download
- Added `css/resources.css` — full styling for individual pages and listing table (buttons, badges, mobile cards, accessibility)
- Attached resources CSS via `hook_preprocess_node()` and `hook_page_attachments()`
- Renamed file field label from "Upload File" to "Download"

### Added - Pathauto URL Patterns
- Resources: `/resources/[title]` (was `/node/[nid]`)
- Events: `/events/[title]` (was `/node/[nid]`)
- Old node URLs 301 redirect to clean aliases



### Fixed - Cloudflare Cache Serving Authenticated Pages to Anonymous Users
- `gablvm_core_node_insert()` purges all Cloudflare cache on content creation
- Combined with the "Cache Everything" page rule, this caused Cloudflare to re-cache the admin's authenticated page (toolbar, status messages) and serve it to anonymous visitors
- Fix: Added Cloudflare Cache Rule to bypass cache when request contains a Drupal session cookie (`SESS`)

### Bug Fixes: API Resilience, Midnight Refresh, Accessibility

### Fixed - Quran API Retry with Backoff
- `request()` now retries up to 2 times on 5xx server errors with 1s/2s delays
- Invalid JSON responses logged instead of silently returning null
- `getVerseAudioFiles()` logs when no audio is returned

### Fixed - Midnight Refresh Race Condition
- `prayer-times.js` and `hijri-calendar.js` had overlapping setTimeout/setInterval that could both fire at midnight
- Added `refreshing` guard to prevent double page reload

### Fixed - Calendar Null Pointer in Screen Reader Announcements
- `announceCell()` and `selectDate()` now check for null before accessing `.textContent`

### Fixed - Quran Player Verse Error Feedback
- `loadVerses()` error now shows visible status message to user

### Files Modified
- `src/Service/QuranApiService.php` — retry/backoff in `request()`, json validation, logging
- `js/prayer-times.js` — `setupMidnightRefresh()` guard
- `js/hijri-calendar.js` — `setupMidnightRefresh()` guard, null checks in `announceCell()` / `selectDate()`
- `js/quran-player.js` — `showStatus()` call in verse fetch error handler

---

## [2026-02-15] - Calendar Timezone Fix + Banner Removal

### Fixed - Islamic Calendar Showing Server Date Instead of User's Local Date
- `getCurrentHijriDate()` used `date('d-m-Y')` which returns the server's UTC date
- Users in timezones ahead of or behind UTC could see the wrong Hijri day (off by one)
- Service now accepts an optional Gregorian date parameter from the browser
- Controller reads `local_date` query param and passes it through
- JavaScript detects browser's local date and redirects if it differs from server's date
- Navigation (month/year switching) preserves the `local_date` parameter

### Removed - Prayer Times Fix Notification Banner
- Banner announcing the Feb 11 bug fixes removed from `gablvm-prayer-times.html.twig`

### Files Modified
- `src/Service/HijriCalendarService.php` — `getCurrentHijriDate()` accepts optional date
- `src/Controller/HijriCalendarController.php` — reads and validates `local_date` query param
- `js/hijri-calendar.js` — `checkLocalDate()`, updated `submitForm()` to carry local date
- `templates/gablvm-prayer-times.html.twig` — banner removed

---

## [2026-02-14] - Cloudflare Auto-Purge on Content Changes

### Added - Automatic Cloudflare Cache Purge
- When any node is created, updated, or deleted, the module calls Cloudflare's `purge_everything` API
- Ensures users see content updates immediately despite aggressive edge caching
- `drupal_static()` dedup prevents multiple purges per request
- 5-second timeout so content saves are never blocked by Cloudflare API issues
- Failures logged but don't prevent content from saving

### Files Modified
- `gablvm_core.module` — `gablvm_core_node_insert()`, `gablvm_core_node_update()`, `gablvm_core_node_delete()`, `_gablvm_core_purge_cloudflare_cache()`

---

## [2026-02-14] - Phone Sleep Fix + Accessibility Audit

### Fixed - Countdown Not Advancing After Phone Sleep/Wake
- `setInterval`/`setTimeout` are paused when phone is locked; on wake, stale timer state caused countdown to show ~24 hours for the same prayer
- Added `visibilitychange` listener to recalculate next prayer when page becomes visible
- Fixed timer race condition in `startCountdownUpdate()` where arrival detection could leave a competing interval running
- Cleared Drupal JS aggregation cache that was still serving old countdown code

### Fixed - Accessibility Issues from Comprehensive Audit
- Close button `×` now wrapped in `aria-hidden` (was announced as "times" by screen readers)
- Tomorrow notice has `aria-live="assertive"` for dynamic display changes
- Location errors now use `aria-live="assertive"` to interrupt screen reader immediately
- Prayer arrival announcement timeout extended from 5s to 10s (screen readers need more time)
- Error status border added for color-blind users (not just color change)
- Removed redundant `role="table"` on `<table>` element

### Files Modified
- `js/prayer-times.js` — visibilitychange handler, timer race fix, assertive errors, announcement timeout
- `css/prayer-times.css` — error border for color-blind accessibility
- `templates/gablvm-prayer-times.html.twig` — aria-live, aria-hidden, redundant role removal

---

## [2026-02-13] - Prayer Countdown & Date Display Fixes

### Fixed - Countdown Not Advancing When Prayer Time Arrives
- `getSecondsUntilPrayer()` wrapped negative diffs by adding 86400 seconds, making arrival detection impossible
- Countdown showed "23 hours, 59 minutes" instead of advancing to the next prayer; required manual page refresh
- Screen reader announcement ("It is time for [prayer] prayer") never fired
- Fix: only add 86400 when `isTomorrow` is explicitly true — one-line change

### Fixed - Gregorian Date Showing Server Date Instead of Location Date
- `date('l, F j, Y')` used the server's timezone, not the prayer location's timezone
- Viewing Nairobi (UTC+3) prayer times at 11 PM Chicago time showed Thursday instead of Friday
- Now uses `DateTimeZone` from the API response to calculate the correct local date
- Server date kept as fallback if timezone is invalid

### Files Modified
- `js/prayer-times.js` — fixed `getSecondsUntilPrayer()` 86400-wrapping logic
- `src/Controller/PrayerTimesController.php` — timezone-aware Gregorian date display

---

## [2026-02-12] - Watchdog Log Noise + Smart Geocoding Fallback

### Fixed - Watchdog Log Noise from Prayer Times Requests
- Performance instrumentation was logging every prayer times request at `info` severity, including health checks every 10 minutes (~144+ entries/day)
- Changed to only log slow requests (>3 seconds) at `warning` severity
- Health checks and cached hits no longer generate log noise; real performance issues still captured

### Fixed - City Without Correct Country Returns Wrong Location
- When a user types a city (e.g., "Nairobi") but leaves country as "United States", Nominatim returned a location in the wrong country (e.g., a train station in Tampa, FL)
- Added city-name verification: after geocoding `"City, Country"`, the returned city name is compared against the user's input
- On mismatch, automatically retries with just the city name (e.g., "Nairobi" alone → Nairobi, Kenya)
- Extracted `nominatimSearch()` helper method from `forwardGeocode()` for reuse by the fallback query
- Both queries are independently cached (different cache keys) for 30 days

### Files Modified
- `src/Controller/PrayerTimesController.php` — conditional warning log, `nominatimSearch()` helper, city-name verification fallback

---

## [2026-02-11] - Performance Optimization (All Features)

### Changed - Prayer Times
- **Aladhan API cache**: Increased from 15 minutes to end-of-day. Prayer times are static per date/location/method — no reason to re-fetch 96 times/day
- **Aladhan API timeout**: Reduced from 15s to 5s (connect: 3s). Prevents slow hangs when API is unresponsive
- **Monthly calendar timeout**: Reduced from 30s to 10s (connect: 3s)
- **Drupal render cache**: Changed `max-age` from 0 to 300 (5 minutes)

### Changed - Quran Player
- **Quran API timeout**: Reduced from 30s to 5s (connect: 3s). OAuth token request: 10s
- **Verses cache**: Increased from 1 hour to 7 days (verse text is static)
- **Drupal render cache**: Changed `max-age` from 0 to 3600 (1 hour)
- Page load: **2.4s → 0.25s** on warm cache (10x faster)

### Changed - Islamic Calendar
- **Aladhan API timeout**: Reduced from 15s/30s to 5s/10s (connect: 3s)
- **Drupal render cache**: Changed `max-age` from 0 to 3600 (1 hour)

### Added - Performance Instrumentation
- Prayer times controller logs timing breakdown for every request: `total_ms`, `geocode_ms` (type: forward/reverse/none), `api_ms`, `tomorrow_ms`, city, method
- Enables diagnosing slow requests via `drush watchdog:show --type=gablvm_core --severity=6`

### Files Modified
- `src/Controller/PrayerTimesController.php` — timing instrumentation, render cache max-age
- `src/Controller/QuranPlayerController.php` — render cache max-age
- `src/Controller/HijriCalendarController.php` — render cache max-age
- `src/Service/PrayerTimesService.php` — cache TTL, API timeouts
- `src/Service/QuranApiService.php` — API timeouts, verses cache TTL
- `src/Service/HijriCalendarService.php` — API timeouts

---

## [2026-02-11] - Non-Latin City Names Breaking Prayer Times

### Fixed - Calculation Method Change Fails for Non-English Locations
- **Bug**: When viewing prayer times for a non-English city (e.g., Kathmandu, Nepal) and changing the calculation method dropdown, the page displayed "Unable to load prayer times"
- **Root cause**: Nominatim API returned city names in the location's local script (e.g., "काठमाडौं महानगर" in Devanagari for Kathmandu) because no `Accept-Language` header was set. This local-script name was written into the city form field. When the user changed the calculation method and resubmitted, the local-script city name failed forward geocoding and fell through to Aladhan's `timingsByCity` endpoint, which returned a 400 error on non-Latin characters
- **Fix**: Added `'Accept-Language' => 'en'` header to all Nominatim API requests (`forwardGeocode()` and `reverseGeocodeDetailed()`). City names are now always returned in English/Latin script, ensuring reliable round-trips through form resubmission
- **Impact**: Prayer times now work for all locations worldwide with any calculation method, including cities with non-Latin names (Arabic, Devanagari, Chinese, Cyrillic, etc.)

### Added - Prayer Times Fix Notification Banner
- Dismissible info banner at the top of the prayer times page explaining the recent fixes
- Auto-expires after March 15, 2026
- Remembers dismissal via `localStorage`

### Files Modified
- `src/Controller/PrayerTimesController.php` — added `Accept-Language: en` to Nominatim requests
- `templates/gablvm-prayer-times.html.twig` — added dismissible fix notification banner
- `css/prayer-times.css` — banner styles

---

## [2026-02-11] - Critical Prayer Times Fix

### Fixed - City-Based Prayer Times Showing Wrong Location
- **Bug**: When users manually entered a city name (e.g., "Apple Valley, United States"), the Aladhan API's `timingsByCity` endpoint returned completely wrong coordinates (e.g., lat 8.89 in Nigeria instead of lat 44.73 in Minnesota), resulting in prayer times for the wrong location
- **Fix**: City names are now forward-geocoded via Nominatim (OpenStreetMap) before querying prayer times, ensuring accurate coordinates
- **Impact**: All manual city entries now return correct prayer times with proper timezone
- Tomorrow's prayer times now also work for city-based lookups (previously only worked with geolocation)

### Fixed - Stale Coordinates Override New City on Form Resubmission
- **Bug**: When users changed the city and clicked "Update Prayer Times", hidden `<input>` fields containing the previous location's lat/lng were submitted alongside the new city name. The controller trusted lat/lng over city, so the old coordinates won and the new city was ignored
- **Root cause**: Cloudflare Rocket Loader delays JS execution, so the client-side code meant to remove stale hidden fields often hadn't run yet when the form was submitted
- **Fix**: Server-side — when a `city` parameter is explicitly in the query string, always forward-geocode it and ignore any lat/lng. Hidden lat/lng fields removed from template entirely (geolocation navigates directly via JS, doesn't use the form)
- **Impact**: Changing the city in the form now always shows correct prayer times for the new city

### Fixed - "Next Prayer" Wrong for Cross-Timezone Viewers (Server + Client)
- **Bug (server)**: `getNextPrayer()` used `new \DateTime()` (UTC) to determine the next prayer, but prayer times from the API are in the location's local timezone. A Nairobi request at 3 PM local (12 PM UTC) incorrectly showed "Dhuhr is next" instead of "Asr is next"
- **Bug (client)**: The JavaScript used `new Date()` (browser local time) for the countdown and next-prayer highlight, producing wrong results when viewed from a different timezone
- **Fix (server)**: `getNextPrayer()` and `getMinutesUntil()` now accept and use the prayer location's timezone from the API response
- **Fix (client)**: Added `getTimeInTimezone()` and `getSecondsUntilPrayer()` — all time calculations use `Intl.DateTimeFormat` with the prayer location's timezone
- **Impact**: Next prayer is now correct on initial page load (server-rendered) AND after JS loads (client-side), regardless of where the viewer is located

### Files Modified
- `src/Controller/PrayerTimesController.php` — added `forwardGeocode()` method; city queries always forward-geocode; passes timezone to `getNextPrayer()`
- `src/Service/PrayerTimesService.php` — `getNextPrayer()` and `getMinutesUntil()` now accept timezone parameter and use it for time comparison
- `js/prayer-times.js` — added `getTimeInTimezone()` and `getSecondsUntilPrayer()`; all time calculations use prayer location timezone
- `templates/gablvm-prayer-times.html.twig` — removed hidden lat/lng fields that caused stale coordinate bugs

---

## [2026-02-11] - Security Hardening

### Changed - Security Notifications
- Disabled automatic email notifications for IP auto-bans (`notify_on_ban: false`) — dashboard at `/admin/reports/gablvm-security` shows all activity, email alerts were generating noise from routine bot blocking

### Added - Cloudflare Origin Protection
- Added `CF-Ray` header check in `web/.htaccess` to block requests that bypass Cloudflare
- Requests missing the `CF-Ray` header (i.e., direct-to-IP traffic) return 403 Forbidden
- Localhost (127.0.0.1, ::1) exempted for cron and drush
- HTTPS direct-to-IP was already blocked by SNI mismatch (421), HTTP redirects back through Cloudflare

### Security Audit Notes
- Reviewed watchdog logs: 133 security events in 7 days, 10 IPs auto-banned on Feb 10
- Attack vectors: brute force login attempts (8 IPs), malicious path probes (2 IPs), PHP shell scanning
- All attacks successfully detected and blocked by gablvm_security module + Cloudflare edge blocking
- No unauthorized access achieved

## [2026-02-10] - Prayer Times & Calendar Updates

### Changed - Prayer Times Table
- Renamed "Sunrise" to **"Sunrise Time"** — sunrise is not a prayer, the label now reflects that
- Added **"Sunset Time"** row between Asr and Maghrib (data from Aladhan API `Sunset` field)
- Next prayer countdown now only tracks actual prayers (Fajr, Dhuhr, Asr, Maghrib, Isha) — skips Sunrise and Sunset

### Added - Islamic Calendar iCal Export
- **Add to Calendar** button next to every Islamic event (monthly and all-events sections)
- **Download All Events** button to export all events for the year in one `.ics` file
- New route `/islam-calendar/export` generates valid iCal files (RFC 5545)
- Works with Apple Calendar, Google Calendar, Outlook, and any iCal-compatible app
- Supports single event or bulk export via `?event=0` or `?event=all` query params

### Files Modified
- `src/Service/PrayerTimesService.php` — added sunset to formatTimings/formatTimingsRaw, removed Sunrise from getNextPrayer
- `src/Controller/HijriCalendarController.php` — added `exportIcal()` method with iCal generation
- `templates/gablvm-prayer-times.html.twig` — relabeled Sunrise, added Sunset Time row
- `templates/gablvm-hijri-calendar.html.twig` — added Add to Calendar and Download All buttons
- `js/prayer-times.js` — removed Sunrise from next prayer order, added Sunset to table mapping
- `css/hijri-calendar.css` — styled calendar export buttons
- `gablvm_core.routing.yml` — added `/islam-calendar/export` route

## [Unreleased] - 2026-01-16

### Added - English Audio Translation Feature

#### Overview
Added the ability to play English audio translation (Ibrahim Walk - Saheeh International) after each Arabic verse in the Quran Player. This creates an interleaved playback experience: Arabic verse → English translation → next Arabic verse → etc.

#### Audio Files Setup
- **Location:** `/web/sites/default/files/quran-audio/ibrahim-walk-english/`
- **Structure:**
  ```
  ibrahim-walk-english/
  ├── surah/          # Full surah files (114 files, ~1.5GB)
  │   ├── 001.mp3     # Full Surah Al-Fatihah
  │   ├── 002.mp3     # Full Surah Al-Baqarah
  │   └── ...114.mp3
  └── verse/          # Individual verse files (6,350 files, ~1.5GB)
      ├── 001001.mp3  # Surah 1, Verse 1
      ├── 001002.mp3  # Surah 1, Verse 2
      ├── ...
      ├── 002000.mp3  # Bismillah for Surah 2
      ├── 002001.mp3  # Surah 2, Verse 1
      └── ...114006.mp3
  ```
- **File Naming Convention:** `XXXYYY.mp3` where XXX = Surah number (001-114), YYY = Verse number (000 for Bismillah, 001+ for verses)
- **Source:** Downloaded from EveryAyah.com (`https://everyayah.com/data/English/Sahih_Intnl_Ibrahim_Walk_192kbps/`)
- **License:** See `/verse/000_license.html` for usage terms

#### UI Changes
- Added checkbox: "Play English audio translation after each verse" in Playback Options section
- Checkbox is unchecked by default (opt-in feature)
- Status messages show current playback state (e.g., "Arabic: Verse 2:5", "English: Verse 2:5")

#### Technical Implementation

**New State Variables** (in `gablvm-quran-player.html.twig`):
```javascript
var verseByVerseMode = false;      // true when English translation is enabled
var versesPlaylist = [];           // Array of verse objects from API
var currentVerseIndex = 0;         // Current position in playlist
var playingEnglish = false;        // true when playing English audio
```

**New Functions:**
- `getEnglishAudioUrl(verseKey)` - Builds URL for English verse audio (e.g., "2:5" → "/sites/default/files/quran-audio/ibrahim-walk-english/verse/002005.mp3")
- `getEnglishBismillahUrl(surahNum)` - Builds URL for English Bismillah audio (e.g., 2 → ".../verse/002000.mp3")
- `loadSurahVerseByVerse(surahNum, reciterId, autoPlay)` - Loads surah in verse-by-verse mode from API
- `playCurrentVerse()` - Plays the current Arabic verse
- `playEnglishForCurrentVerse()` - Plays English translation for current verse
- `loadSurahWithMode(surahNum, reciterId, autoPlay)` - Wrapper that chooses between verse-by-verse or gapless mode based on checkbox state

**Modified Functions:**
- `onAudioEnded()` - Added verse-by-verse mode handling with English interleaving
- Play/Pause button handler - Added verse-by-verse mode support

**Playback Flow (when English enabled):**
1. User enables checkbox and loads a Surah
2. System fetches verses from `/api/quran/verses/{surah}/{reciter}/{translation}`
3. For Surahs 2-8, 10-114: Arabic Bismillah → English Bismillah → Arabic Verse 1 → English Verse 1 → ...
4. For Surah 1 (Al-Fatihah): Arabic Verse 1 → English Verse 1 → ... (Bismillah IS verse 1)
5. For Surah 9 (At-Tawbah): Arabic Verse 1 → English Verse 1 → ... (no Bismillah)

**Mode Switching:**
- Toggling the checkbox mid-playback immediately switches modes
- Switching to English: Reloads surah in verse-by-verse mode
- Switching off English: Reloads surah in gapless mode (full surah audio)
- Status message shown: "Restarting with/without English translation..."

#### Accessibility Improvements
- VoiceOver/screen reader announcements silenced during verse-by-verse playback to prevent interruption
- `setStatus(msg, silent)` function added with optional `silent` parameter
- When `silent=true`, temporarily sets `aria-live="off"` on status element

#### API Endpoints Used
- `/api/quran/verses/{surah}/{reciter}/{translation}` - Returns verses with individual audio URLs
- Response includes: `verse_key`, `arabic`, `translation`, `audio_url`, `is_bismillah`

### Known Issues

*None - all previously reported issues have been resolved.*

#### [RESOLVED] Playback Error for All Surahs/Reciters (January 16, 2026)
- **Status:** Fixed
- **Symptom:** "Playback error" when English translation checkbox was enabled
- **Root Cause:** Cloudflare SBFM (Super Bot Fight Mode) had `sbfm_static_resource_protection: true`, which challenged requests for static MP3 files. The HTML5 `<audio>` element cannot complete JavaScript challenges.
- **Fix:** Disabled SBFM Static Resource Protection and created Configuration Rule to set security_level to "essentially_off" for the Quran audio path

### Bug Fixes

#### Fixed: Play Button Not Working After Loading New Surah (Verse-by-Verse Mode)
- **Issue:** After loading a new Surah with English enabled, clicking Play would play the previous Surah
- **Cause:** Old audio source wasn't cleared when loading new Surah in verse-by-verse mode
- **Fix:** Added `audio.src = '';` in `loadSurahVerseByVerse()` to clear old source

#### Fixed: Checkbox Toggle Not Respected During Playback
- **Issue:** Unchecking English translation mid-playback still played English
- **Fix:** Added real-time checkbox check in `onAudioEnded()` before playing English

#### Fixed: Mode Not Switching When Checkbox Toggled
- **Issue:** Toggling checkbox mid-playback didn't switch between verse-by-verse and gapless modes
- **Fix:** Added change event listener to checkbox that reloads surah in appropriate mode

---

## File Changes Summary

### Modified Files
- `templates/gablvm-quran-player.html.twig`
  - Added English translation checkbox UI
  - Added ~200 lines of JavaScript for verse-by-verse mode with English interleaving
  - Modified `onAudioEnded()`, Play button handler, and added mode switching logic

### New Directories/Files
- `/web/sites/default/files/quran-audio/ibrahim-walk-english/surah/` - 114 full surah MP3 files
- `/web/sites/default/files/quran-audio/ibrahim-walk-english/verse/` - 6,350 verse MP3 files

---

## Developer Notes

### Testing the Feature
1. Navigate to `/quran-listen`
2. Select a reciter from the dropdown
3. Check "Play English audio translation after each verse"
4. Select a Surah and click Play
5. Verify: Arabic plays → English plays → next Arabic plays → etc.

### Console Debugging
Enable browser console (F12) to see `[QuranPlayer]` logs:
- `loadSurahVerseByVerse(...)` - When loading in verse-by-verse mode
- `Playing Arabic: Verse X:Y` - When Arabic verse starts
- `playEnglishForCurrentVerse: verse_key=X:Y` - When preparing English audio
- `Using verse URL: /sites/default/files/...` - The URL being loaded
- `Playing English: Verse X:Y` - When English audio starts

### File URL Pattern
```
/sites/default/files/quran-audio/ibrahim-walk-english/verse/{surah}{verse}.mp3

Examples:
- Verse 2:255 → /sites/default/files/quran-audio/ibrahim-walk-english/verse/002255.mp3
- Bismillah for Surah 3 → /sites/default/files/quran-audio/ibrahim-walk-english/verse/003000.mp3
```

### Bismillah Handling
- `XXX000.mp3` files are all identical (same 83,590 bytes) - generic Bismillah recording
- Used as prefix for Surahs 2-8, 10-114
- Surah 1: No separate Bismillah (it IS verse 1)
- Surah 9: No Bismillah at all
