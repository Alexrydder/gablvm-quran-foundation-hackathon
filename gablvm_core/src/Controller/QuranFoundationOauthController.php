<?php

declare(strict_types=1);

namespace Drupal\gablvm_core\Controller;

use Drupal\Component\Utility\Html;
use Drupal\Core\Access\AccessResult;
use Drupal\Core\Cache\CacheableJsonResponse;
use Drupal\Core\Controller\ControllerBase;
use Drupal\Core\Routing\LocalRedirectResponse;
use Drupal\Core\Routing\TrustedRedirectResponse;
use Drupal\Core\Session\AccountInterface;
use Drupal\Core\Url;
use Drupal\externalauth\ExternalAuthInterface;
use Drupal\gablvm_core\Service\QuranApiService;
use Drupal\gablvm_core\Service\QuranFoundationOauthService;
use Drupal\user\UserInterface;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;

/**
 * Quran Foundation OAuth2 authorization_code flow.
 *
 * Routes:
 *   /oauth/quran-foundation/login     → redirect user to QF authorize URL
 *   /oauth/quran-foundation/callback  → QF redirects back with ?code&state
 *   /oauth/quran-foundation/disconnect → clear stored tokens for the user
 *   /quran/my-account                  → connection status + try the API
 *
 * Scope model: QF uses only three OAuth scopes total — `content`, `user`,
 * `openid`. We request `user openid`; all user features (bookmarks, notes,
 * collections, streaks, goals) are sub-resources reachable with the `user`
 * scope. User data endpoints require the authorization_code flow (not
 * client_credentials) because they need a real user context.
 */
class QuranFoundationOauthController extends ControllerBase {

  protected QuranFoundationOauthService $qf;
  protected QuranApiService $quranApi;
  protected ExternalAuthInterface $externalAuth;

  public function __construct(QuranFoundationOauthService $qf, QuranApiService $quran_api, ExternalAuthInterface $external_auth) {
    $this->qf = $qf;
    $this->quranApi = $quran_api;
    $this->externalAuth = $external_auth;
  }

  public static function create(ContainerInterface $container): self {
    return new self(
      $container->get('gablvm_core.qf_oauth'),
      $container->get('gablvm_core.quran_api'),
      $container->get('externalauth.externalauth'),
    );
  }

  /**
   * Sign in with Quran.com — anonymous-accessible entry point.
   *
   * Sets the OAuth intent to LOGIN so the callback knows to find/create a
   * Drupal user from the QF identity instead of linking to current_user.
   * Authenticated visitors who land here are bounced to /quran/my-account
   * since they're already signed in.
   *
   * Drupal's session middleware makes anonymous sessions read-only by default
   * (WriteSafeSessionHandler::setSessionWritable false until an authenticated
   * user appears). That breaks OAuth state persistence: we set the CSRF state
   * in the session here, redirect to QF, QF redirects back to /callback, and
   * Drupal hands us a fresh empty session — state_mismatch on the callback.
   * Force the session writable + started before storing state so it persists.
   */
  public function signIn(Request $request): RedirectResponse {
    if ($this->currentUser()->isAuthenticated()) {
      return new RedirectResponse(Url::fromRoute('gablvm_core.qf_account')->toString());
    }
    \Drupal::service('session_handler.write_safe')->setSessionWritable(TRUE);
    $session = $request->getSession();
    if (!$session->isStarted()) {
      $session->start();
    }

    $destination = $request->query->get('destination');
    $request->query->remove('destination');
    $url = $this->qf->buildAuthorizeUrl(
      is_string($destination) ? $destination : NULL,
      QuranFoundationOauthService::INTENT_LOGIN,
    );
    $this->getLogger('gablvm_core')->info('QF OAuth sign-in initiated (anonymous), session id=@sid', [
      '@sid' => substr($session->getId(), 0, 8),
    ]);
    return new TrustedRedirectResponse($url);
  }

  /**
   * Only authenticated users can initiate/disconnect/view the account page.
   */
  public function authenticatedAccess(AccountInterface $account): AccessResult {
    return AccessResult::allowedIf($account->isAuthenticated());
  }

