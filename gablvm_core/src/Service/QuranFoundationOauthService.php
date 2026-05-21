<?php

declare(strict_types=1);

namespace Drupal\gablvm_core\Service;

use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Logger\LoggerChannelFactoryInterface;
use Drupal\Core\Session\AccountProxyInterface;
use Drupal\user\UserDataInterface;
use GuzzleHttp\Client as GuzzleClient;
use GuzzleHttp\ClientInterface;
use GuzzleHttp\Exception\GuzzleException;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Middleware as GuzzleMiddleware;
use GuzzleHttp\Promise\Utils as GuzzlePromiseUtils;
use Psr\Http\Message\RequestInterface;
use Psr\Http\Message\ResponseInterface;
use Psr\Log\LoggerInterface;

/**
 * Handles the Quran Foundation OAuth2 authorization_code flow.
 *
 * Scope model (verified 2026-04-24):
 *   - `content` — machine-to-machine content API access
 *   - `user`    — all user-scoped APIs (bookmarks, collections, streaks, etc.)
 *   - `openid`  — OpenID Connect identity
 *
 * User-scoped endpoints reject client_credentials tokens with
 * "missing required headers"; a real user must authenticate at QF.
 *
 * Environment:
 *   - Pre-live endpoints and credentials are used while QF grants scopes.
 *   - Production is still content-scope only until QF allots production scopes.
 */
class QuranFoundationOauthService {

  public const AUTH_BASE_PRELIVE = 'https://prelive-oauth2.quran.foundation';
  public const AUTH_BASE_PROD = 'https://oauth2.quran.foundation';
  public const API_BASE_PRELIVE = 'https://apis-prelive.quran.foundation/content/api/v4';
  public const API_BASE_PROD = 'https://apis.quran.foundation/content/api/v4';

  // User API lives under /auth/v1/ on the same host (no /content/ prefix).
  public const USER_API_BASE_PRELIVE = 'https://apis-prelive.quran.foundation/auth/v1';
  public const USER_API_BASE_PROD = 'https://apis.quran.foundation/auth/v1';

  // Fine-grained scopes (per QF's user-apis-quickstart doc).
  // offline_access is required to receive a refresh_token.
  // `preference` was dropped 2026-04-27 — production didn't grant it and
  // we never call /auth/v1/preferences. Pre-live still accepts the scope
  // list without it; existing pre-live tokens stay valid until they expire.
  public const SCOPE_DEFAULT = 'openid offline_access user bookmark collection reading_session goal streak note activity_day';

  public const ENV_PREPRODUCTION = 'preproduction';
  public const ENV_PRODUCTION = 'production';

  public const USERDATA_MODULE = 'gablvm_core';
  public const USERDATA_ACCESS_TOKEN = 'qf_access_token';
  public const USERDATA_REFRESH_TOKEN = 'qf_refresh_token';
  public const USERDATA_EXPIRES_AT = 'qf_expires_at';
  public const USERDATA_SCOPE = 'qf_scope';
  public const USERDATA_CONNECTED_AT = 'qf_connected_at';

  /**
   * Per-bookmark side metadata: which reciter + mode was active when the
   * user saved the bookmark. QF's bookmark schema doesn't carry that, so we
   * keep a small lookup table in user.data keyed by "{surah}:{verse}". On
   * jump-to-verse, we restore the dropdown selection so the user lands back
   * in the same listening context.
   */
  public const USERDATA_BOOKMARK_META = 'qf_bookmark_meta';

  public const SESSION_STATE_KEY = 'gablvm_qf_oauth_state';
  public const SESSION_PKCE_KEY = 'gablvm_qf_oauth_pkce';
  public const SESSION_DESTINATION_KEY = 'gablvm_qf_oauth_destination';
  public const SESSION_INTENT_KEY = 'gablvm_qf_oauth_intent';

  // OAuth intent: are we connecting an existing Drupal user to QF, or are we
  // signing the visitor in (possibly creating a new Drupal user)?
  public const INTENT_CONNECT = 'connect';
  public const INTENT_LOGIN = 'login';

  // External-auth provider name used by drupal/externalauth to record the
  // Drupal-user-to-QF-user link.
  public const AUTH_PROVIDER = 'quran_foundation';

  protected ClientInterface $httpClient;
  protected ConfigFactoryInterface $configFactory;
  protected UserDataInterface $userData;
  protected AccountProxyInterface $currentUser;
  protected LoggerInterface $logger;

