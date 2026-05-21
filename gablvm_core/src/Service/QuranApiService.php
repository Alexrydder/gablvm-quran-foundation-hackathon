<?php

declare(strict_types=1);

namespace Drupal\gablvm_core\Service;

use Drupal\Core\Cache\CacheBackendInterface;
use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Logger\LoggerChannelFactoryInterface;
use GuzzleHttp\ClientInterface;
use GuzzleHttp\Exception\ConnectException;
use GuzzleHttp\Exception\GuzzleException;
use Psr\Log\LoggerInterface;

/**
 * Service for interacting with the Quran API.
 *
 * Supports both:
 * - Public API (api.quran.com) - Free, no authentication required
 * - Foundation API (apis.quran.foundation) - Requires OAuth2 credentials
 *
 * When no credentials are configured, uses the public API.
 * API Documentation: https://api-docs.quran.com/
 */
class QuranApiService {

  /**
   * GABLVM API proxy URL (handles auth + edge caching).
   */
  protected const PROXY_API_URL = 'https://api.gablvm.org/quran';

  /**
   * Public API base URL (no auth required).
   */
  protected const PUBLIC_API_URL = 'https://api.quran.com/api/v4';

  /**
   * Production API base URL (auth required).
   */
  protected const PROD_API_URL = 'https://apis.quran.foundation/content/api/v4';

  /**
   * Pre-production API base URL (auth required).
   */
  protected const DEV_API_URL = 'https://apis-prelive.quran.foundation/content/api/v4';

  /**
   * Production OAuth2 token endpoint.
   */
  protected const PROD_TOKEN_URL = 'https://oauth2.quran.foundation/oauth2/token';

  /**
   * Pre-production OAuth2 token endpoint.
   */
  protected const DEV_TOKEN_URL = 'https://prelive-oauth2.quran.foundation/oauth2/token';

  /**
   * The HTTP client.
   */
  protected ClientInterface $httpClient;

  /**
   * The config factory.
   */
  protected ConfigFactoryInterface $configFactory;

  /**
   * The cache backend.
   */
  protected CacheBackendInterface $cache;

  /**
   * The logger.
   */
  protected LoggerInterface $logger;

  /**
   * Cached access token.
   */
  protected ?string $accessToken = NULL;

  /**
   * Constructs a QuranApiService object.
   */
  public function __construct(
    ClientInterface $http_client,
    ConfigFactoryInterface $config_factory,
    CacheBackendInterface $cache,
    LoggerChannelFactoryInterface $logger_factory,
  ) {
    $this->httpClient = $http_client;
    $this->configFactory = $config_factory;
    $this->cache = $cache;
    $this->logger = $logger_factory->get('gablvm_core');
  }

  /**
   * Get the current API mode setting.
   */
  protected function getApiMode(): string {
    $config = $this->configFactory->get('gablvm_core.settings');
    return $config->get('quran_api.api_mode') ?? 'public';
  }

  /**
   * Check if we should use authenticated API.
   */
  protected function useAuthenticatedApi(): bool {
    $mode = $this->getApiMode();
    // Public and proxy modes never use authentication from Drupal.
    // Proxy mode delegates auth to the Cloudflare Worker.
    if ($mode === 'public' || $mode === 'proxy') {
      return FALSE;
    }
    // Production/development modes require credentials.
    return $this->hasCredentials();
  }

  /**
   * Get the API base URL based on API mode setting.
   */
  protected function getApiUrl(): string {
    $mode = $this->getApiMode();

    switch ($mode) {
      case 'proxy':
        return self::PROXY_API_URL;

      case 'production':
        // Use authenticated API if credentials available, else fall back to public.
        return $this->hasCredentials() ? self::PROD_API_URL : self::PUBLIC_API_URL;

      case 'development':
        // Use authenticated API if credentials available, else fall back to public.
        return $this->hasCredentials() ? self::DEV_API_URL : self::PUBLIC_API_URL;

      case 'public':
      default:
        return self::PUBLIC_API_URL;
    }
  }