  /**
   * Kick off the OAuth2 flow: build authorize URL + redirect.
   *
   * Anonymous visitors are redirected to /user/login first with a destination
   * chain that brings them back here once they have a Drupal session, since
   * the OAuth tokens we get back are stored against the Drupal user.
   */
  public function login(Request $request): RedirectResponse {
    if (!$this->currentUser()->isAuthenticated()) {
      // Bounce anonymous visitors to /user/login first.
      //
      // Important: Drupal's RedirectResponseSubscriber inspects the inbound
      // request's ?destination= query and overrides the target URL of any
      // RedirectResponse (even Secured/Local subclasses — it always tries
      // setTargetUrl first). Our inbound URL is e.g.
      // /oauth/quran-foundation/login?destination=/quran-listen, and
      // without intervention the subscriber would rewrite our redirect
      // from /user/login back to /quran-listen — skipping the sign-in page
      // entirely. We must remove the query parameter before returning.
      $destination = $request->query->get('destination');
      $request->query->remove('destination');

      $return_to = '/oauth/quran-foundation/login';
      if (is_string($destination) && str_starts_with($destination, '/') && !str_starts_with($destination, '//')) {
        $return_to .= '?destination=' . rawurlencode($destination);
      }
      $login_url = '/user/login?destination=' . rawurlencode($return_to);
      return new RedirectResponse($login_url);
    }

    if (!$this->qf->hasCredentials()) {
      $this->messenger()->addError($this->t('Quran Foundation sign-in is not configured yet. Please contact the site administrator.'));
      return new RedirectResponse(Url::fromRoute('<front>')->toString());
    }

    // Snapshot the destination, then strip it from the request. Same reason
    // as the anonymous branch above: Drupal's RedirectResponseSubscriber
    // overrides ANY RedirectResponse target (including TrustedRedirect-
    // Response) when ?destination= is on the inbound request. Without this
    // remove(), the user gets bounced straight to /quran-listen instead of
    // to oauth2.quran.foundation/oauth2/auth — skipping the OAuth handshake
    // entirely. The destination is preserved in $destination and stashed
    // into the OAuth session inside buildAuthorizeUrl() for the callback to
    // honor after QF returns.
    $destination = $request->query->get('destination');
    $request->query->remove('destination');

    $url = $this->qf->buildAuthorizeUrl(is_string($destination) ? $destination : NULL);
    $this->getLogger('gablvm_core')->info('QF OAuth login initiated for uid @uid', [
      '@uid' => $this->currentUser()->id(),
    ]);
    return new TrustedRedirectResponse($url);
  }

