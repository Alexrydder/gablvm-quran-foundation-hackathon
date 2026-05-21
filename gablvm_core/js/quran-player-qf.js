/**
 * @file
 * Bookmark + reading-session hooks for /quran-listen when the visitor's
 * Quran.com account is connected.
 *
 * Surah:verse capture: the inline player script publishes
 * window.gablvmCurrentSurahVerse each time it changes verse position
 * (verse-by-verse mode tracks the live verse; chapter/gapless mode just
 * pins to verse 1 of the current surah since verse_timings aren't fetched
 * client-side). The bookmark button reads that global; if it isn't set
 * yet (no surah loaded), we fall back to surah from #surah-select + verse 1.
 *
 * Reading-session POSTs are debounced: the first one fires shortly after
 * playback begins, then at most once every 5 minutes per surah change.
 * QF's API also dedupes within ~20 minutes server-side.
 */
(function (Drupal, drupalSettings, once) {
  'use strict';

  var qf = drupalSettings.gablvmQf || {};
  if (!qf.connected) return;

  function postJson(url, payload) {
    return fetch(url, {
      method: 'POST',
      credentials: 'same-origin',
      headers: { 'Content-Type': 'application/json', 'Accept': 'application/json' },
      body: JSON.stringify(Object.assign({ csrf_token: qf.csrfToken || '' }, payload))
    }).then(function (r) { return r.json().then(function (j) { return { status: r.status, body: j }; }); });
  }

  function getCurrentSurah() {
    var sel = document.getElementById('surah-select');
    if (!sel) return 0;
    return parseInt(sel.value, 10) || 0;
  }

  // True when either of the two audio elements is currently playing.
  // Used to suppress polite-live-region status announcements that would
  // otherwise speak over the recitation under VoiceOver.
  function isAudioPlaying() {
    var a = document.getElementById('quran-audio-a');
    var b = document.getElementById('quran-audio-b');
    return (a && !a.paused) || (b && !b.paused);
  }

  // Returns {surah, verse} — prefers the live verse the player is on,
  // falls back to the dropdown's surah at verse 1 when nothing is loaded.
  function getCurrentSurahVerse() {
    var live = window.gablvmCurrentSurahVerse;
    if (live && live.surah) {
      return { surah: live.surah, verse: live.verse || 1 };
    }
    return { surah: getCurrentSurah(), verse: 1 };
  }

  // Captures the listening context (reciter id, mode) for jump-to-verse
  // restoration. Reciter is from the visible dropdown. Mode is verse_by_verse
  // when the English checkbox is checked (player loads per-verse audio
  // queued), otherwise chapter (single gapless MP3).
  function getListeningContext() {
    var reciterEl = document.getElementById('reciter-select');
    var englishEl = document.getElementById('english-translation-audio');
    var reciter = reciterEl ? parseInt(reciterEl.value, 10) || 0 : 0;
    var mode = (englishEl && englishEl.checked) ? 'verse_by_verse' : 'chapter';
    return { reciter: reciter, mode: mode };
  }

  Drupal.behaviors.gablvmQfBookmark = {
    attach: function (context) {
      once('qf-bookmark-btn', '#qf-bookmark-btn', context).forEach(function (btn) {
        var status = document.getElementById('qf-bookmark-status');
        btn.addEventListener('click', function () {
          var sv = getCurrentSurahVerse();
          if (!sv.surah) {
            if (status) status.textContent = Drupal.t('Pick a surah first.');
            return;
          }
          var ctx = getListeningContext();
          btn.disabled = true;
          // Capture whether audio was playing at the moment of click. We
          // use this to decide whether to announce success via the
          // role=status live region. If audio is playing, suppress the
          // success announcement so VoiceOver does not speak over the
          // recitation. Failures always announce, because the user needs
          // to know the bookmark did not save.
          var wasPlayingOnClick = isAudioPlaying();
          if (status) status.textContent = wasPlayingOnClick ? '' : Drupal.t('Saving verse @s:@v.', { '@s': sv.surah, '@v': sv.verse });
          postJson(qf.bookmarkUrl, {
            surah: sv.surah,
            verse: sv.verse,
            reciter: ctx.reciter,
            mode: ctx.mode
          })
            .then(function (res) {
              btn.disabled = false;
              if (res.status === 200 && res.body && res.body.ok) {
                if (status) {
                  // On success: if audio is currently playing, do not announce.
                  // The button-disabled-then-re-enabled transition is itself an
                  // audible cue under VoiceOver, sufficient feedback.
                  status.textContent = isAudioPlaying() ? '' : Drupal.t('Bookmarked verse @s:@v.', { '@s': sv.surah, '@v': sv.verse });
                }
              } else {
                if (status) status.textContent = Drupal.t('Could not bookmark, try again.');
              }
            })
            .catch(function () {
              btn.disabled = false;
              if (status) status.textContent = Drupal.t('Network error, try again.');
            });
        });
      });
    }
  };

  // Note: bookmark deep-link reciter/English preselection used to live here
  // as a synthetic-change-event dance. As of 2026-04-27 the controller
  // renders the dropdown + checkbox state directly into the HTML markup
  // and the inline player auto-triggers loadSurahWithMode at init when it
  // sees a preselected reciter. No JS race condition; the player loads
  // verse-by-verse mode immediately because the english checkbox is
  // already checked when its compatibility check runs.

  // ============================================================
  // Chapter-mode verse estimation
  // ============================================================
  // In gapless (single-MP3) playback, the player has no native concept of
  // "which verse is playing right now." For the bookmark button to capture
  // the actually-playing verse instead of defaulting to verse 1, we fetch
  // a precomputed cumulative duration array for (surah, reciter) and use
  // audioElement.currentTime to find the verse the cumulative window
  // contains. Approximate (chapter MP3 and per-verse MP3s have slightly
  // different pacing — typically off by ~5%) but correct in 90%+ of clicks.
  //
  // We refetch the array each time the loaded surah or reciter changes;
  // the JSON endpoint is aggressively cacheable so this is cheap.
  Drupal.behaviors.gablvmQfChapterVerseTracker = {
    attach: function (context) {
      once('qf-chapter-verse-tracker', 'body', context).forEach(function () {
        var timings = [];           // [{verse, cumulative_end_ms}, …]
        var fetchedKey = '';        // "surah:reciter" of the timings we hold
        var pendingKey = '';        // in-flight fetch key (avoid duplicates)

        function fetchTimings(surah, reciter) {
          var key = surah + ':' + reciter;
          if (key === fetchedKey || key === pendingKey) return;
          pendingKey = key;
          fetch('/api/quran/chapter-timings/' + surah + '/' + reciter, {
            credentials: 'same-origin', headers: { 'Accept': 'application/json' }
          })
            .then(function (r) { return r.ok ? r.json() : { timings: [] }; })
            .then(function (j) {
              if (pendingKey !== key) return;  // stale fetch, discard
              timings = Array.isArray(j.timings) ? j.timings : [];
              fetchedKey = key;
              pendingKey = '';
              // Race-condition guard: if the gapless audio's loadedmetadata
              // and canplay events fired BEFORE this fetch landed, the
              // chapter-mode bookmark seek bailed at the empty-timings
              // check with no retry. Re-attempt now that timings are ready.
              // No-op when the seek already succeeded — gablvmQuranInitial-
              // VerseTarget gets nulled out on first success.
              qflog('timings loaded: ' + timings.length + ' entries for ' + key);
              maybeSeekChapterBookmark('timings-loaded');
            })
            .catch(function () { pendingKey = ''; });
        }

        function currentVerseFromTime(timeMs) {
          if (!timings.length) return 1;
          // Boundary grace: if the listener is in the last 500ms of verse N,
          // treat them as already in verse N+1 for bookmark-capture purposes.
          // Without this, a user who clicks "bookmark this verse" the moment
          // verse 12 finishes captures verse 12 (still technically playing),
          // and on resume the player rewinds to verse 12's start — annoying.
          // The grace anticipates user intent: clicking near the end of a
          // verse usually means "I want to start from the next one."
          var GRACE_MS = 500;
          for (var i = 0; i < timings.length; i++) {
            if (timeMs < timings[i].cumulative_end_ms - GRACE_MS) return timings[i].verse;
          }
          // Past the end of the timing window — return the last verse.
          return timings[timings.length - 1].verse;
        }

        function maybeUpdate() {
          var sel = document.getElementById('surah-select');
          var rec = document.getElementById('reciter-select');
          var en = document.getElementById('english-translation-audio');
          if (!sel || !rec) return;
          // Only run in CHAPTER (gapless) mode. Verse-by-verse mode publishes
          // its own state from playCurrentVerse() in the inline script.
          if (en && en.checked) return;

          var surah = parseInt(sel.value, 10);
          var reciter = parseInt(rec.value, 10);
          if (!surah || !reciter) return;

          // Fetch timings if we don't have them for this combo.
          var key = surah + ':' + reciter;
          if (key !== fetchedKey) fetchTimings(surah, reciter);

          // Find the active audio element (the one that's actually playing).
          var a = document.getElementById('quran-audio-a');
          var b = document.getElementById('quran-audio-b');
          var active = (a && !a.paused) ? a : ((b && !b.paused) ? b : null);
          if (!active) return;

          var verse = currentVerseFromTime(active.currentTime * 1000);
          window.gablvmCurrentSurahVerse = { surah: surah, verse: verse };
        }

        // Chapter-mode bookmark seek: when /quran-listen?surah=X&verse=Y
        // lands in chapter (gapless) mode, the inline player can't honor
        // the verse target on its own — that path needs verse-by-verse
        // mode. We can approximate the right playback position by waiting
        // for the gapless audio to load enough metadata, fetching the
        // cumulative timing array, and seeking the audio element to the
        // ms position where verse Y starts. Off by ~5% (chapter MP3 and
        // per-verse MP3s have slightly different pacing) but lands the
        // user in the same general area, not at verse 1.
        //
        // Only fires once per page load. window.gablvmQuranInitialVerse-
        // Target is set by the inline player's IIFE init; the verse-by-
        // verse path nulls it out when it succeeds. If we still see it
        // when the gapless audio is ready, we know the player ended up
        // in chapter mode and we should seek.
        // Use the inline player's debug-panel logger when available, fall
        // back to console.log otherwise. The inline player exposes itself
        // as window.gablvmDebugLog only when ?debug=1 is on the URL.
        function qflog(msg) {
          if (window.gablvmDebugLog) { window.gablvmDebugLog('[QF] ' + msg); }
          else if (window.console && console.log) { console.log('[QF] ' + msg); }
        }

        function maybeSeekChapterBookmark(reason) {
          var t = window.gablvmQuranInitialVerseTarget;
          if (!t) { qflog('seek skip: no target (reason=' + reason + ')'); return; }
          var en = document.getElementById('english-translation-audio');
          if (en && en.checked) { qflog('seek skip: english-mode (VBV handled)'); return; }

          // Surah-mismatch guard: if the user changed the surah dropdown
          // after the bookmark URL loaded but before the seek fires, the
          // currently-loaded surah no longer matches the bookmark target.
          // Cumulative timings would be for the new surah, applied to the
          // wrong target verse — could land mid-arbitrary-verse. Bail and
          // null the target so future surah/reciter changes don't trip
          // this code path again.
          var sel = document.getElementById('surah-select');
          var loadedSurah = sel ? parseInt(sel.value, 10) : 0;
          if (loadedSurah && loadedSurah !== t.surah) {
            qflog('seek abort: surah mismatch (target surah=' + t.surah + ', loaded surah=' + loadedSurah + ', reason=' + reason + ')');
            window.gablvmQuranInitialVerseTarget = null;
            return;
          }

          if (!timings.length) {
            qflog('seek defer: timings empty for ' + (fetchedKey || pendingKey) + ' (reason=' + reason + ')');
            return;
          }
          if (timings[0].verse > t.verse) { qflog('seek skip: target verse ' + t.verse + ' before timings[0].verse ' + timings[0].verse); return; }

          // Find cumulative end-ms of the verse BEFORE the target = start
          // of the target verse. Special-case verse 1: starts at 0.
          var seekMs = 0;
          for (var i = 0; i < timings.length; i++) {
            if (timings[i].verse === t.verse - 1) {
              seekMs = timings[i].cumulative_end_ms;
              break;
            }
          }
          if (seekMs === 0 && t.verse > 1) {
            // Fallback: linear scan
            for (var j = 0; j < timings.length; j++) {
              if (timings[j].verse < t.verse) {
                seekMs = Math.max(seekMs, timings[j].cumulative_end_ms);
              }
            }
          }

          var a = document.getElementById('quran-audio-a');
          var b = document.getElementById('quran-audio-b');
          var active = (a && a.src && !a.paused) ? a : ((b && b.src && !b.paused) ? b : (a && a.src ? a : (b && b.src ? b : null)));
          if (!active) { qflog('seek defer: no audio with src (reason=' + reason + ')'); return; }
          if (!isFinite(active.duration) || active.duration === 0) { qflog('seek defer: ' + active.id + ' duration=' + active.duration + ' (reason=' + reason + ')'); return; }

          // Bismillah guard: 112 of 114 surahs play a ~3-second Bismillah
          // preroll before the surah audio. If the active audio's duration
          // is shorter than the target seek position, this is the Bismillah
          // (or some other short-prefix audio), not the surah. Bail without
          // nulling gablvmQuranInitialVerseTarget — the surah audio's own
          // loadedmetadata fires when the player swaps it in (line 1251 of
          // gablvm-quran-player.html.twig: activeAudio.src = surahUrl;
          // activeAudio.load()), and the seek runs correctly that pass.
          var rawSeekSec = seekMs / 1000;
          if (rawSeekSec >= active.duration - 0.5) {
            qflog('seek defer (Bismillah guard): ' + active.id + ' duration=' + active.duration.toFixed(1) + 's, target=' + rawSeekSec.toFixed(1) + 's');
            return;
          }

          var seekSec = Math.min(rawSeekSec, active.duration - 0.1);
          if (seekSec > 0.1) {
            try {
              active.currentTime = seekSec;
              window.gablvmCurrentSurahVerse = { surah: t.surah, verse: t.verse };
              window.gablvmQuranInitialVerseTarget = null;
              qflog('SEEK FIRED: ' + active.id + '.currentTime = ' + seekSec.toFixed(1) + 's (verse ' + t.verse + ', reason=' + reason + ')');
            } catch (e) { qflog('SEEK ERROR: ' + e.message); }
          }
        }

        // Hook into both audio elements' timeupdate events. Throttled by
        // browser (~4 Hz) so this is cheap.
        ['quran-audio-a', 'quran-audio-b'].forEach(function (id) {
          var el = document.getElementById(id);
          if (el) {
            el.addEventListener('timeupdate', maybeUpdate);
            el.addEventListener('play', maybeUpdate);
            // loadedmetadata fires when audio.duration becomes valid.
            // canplay/canplaythrough fire later; either is a fine moment
            // to attempt the chapter-mode bookmark seek.
            el.addEventListener('loadedmetadata', function () { maybeSeekChapterBookmark('loadedmetadata-' + id); });
            el.addEventListener('canplay', function () { maybeSeekChapterBookmark('canplay-' + id); });
          }
        });

        // Surah / reciter change → drop the cached timings so the next
        // playback fetches fresh. ALSO triggers an early fetch since the
        // bookmark deep-link auto-load will need the timings ready when
        // the gapless audio finishes loading.
        var sel = document.getElementById('surah-select');
        var rec = document.getElementById('reciter-select');
        function maybeRefetchTimings() {
          fetchedKey = '';
          var s = sel ? parseInt(sel.value, 10) : 0;
          var r = rec ? parseInt(rec.value, 10) : 0;
          if (s && r) fetchTimings(s, r);
        }
        if (sel) sel.addEventListener('change', maybeRefetchTimings);
        if (rec) rec.addEventListener('change', maybeRefetchTimings);

        // Kick off an initial timing fetch on page load so chapter-mode
        // bookmark seek has the data ready by the time gapless audio
        // metadata arrives.
        var initSurah = sel ? parseInt(sel.value, 10) : 0;
        var initReciter = rec ? parseInt(rec.value, 10) : 0;
        if (initSurah && initReciter) {
          fetchTimings(initSurah, initReciter);
        }
      });
    }
  };

  // ============================================================
  // Activity tracker: real-time elapsed playback + verse range
  // ============================================================
  // Drives both the position log (Continue reading panel) AND the
  // activity-day record (Today's reading + streak engine). Posts to
  // /quran/api/reading-session with a rich payload on session-end
  // events: surah change, audio ended, prolonged pause, page unload.
  //
  // Session state:
  //   surah:        int (1-114)
  //   verseFrom:    int — verse the user started on this surah
  //   verseLast:    int — most recent verse observed (chapter-mode
  //                 tracker keeps window.gablvmCurrentSurahVerse updated;
  //                 verse-by-verse mode publishes via playCurrentVerse)
  //   secondsAccumulated: float — total play time on this surah
  //   lastTickAt:   ms timestamp of last tick (only valid while playing)
  //   isPlaying:    bool
  //
  // A session always represents one surah. Switching surahs flushes the
  // current session and starts a fresh one. The 8-second floor is honored
  // by the server (activity-day post is skipped below that), so very brief
  // plays don't pollute the streak.
  Drupal.behaviors.gablvmQfReadingSession = {
    attach: function (context) {
      once('qf-reading-session', 'body', context).forEach(function () {
        var session = null;
        var tickIntervalId = null;
        var positionLoggedForSurah = 0;

        function getCurrentVerse() {
          var live = window.gablvmCurrentSurahVerse;
          if (live && typeof live.verse === 'number' && live.verse >= 1) {
            return live.verse;
          }
          return 1;
        }

        function tick() {
          if (!session || !session.isPlaying) return;
          var now = Date.now();
          var dt = (now - session.lastTickAt) / 1000;
          // Guard against tab-throttling spikes: if the gap is huge
          // (>20s), the tab was probably suspended. Don't credit time we
          // can't verify. Browsers cap setInterval to ~1s in foreground.
          if (dt > 0 && dt < 20) {
            session.secondsAccumulated += dt;
          }
          session.lastTickAt = now;
          var v = getCurrentVerse();
          if (v > session.verseLast) session.verseLast = v;
        }

        function startTicker() {
          if (tickIntervalId) return;
          tickIntervalId = window.setInterval(tick, 1000);
        }

        function stopTicker() {
          if (tickIntervalId) {
            window.clearInterval(tickIntervalId);
            tickIntervalId = null;
          }
        }

        // Send the session via fetch when we have time + a route open.
        // For beforeunload we use sendBeacon (next function) because
        // fetch promises may not resolve before the page tears down.
        function sendSessionFetch(s) {
          if (!s || s.secondsAccumulated < 8) return;
          var seconds = Math.round(s.secondsAccumulated);
          var verseFrom = Math.max(1, s.verseFrom);
          var verseTo = Math.max(verseFrom, s.verseLast || verseFrom);
          postJson(qf.readingSessionUrl, {
            surah: s.surah,
            verse: verseFrom,
            secondsRead: seconds,
            verseFrom: verseFrom,
            verseTo: verseTo
          }).catch(function () {});
        }

        function sendSessionBeacon(s) {
          if (!s || s.secondsAccumulated < 8) return;
          if (!navigator.sendBeacon) {
            sendSessionFetch(s);
            return;
          }
          var seconds = Math.round(s.secondsAccumulated);
          var verseFrom = Math.max(1, s.verseFrom);
          var verseTo = Math.max(verseFrom, s.verseLast || verseFrom);
          var body = JSON.stringify({
            csrf_token: qf.csrfToken || '',
            surah: s.surah,
            verse: verseFrom,
            secondsRead: seconds,
            verseFrom: verseFrom,
            verseTo: verseTo
          });
          var blob = new Blob([body], { type: 'application/json' });
          try { navigator.sendBeacon(qf.readingSessionUrl, blob); }
          catch (e) { sendSessionFetch(s); }
        }

        // Flush: finalize the current session (if any) and clear state.
        // Beacon = true uses sendBeacon (for beforeunload paths).
        function flushSession(useBeacon) {
          if (!session) return;
          tick(); // capture trailing seconds before flush
          if (useBeacon) sendSessionBeacon(session);
          else sendSessionFetch(session);
          session = null;
        }

        // Position log: keep the existing "fires once per surah after 8s
        // of play" semantic so the Continue reading panel updates
        // immediately when the user starts a surah, even if the activity
        // session is still building up. We piggyback off the play handler.
        function logPositionOnce(surah) {
          if (!surah || surah === positionLoggedForSurah) return;
          window.setTimeout(function () {
            // Only log if the user kept playing this surah past 8 seconds.
            if (session && session.surah === surah && session.secondsAccumulated >= 8) {
              positionLoggedForSurah = surah;
              // Position-only post; controller treats absent secondsRead as
              // "do not log activity-day," but here we let the session
              // flushes carry that load. This is a separate POST to keep
              // Continue reading snappy.
              postJson(qf.readingSessionUrl, {
                surah: surah,
                verse: getCurrentVerse()
              }).catch(function () {});
            }
          }, 8000);
        }

        function onPlay(e) {
          var surah = getCurrentSurah();
          if (!surah) return;
          var verse = getCurrentVerse();
          if (!session || session.surah !== surah) {
            // Surah change or first play: flush previous, start fresh.
            flushSession(false);
            session = {
              surah: surah,
              verseFrom: verse,
              verseLast: verse,
              secondsAccumulated: 0,
              lastTickAt: Date.now(),
              isPlaying: true
            };
            logPositionOnce(surah);
          }
          else {
            // Resume.
            session.isPlaying = true;
            session.lastTickAt = Date.now();
          }
          startTicker();
        }

        function onPause(e) {
          if (!session) return;
          tick();
          session.isPlaying = false;
          stopTicker();
          // Don't flush on pause — user may resume. The session sits
          // dormant until: a different surah starts, audio ends, or the
          // page unloads.
        }

        function onEnded(e) {
          if (!session) return;
          tick();
          session.isPlaying = false;
          stopTicker();
          flushSession(false);
        }

        ['quran-audio-a', 'quran-audio-b'].forEach(function (id) {
          var el = document.getElementById(id);
          if (!el) return;
          el.addEventListener('play', onPlay);
          el.addEventListener('pause', onPause);
          el.addEventListener('ended', onEnded);
        });

        // Surah dropdown change: even before audio starts, treat this
        // as a fresh-session signal so the verseFrom resets.
        var sel = document.getElementById('surah-select');
        if (sel) {
          sel.addEventListener('change', function () {
            flushSession(false);
            positionLoggedForSurah = 0;
          });
        }

        // Page navigation / tab close: salvage the in-flight session.
        // pagehide is the modern equivalent of beforeunload that survives
        // bfcache; we listen to both for browser-coverage breadth.
        function onPagehide() {
          flushSession(true);
        }
        window.addEventListener('pagehide', onPagehide);
        window.addEventListener('beforeunload', onPagehide);

        // visibilitychange: when the user hides the tab AND audio paused,
        // flush early so we don't lose seconds to a closed tab. If audio
        // is still playing (background tab), keep ticking — browsers
        // throttle but don't kill setInterval for tabs with active audio.
        document.addEventListener('visibilitychange', function () {
          if (document.visibilityState === 'hidden' && session && !session.isPlaying) {
            flushSession(true);
          }
        });
      });
    }
  };

})(Drupal, drupalSettings, once);