  /**
   * Get the OAuth2 token endpoint URL.
   */
  protected function getTokenUrl(): string {
    return $this->getApiMode() === 'development' ? self::DEV_TOKEN_URL : self::PROD_TOKEN_URL;
  }

  /**
   * Get Client ID from config.
   */
  protected function getClientId(): ?string {
    $config = $this->configFactory->get('gablvm_core.settings');
    return $config->get('quran_api.client_id') ?: NULL;
  }

  /**
   * Get Client Secret from config.
   */
  protected function getClientSecret(): ?string {
    $config = $this->configFactory->get('gablvm_core.settings');
    return $config->get('quran_api.client_secret') ?: NULL;
  }

  /**
   * Get OAuth2 access token using Client Credentials flow.
   */
  protected function getAccessToken(): ?string {
    // Check if we have a cached token.
    $cache_key = 'gablvm:quran_access_token:' . $this->getApiMode();
    if ($cached = $this->cache->get($cache_key)) {
      return $cached->data;
    }

    $clientId = $this->getClientId();
    $clientSecret = $this->getClientSecret();

    if (!$clientId || !$clientSecret) {
      $this->logger->warning('Quran API credentials not configured. Please set Client ID and Secret in GABLVM settings.');
      return NULL;
    }

    try {
      $response = $this->httpClient->request('POST', $this->getTokenUrl(), [
        'auth' => [$clientId, $clientSecret],
        'headers' => [
          'Content-Type' => 'application/x-www-form-urlencoded',
        ],
        'form_params' => [
          'grant_type' => 'client_credentials',
          'scope' => 'content',
        ],
        'connect_timeout' => 3,
        'timeout' => 10,
      ]);

      $data = json_decode($response->getBody()->getContents(), TRUE);

      if (isset($data['access_token'])) {
        $token = $data['access_token'];
        // Cache for slightly less than expiry time (default 1 hour).
        $expires_in = ($data['expires_in'] ?? 3600) - 300;
        $this->cache->set($cache_key, $token, time() + $expires_in);
        $this->logger->info('Successfully obtained Quran API access token.');
        return $token;
      }
    }
    catch (GuzzleException $e) {
      $this->logger->error('Failed to obtain Quran API access token: @message', [
        '@message' => $e->getMessage(),
      ]);
    }

    return NULL;
  }