  /**
   * OAuth2 redirect URI. Exchange the returned code for tokens and store them.
   */
  public function callback(Request $request) {
    $code = $request->query->get('code');
    $state = $request->query->get('state');
    $error = $request->query->get('error');
    $error_description = $request->query->get('error_description');

    // Branch 1: QF returned an error.
    if ($error) {
      return $this->renderPage(
        $this->t('Sign-in could not be completed'),
        $this->t('Quran Foundation returned an error during sign-in. You can close this tab and return to the site.'),
        (string) $error . ($error_description ? ': ' . (string) $error_description : ''),
      );
    }

    // Branch 2: someone hit the URL with no parameters.
    if (!$code) {
      return $this->renderPage(
        $this->t('This is the Quran Foundation sign-in callback'),
        $this->t('This page is the OAuth2 redirect URI for the Quran Foundation sign-in flow. You should not normally navigate here directly. If you reached this page by mistake, return to the home page.'),
        '',
      );
    }

    // Branch 3: real callback — validate state + exchange code.
    $session = $request->getSession();
    $expected_state = $session->get(QuranFoundationOauthService::SESSION_STATE_KEY);
    $pkce_verifier = $session->get(QuranFoundationOauthService::SESSION_PKCE_KEY);
    $destination = $session->get(QuranFoundationOauthService::SESSION_DESTINATION_KEY);
    $intent = $this->qf->getStoredIntent();
    $session->remove(QuranFoundationOauthService::SESSION_STATE_KEY);
    $session->remove(QuranFoundationOauthService::SESSION_PKCE_KEY);
    $session->remove(QuranFoundationOauthService::SESSION_DESTINATION_KEY);
    $session->remove(QuranFoundationOauthService::SESSION_INTENT_KEY);

    if (!$expected_state || !hash_equals((string) $expected_state, (string) $state)) {
      return $this->renderPage(
        $this->t('Sign-in could not be completed'),
        $this->t('The sign-in session could not be verified. Please try again.'),
        'state_mismatch',
      );
    }

    // Connect-intent (existing v3.0 behavior) requires an authenticated Drupal
    // user. Login-intent does not — the whole point of login intent is to
    // create or restore a Drupal session from the QF identity.
    if ($intent === QuranFoundationOauthService::INTENT_CONNECT && !$this->currentUser()->isAuthenticated()) {
      return $this->renderPage(
        $this->t('Please sign in first'),
        $this->t('You need to be signed in to gablvm.org before connecting your Quran.com account. Please sign in, then try again.'),
        '',
      );
    }

    $tokens = $this->qf->exchangeCodeForTokens((string) $code, (string) $pkce_verifier);
    if (!$tokens) {
      return $this->renderPage(
        $this->t('Sign-in could not be completed'),
        $this->t('We could not complete the sign-in with Quran Foundation. Please try again in a moment. If the problem continues, contact support@gablvm.org.'),
        '',
      );
    }

    if ($intent === QuranFoundationOauthService::INTENT_LOGIN) {
      // Resolve QF identity. Prefer id_token (no extra HTTP call); fall back
      // to userinfo endpoint if the token response did not include id_token
      // or did not include the sub/email claims we need.
      $profile = $this->qf->decodeIdToken($tokens['id_token'] ?? NULL);
      if (empty($profile['sub']) || empty($profile['email'])) {
        $profile = $this->qf->fetchUserInfo((string) $tokens['access_token']) ?? $profile ?? [];
      }
      $sub = isset($profile['sub']) ? (string) $profile['sub'] : '';
      $email = isset($profile['email']) ? (string) $profile['email'] : '';
      if ($sub === '' || $email === '' || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
        $this->getLogger('gablvm_core')->error('QF login: missing sub/email in profile @p', [
          '@p' => json_encode($profile ?: []),
        ]);
        return $this->renderPage(
          $this->t('Sign-in could not be completed'),
          $this->t('We could not read your Quran.com account profile. Please try again or sign in with email and password instead.'),
          '',
        );
      }
      // Find or create the Drupal user.
      // 1. If externalauth already has a Drupal user linked to this QF sub:
      //    log them in. Safe regardless of email_verified — the sub is the
      //    unique QF identity and was bound to this Drupal user on a prior
      //    successful sign-in.
      // 2. Else if a Drupal user exists with this email: link via
      //    externalauth and log them in. REQUIRES email_verified=true to
      //    prevent account hijacking via an unverified-email match. Apple
      //    @privaterelay.appleid.com addresses are accepted in lieu of the
      //    claim because Apple controls allocation of those relay addresses
      //    per (AppleID, service) pair, so they are intrinsically verified.
      // 3. Else: create a fresh Drupal user with this email and log them in.
      //    Safe regardless of email_verified — no pre-existing account to
      //    hijack.
      $existing = $this->externalAuth->load($sub, QuranFoundationOauthService::AUTH_PROVIDER);
      if ($existing instanceof UserInterface) {
        $this->externalAuth->userLoginFinalize($existing, $sub, QuranFoundationOauthService::AUTH_PROVIDER);
        $account = $existing;
      }
      else {
        $email_users = $this->entityTypeManager()->getStorage('user')->loadByProperties(['mail' => $email]);
        if (!empty($email_users)) {
          $email_verified = $profile['email_verified'] ?? NULL;
          $is_apple_relay = str_ends_with(strtolower($email), '@privaterelay.appleid.com');
          if ($email_verified !== TRUE && $email_verified !== 'true' && !$is_apple_relay) {
            $this->getLogger('gablvm_core')->warning('QF login: email-match link refused (email_verified=@v) for sub=@sub email_domain=@dom profile_keys=@k', [
              '@v' => var_export($email_verified, TRUE),
              '@sub' => substr($sub, 0, 12) . '...',
              '@dom' => substr(strrchr($email, '@') ?: '', 0, 40),
              '@k' => implode(',', array_keys($profile ?: [])),
            ]);
            return $this->renderPage(
              $this->t('Please verify your Quran.com email first'),
              $this->t('Your Quran.com email is not verified. Verify your email at quran.com, then try signing in here again.'),
              '',
            );
          }
          $account = reset($email_users);
          $this->externalAuth->linkExistingAccount($sub, QuranFoundationOauthService::AUTH_PROVIDER, $account);
          $this->externalAuth->userLoginFinalize($account, $sub, QuranFoundationOauthService::AUTH_PROVIDER);
        }
        else {
          // Generate a unique username from the email local-part. Cap the
          // collision search at 50 iterations so a hostile QF profile cannot
          // pin the request hammering the user table; fall back to a random
          // suffix.
          $base = preg_replace('/[^a-zA-Z0-9_.-]/', '', explode('@', $email)[0]) ?: 'qcuser';
          $name = $base;
          for ($i = 1; $i <= 50; $i++) {
            if (!user_load_by_name($name)) {
              break;
            }
            $name = $base . $i;
          }
          if (user_load_by_name($name)) {
            $name = 'qcuser' . random_int(100000, 999999);
          }
          $account = $this->externalAuth->register($sub, QuranFoundationOauthService::AUTH_PROVIDER, [
            'name' => $name,
            'mail' => $email,
            'init' => $email,
            'status' => 1,
          ]);
          $this->externalAuth->userLoginFinalize($account, $sub, QuranFoundationOauthService::AUTH_PROVIDER);
        }
      }

      // Persist the QF tokens against the (now logged-in) Drupal user so the
      // sync features at /quran/my-account work without a second handshake.
      $this->qf->storeTokensForUser((int) $account->id(), $tokens);
      $this->messenger()->addStatus($this->t('Welcome. You are signed in via your Quran.com account.'));

      $redirect_to = $this->safeInternalDestination($destination, '/user/login')
        ?: Url::fromRoute('gablvm_core.qf_account')->toString();
      return new RedirectResponse($redirect_to);
    }

    // Connect intent (default): link tokens to the already-authenticated user.
    $this->qf->storeTokensForUser((int) $this->currentUser()->id(), $tokens);
    $this->messenger()->addStatus($this->t('Your Quran.com account is now connected to gablvm.org.'));

    $redirect_to = $this->safeInternalDestination($destination)
      ?: Url::fromRoute('gablvm_core.qf_account')->toString();
    return new RedirectResponse($redirect_to);
  }