  public function __construct(
    ClientInterface $http_client,
    ConfigFactoryInterface $config_factory,
    UserDataInterface $user_data,
    AccountProxyInterface $current_user,
    LoggerChannelFactoryInterface $logger_factory,
  ) {
    $this->httpClient = $http_client;
    $this->configFactory = $config_factory;
    $this->userData = $user_data;
    $this->currentUser = $current_user;
    $this->logger = $logger_factory->get('gablvm_core');
  }

  /**
   * Whether we have credentials stored in Drupal config for the active env.
   */
  public function hasCredentials(): bool {
    [$cid, $sec] = $this->getCredentials();
    return $cid !== '' && $sec !== '';
  }

  /**
   * Active environment: 'production' or 'preproduction'.
   *
   * Driven by config key `gablvm_core.settings:quran_api.environment`.
   * Defaults to preproduction so a missing/empty config does not
   * accidentally hit production with the wrong credentials.
   */
  public function getEnvironment(): string {
    $env = (string) $this->configFactory
      ->get('gablvm_core.settings')
      ->get('quran_api.environment');
    return $env === self::ENV_PRODUCTION ? self::ENV_PRODUCTION : self::ENV_PREPRODUCTION;
  }

  /**
   * Get (client_id, client_secret) for the active environment.
   *
   * Production reads `quran_api.client_id`/`client_secret`. Preproduction
   * reads `quran_api.development_client_id`/`development_client_secret`.
   * Switching envs is a one-line config change; users must reconnect to
   * pick up the new credential's tokens.
   */
  public function getCredentials(): array {
    $config = $this->configFactory->get('gablvm_core.settings');
    if ($this->getEnvironment() === self::ENV_PRODUCTION) {
      return [
        (string) ($config->get('quran_api.client_id') ?? ''),
        (string) ($config->get('quran_api.client_secret') ?? ''),
      ];
    }
    return [
      (string) ($config->get('quran_api.development_client_id') ?? ''),
      (string) ($config->get('quran_api.development_client_secret') ?? ''),
    ];
  }

  /**
   * Base URL for the OAuth2 authorize/token endpoints.
   */
  public function getAuthBase(): string {
    return $this->getEnvironment() === self::ENV_PRODUCTION
      ? self::AUTH_BASE_PROD
      : self::AUTH_BASE_PRELIVE;
  }

  /**
   * Base URL for content API calls (machine-to-machine content).
   */
  public function getApiBase(): string {
    return $this->getEnvironment() === self::ENV_PRODUCTION
      ? self::API_BASE_PROD
      : self::API_BASE_PRELIVE;
  }

  /**
   * Base URL for user-scoped API calls (bookmarks, notes, streaks, …).
   */
  public function getUserApiBase(): string {
    return $this->getEnvironment() === self::ENV_PRODUCTION
      ? self::USER_API_BASE_PROD
      : self::USER_API_BASE_PRELIVE;
  }

  /**
   * The registered redirect URI (must match QF's whitelist exactly).
   */
  public function getRedirectUri(): string {
    return 'https://gablvm.org/oauth/quran-foundation/callback';
  }

  /**
   * Build the authorize URL, stashing state + PKCE in the Drupal session.
   *
   * @param string|null $destination
   *   Where to send the user after successful auth. Defaults to "/".
   * @param string $intent
   *   Either INTENT_CONNECT (link QF to current Drupal user) or INTENT_LOGIN
   *   (sign the visitor in, creating a new Drupal user if needed).
   */
  public function buildAuthorizeUrl(?string $destination = NULL, string $intent = self::INTENT_CONNECT): string {
    [$cid] = $this->getCredentials();

    // CSRF state + PKCE verifier.
    $state = bin2hex(random_bytes(16));
    $pkce_verifier = rtrim(strtr(base64_encode(random_bytes(32)), '+/', '-_'), '=');
    $pkce_challenge = rtrim(strtr(base64_encode(hash('sha256', $pkce_verifier, TRUE)), '+/', '-_'), '=');

    // Force the session writable + started so anonymous OAuth state persists
    // across the QF round-trip. Without this, Drupal's WriteSafeSessionHandler
    // discards the state we just set on the way out, and the callback sees a
    // fresh empty session → state_mismatch.
    \Drupal::service('session_handler.write_safe')->setSessionWritable(TRUE);
    $session = \Drupal::request()->getSession();
    if (!$session->isStarted()) {
      $session->start();
    }
    $session->set(self::SESSION_STATE_KEY, $state);
    $session->set(self::SESSION_PKCE_KEY, $pkce_verifier);
    $session->set(self::SESSION_INTENT_KEY, $intent === self::INTENT_LOGIN ? self::INTENT_LOGIN : self::INTENT_CONNECT);
    if ($destination !== NULL && str_starts_with($destination, '/') && !str_starts_with($destination, '//')) {
      $session->set(self::SESSION_DESTINATION_KEY, $destination);
    }

    $params = [
      'response_type' => 'code',
      'client_id' => $cid,
      'redirect_uri' => $this->getRedirectUri(),
      'scope' => self::SCOPE_DEFAULT,
      'state' => $state,
      'code_challenge' => $pkce_challenge,
      'code_challenge_method' => 'S256',
    ];

    return $this->getAuthBase() . '/oauth2/auth?' . http_build_query($params);
  }