  /**
   * Make an API request (authenticated or public) with retry on 5xx errors.
   */
  protected function request(string $endpoint, array $params = []): ?array {
    $url = $this->getApiUrl() . '/' . ltrim($endpoint, '/');
    $headers = ['Accept' => 'application/json'];

    // If using authenticated API, add auth headers.
    if ($this->useAuthenticatedApi()) {
      $accessToken = $this->getAccessToken();
      $clientId = $this->getClientId();

      if (!$accessToken || !$clientId) {
        $this->logger->warning('Cannot make authenticated Quran API request: missing credentials. Falling back to public API.');
        // Fall back to public API.
        $url = self::PUBLIC_API_URL . '/' . ltrim($endpoint, '/');
      }
      else {
        $headers['x-auth-token'] = $accessToken;
        $headers['x-client-id'] = $clientId;
      }
    }

    $max_retries = 2;
    $retry_delays = [1, 2]; // seconds

    for ($attempt = 0; $attempt <= $max_retries; $attempt++) {
      try {
        $response = $this->httpClient->request('GET', $url, [
          'query' => $params,
          'headers' => $headers,
          'connect_timeout' => 3,
          'timeout' => 5,
        ]);

        $body = $response->getBody()->getContents();
        $data = json_decode($body, TRUE);

        if ($data === NULL && $body !== 'null') {
          $this->logger->warning('Quran API returned invalid JSON for @endpoint (attempt @n)', [
            '@endpoint' => $endpoint,
            '@n' => $attempt + 1,
          ]);
          return NULL;
        }

        return $data;
      }
      catch (GuzzleException $e) {
        $code = $e->getCode();
        $is_timeout = $e instanceof ConnectException;

        // Retry on 5xx server errors AND on connection/timeout errors
        // (cURL error 28 et al). Guzzle reports timeouts with code 0 via
        // ConnectException, so a pure $code >= 500 check misses them.
        if (($is_timeout || $code >= 500) && $attempt < $max_retries) {
          $this->logger->info('Quran API @reason for @endpoint, retrying in @delay s (attempt @n/@max)', [
            '@reason' => $is_timeout ? 'timeout' : $code,
            '@endpoint' => $endpoint,
            '@delay' => $retry_delays[$attempt],
            '@n' => $attempt + 1,
            '@max' => $max_retries + 1,
          ]);
          sleep($retry_delays[$attempt]);
          continue;
        }

        // Don't log 404s for recitations endpoint - expected for chapter-only reciters.
        $is_expected_404 = ($code === 404 && str_contains($endpoint, 'recitations/'));
        if (!$is_expected_404) {
          $this->logger->error('Quran API request failed for @endpoint: @message', [
            '@endpoint' => $endpoint,
            '@message' => $e->getMessage(),
          ]);
        }

        // If unauthorized on authenticated API, clear token and fall back to public API.
        if ($this->useAuthenticatedApi() && ($code === 401 || $code === 403)) {
          $cache_key = 'gablvm:quran_access_token:' . $this->getApiMode();
          $this->cache->delete($cache_key);
          $this->logger->warning('Falling back to public Quran API after @code on @endpoint.', [
            '@code' => $code,
            '@endpoint' => $endpoint,
          ]);
          // Retry once with the public API.
          try {
            $public_url = 'https://api.quran.com/api/v4/' . ltrim($endpoint, '/');
            $response = $this->httpClient->request('GET', $public_url, [
              'query' => $params,
              'timeout' => 8,
            ]);
            return json_decode($response->getBody()->getContents(), TRUE);
          }
          catch (\Exception $fallbackError) {
            $this->logger->error('Public API fallback also failed for @endpoint: @msg', [
              '@endpoint' => $endpoint,
              '@msg' => $fallbackError->getMessage(),
            ]);
          }
        }

        return NULL;
      }
    }

    return NULL;
  }

  /**
   * Get all Surahs (chapters).
   */
  public function getSurahs(string $language = 'en'): array {
    $cache_key = 'gablvm:surahs:' . $language;

    if ($cached = $this->cache->get($cache_key)) {
      return $cached->data;
    }

    $response = $this->request('chapters', ['language' => $language]);

    if ($response && isset($response['chapters'])) {
      $surahs = $response['chapters'];
      // Cache for 24 hours.
      $this->cache->set($cache_key, $surahs, time() + 86400);
      return $surahs;
    }

    // Return default surahs if API fails.
    return $this->getDefaultSurahs();
  }

  /**
   * Get default surahs as fallback.
   */
  protected function getDefaultSurahs(): array {
    return [
      ['id' => 1, 'name_simple' => 'Al-Fatihah', 'name_arabic' => 'الفاتحة', 'verses_count' => 7],
      ['id' => 2, 'name_simple' => 'Al-Baqarah', 'name_arabic' => 'البقرة', 'verses_count' => 286],
      ['id' => 3, 'name_simple' => 'Ali \'Imran', 'name_arabic' => 'آل عمران', 'verses_count' => 200],
      ['id' => 36, 'name_simple' => 'Ya-Sin', 'name_arabic' => 'يس', 'verses_count' => 83],
      ['id' => 55, 'name_simple' => 'Ar-Rahman', 'name_arabic' => 'الرحمن', 'verses_count' => 78],
      ['id' => 67, 'name_simple' => 'Al-Mulk', 'name_arabic' => 'الملك', 'verses_count' => 30],
      ['id' => 112, 'name_simple' => 'Al-Ikhlas', 'name_arabic' => 'الإخلاص', 'verses_count' => 4],
      ['id' => 113, 'name_simple' => 'Al-Falaq', 'name_arabic' => 'الفلق', 'verses_count' => 5],
      ['id' => 114, 'name_simple' => 'An-Nas', 'name_arabic' => 'الناس', 'verses_count' => 6],
    ];
  }