  /**
   * Validate a stored destination string before passing it to RedirectResponse.
   *
   * Accepts only paths that start with a single forward slash. Rejects:
   *   - Non-strings, empty strings.
   *   - Protocol-relative URLs (//evil.example/x) which start with `/` but
   *     send the user offsite.
   *   - Optional disallowed prefixes (e.g. /user/login on the login-intent
   *     branch, to avoid bouncing the user back to login).
   */
  private function safeInternalDestination($destination, ?string $disallowed_prefix = NULL): string {
    if (!is_string($destination) || $destination === '') {
      return '';
    }
    if (!str_starts_with($destination, '/')) {
      return '';
    }
    if (str_starts_with($destination, '//')) {
      return '';
    }
    if ($disallowed_prefix !== NULL && str_starts_with($destination, $disallowed_prefix)) {
      return '';
    }
    return $destination;
  }

  /**
   * Clear stored QF tokens for the current user.
   */
  public function disconnect(): RedirectResponse {
    $this->qf->disconnectUser((int) $this->currentUser()->id());
    $this->messenger()->addStatus($this->t('Your Quran.com account has been disconnected from gablvm.org.'));
    return new RedirectResponse(Url::fromRoute('gablvm_core.qf_account')->toString());
  }

  /**
   * Connection status page + bookmarks/notes/streaks/recent dashboard.
   */
  public function account() {
    // Anonymous visitors get bounced to /user/login first, then back here.
    // LocalRedirectResponse (not plain RedirectResponse) so the destination
    // middleware doesn't override our target — see login() for the rationale.
    // Preserve the full request URI (path + query string) so ?debug=1 and
    // any other diagnostic params survive the round-trip through login.
    if (!$this->currentUser()->isAuthenticated()) {
      $return_to = \Drupal::request()->getRequestUri();
      return new LocalRedirectResponse('/user/login?destination=' . rawurlencode($return_to));
    }

    // Debug propagation: if the user lands on /quran/my-account?debug=1,
    // every jump-to-verse URL we emit also gets &debug=1, which makes the
    // Quran Player's debug log visible. Used for triangulating bookmark
    // playback issues in the field.
    $debug_on = (bool) \Drupal::request()->query->get('debug', FALSE);

    $uid = (int) $this->currentUser()->id();
    $connected = $this->qf->isConnected($uid);

    // Auto-redirect authenticated-but-not-connected visitors straight to the
    // QF authorize flow. They explicitly navigated to /quran/my-account, which
    // signals intent to use Quran.com features; an intermediate "Connect your
    // Quran.com account" page just adds a click. The connect-intent flow at
    // gablvm_core.qf_login handles the OAuth handshake and on success sends
    // them back here to render the dashboard.
    //
    // Escape hatch: ?prompt=1 forces the prompt page (useful for support
    // troubleshooting or for users who want to read the explainer first).
    if (!$connected) {
      if (!\Drupal::request()->query->get('prompt')) {
        $login_url = Url::fromRoute('gablvm_core.qf_login', [], [
          'query' => ['destination' => '/quran/my-account'],
        ])->toString();
        return new LocalRedirectResponse($login_url);
      }
      return [
        '#theme' => 'gablvm_quran_account',
        '#connected' => FALSE,
        '#connect_url' => Url::fromRoute('gablvm_core.qf_login')->toString(),
        '#cache' => ['max-age' => 0, 'contexts' => ['user', 'url.query_args:prompt']],
      ];
    }

    $surahs = $this->quranApi->getSurahs($this->languageManager()->getCurrentLanguage()->getId());
    $surah_names = [];
    foreach ($surahs as $s) {
      $id = (int) ($s['id'] ?? 0);
      if ($id > 0) {
        $surah_names[$id] = (string) ($s['name_simple'] ?? ('Surah ' . $id));
      }
    }

    // Six per-user QF API calls fired CONCURRENTLY (Guzzle promises) instead
    // of one-after-another. Cuts dashboard worst-case render from ~60s to
    // ~10s when QF is slow on every endpoint. Per-call retry-on-5xx with
    // exponential backoff + jitter is in the batch helper, matching the
    // resilience pattern Quran Foundation recommended after the May 7-8
    // 2026 Neon DB outage. 4xx is not retried, per Basit's clarification.
    $today_iso = gmdate('Y-m-d');
    $batch_results = $this->qf->callUserApiBatchForUid($uid, [
      'bookmarks' => ['path' => 'bookmarks', 'method' => 'GET', 'options' => ['query' => ['mushafId' => 1, 'first' => 20]]],
      'recent' => ['path' => 'reading-sessions', 'method' => 'GET', 'options' => ['query' => ['first' => 5]]],
      'streaks' => ['path' => 'streaks', 'method' => 'GET', 'options' => ['query' => ['first' => 5]]],
      'notes' => ['path' => 'notes', 'method' => 'GET'],
      'todays_plan' => ['path' => 'goals/get-todays-plan', 'method' => 'GET', 'options' => ['query' => ['type' => 'QURAN_PAGES', 'mushafId' => 1]]],
      'todays_activity' => ['path' => 'activity-days', 'method' => 'GET', 'options' => ['query' => ['from' => $today_iso, 'to' => $today_iso, 'first' => 1]]],
    ]);

    // Bookmarks list — only "real" bookmarks in the default collection.
    $bookmarks_response = $batch_results['bookmarks'] ?? NULL;
    $bookmarks = $this->extractApiList($bookmarks_response);
    $bookmark_meta = $this->qf->getBookmarkMeta($uid);
    $bookmark_items = [];
    foreach ($bookmarks as $b) {
      // The default collection is the "Favorites" set. Quran.com flips
      // isInDefaultCollection to false when a user removes a bookmark from
      // there, leaving the orphan as a "reading position" record.
      if (empty($b['isInDefaultCollection'])) {
        continue;
      }
      $surah = (int) ($b['key'] ?? 0);
      $verse = (int) ($b['verseNumber'] ?? 0);
      if ($surah < 1 || $verse < 1) {
        continue;
      }
      // Restore listening context if we captured it at bookmark time.
      $meta = $bookmark_meta[$surah . ':' . $verse] ?? [];
      $query = ['surah' => $surah, 'verse' => $verse];
      if (!empty($meta['reciter'])) {
        $query['reciter'] = (int) $meta['reciter'];
      }
      if (($meta['mode'] ?? NULL) === 'verse_by_verse') {
        $query['english'] = 1;
      }
      if ($debug_on) {
        $query['debug'] = 1;
      }
      $bookmark_items[] = [
        'id' => (string) ($b['id'] ?? ''),
        'surah' => $surah,
        'verse' => $verse,
        'surah_name' => $surah_names[$surah] ?? ('Surah ' . $surah),
        'created_at' => (string) ($b['createdAt'] ?? ''),
        'jump_url' => Url::fromRoute('gablvm_core.quran_player', [], ['query' => $query])->toString(),
      ];
    }

    // Recent reading positions.
    $recent_response = $batch_results['recent'] ?? NULL;
    $recent = $this->extractApiList($recent_response);
    $recent_items = [];
    foreach ($recent as $r) {
      $surah = (int) ($r['chapterNumber'] ?? 0);
      $verse = (int) ($r['verseNumber'] ?? 0);
      if ($surah < 1) {
        continue;
      }
      $recent_query = ['surah' => $surah, 'verse' => $verse];
      if ($debug_on) {
        $recent_query['debug'] = 1;
      }
      $recent_items[] = [
        'surah' => $surah,
        'verse' => $verse,
        'surah_name' => $surah_names[$surah] ?? ('Surah ' . $surah),
        'updated_at' => (string) ($r['updatedAt'] ?? ''),
        'jump_url' => Url::fromRoute('gablvm_core.quran_player', [], ['query' => $recent_query])->toString(),
      ];
    }

    // Streak — sum days of any ACTIVE streak; otherwise show last broken streak.
    $streaks_response = $batch_results['streaks'] ?? NULL;
    $streaks = $this->extractApiList($streaks_response);
    $current_streak_days = 0;
    $last_streak_status = '';
    foreach ($streaks as $s) {
      $status = strtoupper((string) ($s['status'] ?? ''));
      if ($status === 'ACTIVE') {
        $current_streak_days = (int) ($s['days'] ?? 0);
        $last_streak_status = 'ACTIVE';
        break;
      }
    }
    if ($current_streak_days === 0 && !empty($streaks)) {
      $last = $streaks[0];
      $current_streak_days = (int) ($last['days'] ?? 0);
      $last_streak_status = strtoupper((string) ($last['status'] ?? ''));
    }

    // Notes list — gracefully handle 403 if user's token predates `note`.
    $notes_response = $batch_results['notes'] ?? NULL;
    $notes_status = $this->apiResponseStatus($notes_response);
    $notes = $this->extractApiList($notes_response);
    $note_items = [];
    foreach ($notes as $n) {
      $note_items[] = [
        'id' => (string) ($n['id'] ?? ''),
        'body' => (string) ($n['body'] ?? ''),
        'ranges' => is_array($n['ranges'] ?? NULL) ? array_map('strval', $n['ranges']) : [],
        'created_at' => (string) ($n['createdAt'] ?? ''),
      ];
    }

    // Today's reading-plan + activity-day status (post-Basit clarification
    // 2026-05-05 on the correct paths). Both are silent-fail tolerant — if
    // either endpoint errors, the corresponding panel just doesn't render.
    $todays_plan_resp = $batch_results['todays_plan'] ?? NULL;
    $todays_plan = ($this->apiOk($todays_plan_resp) && is_array($todays_plan_resp['data'] ?? NULL))
      ? $todays_plan_resp['data']
      : NULL;

    $todays_activity_resp = $batch_results['todays_activity'] ?? NULL;
    $todays_activity = NULL;
    if ($this->apiOk($todays_activity_resp)) {
      $rows = $this->extractApiList($todays_activity_resp);
      if (!empty($rows)) {
        $first_row = reset($rows);
        $todays_activity = [
          'pages_read' => (int) ($first_row['pagesRead'] ?? 0),
          'seconds_read' => (int) ($first_row['secondsRead'] ?? 0),
          'verses_read' => (int) ($first_row['versesRead'] ?? 0),
          'progress' => (float) ($first_row['progress'] ?? 0),
        ];
      }
    }

    return [
      '#theme' => 'gablvm_quran_account',
      '#connected' => TRUE,
      '#disconnect_url' => Url::fromRoute('gablvm_core.qf_disconnect')->toString(),
      '#bookmarks' => $bookmark_items,
      '#bookmarks_unavailable' => $this->apiUnavailable($bookmarks_response),
      '#recent' => $recent_items,
      '#recent_unavailable' => $this->apiUnavailable($recent_response),
      '#streak_days' => $current_streak_days,
      '#streak_status' => $last_streak_status,
      '#streak_unavailable' => $this->apiUnavailable($streaks_response),
      '#notes' => $note_items,
      '#notes_needs_reconnect' => ($notes_status === 'insufficient_scope'),
      '#notes_unavailable' => $this->apiUnavailable($notes_response),
      '#todays_plan' => $todays_plan,
      '#todays_plan_unavailable' => $this->apiUnavailable($todays_plan_resp),
      '#todays_activity' => $todays_activity,
      '#todays_activity_unavailable' => $this->apiUnavailable($todays_activity_resp),
      '#surahs' => $surahs,
      '#csrf_token' => \Drupal::csrfToken()->get('gablvm_core.qf_user_actions'),
      '#cache' => ['max-age' => 0, 'contexts' => ['user']],
      '#attached' => [
        'library' => ['gablvm_core/qf_account'],
      ],
    ];
  }