  /**
   * Decode the payload of a JWT id_token without signature verification.
   *
   * The id_token came directly from a TLS-secured POST to QF's token endpoint
   * inside the same authorization-code redemption, so we trust the channel.
   * For the use-case here (look up the QF user identity to find/create a
   * Drupal user) signature verification is not adding security.
   *
   * @return array{sub?:string,email?:string,email_verified?:bool,name?:string}|null
   */
  public function decodeIdToken(?string $id_token): ?array {
    if (empty($id_token)) {
      return NULL;
    }
    $parts = explode('.', $id_token);
    if (count($parts) !== 3) {
      return NULL;
    }
    $payload_json = base64_decode(strtr($parts[1], '-_', '+/'), TRUE);
    if ($payload_json === FALSE) {
      return NULL;
    }
    $data = json_decode($payload_json, TRUE);
    return is_array($data) ? $data : NULL;
  }

  /**
   * Fetch the QF userinfo profile via OIDC userinfo endpoint.
   *
   * Fallback if id_token isn't returned in the token response. Uses the same
   * /auth/v1 base as the other user-scoped APIs.
   *
   * @return array{sub?:string,email?:string,email_verified?:bool,name?:string}|null
   */
  public function fetchUserInfo(string $access_token): ?array {
    $endpoints = [
      $this->getAuthBase() . '/oauth2/userinfo',
      $this->getUserApiBase() . '/me',
    ];
    foreach ($endpoints as $url) {
      try {
        // Userinfo endpoints expect a real user access_token. Do not send
        // the x-auth-type=client_credentials header used on the
        // token-exchange paths; if QF validates it, the userinfo call
        // would silently fail.
        $res = $this->httpClient->request('GET', $url, [
          'headers' => [
            'Authorization' => 'Bearer ' . $access_token,
            'Accept' => 'application/json',
          ],
          'timeout' => 10,
          'http_errors' => FALSE,
        ]);
        $status = $res->getStatusCode();
        if ($status === 200) {
          $data = json_decode((string) $res->getBody(), TRUE);
          if (is_array($data)) {
            return $data;
          }
        }
      }
      catch (GuzzleException $e) {
        // Try the next endpoint.
      }
    }
    return NULL;
  }

  /**
   * Get the active intent ('connect' or 'login') from the OAuth session.
   * Defaults to 'connect' so that legacy callbacks are unchanged.
   */
  public function getStoredIntent(): string {
    $session = \Drupal::request()->getSession();
    $intent = $session->get(self::SESSION_INTENT_KEY);
    return $intent === self::INTENT_LOGIN ? self::INTENT_LOGIN : self::INTENT_CONNECT;
  }

  /**
   * Exchange an authorization code for access + refresh tokens.
   *
   * @return array{access_token:string,refresh_token?:string,expires_in:int,scope?:string}|null
   *   Decoded token response, or NULL on failure. The error is logged.
   */
  public function exchangeCodeForTokens(string $code, string $pkce_verifier): ?array {
    [$cid, $sec] = $this->getCredentials();
    try {
      $res = $this->httpClient->request('POST', $this->getAuthBase() . '/oauth2/token', [
        'auth' => [$cid, $sec],
        'headers' => [
          'x-auth-type' => 'client_credentials',
          'Accept' => 'application/json',
        ],
        'form_params' => [
          'grant_type' => 'authorization_code',
          'code' => $code,
          'redirect_uri' => $this->getRedirectUri(),
          'code_verifier' => $pkce_verifier,
        ],
        'timeout' => 10,
        'http_errors' => FALSE,
      ]);
      $status = $res->getStatusCode();
      $body = (string) $res->getBody();
      $data = json_decode($body, TRUE);
      if ($status !== 200 || !is_array($data) || empty($data['access_token'])) {
        $this->logger->error('QF token exchange failed: HTTP @s body @b', [
          '@s' => $status,
          '@b' => substr($body, 0, 500),
        ]);
        return NULL;
      }
      return $data;
    }
    catch (GuzzleException $e) {
      $this->logger->error('QF token exchange exception: @m', ['@m' => $e->getMessage()]);
      return NULL;
    }
  }

