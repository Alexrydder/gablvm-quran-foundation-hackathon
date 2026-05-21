/**
 * @file
 * Simple Quran Player JavaScript
 *
 * Uses native HTML5 audio controls for accessibility.
 */

(function (Drupal, drupalSettings) {
  'use strict';

  console.log('Quran Player: Script loaded');

  // Prevent double initialization
  if (window.quranPlayerInitialized) {
    console.log('Quran Player: Already initialized, skipping');
    return;
  }
  window.quranPlayerInitialized = true;

  // Initialize when DOM is ready
  if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', initQuranPlayer);
  } else {
    initQuranPlayer();
  }

  function initQuranPlayer() {
    console.log('Quran Player: Initializing...');

    // Get elements
    var player = document.getElementById('quran-player');
    if (!player) {
      console.log('Quran Player: No #quran-player element found');
      return;
    }

    var audio = document.getElementById('quran-audio');
    var surahSelect = document.getElementById('surah-select');
    var reciterSelect = document.getElementById('reciter-select');
    var translationSelect = document.getElementById('translation-select');
    var speedSelect = document.getElementById('speed-select');
    var autoAdvanceCheckbox = document.getElementById('auto-advance');
    var loadBtn = document.getElementById('load-audio-btn');
    var prevSurahBtn = document.getElementById('prev-surah-btn');
    var nextSurahBtn = document.getElementById('next-surah-btn');
    var prevVerseBtn = document.getElementById('prev-verse-btn');
    var nextVerseBtn = document.getElementById('next-verse-btn');
    var currentSurahName = document.getElementById('current-surah-name');
    var audioStatus = document.getElementById('audio-status');
    var arabicText = document.getElementById('arabic-text');
    var translationText = document.getElementById('translation-text');
    var verseRef = document.getElementById('verse-ref');
    var verseCounter = document.getElementById('verse-counter');

    console.log('Quran Player: Elements found - audio:', !!audio, 'loadBtn:', !!loadBtn);

    // State
    var verses = [];
    var currentVerseIndex = 0;
    var isLoading = false;

    // Debug element for visible logging on mobile
    var debugText = document.getElementById('debug-text');
    function debug(msg) {
      console.log('Quran Player:', msg);
      if (debugText) {
        debugText.textContent = msg;
      }
    }

    // Get settings from Drupal
    var settings = (drupalSettings && drupalSettings.gablvmQuran) || {};
    debug('Settings loaded. audioUrl: ' + (settings.audioUrl ? 'YES' : 'NO'));

    // Show status message
    function showStatus(message, isError) {
      console.log('Quran Player: Status -', message);
      if (audioStatus) {
        audioStatus.textContent = message;
        audioStatus.className = 'quran-status' + (isError ? ' quran-status-error' : '');
      }
    }

    // Get selected surah name
    function getSelectedSurahName() {
      if (surahSelect && surahSelect.selectedOptions.length > 0) {
        return surahSelect.selectedOptions[0].textContent;
      }
      return '';
    }

    // Set audio source directly
    function setAudioSource(url) {
      if (!audio) {
        debug('ERROR: No audio element!');
        return;
      }
      debug('Setting audio src...');
      audio.src = url;
      audio.load();
      debug('Audio src set: ' + url.substring(0, 50) + '...');
      showStatus('Audio loaded. Press play to start.', false);
      if (currentSurahName) {
        currentSurahName.textContent = 'Now Playing: ' + getSelectedSurahName();
      }
    }

    // Load audio via AJAX
    function loadAudioFromApi() {
      if (isLoading) {
        debug('Already loading, skipping');
        return;
      }

      var surah = surahSelect ? surahSelect.value : 1;
      var reciter = reciterSelect ? reciterSelect.value : '';

      // Don't fetch if no reciter selected (empty value)
      if (!reciter) {
        debug('loadAudioFromApi: No reciter selected');
        showStatus('Please select a reciter first.', true);
        return;
      }

      debug('Loading surah ' + surah + ', reciter ' + reciter);

      isLoading = true;
      showStatus('Loading audio...', false);

      if (currentSurahName) {
        currentSurahName.textContent = 'Loading ' + getSelectedSurahName() + '...';
      }

      var apiUrl = '/api/quran/audio/' + surah + '/' + reciter;
      debug('Fetching: ' + apiUrl);

      fetch(apiUrl, {
        method: 'GET',
        credentials: 'same-origin',
        headers: { 'Accept': 'application/json' }
      })
      .then(function (response) {
        debug('Response: ' + response.status);
        if (!response.ok) throw new Error('HTTP ' + response.status);
        return response.json();
      })
      .then(function (data) {
        isLoading = false;
        debug('Got data: ' + (data.success ? 'success' : 'failed'));

        if (data.success && data.audioUrl) {
          setAudioSource(data.audioUrl);
          loadVerses();
        } else {
          debug('No audioUrl in response');
          showStatus('Could not load audio. Try a different reciter.', true);
        }
      })
      .catch(function (error) {
        isLoading = false;
        debug('FETCH ERROR: ' + error.message);
        showStatus('Error loading audio: ' + error.message, true);
      });
    }

    // Load verses for display
    function loadVerses() {
      var surah = surahSelect ? surahSelect.value : 1;
      var reciter = reciterSelect ? reciterSelect.value : '';
      var translation = translationSelect ? translationSelect.value : 85;

      // Don't fetch if no reciter selected (empty value)
      if (!reciter) {
        debug('loadVerses: No reciter selected, skipping fetch');
        return;
      }

      fetch('/api/quran/verses/' + surah + '/' + reciter + '/' + translation, {
        method: 'GET',
        credentials: 'same-origin',
        headers: { 'Accept': 'application/json' }
      })
      .then(function (response) { return response.json(); })
      .then(function (data) {
        if (data.success && data.verses) {
          verses = data.verses;
          currentVerseIndex = 0;
          updateVerseDisplay();
        }
      })
      .catch(function (error) {
        console.error('Quran Player: Verses error:', error);
        showStatus('Could not load verse text. Audio may still work.', true);
      });
    }

    // Update verse display
    function updateVerseDisplay() {
      if (verses.length === 0) return;
      var verse = verses[currentVerseIndex];
      if (!verse) return;

      if (arabicText) arabicText.textContent = verse.arabic || '';
      if (translationText) translationText.textContent = verse.translation || '';
      if (verseRef) verseRef.textContent = verse.verse_key || '';
      if (verseCounter) {
        var total = verses.filter(function(v) { return !v.is_bismillah; }).length;
        var current = verse.is_bismillah ? 'Bismillah' : verse.verse_number;
        verseCounter.textContent = 'Verse ' + current + ' of ' + total;
      }
    }

    // Navigation
    function previousVerse() {
      if (currentVerseIndex > 0) {
        currentVerseIndex--;
        updateVerseDisplay();
      }
    }

    function nextVerse() {
      if (currentVerseIndex < verses.length - 1) {
        currentVerseIndex++;
        updateVerseDisplay();
      }
    }

    function previousSurah() {
      if (!surahSelect) return;
      if (surahSelect.selectedIndex > 0) {
        surahSelect.selectedIndex--;
        loadAudioFromApi();
      }
    }

    function nextSurah() {
      if (!surahSelect) return;
      if (surahSelect.selectedIndex < surahSelect.options.length - 1) {
        surahSelect.selectedIndex++;
        loadAudioFromApi();
      }
    }

    // Handle audio ended
    function onAudioEnded() {
      if (autoAdvanceCheckbox && autoAdvanceCheckbox.checked) {
        var surahNum = parseInt(surahSelect.value, 10);
        if (surahNum < 114) {
          showStatus('Surah complete. Loading next...', false);
          nextSurah();
          setTimeout(function () {
            if (audio.src) audio.play().catch(function () {});
          }, 1500);
        } else {
          showStatus('Quran complete.', false);
        }
      } else {
        showStatus('Surah complete.', false);
      }
    }

    // Handle speed change
    function onSpeedChange() {
      if (audio && speedSelect) {
        audio.playbackRate = parseFloat(speedSelect.value) || 1;
      }
    }

    // Set up event listeners
    if (loadBtn) {
      loadBtn.addEventListener('click', function() {
        console.log('Quran Player: Load button clicked');
        loadAudioFromApi();
      });
    }

    if (prevSurahBtn) prevSurahBtn.addEventListener('click', previousSurah);
    if (nextSurahBtn) nextSurahBtn.addEventListener('click', nextSurah);
    if (prevVerseBtn) prevVerseBtn.addEventListener('click', previousVerse);
    if (nextVerseBtn) nextVerseBtn.addEventListener('click', nextVerse);
    if (speedSelect) speedSelect.addEventListener('change', onSpeedChange);
    if (translationSelect) translationSelect.addEventListener('change', loadVerses);

    if (audio) {
      audio.addEventListener('ended', onAudioEnded);
      audio.addEventListener('error', function (e) {
        debug('AUDIO ERROR: ' + (e.message || 'unknown'));
        showStatus('Audio error. Try a different reciter.', true);
      });
      audio.addEventListener('canplay', function () {
        debug('Audio ready to play!');
        onSpeedChange();
      });
      audio.addEventListener('playing', function () {
        debug('Audio is now playing');
      });
      audio.addEventListener('loadstart', function () {
        debug('Audio loadstart');
      });
    }

    // Initialize with pre-loaded audio URL from server
    if (settings.audioUrl) {
      debug('Using pre-loaded URL');
      setAudioSource(settings.audioUrl);
      loadVerses();
    } else {
      debug('No pre-loaded URL. Click Load Surah.');
      showStatus('Select a Surah and click Load to begin.', false);
    }

    debug('Player ready!');
  }

})(Drupal, drupalSettings);