  /**
   * AJAX: add a verse to the user's default bookmark collection.
   *
   * Body: {surah:int, verse:int, csrf_token:string}.
   */
  public function bookmarkAdd(Request $request): JsonResponse {
    [$err, $payload] = $this->parseJsonRequest($request, ['surah', 'verse']);
    if ($err) {
      return $err;
    }
    $surah = $this->normalizeSurah($payload['surah'] ?? 0);
    $verse = $this->normalizeVerse($payload['verse'] ?? 0);
    if (!$surah || !$verse) {
      return new JsonResponse(['ok' => FALSE, 'error' => 'invalid_verse'], 400);
    }

    // Optional listening-context for jump-to-verse restoration.
    $reciter = isset($payload['reciter']) ? (int) $payload['reciter'] : 0;
    $reciter = ($reciter > 0 && $reciter < 10000) ? $reciter : NULL;
    $mode = (string) ($payload['mode'] ?? '');
    $mode = in_array($mode, ['verse_by_verse', 'chapter'], TRUE) ? $mode : NULL;

    $uid = (int) $this->currentUser()->id();
    $resp = $this->qf->addBookmark($uid, $surah, $verse, $reciter, $mode);
    if (!$this->apiOk($resp)) {
      return new JsonResponse([
        'ok' => FALSE,
        'error' => 'qf_failed',
        'detail' => $this->apiErrorMessage($resp),
      ]);
    }
    return new JsonResponse(['ok' => TRUE]);
  }