  /**
   * Refresh an access token using its refresh_token.
   */
  public function refreshToken(string $refresh_token): ?array {
    [$cid, $sec] = $this->getCredentials();
    try {
      $res = $this->httpClient->request('POST', $this->getAuthBase() . '/oauth2/token', [
        'auth' => [$cid, $sec],
        'headers' => [
          'x-auth-type' => 'client_credentials',
          'Accept' => 'application/json',
        ],
        'form_params' => [
          'grant_type' => 'refresh_token',
          'refresh_token' => $refresh_token,
        ],
        'timeout' => 10,
        'http_errors' => FALSE,
      ]);
      $status = $res->getStatusCode();
      $body = (string) $res->getBody();
      $data = json_decode($body, TRUE);
      if ($status !== 200 || !is_array($data) || empty($data['access_token'])) {
        $this->logger->warning('QF refresh failed: HTTP @s body @b', [
          '@s' => $status,
          '@b' => substr($body, 0, 300),
        ]);
        return NULL;
      }
      return $data;
    }
    catch (GuzzleException $e) {
      $this->logger->warning('QF refresh exception: @m', ['@m' => $e->getMessage()]);
      return NULL;
    }
  }

  /**
   * Persist a token response against a Drupal user.
   */
  public function storeTokensForUser(int $uid, array $tokens): void {
    $now = \Drupal::time()->getRequestTime();
    $expires_at = $now + (int) ($tokens['expires_in'] ?? 3600);
    $this->userData->set(self::USERDATA_MODULE, $uid, self::USERDATA_ACCESS_TOKEN, (string) $tokens['access_token']);
    if (!empty($tokens['refresh_token'])) {
      $this->userData->set(self::USERDATA_MODULE, $uid, self::USERDATA_REFRESH_TOKEN, (string) $tokens['refresh_token']);
    }
    $this->userData->set(self::USERDATA_MODULE, $uid, self::USERDATA_EXPIRES_AT, $expires_at);
    if (!empty($tokens['scope'])) {
      $this->userData->set(self::USERDATA_MODULE, $uid, self::USERDATA_SCOPE, (string) $tokens['scope']);
    }
    if (!$this->userData->get(self::USERDATA_MODULE, $uid, self::USERDATA_CONNECTED_AT)) {
      $this->userData->set(self::USERDATA_MODULE, $uid, self::USERDATA_CONNECTED_AT, $now);
    }
  }

  /**
   * Whether a Drupal user has connected their QF account.
   */
  public function isConnected(int $uid): bool {
    return !empty($this->userData->get(self::USERDATA_MODULE, $uid, self::USERDATA_ACCESS_TOKEN));
  }

  /**
   * Return a valid access token for a user, refreshing if needed.
   */
  public function getValidAccessToken(int $uid): ?string {
    $access = $this->userData->get(self::USERDATA_MODULE, $uid, self::USERDATA_ACCESS_TOKEN);
    if (!$access) {
      return NULL;
    }
    $expires_at = (int) ($this->userData->get(self::USERDATA_MODULE, $uid, self::USERDATA_EXPIRES_AT) ?? 0);
    $now = \Drupal::time()->getRequestTime();
    // 60-second safety buffer.
    if ($expires_at > $now + 60) {
      return (string) $access;
    }
    $refresh = $this->userData->get(self::USERDATA_MODULE, $uid, self::USERDATA_REFRESH_TOKEN);
    if (!$refresh) {
      return NULL;
    }
    $refreshed = $this->refreshToken((string) $refresh);
    if (!$refreshed) {
      return NULL;
    }
    $this->storeTokensForUser($uid, $refreshed);
    return (string) $refreshed['access_token'];
  }

  /**
   * Clear stored tokens for a user (local disconnect).
   */
  public function disconnectUser(int $uid): void {
    // Clear OAuth state only. Do NOT clear USERDATA_BOOKMARK_META — that
    // map is local-only listening context (reciter + mode keyed by
    // surah:verse) and should survive disconnect/reconnect cycles, since
    // the user's bookmarks themselves persist on Quran.com and will return
    // when they reconnect to the same account. Wiping the meta on
    // disconnect (the prior behavior) caused jump-to-verse URLs to lose
    // their &reciter= and &english= params, leaving the reciter dropdown
    // unselected and the auto-load gated off.
    foreach ([
      self::USERDATA_ACCESS_TOKEN,
      self::USERDATA_REFRESH_TOKEN,
      self::USERDATA_EXPIRES_AT,
      self::USERDATA_SCOPE,
      self::USERDATA_CONNECTED_AT,
    ] as $key) {
      $this->userData->delete(self::USERDATA_MODULE, $uid, $key);
    }
  }