  /**
   * Get a single Surah.
   */
  public function getSurah(int $surah_number, string $language = 'en'): ?array {
    $cache_key = 'gablvm:surah:' . $surah_number . ':' . $language;

    if ($cached = $this->cache->get($cache_key)) {
      return $cached->data;
    }

    $response = $this->request("chapters/{$surah_number}", ['language' => $language]);

    if ($response && isset($response['chapter'])) {
      $surah = $response['chapter'];
      $this->cache->set($cache_key, $surah, time() + 86400);
      return $surah;
    }

    return NULL;
  }

  /**
   * Get verses for a Surah.
   */
  public function getVerses(int $surah_number, int $translation_id = 85, string $language = 'en', int $page = 1): ?array {
    $cache_key = "gablvm:verses:{$surah_number}:{$translation_id}:{$language}:{$page}";

    if ($cached = $this->cache->get($cache_key)) {
      return $cached->data;
    }

    $response = $this->request("verses/by_chapter/{$surah_number}", [
      'language' => $language,
      'translations' => $translation_id,
      'fields' => 'text_uthmani,text_imlaei',
      'page' => $page,
      'per_page' => 300,
    ]);

    if ($response && isset($response['verses'])) {
      // Verse text is static — cache for 7 days.
      $this->cache->set($cache_key, $response, time() + 604800);
      return $response;
    }

    return NULL;
  }

  /**
   * Per-verse cumulative timing array for a (surah, reciter) chapter recitation.
   *
   * QF's chapter_recitations endpoint exposes ONLY a single gapless MP3 URL
   * with no verse boundaries. The verse-by-verse endpoint
   * `recitations/{reciter}/by_chapter/{surah}?fields=segments` does include
   * word-level segment timings per verse file. We use those to compute a
   * per-verse duration (last segment's end_ms), then sum into a cumulative
   * array so the player JS can map audioElement.currentTime in chapter mode
   * to the verse-most-likely-being-played-right-now.
   *
   * The chapter MP3 and the per-verse MP3s are DIFFERENT recordings with
   * slightly different pacing, so the mapping is approximate (typically off
   * by a few percent — close enough that "bookmark verse the reciter is
   * saying right now" works correctly in 90%+ of clicks).
   *
   * @return array<int, array{verse:int, cumulative_end_ms:int}>
   *   Verses 1..N with cumulative end-time (in milliseconds) within the
   *   notional concatenated stream. Empty array if the per-verse audio
   *   isn't available for this reciter (e.g. chapter-only reciters like
   *   Bandar Baleela). Cached aggressively — segment data is static.
   */
  public function getChapterVerseTimings(int $surah_number, int $reciter_id): array {
    $cache_key = "gablvm:chapter_timings:{$surah_number}:{$reciter_id}";
    if ($cached = $this->cache->get($cache_key)) {
      return $cached->data;
    }

    $response = $this->request("recitations/{$reciter_id}/by_chapter/{$surah_number}", [
      'fields' => 'segments',
      'per_page' => 300,
    ]);

    $timings = [];
    if (is_array($response) && !empty($response['audio_files'])) {
      $cumulative_ms = 0;
      foreach ($response['audio_files'] as $f) {
        $verse_key = (string) ($f['verse_key'] ?? '');
        $parts = explode(':', $verse_key);
        if (count($parts) !== 2) {
          continue;
        }
        $verse = (int) $parts[1];
        // Last segment's [3] entry is the verse's end time within its own file.
        $segments = is_array($f['segments'] ?? NULL) ? $f['segments'] : [];
        if (empty($segments)) {
          continue;
        }
        $last = end($segments);
        $end_ms = is_array($last) ? (int) ($last[3] ?? 0) : 0;
        if ($end_ms <= 0) {
          continue;
        }
        $cumulative_ms += $end_ms;
        $timings[] = [
          'verse' => $verse,
          'cumulative_end_ms' => $cumulative_ms,
        ];
      }
    }

    // 30-day cache — segment data is static per (surah, reciter).
    $this->cache->set($cache_key, $timings, time() + 2592000);
    return $timings;
  }