  /**
   * AJAX: remove a bookmark by its id from the default collection.
   */
  public function bookmarkRemove(Request $request): JsonResponse {
    [$err, $payload] = $this->parseJsonRequest($request, ['id']);
    if ($err) {
      return $err;
    }
    $id = (string) ($payload['id'] ?? '');
    if (!preg_match('/^[a-z0-9-]{6,}$/i', $id)) {
      return new JsonResponse(['ok' => FALSE, 'error' => 'invalid_id'], 400);
    }
    $uid = (int) $this->currentUser()->id();
    $resp = $this->qf->removeBookmark($uid, $id);
    if (!$this->apiOk($resp)) {
      return new JsonResponse([
        'ok' => FALSE,
        'error' => 'qf_failed',
        'detail' => $this->apiErrorMessage($resp),
      ]);
    }
    return new JsonResponse(['ok' => TRUE]);
  }

  /**
   * AJAX: log a reading-session position at a surah/verse.
   *
   * Body shape (all fields):
   *   surah:       int 1-114 (required)
   *   verse:       int >= 1 (optional, defaults to 1) — position log only
   *   secondsRead: int >= 8 (optional) — when present, also posts an
   *                activity-day record so the streak engine + "Today's
   *                reading" panel populate. Below 8 seconds is treated
   *                as not-meaningful and the activity-day post is skipped.
   *   verseFrom:   int >= 1 (optional, defaults to verse) — start of the
   *                verse range covered in this submission
   *   verseTo:     int >= verseFrom (optional, defaults to verseFrom) —
   *                end of the verse range covered in this submission
   *
   * Backward compatibility: a body with just {surah, verse} still works
   * — it logs the position only, no activity-day post. That keeps the
   * "Continue reading" panel populated even from old/stale clients.
   *
   * Response: {ok: true, session: bool, activity: bool} — session and
   * activity flags reflect which of the two QF posts succeeded. We do
   * NOT 5xx if activity-day fails (e.g., scope missing) because the
   * position log is the primary signal for "Continue reading."
   */
  public function readingSessionLog(Request $request): JsonResponse {
    [$err, $payload] = $this->parseJsonRequest($request, ['surah']);
    if ($err) {
      return $err;
    }
    $surah = $this->normalizeSurah($payload['surah'] ?? 0);
    $verse = $this->normalizeVerse($payload['verse'] ?? 1);
    if (!$surah) {
      return new JsonResponse(['ok' => FALSE, 'error' => 'invalid_surah'], 400);
    }
    if (!$verse) {
      $verse = 1;
    }
    $uid = (int) $this->currentUser()->id();

    // Position log: drives the "Continue reading" panel. This is the
    // existing behavior — never disturbed.
    $session_resp = $this->qf->addReadingSession($uid, $surah, $verse);
    $session_ok = $this->apiOk($session_resp);
    if (!$session_ok) {
      return new JsonResponse([
        'ok' => FALSE,
        'error' => 'qf_failed',
        'detail' => $this->apiErrorMessage($session_resp),
      ]);
    }

    // Activity-day record: drives the streak engine + "Today's reading"
    // panel. Optional, only fires when the client supplies secondsRead.
    // Added 2026-05-20 after discovering /reading-sessions is position-only.
    $activity_ok = FALSE;
    $seconds_read = (int) ($payload['secondsRead'] ?? 0);
    if ($seconds_read >= 8) {
      $verse_from = $this->normalizeVerse($payload['verseFrom'] ?? $verse) ?: $verse;
      $verse_to_raw = $this->normalizeVerse($payload['verseTo'] ?? $verse_from);
      $verse_to = $verse_to_raw && $verse_to_raw >= $verse_from ? $verse_to_raw : $verse_from;
      $activity_resp = $this->qf->addActivityDay($uid, $surah, $verse_from, $surah, $verse_to, $seconds_read);
      $activity_ok = $this->apiOk($activity_resp);
      if (!$activity_ok) {
        $this->getLogger('gablvm_core')->info('QF activity-day post failed for uid=@uid surah=@s verses=@vf-@vt seconds=@sec: @msg', [
          '@uid' => $uid,
          '@s' => $surah,
          '@vf' => $verse_from,
          '@vt' => $verse_to,
          '@sec' => $seconds_read,
          '@msg' => $this->apiErrorMessage($activity_resp),
        ]);
      }
    }

    return new JsonResponse([
      'ok' => TRUE,
      'session' => $session_ok,
      'activity' => $activity_ok,
    ]);
  }