  /**
   * Call an authenticated user-scoped QF API endpoint.
   *
   * @param string $path
   *   Path relative to the API base, e.g. "bookmarks".
   * @param string $method
   *   HTTP method.
   * @param array $options
   *   Guzzle request options (json, form_params, query, etc.).
   *
   * @return array|null
   *   Decoded JSON response, or NULL on failure.
   */
  public function callUserApi(string $path, string $method = 'GET', array $options = []): ?array {
    $uid = (int) $this->currentUser->id();
    return $this->callUserApiForUid($uid, $path, $method, $options);
  }

  /**
   * Like callUserApi() but for an explicit uid.
   *
   * @return array|null
   *   Decoded JSON body on success. NULL on transport failure. On HTTP
   *   error responses, the parsed error body is returned with an extra
   *   '_http_status' key so callers can distinguish "no response" from
   *   "QF returned 4xx".
   */
  public function callUserApiForUid(int $uid, string $path, string $method = 'GET', array $options = []): ?array {
    if ($uid < 1) {
      return NULL;
    }
    $token = $this->getValidAccessToken($uid);
    if (!$token) {
      return NULL;
    }
    [$cid] = $this->getCredentials();

    $options['headers'] = ($options['headers'] ?? []) + [
      'x-auth-token' => $token,
      'x-client-id' => $cid,
      'Accept' => 'application/json',
    ];
    $options['timeout'] = $options['timeout'] ?? 10;
    $options['http_errors'] = FALSE;

    try {
      $res = $this->httpClient->request($method, $this->getUserApiBase() . '/' . ltrim($path, '/'), $options);
      $status = $res->getStatusCode();
      $body = (string) $res->getBody();
      $data = json_decode($body, TRUE);
      if ($status >= 400) {
        $this->logger->warning('QF user API @m @p returned HTTP @s: @b', [
          '@m' => $method,
          '@p' => $path,
          '@s' => $status,
          '@b' => substr($body, 0, 300),
        ]);
        if (is_array($data)) {
          $data['_http_status'] = $status;
          return $data;
        }
        return ['_http_status' => $status];
      }
      return is_array($data) ? $data : NULL;
    }
    catch (GuzzleException $e) {
      $this->logger->warning('QF user API @m @p exception: @e', [
        '@m' => $method,
        '@p' => $path,
        '@e' => $e->getMessage(),
      ]);
      return NULL;
    }
  }