  /**
   * Get available reciters for verse-by-verse playback.
   */
  public function getReciters(string $language = 'en'): array {
    $cache_key = 'gablvm:reciters:' . $language;

    if ($cached = $this->cache->get($cache_key)) {
      return $cached->data;
    }

    // Try with language parameter first, fall back to no language if it fails.
    $response = $this->request('resources/recitations', ['language' => $language]);

    // If that fails, try without language parameter.
    if (!$response || !isset($response['recitations'])) {
      $response = $this->request('resources/recitations');
    }

    if ($response && isset($response['recitations'])) {
      $reciters = $response['recitations'];
      $this->cache->set($cache_key, $reciters, time() + 86400);
      return $reciters;
    }

    // Return default reciters if API fails.
    return $this->getDefaultReciters();
  }

  /**
   * Get available chapter reciters for full surah (gapless) playback.
   */
  public function getChapterReciters(string $language = 'en'): array {
    $cache_key = 'gablvm:chapter_reciters:' . $language;

    if ($cached = $this->cache->get($cache_key)) {
      return $cached->data;
    }

    // Try with language parameter first, fall back to no language if it fails.
    $response = $this->request('resources/chapter_reciters', ['language' => $language]);

    // If that fails, try without language parameter (some API versions don't support it).
    if (!$response || !isset($response['reciters'])) {
      $response = $this->request('resources/chapter_reciters');
    }

    if ($response && isset($response['reciters'])) {
      $reciters = $response['reciters'];
      $this->cache->set($cache_key, $reciters, time() + 86400);
      return $reciters;
    }

    // Return default chapter reciters if API fails.
    return $this->getDefaultChapterReciters();
  }

  /**
   * Get default reciters as fallback for verse-by-verse mode.
   */
  protected function getDefaultReciters(): array {
    return [
      ['id' => 7, 'reciter_name' => 'Mishari Rashid al-Afasy', 'style' => ''],
      ['id' => 1, 'reciter_name' => 'AbdulBaset AbdulSamad', 'style' => 'Mujawwad'],
      ['id' => 2, 'reciter_name' => 'AbdulBaset AbdulSamad', 'style' => 'Murattal'],
      ['id' => 3, 'reciter_name' => 'Abdur-Rahman as-Sudais', 'style' => ''],
      ['id' => 4, 'reciter_name' => 'Abu Bakr al-Shatri', 'style' => ''],
      ['id' => 5, 'reciter_name' => 'Hani ar-Rifai', 'style' => ''],
      ['id' => 6, 'reciter_name' => 'Mahmoud Khalil Al-Husary', 'style' => ''],
      ['id' => 8, 'reciter_name' => 'Mohamed Siddiq al-Minshawi', 'style' => 'Mujawwad'],
      ['id' => 9, 'reciter_name' => 'Mohamed Siddiq al-Minshawi', 'style' => 'Murattal'],
      ['id' => 10, 'reciter_name' => 'Saud ash-Shuraym', 'style' => ''],
      ['id' => 11, 'reciter_name' => 'Mohamed al-Tablawi', 'style' => ''],
      ['id' => 12, 'reciter_name' => 'Mahmoud Khalil Al-Husary', 'style' => 'Muallim'],
    ];
  }

