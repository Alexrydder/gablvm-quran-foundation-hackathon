# English Audio Translation Feature

## Overview

This feature adds interleaved English audio translation (Ibrahim Walk - Saheeh International) to the Quran Player. When enabled, after each Arabic verse is recited, the English translation audio plays before continuing to the next verse.

## Audio Source

- **Reciter:** Ibrahim Walk
- **Translation:** Saheeh International
- **Source:** EveryAyah.com
- **Quality:** 192kbps MP3
- **Total Files:** 6,350 individual verse files (~1.5GB)
- **Download URL:** `https://everyayah.com/data/English/Sahih_Intnl_Ibrahim_Walk_192kbps/`

## File Structure

```
/webhttps://cdn.gablvm.org/quran-audio/ibrahim-walk-english/
├── surah/                    # Full surah files (kept for potential future use)
│   ├── 001.mp3              # ~960KB - Full Al-Fatihah
│   ├── 002.mp3              # ~116MB - Full Al-Baqarah
│   └── ...
└── verse/                    # Individual verse files (used by this feature)
    ├── 000_checksum.md5     # MD5 checksums for verification
    ├── 000_disclaimer.txt   # Usage disclaimer
    ├── 000_license.html     # License information
    ├── 001000.mp3           # Bismillah (Surah 1) - 82KB
    ├── 001001.mp3           # Surah 1, Verse 1 - 168KB
    ├── 001002.mp3           # Surah 1, Verse 2 - 119KB
    ├── ...
    ├── 002000.mp3           # Bismillah (Surah 2) - 82KB (same as 001000)
    ├── 002001.mp3           # Surah 2, Verse 1
    └── ...114006.mp3        # Surah 114, Verse 6
```

## File Naming Convention

```
Format: XXXYYY.mp3
- XXX: Surah number (001-114), zero-padded to 3 digits
- YYY: Verse number (000 for Bismillah, 001+ for verses), zero-padded to 3 digits

Examples:
- 001001.mp3 = Surah 1 (Al-Fatihah), Verse 1
- 002255.mp3 = Surah 2 (Al-Baqarah), Verse 255 (Ayatul Kursi)
- 003000.mp3 = Bismillah for Surah 3
- 114006.mp3 = Surah 114 (An-Nas), Verse 6
```

## Bismillah Files

All `XXX000.mp3` files are **identical** (83,590 bytes each). They contain the same generic Bismillah recording: "In the name of God, the Most Gracious, the Most Merciful."

**Usage:**
- Surahs 2-8, 10-114: Play `XXX000.mp3` before verse 1
- Surah 1 (Al-Fatihah): No separate Bismillah (verse 1 IS the Bismillah)
- Surah 9 (At-Tawbah): No Bismillah at all

## Implementation Details

### Playback Modes

The Quran Player now supports two modes:

| Mode | When | How It Works |
|------|------|--------------|
| **Gapless** | English checkbox unchecked | Full surah audio from Quran API, continuous playback |
| **Verse-by-Verse** | English checkbox checked | Individual verse files, Arabic → English interleaving |

### State Machine

```
                         User clicks Play
                               │
                               ▼
                    ┌─────────────────────┐
                    │  PLAYING_ARABIC     │
                    │  (current verse)    │
                    └──────────┬──────────┘
                               │ Audio ends
                               ▼
                    ┌─────────────────────┐
              ┌─────│  CHECK ENGLISH      │
              │     │  CHECKBOX           │
              │     └──────────┬──────────┘
              │                │
    Unchecked │                │ Checked
              │                ▼
              │     ┌─────────────────────┐
              │     │  PLAYING_ENGLISH    │
              │     │  (same verse)       │
              │     └──────────┬──────────┘
              │                │ Audio ends
              │                │
              ▼                ▼
        ┌─────────────────────────────────┐
        │     INCREMENT VERSE INDEX       │
        │     currentVerseIndex++         │
        └──────────────┬──────────────────┘
                       │
                       ▼
              ┌────────────────┐
              │ More verses?   │
              └───────┬────────┘
                      │
           Yes        │        No
            │         │         │
            ▼         │         ▼
    PLAY_NEXT_ARABIC  │   CHECK_AUTO_ADVANCE
                      │         │
                      └─────────┘
```

### JavaScript Functions

#### URL Builders

```javascript
// Get English verse audio URL
function getEnglishAudioUrl(verseKey) {
  // Input: "2:255" → Output: "/sites/.../verse/002255.mp3"
  var parts = verseKey.split(':');
  var surah = parts[0].padStart(3, '0');
  var verse = parts[1].padStart(3, '0');
  return 'https://cdn.gablvm.org/quran-audio/ibrahim-walk-english/verse/' + surah + verse + '.mp3';
}

// Get English Bismillah audio URL
function getEnglishBismillahUrl(surahNum) {
  // Input: 2 → Output: "/sites/.../verse/002000.mp3"
  // Returns null for Surah 1 and 9
  if (surahNum === 1 || surahNum === 9) return null;
  var surah = String(surahNum).padStart(3, '0');
  return 'https://cdn.gablvm.org/quran-audio/ibrahim-walk-english/verse/' + surah + '000.mp3';
}
```