  /**
   * Run a batch of QF user API calls concurrently, with per-call retry on
   * 5xx and transport errors (exponential backoff with jitter), and no
   * retry on 4xx — matching the resilience pattern Quran Foundation
   * recommended after the May 7-8 2026 Neon DB outage.
   *
   * Replaces a sequential chain of callUserApiForUid() calls. The
   * /quran/my-account dashboard previously made six per-user API calls
   * back-to-back. Worst case render was 60s if QF was slow on every call.
   * This method fires all six as concurrent Guzzle promises and settles
   * them together, capping page render at the slowest single call (~10s
   * with timeout) plus up to two retry cycles for 5xx.
   *
   * @param int $uid
   *   Drupal user id whose stored access_token authenticates the requests.
   * @param array<string, array{path:string,method?:string,options?:array}> $batch
   *   Map of caller-provided keys to request specs. Each spec has the same
   *   shape as callUserApiForUid()'s args.
   *
   * @return array<string, ?array>
   *   Map of the same keys to decoded JSON bodies (or NULL on transport
   *   failure, or array with `_http_status` on HTTP error).
   */
  public function callUserApiBatchForUid(int $uid, array $batch): array {
    if ($uid < 1 || empty($batch)) {
      return array_fill_keys(array_keys($batch), NULL);
    }
    $token = $this->getValidAccessToken($uid);
    if (!$token) {
      return array_fill_keys(array_keys($batch), NULL);
    }
    [$cid] = $this->getCredentials();

    // Build a dedicated client with Guzzle's retry middleware. Retries on
    // 5xx and transport errors only; 4xx responses pass through unchanged
    // (per Basit's recommendation 2026-05-09: do not retry 4xx).
    $logger = $this->logger;
    $handler = HandlerStack::create();
    $handler->push(GuzzleMiddleware::retry(
      function (
        int $retries,
        RequestInterface $request,
        ?ResponseInterface $response = NULL,
        ?\Throwable $reason = NULL
      ) use ($logger): bool {
        if ($retries >= 2) {
          return FALSE;
        }
        if ($reason !== NULL) {
          $logger->warning('QF batch @p transport error (retry @r): @e', [
            '@p' => (string) $request->getUri()->getPath(),
            '@r' => $retries + 1,
            '@e' => $reason->getMessage(),
          ]);
          return TRUE;
        }
        if ($response && $response->getStatusCode() >= 500) {
          $logger->warning('QF batch @p HTTP @s (retry @r)', [
            '@p' => (string) $request->getUri()->getPath(),
            '@s' => $response->getStatusCode(),
            '@r' => $retries + 1,
          ]);
          return TRUE;
        }
        return FALSE;
      },
      function (int $retries): int {
        // Exponential backoff with jitter, in milliseconds.
        // retry 1: 500-750ms; retry 2: 1000-1250ms.
        return (int) ((2 ** ($retries - 1)) * 500) + random_int(0, 250);
      }
    ));
    $client = new GuzzleClient(['handler' => $handler]);

    $promises = [];
    foreach ($batch as $key => $req) {
      $path = (string) ($req['path'] ?? '');
      $method = (string) ($req['method'] ?? 'GET');
      $options = (array) ($req['options'] ?? []);
      $options['headers'] = ($options['headers'] ?? []) + [
        'x-auth-token' => $token,
        'x-client-id' => $cid,
        'Accept' => 'application/json',
      ];
      $options['timeout'] = $options['timeout'] ?? 10;
      $options['http_errors'] = FALSE;
      $url = $this->getUserApiBase() . '/' . ltrim($path, '/');
      $promises[$key] = $client->requestAsync($method, $url, $options);
    }

    $settled = GuzzlePromiseUtils::settle($promises)->wait();

    $results = [];
    foreach ($settled as $key => $result) {
      if ($result['state'] === 'fulfilled') {
        /** @var ResponseInterface $res */
        $res = $result['value'];
        $status = $res->getStatusCode();
        $body = (string) $res->getBody();
        $data = json_decode($body, TRUE);
        if ($status >= 400) {
          $this->logger->warning('QF batch @k returned HTTP @s after retries: @b', [
            '@k' => $key,
            '@s' => $status,
            '@b' => substr($body, 0, 300),
          ]);
          if (is_array($data)) {
            $data['_http_status'] = $status;
            $results[$key] = $data;
          }
          else {
            $results[$key] = ['_http_status' => $status];
          }
        }
        else {
          $results[$key] = is_array($data) ? $data : NULL;
        }
      }
      else {
        // Rejected after all retries exhausted.
        $reason = $result['reason'] ?? NULL;
        $this->logger->warning('QF batch @k failed after retries: @e', [
          '@k' => $key,
          '@e' => $reason instanceof \Throwable ? $reason->getMessage() : 'unknown',
        ]);
        $results[$key] = NULL;
      }
    }

    return $results;
  }

  /**
   * Add a verse bookmark to the user's default ("Favorites") collection.
   *
   * Verified path 2026-04-26: POST /auth/v1/collections/__default__/bookmarks
   * with body {mushafId,key,verseNumber,type:'ayah'}.
   *
   * If $reciter / $mode are supplied, we save them to user.data keyed by
   * "{surah}:{verse}" so the jump-to-verse link can restore the same
   * listening context (reciter dropdown + verse-by-verse vs gapless mode).
   */
  public function addBookmark(int $uid, int $surah, int $verse, ?int $reciter = NULL, ?string $mode = NULL): ?array {
    $resp = $this->callUserApiForUid($uid, 'collections/__default__/bookmarks', 'POST', [
      'json' => [
        'mushafId' => 1,
        'key' => $surah,
        'verseNumber' => $verse,
        'type' => 'ayah',
      ],
    ]);
    if ($reciter && is_array($resp) && !empty($resp['success'])) {
      $this->saveBookmarkMeta($uid, $surah, $verse, $reciter, $mode);
    }
    return $resp;
  }

  /**
   * Save per-bookmark side metadata for later jump-to-verse restoration.
   */
  public function saveBookmarkMeta(int $uid, int $surah, int $verse, int $reciter, ?string $mode = NULL): void {
    $meta = $this->getBookmarkMeta($uid);
    $meta[$surah . ':' . $verse] = [
      'reciter' => $reciter,
      'mode' => ($mode === 'verse_by_verse' || $mode === 'chapter') ? $mode : NULL,
    ];
    $this->userData->set(self::USERDATA_MODULE, $uid, self::USERDATA_BOOKMARK_META, json_encode($meta));
  }