  /**
   * Get default chapter reciters as fallback for gapless mode.
   */
  protected function getDefaultChapterReciters(): array {
    return [
      ['id' => 7, 'name' => 'Mishari Rashid al-Afasy'],
      ['id' => 1, 'name' => 'Abdul Basit Abdul Samad'],
      ['id' => 2, 'name' => 'Abdul Basit Abdul Samad'],
      ['id' => 3, 'name' => 'Abdur-Rahman as-Sudais'],
      ['id' => 4, 'name' => 'Abu Bakr al-Shatri'],
      ['id' => 5, 'name' => 'Hani ar-Rifai'],
      ['id' => 6, 'name' => 'Mahmoud Khalil Al-Husary'],
      ['id' => 9, 'name' => 'Muhammad Siddiq al-Minshawi'],
      ['id' => 10, 'name' => 'Saud ash-Shuraym'],
      ['id' => 12, 'name' => 'Mahmoud Khalil Al-Husary'],
      ['id' => 13, 'name' => 'Saad al-Ghamdi'],
      ['id' => 19, 'name' => 'Ahmed ibn Ali al-Ajmy'],
      ['id' => 158, 'name' => 'Abdullah Ali Jabir'],
      ['id' => 159, 'name' => 'Maher al-Muaiqly'],
      ['id' => 160, 'name' => 'Bandar Baleela'],
      ['id' => 161, 'name' => 'Khalifah Al Tunaiji'],
      ['id' => 168, 'name' => 'Muhammad Siddiq al-Minshawi (with kids)'],
      ['id' => 173, 'name' => 'Mishari Rashid al-Afasy Streaming'],
      ['id' => 174, 'name' => 'Yasser ad-Dussary'],
      ['id' => 175, 'name' => 'Abdullah Hamad Abu Sharida'],
    ];
  }

  /**
   * Get available translations.
   */
  public function getTranslations(string $language = 'en'): array {
    $cache_key = 'gablvm:translations:' . $language;

    if ($cached = $this->cache->get($cache_key)) {
      return $cached->data;
    }

    $response = $this->request('resources/translations', ['language' => $language]);

    if ($response && isset($response['translations'])) {
      $translations = $response['translations'];
      $this->cache->set($cache_key, $translations, time() + 86400);
      return $translations;
    }

    // Return default translations if API fails.
    return $this->getDefaultTranslations();
  }

  /**
   * Get default translations as fallback.
   */
  protected function getDefaultTranslations(): array {
    return [
      ['id' => 85, 'name' => 'M.A.S. Abdel Haleem', 'author_name' => 'Abdul Haleem', 'language_name' => 'english'],
      ['id' => 20, 'name' => 'Saheeh International', 'author_name' => 'Saheeh International', 'language_name' => 'english'],
      ['id' => 84, 'name' => 'T. Usmani', 'author_name' => 'Mufti Taqi Usmani', 'language_name' => 'english'],
      ['id' => 22, 'name' => 'A. Yusuf Ali', 'author_name' => 'Abdullah Yusuf Ali', 'language_name' => 'english'],
      ['id' => 19, 'name' => 'M. Pickthall', 'author_name' => 'Mohammed Marmaduke William Pickthall', 'language_name' => 'english'],
    ];
  }

  /**
   * Get available tafsirs (scholarly commentaries), filtered to English.
   */
  public function getTafsirResources(string $language = 'en'): array {
    $cache_key = 'gablvm:tafsirs:' . $language;

    if ($cached = $this->cache->get($cache_key)) {
      return $cached->data;
    }

    $response = $this->request('resources/tafsirs', ['language' => $language]);

    if ($response && isset($response['tafsirs'])) {
      $english = array_values(array_filter($response['tafsirs'], static function ($t) {
        $lang = strtolower($t['language_name'] ?? '');
        return $lang === 'english' || $lang === 'en';
      }));
      $this->cache->set($cache_key, $english, time() + 86400);
      return $english;
    }

    return $this->getDefaultTafsirs();
  }

