/**
 * @file
 * Listen Aloud - Text-to-speech for blog articles.
 * Uses the browser's built-in SpeechSynthesis API (free, no API costs).
 *
 * Splits article text into paragraph-sized chunks and plays them as a
 * queue of short utterances. This avoids Chrome's ~15-second speech
 * cutoff bug without needing any pause/resume workaround.
 *
 * Debug mode: add ?debug=1 to the URL to see a live debug log panel.
 */

(function (Drupal, once) {
  'use strict';

  // Debug log buffer
  window.gablvmListenDebugLog = [];

  var debugEnabled = window.location.search.indexOf('debug=1') !== -1;

  function log(msg) {
    var ts = new Date().toLocaleTimeString('en-US', { hour12: false });
    var entry = '[' + ts + '] ' + msg;
    if (debugEnabled) {
      console.log('Listen Aloud: ' + msg);
    }
    window.gablvmListenDebugLog.push(entry);
    if (window.gablvmListenDebugLog.length > 200) window.gablvmListenDebugLog.shift();
    var el = document.getElementById('listen-debug-log');
    if (el) {
      el.textContent = window.gablvmListenDebugLog.join('\n');
      el.scrollTop = el.scrollHeight;
    }
  }

  Drupal.behaviors.gablvmListenAloud = {
    attach: function (context) {
      // Attach to article bodies AND any element opted-in with .js-listen-aloud
      // (e.g. the tafsir page's verse list container).
      once('listen-aloud', '.node--type-article .field--name-body, .js-listen-aloud', context).forEach(function (bodyField) {
        new ListenAloud(bodyField);
      });
    }
  };

  function ListenAloud(bodyField) {
    this.bodyField = bodyField;
    this.speaking = false;
    this.paused = false;
    this.rate = 1.0;
    this.selectedVoice = null;
    this.voices = [];

    // Chunk queue
    this.chunks = [];
    this.currentChunk = 0;
    this.currentUtterance = null;
    this.utteranceId = 0;
    this.restarting = false; // Flag to handle speed/voice restarts

    if (!('speechSynthesis' in window)) {
      log('SpeechSynthesis not supported');
      return;
    }

    log('Initializing Listen Aloud');
    this.createControls();
    this.loadVoices();
    log('Init complete');
  }

  /**
   * Load available voices. Voices load asynchronously in some browsers.
   */
  ListenAloud.prototype.loadVoices = function () {
    var self = this;

    function populateVoices() {
      var allVoices = window.speechSynthesis.getVoices();
      self.voices = allVoices
        .filter(function (v) { return v.lang.startsWith('en'); })
        .sort(function (a, b) {
          if (a.default && !b.default) return -1;
          if (!a.default && b.default) return 1;
          return a.name.localeCompare(b.name);
        });

      if (self.voices.length === 0) {
        self.voices = allVoices.sort(function (a, b) {
          if (a.default && !b.default) return -1;
          if (!a.default && b.default) return 1;
          return a.name.localeCompare(b.name);
        });
      }

      log('Voices loaded: ' + self.voices.length + ' available');
      self.updateVoiceSelect();
    }

    populateVoices();

    if (window.speechSynthesis.onvoiceschanged !== undefined) {
      window.speechSynthesis.onvoiceschanged = populateVoices;
    }
  };

  /**
   * Create the listen controls and insert before the article body.
   */
  ListenAloud.prototype.createControls = function () {
    var container = document.createElement('div');
    container.className = 'listen-aloud';
    container.setAttribute('role', 'region');
    container.setAttribute('aria-label', Drupal.t('Listen to this article'));

    // Button row
    var btnRow = document.createElement('div');
    btnRow.className = 'listen-aloud__buttons';

    this.playBtn = document.createElement('button');
    this.playBtn.className = 'listen-aloud__btn listen-aloud__btn--play';
    this.playBtn.type = 'button';
    this.playBtn.innerHTML = '<span class="listen-aloud__icon" aria-hidden="true">&#x1f50a;</span> <span class="listen-aloud__label">' + Drupal.t('Listen to This Article') + '</span>';
    btnRow.appendChild(this.playBtn);

    this.stopBtn = document.createElement('button');
    this.stopBtn.className = 'listen-aloud__btn listen-aloud__btn--stop';
    this.stopBtn.type = 'button';
    this.stopBtn.textContent = Drupal.t('Stop');
    this.stopBtn.style.display = 'none';
    btnRow.appendChild(this.stopBtn);

    container.appendChild(btnRow);

    // Settings (collapsible)
    var settingsDetails = document.createElement('details');
    settingsDetails.className = 'listen-aloud__settings';
    var settingsSummary = document.createElement('summary');
    settingsSummary.textContent = Drupal.t('Voice & Speed');
    settingsDetails.appendChild(settingsSummary);

    var settingsContent = document.createElement('div');
    settingsContent.className = 'listen-aloud__settings-content';

    // Speed
    var speedGroup = document.createElement('div');
    speedGroup.className = 'listen-aloud__control-group';
    var speedLabel = document.createElement('label');
    speedLabel.setAttribute('for', 'listen-speed');
    speedLabel.textContent = Drupal.t('Speed');
    speedGroup.appendChild(speedLabel);

    this.speedSelect = document.createElement('select');
    this.speedSelect.id = 'listen-speed';
    this.speedSelect.className = 'listen-aloud__select';

    [
      { value: '0.5', label: '0.5x (Slow)' },
      { value: '0.75', label: '0.75x' },
      { value: '1', label: '1x (Normal)' },
      { value: '1.25', label: '1.25x' },
      { value: '1.5', label: '1.5x' },
      { value: '1.75', label: '1.75x' },
      { value: '2', label: '2x (Fast)' },
    ].forEach(function (s) {
      var opt = document.createElement('option');
      opt.value = s.value;
      opt.textContent = s.label;
      if (s.value === '1') opt.selected = true;
      this.speedSelect.appendChild(opt);
    }.bind(this));

    speedGroup.appendChild(this.speedSelect);
    settingsContent.appendChild(speedGroup);

    // Voice
    var voiceGroup = document.createElement('div');
    voiceGroup.className = 'listen-aloud__control-group';
    var voiceLabel = document.createElement('label');
    voiceLabel.setAttribute('for', 'listen-voice');
    voiceLabel.textContent = Drupal.t('Voice');
    voiceGroup.appendChild(voiceLabel);

    this.voiceSelect = document.createElement('select');
    this.voiceSelect.id = 'listen-voice';
    this.voiceSelect.className = 'listen-aloud__select';
    var loadingOpt = document.createElement('option');
    loadingOpt.value = '';
    loadingOpt.textContent = Drupal.t('Loading voices');
    this.voiceSelect.appendChild(loadingOpt);
    voiceGroup.appendChild(this.voiceSelect);
    settingsContent.appendChild(voiceGroup);

    settingsDetails.appendChild(settingsContent);
    container.appendChild(settingsDetails);

    // Status
    this.status = document.createElement('p');
    this.status.className = 'listen-aloud__status';
    this.status.setAttribute('aria-live', 'polite');
    container.appendChild(this.status);

    // Debug panel (hidden unless ?debug=1)
    if (debugEnabled) {
      var debugSection = document.createElement('details');
      debugSection.style.marginTop = '0.75rem';
      debugSection.open = true;
      var debugSummary = document.createElement('summary');
      debugSummary.textContent = 'Listen Aloud Debug Log';
      debugSummary.style.cssText = 'cursor:pointer; font-size:12px; color:#888;';
      debugSection.appendChild(debugSummary);

      var debugPre = document.createElement('pre');
      debugPre.id = 'listen-debug-log';
      debugPre.style.cssText = 'background:#1a1a2e; color:#0f0; font-family:monospace; font-size:11px; padding:12px; border-radius:6px; max-height:200px; overflow-y:auto; white-space:pre-wrap; word-break:break-all; margin-top:6px;';
      debugSection.appendChild(debugPre);

      container.appendChild(debugSection);
    }

    this.bodyField.parentNode.insertBefore(container, this.bodyField);
    this.bindEvents();
  };

  /**
   * Update the voice dropdown with available voices.
   */
  ListenAloud.prototype.updateVoiceSelect = function () {
    if (!this.voiceSelect || this.voices.length === 0) return;

    this.voiceSelect.innerHTML = '';

    var defaultOpt = document.createElement('option');
    defaultOpt.value = '';
    defaultOpt.textContent = Drupal.t('Default voice');
    this.voiceSelect.appendChild(defaultOpt);

    var self = this;
    this.voices.forEach(function (voice, index) {
      var opt = document.createElement('option');
      opt.value = index;
      var label = voice.name;
      if (voice.lang) label += ' (' + voice.lang + ')';
      if (voice.default) label += ' *';
      opt.textContent = label;
      self.voiceSelect.appendChild(opt);
    });

    var savedVoice = localStorage.getItem('gablvm_listen_voice');
    if (savedVoice) {
      for (var i = 0; i < this.voices.length; i++) {
        if (this.voices[i].name === savedVoice) {
          this.voiceSelect.value = i;
          this.selectedVoice = this.voices[i];
          break;
        }
      }
    }

    var savedSpeed = localStorage.getItem('gablvm_listen_speed');
    if (savedSpeed && this.speedSelect) {
      this.speedSelect.value = savedSpeed;
      this.rate = parseFloat(savedSpeed);
    }
  };

  /**
   * Bind events.
   */
  ListenAloud.prototype.bindEvents = function () {
    var self = this;

    this.playBtn.addEventListener('click', function () {
      if (self.speaking && !self.paused) {
        log('User clicked: Pause');
        self.pause();
      } else if (self.paused) {
        log('User clicked: Resume');
        self.resume();
      } else {
        log('User clicked: Play');
        self.play();
      }
    });

    this.stopBtn.addEventListener('click', function () {
      log('User clicked: Stop');
      self.stop();
    });

    this.speedSelect.addEventListener('change', function () {
      var newRate = parseFloat(this.value);
      log('Speed changed: ' + self.rate + 'x -> ' + newRate + 'x | speaking=' + self.speaking + ' paused=' + self.paused);
      self.rate = newRate;
      localStorage.setItem('gablvm_listen_speed', this.value);
      if (self.speaking && !self.paused) {
        self.restartAtCurrentChunk('Speed changed to ' + newRate + 'x');
      }
    });

    this.voiceSelect.addEventListener('change', function () {
      var index = parseInt(this.value, 10);
      var voiceName;
      if (!isNaN(index) && self.voices[index]) {
        self.selectedVoice = self.voices[index];
        voiceName = self.selectedVoice.name;
        localStorage.setItem('gablvm_listen_voice', voiceName);
      } else {
        self.selectedVoice = null;
        voiceName = 'default';
        localStorage.removeItem('gablvm_listen_voice');
      }
      log('Voice changed to: ' + voiceName + ' | speaking=' + self.speaking);
      if (self.speaking && !self.paused) {
        self.restartAtCurrentChunk('Voice changed');
      }
    });
  };

  /**
   * Restart playback at the current chunk after speed/voice change.
   * Uses a delay to ensure speechSynthesis.cancel() has fully completed.
   */
  ListenAloud.prototype.restartAtCurrentChunk = function (reason) {
    var self = this;
    var resumeChunk = this.currentChunk;

    log('restartAtCurrentChunk: reason="' + reason + '" chunk=' + resumeChunk + '/' + this.chunks.length);

    // Increment utterance ID to invalidate any pending onend/onerror
    this.utteranceId++;
    log('  Incremented utteranceId to ' + this.utteranceId);

    // Cancel current speech
    this.restarting = true;
    window.speechSynthesis.cancel();
    log('  speechSynthesis.cancel() called');

    // Wait for cancel to fully complete. Safari needs ~200ms, Chrome needs ~300ms.
    // We poll speechSynthesis.speaking to confirm it's actually stopped.
    var attempts = 0;
    var waitForCancel = setInterval(function () {
      attempts++;
      var stillSpeaking = window.speechSynthesis.speaking;
      log('  Waiting for cancel... attempt=' + attempts + ' still_speaking=' + stillSpeaking);

      if (!stillSpeaking || attempts >= 10) {
        clearInterval(waitForCancel);
        self.restarting = false;
        self.speaking = true;
        self.paused = false;
        self.currentChunk = resumeChunk;
        log('  Restarting chunk ' + resumeChunk + ' at ' + self.rate + 'x (after ' + (attempts * 50) + 'ms)');
        self.updateStatus(Drupal.t('@reason.', { '@reason': reason }));
        self.speakCurrentChunk();
      }
    }, 50);
  };

  /**
   * Extract article text as an array of chunks (one per paragraph/heading).
   * Each chunk is short enough that Chrome won't kill it.
   */
  ListenAloud.prototype.getArticleChunks = function () {
    var chunks = [];

    var titleEl = document.querySelector('.page-title');
    if (titleEl) {
      var titleText = titleEl.textContent.trim();
      if (titleText) chunks.push(titleText);
    }

    var clone = this.bodyField.cloneNode(true);

    // .js-listen-aloud-skip is the generic opt-out — any page can tag content
    // it wants excluded from the read queue (e.g. Arabic verse text on the
    // tafsir page, since English TTS voices silently drop non-Latin scripts).
    var removeSelectors = ['script', 'style', '.visually-hidden', '[aria-hidden="true"]', '.listen-aloud', '.js-listen-aloud-skip'];
    removeSelectors.forEach(function (sel) {
      clone.querySelectorAll(sel).forEach(function (el) { el.remove(); });
    });

    var blockSelector = 'p, li, h2, h3, h4, h5, h6, dt, dd, blockquote, figcaption, tr';
    var blocks = clone.querySelectorAll(blockSelector);
    if (blocks.length > 0) {
      blocks.forEach(function (block) {
        // Skip containers whose children are already block-level — otherwise
        // we'd chunk the wrapper's full text AND each child, causing duplicate
        // readback. Matters for nested structures like the tafsir verse list
        // (<li class="tafsir-verse"> wraps an <article> with its own <h3>/<p>s).
        if (block.querySelector(blockSelector)) return;
        var text = block.textContent.trim();
        if (text) chunks.push(text);
      });
    } else {
      var fullText = clone.textContent.trim();
      if (fullText) {
        var sentences = fullText.match(/[^.!?]+[.!?]+/g) || [fullText];
        var currentChunk = '';
        sentences.forEach(function (sentence) {
          currentChunk += sentence.trim() + ' ';
          if (currentChunk.length > 200) {
            chunks.push(currentChunk.trim());
            currentChunk = '';
          }
        });
        if (currentChunk.trim()) chunks.push(currentChunk.trim());
      }
    }

    log('Extracted ' + chunks.length + ' chunks from article');
    return chunks;
  };

  /**
   * Start reading the article.
   */
  ListenAloud.prototype.play = function () {
    this.chunks = this.getArticleChunks();

    if (this.chunks.length === 0) {
      this.updateStatus(Drupal.t('No article text found.'));
      log('No chunks found');
      return;
    }

    window.speechSynthesis.cancel();
    this.currentChunk = 0;
    this.speaking = true;
    this.paused = false;
    this.updateUI('playing');

    var voiceName = this.selectedVoice ? this.selectedVoice.name : 'default';
    log('Starting playback: ' + this.chunks.length + ' chunks, rate=' + this.rate + ', voice=' + voiceName);
    this.updateStatus(Drupal.t('Reading article at @speed speed using @voice.', {
      '@speed': this.rate + 'x',
      '@voice': voiceName,
    }));

    this.speakCurrentChunk();
  };

  /**
   * Speak the current chunk and queue the next one when done.
   */
  ListenAloud.prototype.speakCurrentChunk = function () {
    var self = this;

    if (this.currentChunk >= this.chunks.length) {
      this.speaking = false;
      this.paused = false;
      this.currentUtterance = null;
      this.updateUI('idle');
      this.updateStatus(Drupal.t('Finished reading.'));
      log('All chunks complete');
      return;
    }

    var text = this.chunks[this.currentChunk];
    var utterance = new SpeechSynthesisUtterance(text);
    utterance.rate = this.rate;
    utterance.pitch = 1.0;
    utterance.volume = 1.0;
    utterance.lang = document.documentElement.lang || 'en';

    if (this.selectedVoice) {
      utterance.voice = this.selectedVoice;
    }

    var myId = ++this.utteranceId;
    log('Speaking chunk ' + this.currentChunk + '/' + this.chunks.length + ' (id=' + myId + ', rate=' + this.rate + '): "' + text.substring(0, 50) + '..."');

    utterance.onstart = function () {
      log('  onstart fired (id=' + myId + ', current=' + self.utteranceId + ')');
    };

    utterance.onend = function () {
      log('  onend fired (id=' + myId + ', current=' + self.utteranceId + ', speaking=' + self.speaking + ', restarting=' + self.restarting + ')');
      if (myId !== self.utteranceId) {
        log('  → IGNORED: stale utterance');
        return;
      }
      if (!self.speaking) {
        log('  → IGNORED: not speaking');
        return;
      }
      if (self.restarting) {
        log('  → IGNORED: restart in progress');
        return;
      }
      self.currentChunk++;
      log('  → Advancing to chunk ' + self.currentChunk);
      self.speakCurrentChunk();
    };

    utterance.onerror = function (e) {
      log('  onerror fired (id=' + myId + ', error=' + e.error + ', current=' + self.utteranceId + ')');
      if (e.error === 'canceled') {
        log('  → IGNORED: canceled error');
        return;
      }
      if (myId !== self.utteranceId) {
        log('  → IGNORED: stale utterance');
        return;
      }
      if (self.restarting) {
        log('  → IGNORED: restart in progress');
        return;
      }
      if (self.speaking) {
        self.currentChunk++;
        log('  → Skipping to chunk ' + self.currentChunk);
        self.speakCurrentChunk();
      }
    };

    this.currentUtterance = utterance;
    window.speechSynthesis.speak(utterance);
  };

  ListenAloud.prototype.pause = function () {
    window.speechSynthesis.pause();
    this.paused = true;
    this.updateUI('paused');
    this.updateStatus(Drupal.t('Paused. Press again to resume.'));
    log('Paused at chunk ' + this.currentChunk);
  };

  ListenAloud.prototype.resume = function () {
    window.speechSynthesis.resume();
    this.paused = false;
    this.updateUI('playing');
    this.updateStatus(Drupal.t('Resumed reading.'));
    log('Resumed at chunk ' + this.currentChunk);
  };

  ListenAloud.prototype.stop = function () {
    log('Stop: cancelling speech');
    this.utteranceId++; // Invalidate pending events
    window.speechSynthesis.cancel();
    this.speaking = false;
    this.paused = false;
    this.restarting = false;
    this.currentUtterance = null;
    this.updateUI('idle');
    this.updateStatus(Drupal.t('Stopped.'));
  };

  ListenAloud.prototype.updateUI = function (state) {
    var label = this.playBtn.querySelector('.listen-aloud__label');
    var icon = this.playBtn.querySelector('.listen-aloud__icon');

    switch (state) {
      case 'playing':
        label.textContent = Drupal.t('Pause');
        icon.textContent = '\u23F8';
        this.playBtn.classList.add('listen-aloud__btn--active');
        this.stopBtn.style.display = '';
        break;
      case 'paused':
        label.textContent = Drupal.t('Resume');
        icon.textContent = '\u25B6';
        this.playBtn.classList.remove('listen-aloud__btn--active');
        this.stopBtn.style.display = '';
        break;
      default:
        label.textContent = Drupal.t('Listen to This Article');
        icon.textContent = '\uD83D\uDD0A';
        this.playBtn.classList.remove('listen-aloud__btn--active');
        this.stopBtn.style.display = 'none';
    }
  };

  ListenAloud.prototype.updateStatus = function (message) {
    if (this.status) {
      this.status.textContent = message;
    }
  };

})(Drupal, once);