  /**
   * Get the bookmark metadata map for a user.
   *
   * @return array<string, array{reciter:int, mode:?string}>
   *   Keyed by "{surah}:{verse}".
   */
  public function getBookmarkMeta(int $uid): array {
    $raw = $this->userData->get(self::USERDATA_MODULE, $uid, self::USERDATA_BOOKMARK_META);
    if (!$raw) {
      return [];
    }
    $decoded = json_decode((string) $raw, TRUE);
    return is_array($decoded) ? $decoded : [];
  }

  /**
   * Remove a bookmark from the user's default collection.
   *
   * Verified path 2026-04-26: DELETE /auth/v1/collections/__default__/bookmarks/{id}.
   * (DELETE /auth/v1/bookmarks/{id} only flips isReading=false, doesn't remove.)
   */
  public function removeBookmark(int $uid, string $bookmark_id): ?array {
    $bookmark_id = preg_replace('/[^a-z0-9-]/i', '', $bookmark_id);
    if ($bookmark_id === '') {
      return NULL;
    }
    return $this->callUserApiForUid($uid, 'collections/__default__/bookmarks/' . $bookmark_id, 'DELETE');
  }

  /**
   * List the user's bookmarks (cursor-paginated, mushafId required).
   */
  public function listBookmarks(int $uid, int $first = 20, ?string $after = NULL): ?array {
    $query = ['mushafId' => 1, 'first' => max(1, min(20, $first))];
    if ($after) {
      $query['after'] = $after;
    }
    return $this->callUserApiForUid($uid, 'bookmarks', 'GET', ['query' => $query]);
  }

  /**
   * Log a reading session at a given surah/verse position.
   *
   * Verified 2026-04-26: POST /auth/v1/reading-sessions with body
   * {chapterNumber,verseNumber}. QF dedupes within ~20 minutes per the docs.
   * This populates the "Continue reading" panel but does NOT count toward
   * the streak engine — for that, also call addActivityDay() below.
   */
  public function addReadingSession(int $uid, int $surah, int $verse): ?array {
    return $this->callUserApiForUid($uid, 'reading-sessions', 'POST', [
      'json' => ['chapterNumber' => $surah, 'verseNumber' => $verse],
    ]);
  }

  /**
   * Log a daily activity-day record so QF aggregates it into the streak
   * engine and the "Today's reading" panel.
   *
   * Schema reverse-engineered 2026-05-20 via 422-driven discovery:
   *   POST /auth/v1/activity-days  body {
   *     type: "QURAN",                      // enum: QURAN|LESSON|QURAN_READING_PROGRAM
   *     seconds: int,                        // total seconds read this submission
   *     ranges: ["S:V-S:V", ...],            // verse ranges, regex /^(\d+:\d+-\d+:\d+(?:,\d+:\d+-\d+:\d+)*)$/
   *     mushafId: int                        // 1 = default Hafs
   *   }
   * QF computes pagesRead and versesRead server-side from the range.
   * Response shape: {success: true, data: []}.
   * Failure shape: HTTP 422 with field-specific error in details.error.message.
   *
   * Note: QF appears to aggregate multiple POSTs across the day (verified by
   * the documented streak/activity-day model). Each POST adds to the day's
   * running totals — it does not overwrite.
   */
  public function addActivityDay(int $uid, int $surah_from, int $verse_from, int $surah_to, int $verse_to, int $seconds, int $mushaf_id = 1): ?array {
    // Defensive clamps. QF will 422 on bad ranges, but failing fast in PHP
    // keeps the watchdog log cleaner during a misbehaving JS dispatch.
    $surah_from = max(1, min(114, $surah_from));
    $surah_to = max($surah_from, min(114, $surah_to));
    $verse_from = max(1, $verse_from);
    $verse_to = max($verse_from, $verse_to);
    $seconds = max(1, $seconds);
    $mushaf_id = max(1, $mushaf_id);
    $range = "{$surah_from}:{$verse_from}-{$surah_to}:{$verse_to}";
    return $this->callUserApiForUid($uid, 'activity-days', 'POST', [
      'json' => [
        'type' => 'QURAN',
        'seconds' => $seconds,
        'ranges' => [$range],
        'mushafId' => $mushaf_id,
      ],
    ]);
  }

  /**
   * Get recent reading-session entries.
   */
  public function getRecentReadingSessions(int $uid, int $first = 5): ?array {
    return $this->callUserApiForUid($uid, 'reading-sessions', 'GET', [
      'query' => ['first' => max(1, min(20, $first))],
    ]);
  }