  /**
   * Default tafsirs (fallback if Foundation API is unreachable).
   */
  protected function getDefaultTafsirs(): array {
    return [
      ['id' => 169, 'name' => 'Ibn Kathir (Abridged)', 'author_name' => 'Hafiz Ibn Kathir', 'language_name' => 'english'],
      ['id' => 168, 'name' => "Ma'arif al-Qur'an", 'author_name' => 'Mufti Muhammad Shafi', 'language_name' => 'english'],
      ['id' => 817, 'name' => 'Tazkirul Quran', 'author_name' => 'Maulana Wahid Uddin Khan', 'language_name' => 'english'],
    ];
  }

  /**
   * Get verses of a surah with tafsir text per verse.
   *
   * @param int $surah_number
   *   The surah (1-114).
   * @param int $tafsir_id
   *   The tafsir resource ID (see getTafsirResources()).
   * @param string $language
   *   Language code for verse metadata.
   *
   * @return array|null
   *   Raw API response with a 'verses' array, or NULL on failure.
   */
  public function getVersesWithTafsir(int $surah_number, int $tafsir_id, string $language = 'en'): ?array {
    $cache_key = "gablvm:verses_tafsir:{$surah_number}:{$tafsir_id}:{$language}";

    if ($cached = $this->cache->get($cache_key)) {
      return $cached->data;
    }

    $response = $this->request("verses/by_chapter/{$surah_number}", [
      'language' => $language,
      'tafsirs' => $tafsir_id,
      'fields' => 'text_uthmani',
      'per_page' => 300,
    ]);

    if ($response && isset($response['verses'])) {
      // Tafsir text is static per verse — cache for 7 days.
      $this->cache->set($cache_key, $response, time() + 604800);
      return $response;
    }

    return NULL;
  }

  /**
   * Get all verses of a chapter with Arabic text only (no translation/tafsir).
   *
   * Used to fill coverage gaps when a chosen tafsir doesn't cover every verse
   * — upstream returns only verses that have matching tafsir content, so we
   * fetch the full verse list separately and merge.
   */
  public function getVersesArabicOnly(int $surah_number, string $language = 'en'): ?array {
    $cache_key = "gablvm:verses_arabic:{$surah_number}:{$language}";

    if ($cached = $this->cache->get($cache_key)) {
      return $cached->data;
    }

    $response = $this->request("verses/by_chapter/{$surah_number}", [
      'language' => $language,
      'fields' => 'text_uthmani',
      'per_page' => 300,
    ]);

    if ($response && isset($response['verses'])) {
      $this->cache->set($cache_key, $response, time() + 604800);
      return $response;
    }

    return NULL;
  }

  /**
   * Get verse-by-verse audio files for a chapter.
   *
   * @param int $surah_number
   *   The surah/chapter number (1-114).
   * @param int $reciter_id
   *   The reciter ID.
   *
   * @return array
   *   Array of verse audio data with verse_key and url.
   */
  public function getVerseAudioFiles(int $surah_number, int $reciter_id = 7): array {
    $cache_key = "gablvm:verse_audio:{$reciter_id}:{$surah_number}";

    if ($cached = $this->cache->get($cache_key)) {
      return $cached->data;
    }

    $response = $this->request("recitations/{$reciter_id}/by_chapter/{$surah_number}", [
      'per_page' => 300,
    ]);

    if ($response && isset($response['audio_files'])) {
      $audio_files = $response['audio_files'];
      $this->cache->set($cache_key, $audio_files, time() + 86400);
      return $audio_files;
    }

    $this->logger->warning('No verse audio files returned for surah @surah, reciter @reciter', [
      '@surah' => $surah_number,
      '@reciter' => $reciter_id,
    ]);

    return [];
  }

