<?php

declare(strict_types=1);

namespace Drupal\gablvm_core\Controller;

use Drupal\Core\Controller\ControllerBase;
use Drupal\Core\DependencyInjection\ContainerInjectionInterface;
use Drupal\gablvm_core\Service\QuranApiService;
use Drupal\gablvm_core\Service\QuranFoundationOauthService;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;

/**
 * Controller for the Quran Player.
 */
class QuranPlayerController extends ControllerBase implements ContainerInjectionInterface {

  /**
   * The Quran API service.
   */
  protected QuranApiService $quranApi;

  /**
   * Quran Foundation OAuth service (for connection-state checks).
   */
  protected QuranFoundationOauthService $qfOauth;

  /**
   * Constructs a QuranPlayerController object.
   */
  public function __construct(QuranApiService $quran_api, QuranFoundationOauthService $qf_oauth) {
    $this->quranApi = $quran_api;
    $this->qfOauth = $qf_oauth;
  }

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container): static {
    return new static(
      $container->get('gablvm_core.quran_api'),
      $container->get('gablvm_core.qf_oauth'),
    );
  }

  /**
   * Display the Quran Player.
   */
  public function display(Request $request): array {
    $config = $this->config('gablvm_core.settings');

    // Check if feature is enabled.
    if (!($config->get('features.quran_player_enabled') ?? TRUE)) {
      return [
        '#markup' => '<div class="feature-disabled"><h2>' . $this->t('Quran Player - Coming Soon') . '</h2><p>' . $this->t('The Quran Player feature is currently under maintenance. Please check back later.') . '</p></div>',
        '#cache' => ['max-age' => 0],
      ];
    }

    // Get current language.
    $language = $this->languageManager()->getCurrentLanguage()->getId();

    // Get Surahs list.
    $surahs = $this->quranApi->getSurahs($language);

    // Get reciters for verse-by-verse mode (12 reciters).
    $reciters = $this->quranApi->getReciters($language);

    // Get chapter reciters for gapless mode (20 reciters).
    $chapter_reciters = $this->quranApi->getChapterReciters($language);

    // Get translations.
    $translations = $this->quranApi->getTranslations($language);

    // ===== Read query-string deep-link params FIRST so the overrides
    // below can apply on top of the config defaults. =====
    $current_surah = (int) $request->query->get('surah', 1);
    if ($current_surah < 1 || $current_surah > 114) {
      $current_surah = 1;
    }
    // Optional ?verse=N target for bookmark deep-links. 0 = no target.
    $current_verse = (int) $request->query->get('verse', 0);
    if ($current_verse < 1 || $current_verse > 286) {
      $current_verse = 0;
    }
    // Optional ?reciter=N target — overrides the configured default when a
    // bookmark deep-link tells us which reciter the user was listening to.
    $reciter_override = (int) $request->query->get('reciter', 0);
    if ($reciter_override < 1 || $reciter_override > 10000) {
      $reciter_override = 0;
    }
    // Optional ?english=1 — restores the verse-by-verse-with-English mode the
    // user was using when they bookmarked. The player JS picks this up from
    // drupalSettings on init and toggles the English checkbox before load.
    $english_on = (bool) $request->query->get('english', FALSE);

    // ===== Config defaults, with overrides applied. =====
    $default_reciter = $config->get('quran_api.default_reciter') ?? 7;
    $default_translation = $config->get('quran_api.default_translation') ?? 85;
    $greeting_message = $config->get('display.greeting_message') ?? '';
    if (!empty($reciter_override)) {
      $default_reciter = $reciter_override;
    }

    // Override greeting during Ramadan.
    if (_gablvm_core_is_ramadan()) {
      $greeting_message = (string) $this->t('Ramadan Mubarak! May your Quran recitation this month bring you closer to Allah.');
    }

    // Filter translations to only include English ones for TTS.
    $translations = array_filter($translations, function ($t) {
      $lang = strtolower($t['language_name'] ?? '');
      return strpos($lang, 'english') !== FALSE || $lang === 'en';
    });

    // Get audio URL for current surah.
    $audio_url = $this->quranApi->getSurahAudioUrl($current_surah, $default_reciter);

    // Get verses for display.
    $verses_data = $this->quranApi->getVerses($current_surah, $default_translation, $language);
    $first_verse_arabic = '';
    $first_verse_translation = '';
    if (!empty($verses_data['verses'])) {
      $first_verse = $verses_data['verses'][0] ?? [];
      $first_verse_arabic = $first_verse['text_uthmani'] ?? $first_verse['text_imlaei'] ?? '';
      if (!empty($first_verse['translations'])) {
        $first_verse_translation = $first_verse['translations'][0]['text'] ?? '';
      }
    }

    // Build AJAX endpoint URL template (will be replaced in JS).
    $audio_endpoint = '/api/quran/audio/{surah}/{reciter}';

    // Check if translation text display is enabled.
    $show_translation_text = $config->get('features.show_translation_text') ?? TRUE;

    // Format reciters for JavaScript.
    $verse_reciters_js = $this->formatRecitersForSelect($reciters);
    $chapter_reciters_js = $this->formatChapterRecitersForSelect($chapter_reciters);

    // Per-user signals for the QF bookmark button + reading-session logger.
    $current_uid = (int) $this->currentUser()->id();
    $qf_connected = $current_uid > 0 && $this->qfOauth->isConnected($current_uid);
    $qf_csrf_token = $current_uid > 0
      ? \Drupal::csrfToken()->get('gablvm_core.qf_user_actions')
      : '';

    $libraries = ['gablvm_core/quran_player'];
    if ($qf_connected) {
      $libraries[] = 'gablvm_core/quran_player_qf';
    }

    return [
      '#theme' => 'gablvm_quran_player',
      '#surahs' => $this->formatSurahsForSelect($surahs),
      '#reciters' => $verse_reciters_js,
      '#chapter_reciters' => $chapter_reciters_js,
      '#translations' => $this->formatTranslationsForSelect($translations),
      '#current_surah' => $current_surah,
      '#current_reciter' => $default_reciter,
      '#current_translation' => $default_translation,
      '#greeting_message' => $greeting_message,
      '#show_translation' => TRUE,
      '#show_translation_text' => $show_translation_text,
      '#qf_connected' => $qf_connected,
      '#qf_account_url' => '/quran/my-account',
      '#qf_login_url' => '/oauth/quran-foundation/login?destination=/quran-listen',
      '#qf_csrf_token' => $qf_csrf_token,
      '#reciter_preselected' => !empty($reciter_override),
      '#english_on' => $english_on,
      '#attached' => [
        'library' => $libraries,
        'drupalSettings' => [
          'gablvmQuran' => [
            'currentSurah' => $current_surah,
            'currentVerse' => $current_verse,
            'englishOn' => $english_on,
            'reciterOverride' => $reciter_override,
            'currentReciter' => $default_reciter,
            'currentTranslation' => $default_translation,
            'audioUrl' => $audio_url,
            'audioEndpoint' => $audio_endpoint,
            'firstVerseArabic' => $first_verse_arabic,
            'firstVerseTranslation' => $first_verse_translation,
            'hasCredentials' => $this->quranApi->hasCredentials(),
            'showTranslationText' => $show_translation_text,
            'verseReciters' => $verse_reciters_js,
            'chapterReciters' => $chapter_reciters_js,
          ],
          'gablvmQf' => [
            'connected' => $qf_connected,
            'csrfToken' => $qf_csrf_token,
            'bookmarkUrl' => '/quran/api/bookmark',
            'readingSessionUrl' => '/quran/api/reading-session',
          ],
        ],
      ],
      '#cache' => [
        'contexts' => ['url.query_args', 'languages', 'user.roles:authenticated', 'user'],
        'tags' => ['config:gablvm_core.settings'],
        'max-age' => 0,
      ],
    ];
  }

  /**
   * AJAX endpoint to get audio URL for a surah/reciter combination.
   *
   * This endpoint is used for gapless/chapter playback mode.
   *
   * Bismillah handling (tested December 8, 2025):
   *
   * Reciters WITH embedded bismillah in chapter audio (do NOT add separate):
   * - 13: Saad al-Ghamdi
   * - 19: Ahmed ibn Ali al-Ajmy
   * - 158: Abdullah Ali Jabir
   * - 159: Maher al-Muaiqly
   * - 160: Bandar Baleela
   * - 173: Mishari Rashid al-Afasy Streaming
   * - 175: Abdullah Hamad Abu Sharida
   *
   * Reciters WITHOUT embedded bismillah (NEED separate bismillah):
   * These use their OWN verse 1:1 audio when available:
   * - 1, 2: Abdul Basit Abdul Samad (has verse audio)
   * - 3: Abdur-Rahman as-Sudais (has verse audio)
   * - 4: Abu Bakr al-Shatri (has verse audio)
   * - 5: Hani ar-Rifai (has verse audio)
   * - 6, 12: Mahmoud Khaleel Al-Husary (has verse audio)
   * - 7: Mishari Rashid al-Afasy (has verse audio)
   * - 9: Muhammad Siddiq al-Minshawi (has verse audio)
   * - 10: Saud ash-Shuraym (has verse audio)
   * - 161: Khalifah Al Tunaiji (NO verse audio - use Mishari fallback)
   * - 168: Muhammad Siddiq al-Minshawi with kids (NO verse audio - use Mishari fallback)
   * - 174: Yasser ad-Dussary (NO verse audio - use Mishari fallback)
   */
  public function getAudio(int $surah, int $reciter): JsonResponse {
    // Validate input.
    if ($surah < 1 || $surah > 114) {
      return new JsonResponse(['error' => 'Invalid surah number'], 400);
    }

    if ($reciter < 1 || $reciter > 1000) {
      return new JsonResponse(['error' => 'Invalid reciter ID'], 400);
    }

    $audio_url = $this->quranApi->getSurahAudioUrl($surah, $reciter);

    // Determine if bismillah is needed (surahs 2-8 and 10-114, not 1 or 9).
    $surah_needs_bismillah = ($surah > 1 && $surah != 9);
    $bismillah_audio_url = NULL;

    // Reciters that ALREADY have bismillah embedded in their chapter audio.
    // These do NOT need a separate bismillah added.
    // Configurable at /admin/config/services/gablvm.
    $config = $this->config('gablvm_core.settings');
    $embedded_str = $config->get('quran_player.embedded_bismillah_reciters') ?? '13,19,158,159,160,173,175';
    $reciters_with_embedded_bismillah = array_map('intval', array_filter(explode(',', $embedded_str)));

    // Check if this reciter needs a separate bismillah.
    $needs_separate_bismillah = $surah_needs_bismillah && !in_array($reciter, $reciters_with_embedded_bismillah);

    // For reciters without embedded bismillah, get their own bismillah audio.
    if ($needs_separate_bismillah) {
      // Map chapter reciter IDs to their verse-by-verse reciter IDs.
      // Most are the same, but some chapter-only reciters have no verse audio.
      // Configurable at /admin/config/services/gablvm.
      $map_str = $config->get('quran_player.verse_reciter_map') ?? '1:1,2:2,3:3,4:4,5:5,6:6,7:7,9:9,10:10,12:12,161:7,168:7,174:7';
      $verse_reciter_map = [];
      foreach (array_filter(explode(',', $map_str)) as $pair) {
        $parts = explode(':', trim($pair));
        if (count($parts) === 2) {
          $verse_reciter_map[(int) $parts[0]] = (int) $parts[1];
        }
      }

      // Get the verse reciter ID (fallback to Mishari if not mapped).
      $verse_reciter_id = $verse_reciter_map[$reciter] ?? 7;

      // Fetch the reciter's own bismillah (verse 1:1).
      $surah1_audio = $this->quranApi->getVerseAudioFiles(1, $verse_reciter_id);

      if (!empty($surah1_audio[0]['url'])) {
        $url = $surah1_audio[0]['url'];
        // Handle different URL formats from the API:
        // - Full URL: https://... or http://...
        // - Protocol-relative: //mirrors.quranicaudio.com/...
        // - Relative path: Alafasy/mp3/001001.mp3
        if (str_starts_with($url, 'http')) {
          // Already a full URL, use as-is.
        }
        elseif (str_starts_with($url, '//')) {
          // Protocol-relative URL, add https:
          $url = 'https:' . $url;
        }
        else {
          // Relative path, prepend the verses CDN.
          $url = 'https://verses.quran.com/' . $url;
        }
        $bismillah_audio_url = $url;
      }
    }

    if ($audio_url) {
      return new JsonResponse([
        'success' => TRUE,
        'audioUrl' => $audio_url,
        'bismillahAudioUrl' => $bismillah_audio_url,
        'needsBismillah' => $needs_separate_bismillah && $bismillah_audio_url !== NULL,
        'surah' => $surah,
        'reciter' => $reciter,
      ]);
    }

    return new JsonResponse([
      'success' => FALSE,
      'error' => 'Audio not available for this combination',
      'surah' => $surah,
      'reciter' => $reciter,
    ], 404);
  }

  /**
   * AJAX endpoint to get verses with translations and audio for verse-by-verse playback.
   */
  public function getVersesWithAudio(int $surah, int $reciter, int $translation): JsonResponse {
    // Validate input.
    if ($surah < 1 || $surah > 114) {
      return new JsonResponse(['error' => 'Invalid surah number'], 400);
    }

    if ($reciter < 1 || $reciter > 1000) {
      return new JsonResponse(['error' => 'Invalid reciter ID'], 400);
    }

    // Get current language.
    $language = $this->languageManager()->getCurrentLanguage()->getId();

    // Get chapter details to check bismillah_pre field.
    $chapter = $this->quranApi->getSurah($surah, $language);
    $bismillah_pre = !empty($chapter['bismillah_pre']);

    // Get verses with translations.
    $verses_data = $this->quranApi->getVerses($surah, $translation, $language);

    // Get verse audio files.
    $audio_files = $this->quranApi->getVerseAudioFiles($surah, $reciter);

    // Get verse timing segments for gapless mode sync.
    $timing_segments = $this->quranApi->getVerseTimingSegments($surah, $reciter);

    if (!$verses_data || empty($verses_data['verses'])) {
      return new JsonResponse([
        'success' => FALSE,
        'error' => 'Could not load verses',
      ], 404);
    }

    // Build audio URL map by verse key.
    $audio_map = [];
    foreach ($audio_files as $audio) {
      $verse_key = $audio['verse_key'] ?? '';
      $url = $audio['url'] ?? '';
      if ($verse_key && $url) {
        // Handle different URL formats from the API.
        if (str_starts_with($url, 'http')) {
          // Already a full URL.
        }
        elseif (str_starts_with($url, '//')) {
          // Protocol-relative URL.
          $url = 'https:' . $url;
        }
        else {
          // Relative path.
          $url = 'https://verses.quran.com/' . $url;
        }
        $audio_map[$verse_key] = $url;
      }
    }

    // Build timing map by verse key.
    $timing_map = [];
    foreach ($timing_segments as $segment) {
      $verse_key = $segment['verse_key'] ?? '';
      if ($verse_key) {
        $timing_map[$verse_key] = [
          'start' => $segment['timestamp_from'] ?? 0,
          'end' => $segment['timestamp_to'] ?? 0,
        ];
      }
    }

    // Get bismillah audio URL if needed (from Surah 1, verse 1 for this reciter).
    $bismillah_audio_url = '';
    if ($bismillah_pre && $surah !== 1 && $surah !== 9) {
      $surah1_audio = $this->quranApi->getVerseAudioFiles(1, $reciter);
      if (!empty($surah1_audio[0]['url'])) {
        $url = $surah1_audio[0]['url'];
        // Handle different URL formats from the API.
        if (str_starts_with($url, 'http')) {
          // Already a full URL.
        }
        elseif (str_starts_with($url, '//')) {
          // Protocol-relative URL.
          $url = 'https:' . $url;
        }
        else {
          // Relative path.
          $url = 'https://verses.quran.com/' . $url;
        }
        $bismillah_audio_url = $url;
      }
    }

    // Combine verses with audio and timing.
    $verses = [];

    // Prepend bismillah if bismillah_pre is true (except for Surah 1 and 9).
    // Surah 1: Bismillah IS verse 1:1, no need to prepend.
    // Surah 9: No bismillah at all.
    if ($bismillah_pre && $surah !== 1 && $surah !== 9) {
      $verses[] = [
        'verse_key' => 'bismillah',
        'verse_number' => 0,
        'arabic' => 'بِسْمِ ٱللَّهِ ٱلرَّحْمَـٰنِ ٱلرَّحِيمِ',
        'translation' => 'In the name of Allah, the Most Gracious, the Most Merciful',
        'audio_url' => $bismillah_audio_url,
        'timing' => ['start' => 0, 'end' => 0],
        'is_bismillah' => TRUE,
      ];
    }

    foreach ($verses_data['verses'] as $verse) {
      $verse_key = $verse['verse_key'] ?? '';
      $translation_text = '';
      if (!empty($verse['translations'][0]['text'])) {
        // Clean up HTML tags from translation.
        $translation_text = strip_tags($verse['translations'][0]['text']);
      }

      $timing = $timing_map[$verse_key] ?? ['start' => 0, 'end' => 0];

      $verses[] = [
        'verse_key' => $verse_key,
        'verse_number' => $verse['verse_number'] ?? 0,
        'arabic' => $verse['text_uthmani'] ?? $verse['text_imlaei'] ?? '',
        'translation' => $translation_text,
        'audio_url' => $audio_map[$verse_key] ?? '',
        'timing' => $timing,
        'is_bismillah' => FALSE,
      ];
    }

    return new JsonResponse([
      'success' => TRUE,
      'surah' => $surah,
      'reciter' => $reciter,
      'translation' => $translation,
      'verses' => $verses,
      'total_verses' => count($verses),
      'bismillah_pre' => $bismillah_pre,
    ]);
  }

  /**
   * Format surahs for select element.
   */
  protected function formatSurahsForSelect(array $surahs): array {
    $options = [];
    foreach ($surahs as $surah) {
      $id = $surah['id'] ?? 0;
      $name = $surah['name_simple'] ?? 'Unknown';
      $translated = $surah['translated_name']['name'] ?? '';
      $verses = $surah['verses_count'] ?? 0;

      if ($translated && $translated !== $name) {
        $options[$id] = sprintf('%d. %s (%s) - %d verses', $id, $name, $translated, $verses);
      }
      else {
        $options[$id] = sprintf('%d. %s - %d verses', $id, $name, $verses);
      }
    }
    return $options;
  }

  /**
   * Format reciters for select element (verse-by-verse mode).
   *
   * Adds explanatory labels for recitation styles:
   * - Mujawwad: Melodic, embellished recitation with elongated notes
   * - Murattal: Plain, steady-paced recitation for study/memorization
   * - Muallim: Teaching style with pauses for student repetition
   */
  protected function formatRecitersForSelect(array $reciters): array {
    // Style explanations for clarity.
    $style_labels = [
      'Mujawwad' => 'Melodic',
      'Murattal' => 'Plain',
      'Muallim' => 'Teaching',
    ];

    $options = [];
    foreach ($reciters as $reciter) {
      $name = $reciter['reciter_name'] ?? $reciter['name'] ?? 'Unknown';
      $style = $reciter['style'] ?? '';

      if ($style) {
        $style_label = $style_labels[$style] ?? $style;
        $options[$reciter['id']] = "{$name} - {$style_label}";
      }
      else {
        $options[$reciter['id']] = $name;
      }
    }
    return $options;
  }

  /**
   * Format chapter reciters for select element (gapless mode).
   *
   * Provides clear differentiation for reciters with multiple recordings:
   * - Abdul Basit: Mujawwad (ID 1) vs Murattal (ID 2)
   * - Muhammad Siddiq al-Minshawi: Adult (ID 9) vs With Children (ID 168)
   */
  protected function formatChapterRecitersForSelect(array $reciters): array {
    // Custom labels for reciters with multiple recordings to differentiate them.
    $custom_labels = [
      1 => 'Abdul Basit Abdul Samad - Mujawwad (Melodic)',
      2 => 'Abdul Basit Abdul Samad - Murattal (Plain)',
      9 => 'Muhammad Siddiq al-Minshawi',
      168 => 'Muhammad Siddiq al-Minshawi - With Children',
      12 => 'Mahmoud Khalil Al-Husary - Muallim (Teaching)',
      173 => 'Mishari Rashid al-Afasy - Streaming Quality',
    ];

    // Chapter reciter IDs that support verse-by-verse English audio translation.
    $english_supported = [1, 2, 3, 4, 5, 6, 7, 9, 10, 12];

    $options = [];
    foreach ($reciters as $reciter) {
      $id = $reciter['id'];
      $name = $reciter['name'] ?? $reciter['reciter_name'] ?? 'Unknown';

      // Use custom label if available, otherwise use API name.
      $label = $custom_labels[$id] ?? $name;

      // Add English translation indicator for supported reciters.
      if (in_array($id, $english_supported)) {
        $label .= ' (English translation supported)';
      }

      $options[$id] = $label;
    }
    return $options;
  }

  /**
   * AJAX: cumulative per-verse timings for a (surah, reciter) chapter recitation.
   *
   * Used by the player's JS in chapter (gapless) mode to map the audio
   * element's currentTime to the verse the reciter is currently saying,
   * so the bookmark button captures the right verse instead of always
   * defaulting to verse 1. Returns an empty array for chapter-only
   * reciters that don't have a per-verse audio file set on QF.
   */
  public function getChapterTimings(int $surah, int $reciter): JsonResponse {
    if ($surah < 1 || $surah > 114) {
      return new JsonResponse(['error' => 'Invalid surah'], 400);
    }
    if ($reciter < 1 || $reciter > 10000) {
      return new JsonResponse(['error' => 'Invalid reciter'], 400);
    }
    $timings = $this->quranApi->getChapterVerseTimings($surah, $reciter);
    $response = new JsonResponse(['timings' => $timings]);
    // Aggressive cache — the timing data is static.
    $response->setMaxAge(86400);
    $response->setSharedMaxAge(86400);
    return $response;
  }

  /**
   * Format translations for select element.
   */
  protected function formatTranslationsForSelect(array $translations): array {
    $options = [];
    foreach ($translations as $translation) {
      $name = $translation['name'] ?? 'Unknown';
      $language = $translation['language_name'] ?? '';
      $author = $translation['author_name'] ?? '';
      $label = $language ? "{$name} ({$language})" : $name;
      if ($author) {
        $label .= " - {$author}";
      }
      $options[$translation['id']] = $label;
    }
    return $options;
  }

}