  /**
   * Get the user's streak history (latest first).
   */
  public function getStreaks(int $uid, int $first = 5): ?array {
    return $this->callUserApiForUid($uid, 'streaks', 'GET', [
      'query' => ['first' => max(1, min(20, $first))],
    ]);
  }

  /**
   * Add a note attached to a verse range like "2:255-2:255".
   *
   * Requires the `note` scope; if the user's stored token predates the scope
   * expansion, QF returns 403 insufficient_scope and they must reconnect.
   *
   * QF added a required `saveToQR` field (Quran Reflect cross-post toggle)
   * to POST /notes sometime before 2026-05-04. Without it, QF returns 422
   * ValidationError "saveToQR is required". We send FALSE to keep the note
   * private to the user's Quran.com account. If a "share to Quran Reflect"
   * UI is ever offered, flip this per request.
   */
  public function addNote(int $uid, string $body, array $ranges): ?array {
    return $this->callUserApiForUid($uid, 'notes', 'POST', [
      'json' => [
        'body' => $body,
        'ranges' => array_values($ranges),
        'saveToQR' => FALSE,
      ],
    ]);
  }

  /**
   * List the user's notes.
   *
   * Unlike /bookmarks and /streaks, /notes rejects `first`/`last` ("not
   * allowed", 422). It returns the full list with pagination metadata that
   * indicates no further pages by default.
   */
  public function listNotes(int $uid): ?array {
    return $this->callUserApiForUid($uid, 'notes', 'GET');
  }

  /**
   * Remove a note by its id.
   *
   * QF endpoint: DELETE /notes/{id}. Note ids are alphanumeric (24 chars in
   * observed responses, e.g. "u8qr1vkoi1bus3l5goy2zva0").
   */
  public function removeNote(int $uid, string $note_id): ?array {
    $note_id = preg_replace('/[^a-z0-9]/i', '', $note_id);
    if ($note_id === '') {
      return NULL;
    }
    return $this->callUserApiForUid($uid, 'notes/' . $note_id, 'DELETE');
  }

  /**
   * Get today's goal plan for a user.
   *
   * Confirmed endpoint per Quran Foundation docs (Basit clarified 2026-05-05
   * that earlier paths /goals/today, /today-goal-plan, /activity-days/today
   * do not exist — QF returns 403 for non-existent paths, not 404):
   *
   *   GET /auth/v1/goals/get-todays-plan?type=<TYPE>&mushafId=<MUSHAF_ID>
   *
   * Required query: `type` (one of QURAN_TIME, QURAN_PAGES, QURAN_RANGE,
   * COURSE, QURAN_READING_PROGRAM, RAMADAN_CHALLENGE) and `mushafId`.
   *
   * Response shape: { success: true, data: { hasGoal: false } } if user has
   * no active goal of this type. When a goal IS active, data also includes
   * id, date, progress, ranges, pagesRead, secondsRead, versesRead,
   * dailyTargetPages, dailyTargetSeconds, etc.
   *
   * Default type is QURAN_PAGES + mushafId 1 (Madani 16-line) which is the
   * common case for daily reading goals.
   */
  public function getTodaysGoalPlan(int $uid, string $type = 'QURAN_PAGES', int $mushaf_id = 1): ?array {
    return $this->callUserApiForUid($uid, 'goals/get-todays-plan', 'GET', [
      'query' => ['type' => $type, 'mushafId' => $mushaf_id],
    ]);
  }

  /**
   * Get activity-day records for a date range.
   *
   * Confirmed endpoint: GET /auth/v1/activity-days?from=YYYY-MM-DD&to=YYYY-MM-DD.
   * The /today suffix path does NOT exist; filter the list by today's date.
   *
   * Response shape: { success, data: [{id, date, progress, type, ranges,
   * pagesRead, secondsRead, versesRead, manuallyAddedSeconds,
   * dailyTargetPages, dailyTargetSeconds, ..., mushafId}], pagination }.
   */
  public function getActivityDaysRange(int $uid, string $from, string $to, int $first = 10): ?array {
    return $this->callUserApiForUid($uid, 'activity-days', 'GET', [
      'query' => ['from' => $from, 'to' => $to, 'first' => $first],
    ]);
  }

  /**
   * Convenience: get today's activity-day record (or empty array if none).
   */
  public function getTodaysActivityDay(int $uid): ?array {
    $today = gmdate('Y-m-d');
    return $this->getActivityDaysRange($uid, $today, $today, 1);
  }

}