  /**
   * AJAX: create a note attached to a verse range like "2:255-2:255".
   *
   * Body: {surah:int, verse:int, body:string, csrf_token:string}.
   *
   * Note: we always return HTTP 200 here even on logical failure, because
   * Cloudflare's edge replaces 5xx response bodies with its own error page,
   * which would prevent the client JS from reading our `{ok:false, error}`
   * payload. The client checks `body.ok`, not the HTTP status.
   */
  public function noteCreate(Request $request): JsonResponse {
    [$err, $payload] = $this->parseJsonRequest($request, ['surah', 'verse', 'body']);
    if ($err) {
      return $err;
    }
    $surah = $this->normalizeSurah($payload['surah'] ?? 0);
    $verse = $this->normalizeVerse($payload['verse'] ?? 0);
    $body = trim((string) ($payload['body'] ?? ''));
    if (!$surah || !$verse) {
      return new JsonResponse(['ok' => FALSE, 'error' => 'invalid_verse'], 400);
    }
    if (mb_strlen($body) < 1 || mb_strlen($body) > 5000) {
      return new JsonResponse(['ok' => FALSE, 'error' => 'invalid_body'], 400);
    }
    $range = $surah . ':' . $verse . '-' . $surah . ':' . $verse;
    $uid = (int) $this->currentUser()->id();
    $resp = $this->qf->addNote($uid, $body, [$range]);
    if (!$this->apiOk($resp)) {
      $detail = $this->apiErrorMessage($resp);
      $code = $this->apiResponseStatus($resp);
      return new JsonResponse([
        'ok' => FALSE,
        'error' => $code === 'insufficient_scope' ? 'reconnect_required' : 'qf_failed',
        'detail' => $detail,
      ]);
    }
    // Return the new note shape so the client JS can inject it into the
    // dashboard list without requiring a full page reload.
    $created = $resp['data'] ?? [];
    return new JsonResponse([
      'ok' => TRUE,
      'note' => [
        'id' => (string) ($created['id'] ?? ''),
        'body' => (string) ($created['body'] ?? $body),
        'ranges' => is_array($created['ranges'] ?? NULL) ? array_map('strval', $created['ranges']) : [$range],
      ],
    ]);
  }