  /**
   * Get verse timing segments for a chapter recitation.
   *
   * This returns timing data that maps each verse to its position
   * in the full chapter audio file, enabling verse synchronization
   * during gapless playback.
   *
   * @param int $surah_number
   *   The surah/chapter number (1-114).
   * @param int $reciter_id
   *   The reciter ID.
   *
   * @return array
   *   Array of verse timing data with verse_key, timestamp_from, timestamp_to.
   */
  public function getVerseTimingSegments(int $surah_number, int $reciter_id = 7): array {
    $cache_key = "gablvm:verse_timing:{$reciter_id}:{$surah_number}";

    if ($cached = $this->cache->get($cache_key)) {
      return $cached->data;
    }

    // Request audio files with segments parameter.
    $response = $this->request("recitations/{$reciter_id}/by_chapter/{$surah_number}", [
      'per_page' => 300,
      'fields' => 'segments',
    ]);

    $timing = [];

    if ($response && isset($response['audio_files'])) {
      // Calculate cumulative timing from verse audio durations.
      // Each audio_file may have segments data or we calculate from sequence.
      $current_time = 0;

      foreach ($response['audio_files'] as $audio) {
        $verse_key = $audio['verse_key'] ?? '';
        $segments = $audio['segments'] ?? [];

        // If segments data is available, use it.
        if (!empty($segments)) {
          // Segments format: [[word_start, word_end, timestamp_from_ms, timestamp_to_ms], ...]
          // Timestamps are relative to the individual verse audio file.
          // We accumulate them into chapter-level positions for gapless audio mapping.
          $last_segment = end($segments);
          $verse_duration = $last_segment[3] ?? 5000;

          $timing[] = [
            'verse_key' => $verse_key,
            'timestamp_from' => $current_time,
            'timestamp_to' => $current_time + $verse_duration,
          ];

          $current_time += $verse_duration;
        }
        else {
          // Fallback: estimate ~5 seconds per verse if no segment data.
          $duration = 5000;
          $timing[] = [
            'verse_key' => $verse_key,
            'timestamp_from' => $current_time,
            'timestamp_to' => $current_time + $duration,
          ];
          $current_time += $duration;
        }
      }
    }

    if (!empty($timing)) {
      $this->cache->set($cache_key, $timing, time() + 86400);
    }

    return $timing;
  }

  /**
   * Get audio file for entire Surah.
   */
  public function getSurahAudioUrl(int $surah_number, int $reciter_id = 7): ?string {
    $cache_key = "gablvm:surah_audio:{$reciter_id}:{$surah_number}";

    if ($cached = $this->cache->get($cache_key)) {
      return $cached->data;
    }

    $response = $this->request("chapter_recitations/{$reciter_id}/{$surah_number}");

    if ($response && isset($response['audio_file']['audio_url'])) {
      $audio_url = $response['audio_file']['audio_url'];
      $this->cache->set($cache_key, $audio_url, time() + 86400);
      return $audio_url;
    }

    return NULL;
  }

  /**
   * Get chapter audio files for a reciter.
   */
  public function getReciterAudioFiles(int $reciter_id): array {
    $cache_key = 'gablvm:reciter_audio:' . $reciter_id;

    if ($cached = $this->cache->get($cache_key)) {
      return $cached->data;
    }

    $response = $this->request("chapter_recitations/{$reciter_id}");

    if ($response && isset($response['audio_files'])) {
      $audio_files = $response['audio_files'];
      $this->cache->set($cache_key, $audio_files, time() + 86400);
      return $audio_files;
    }

    return [];
  }

  /**
   * Check if API credentials are configured.
   */
  public function hasCredentials(): bool {
    return !empty($this->getClientId()) && !empty($this->getClientSecret());
  }

  /**
   * Test the API connection.
   */
  public function testConnection(): array {
    if (!$this->hasCredentials()) {
      return [
        'success' => FALSE,
        'message' => 'API credentials not configured.',
      ];
    }

    $token = $this->getAccessToken();
    if (!$token) {
      return [
        'success' => FALSE,
        'message' => 'Failed to obtain access token. Check your Client ID and Secret.',
      ];
    }

    $chapters = $this->request('chapters');
    if ($chapters && isset($chapters['chapters'])) {
      return [
        'success' => TRUE,
        'message' => 'Successfully connected to Quran Foundation API.',
        'chapters_count' => count($chapters['chapters']),
      ];
    }

    return [
      'success' => FALSE,
      'message' => 'Connected but failed to fetch chapters.',
    ];
  }

}