#### Playback Functions

```javascript
// Load surah in verse-by-verse mode
function loadSurahVerseByVerse(surahNum, reciterId, autoPlay) {
  // 1. Clears current audio
  // 2. Fetches verses from /api/quran/verses/{surah}/{reciter}/{translation}
  // 3. Populates versesPlaylist array
  // 4. Calls playCurrentVerse() if autoPlay is true
}

// Play current Arabic verse
function playCurrentVerse() {
  // 1. Gets verse from versesPlaylist[currentVerseIndex]
  // 2. Sets audio.src to verse.audio_url (Arabic from API)
  // 3. Plays audio
  // 4. onAudioEnded will trigger English playback
}

// Play English translation for current verse
function playEnglishForCurrentVerse() {
  // 1. Gets verse from versesPlaylist[currentVerseIndex]
  // 2. Builds English URL using getEnglishAudioUrl() or getEnglishBismillahUrl()
  // 3. Sets audio.src to English URL
  // 4. Plays audio
  // 5. onAudioEnded will trigger next Arabic verse
}
```

### API Response Format

The `/api/quran/verses/{surah}/{reciter}/{translation}` endpoint returns:

```json
{
  "success": true,
  "surah": 2,
  "reciter": 7,
  "translation": 85,
  "verses": [
    {
      "verse_key": "bismillah",
      "verse_number": 0,
      "arabic": "بِسْمِ ٱللَّهِ ٱلرَّحْمَـٰنِ ٱلرَّحِيمِ",
      "translation": "In the name of Allah...",
      "audio_url": "https://verses.quran.com/...",
      "is_bismillah": true
    },
    {
      "verse_key": "2:1",
      "verse_number": 1,
      "arabic": "الٓمٓ",
      "translation": "Alif, Lam, Meem.",
      "audio_url": "https://verses.quran.com/...",
      "is_bismillah": false
    }
    // ... more verses
  ],
  "total_verses": 287,
  "bismillah_pre": true
}
```

## Accessibility

### VoiceOver/Screen Reader Handling

During verse-by-verse playback, status updates are **silenced** to prevent VoiceOver from interrupting the audio:

```javascript
function setStatus(msg, silent) {
  if (silent) {
    statusEl.setAttribute('aria-live', 'off');
  }
  statusEl.textContent = msg;
  if (silent) {
    setTimeout(function() {
      statusEl.setAttribute('aria-live', 'polite');
    }, 100);
  }
}

// Usage in playback functions:
setStatus('Arabic: Verse 2:5', true);  // Silent - no announcement
setStatus('Ready - press Play');       // Normal - will be announced
```

## Known Issues

### Surah 1 (Al-Fatihah) - English Audio Not Playing

**Status:** Under investigation

**Symptoms:**
- Arabic plays correctly for all 7 verses
- English audio does not play after Arabic verses

**Debugging:**
Console logs have been added. Check browser console for:
```
[QuranPlayer] playEnglishForCurrentVerse: verse_key=1:1, is_bismillah=false, surah=1
[QuranPlayer] Using verse URL: https://cdn.gablvm.org/quran-audio/ibrahim-walk-english/verse/001001.mp3
```

**Verified:**
- Files exist: `001001.mp3` through `001007.mp3`
- File sizes are correct (not empty)
- URL pattern matches other surahs

**Possible Causes to Investigate:**
1. File permissions
2. MIME type issues
3. Browser caching
4. API returning unexpected data for Surah 1

## Testing Checklist

- [ ] Enable English checkbox, play Surah 2 - verify Arabic/English interleaving
- [ ] Disable checkbox mid-playback - verify switches to gapless mode
- [ ] Enable checkbox mid-playback - verify switches to verse-by-verse mode
- [ ] Test Surah 9 (no Bismillah) - verify no Bismillah plays
- [ ] Test Surah 1 - **Currently not working**
- [ ] Test auto-advance to next Surah with English enabled
- [ ] Test with VoiceOver - verify no interruptions during playback
- [ ] Test Play/Pause during verse-by-verse mode
- [ ] Test loading different Surah while playing - verify correct Surah plays

## Future Enhancements

1. **Resume Position:** Remember verse position when switching modes
2. **Speed Sync:** Apply same playback speed to English audio
3. **Visual Indicator:** Show which language is currently playing
4. **Verse Highlighting:** Highlight current verse text during playback
5. **Skip English:** Button to skip current English and continue to next Arabic
6. **Multiple Translators:** Support for other English audio translations