  /**
   * AJAX: remove a note by id.
   */
  public function noteRemove(Request $request): JsonResponse {
    [$err, $payload] = $this->parseJsonRequest($request, ['id']);
    if ($err) {
      return $err;
    }
    $id = (string) ($payload['id'] ?? '');
    if (!preg_match('/^[a-z0-9]{6,}$/i', $id)) {
      return new JsonResponse(['ok' => FALSE, 'error' => 'invalid_id'], 400);
    }
    $uid = (int) $this->currentUser()->id();
    $resp = $this->qf->removeNote($uid, $id);
    if (!$this->apiOk($resp)) {
      return new JsonResponse([
        'ok' => FALSE,
        'error' => 'qf_failed',
        'detail' => $this->apiErrorMessage($resp),
      ]);
    }
    return new JsonResponse(['ok' => TRUE]);
  }

  /**
   * Decode a JSON request body, validate CSRF token, ensure required fields.
   *
   * @return array
   *   Tuple [JsonResponse|null $error_response, array $payload].
   */
  private function parseJsonRequest(Request $request, array $required): array {
    $raw = (string) $request->getContent();
    $payload = json_decode($raw, TRUE);
    if (!is_array($payload)) {
      return [new JsonResponse(['ok' => FALSE, 'error' => 'invalid_json'], 400), []];
    }
    $token = (string) ($payload['csrf_token'] ?? $request->headers->get('X-CSRF-Token') ?? '');
    if (!$token || !\Drupal::csrfToken()->validate($token, 'gablvm_core.qf_user_actions')) {
      return [new JsonResponse(['ok' => FALSE, 'error' => 'invalid_csrf'], 403), []];
    }
    if (!$this->qf->isConnected((int) $this->currentUser()->id())) {
      return [new JsonResponse(['ok' => FALSE, 'error' => 'not_connected'], 403), []];
    }
    foreach ($required as $field) {
      if (!array_key_exists($field, $payload)) {
        return [new JsonResponse(['ok' => FALSE, 'error' => 'missing_field', 'field' => $field], 400), []];
      }
    }
    return [NULL, $payload];
  }

  private function normalizeSurah($v): int {
    $n = (int) $v;
    return ($n >= 1 && $n <= 114) ? $n : 0;
  }

  private function normalizeVerse($v): int {
    $n = (int) $v;
    return ($n >= 1 && $n <= 286) ? $n : 0;
  }

  private function apiOk(?array $resp): bool {
    if (!is_array($resp) || isset($resp['_http_status'])) {
      return FALSE;
    }
    return !empty($resp['success']);
  }

  private function apiErrorMessage(?array $resp): string {
    if (!is_array($resp)) {
      return '';
    }
    return (string) ($resp['message'] ?? $resp['details']['error']['message'] ?? '');
  }

  private function apiResponseStatus(?array $resp): string {
    if (!is_array($resp)) {
      return '';
    }
    return (string) ($resp['type'] ?? '');
  }

  /**
   * Detect a section that should render a friendly "currently unavailable"
   * fallback rather than empty content. True if the upstream call failed
   * with a transport error (NULL) or a 5xx after retries — distinct from
   * 4xx (insufficient_scope, validation, etc.) which indicates a real
   * user-actionable condition handled by other branches.
   */
  private function apiUnavailable(?array $resp): bool {
    if ($resp === NULL) {
      return TRUE;
    }
    $code = (int) ($resp['_http_status'] ?? 0);
    return $code >= 500;
  }

  private function extractApiList(?array $resp): array {
    if (!is_array($resp) || isset($resp['_http_status']) || empty($resp['success'])) {
      return [];
    }
    $data = $resp['data'] ?? [];
    return is_array($data) ? $data : [];
  }

  /**
   * Render a simple page with a heading, body, and optional safe detail.
   */
  private function renderPage($heading, $body, string $detail): array {
    $build = [
      '#type' => 'container',
      '#attributes' => ['class' => ['qf-oauth-callback', 'quran-section']],
      'heading' => [
        '#type' => 'html_tag',
        '#tag' => 'h2',
        '#value' => $heading,
      ],
      'body' => [
        '#type' => 'html_tag',
        '#tag' => 'p',
        '#value' => $body,
      ],
    ];
    if ($detail !== '') {
      $build['detail'] = [
        '#type' => 'html_tag',
        '#tag' => 'p',
        '#attributes' => ['class' => ['qf-oauth-detail']],
        '#value' => Html::escape($detail),
      ];
    }
    $build['home_link'] = [
      '#type' => 'html_tag',
      '#tag' => 'p',
      '#value' => '<a href="/">' . $this->t('Return to gablvm.org') . '</a>',
    ];
    $build['#cache'] = ['max-age' => 0];
    return $build;
  }

}
