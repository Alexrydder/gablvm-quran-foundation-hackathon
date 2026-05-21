<?php

declare(strict_types=1);

namespace Drupal\gablvm_core\Controller;

use Drupal\Component\Utility\Xss;
use Drupal\Core\Controller\ControllerBase;
use Drupal\Core\DependencyInjection\ContainerInjectionInterface;
use Drupal\gablvm_core\Service\QuranApiService;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;

/**
 * Hidden experimental route that renders Quranic commentary (tafsir) per verse.
 *
 * Not linked from the main navigation. Reachable only by direct URL:
 * /quran/lab/tafsir[?surah=N&tafsir=M&page=K&debug=1]
 */
class QuranTafsirLabController extends ControllerBase implements ContainerInjectionInterface {

  protected const VERSES_PER_PAGE = 20;
  protected const DEFAULT_TAFSIR_ID = 168;
  protected const ALLOWED_TAFSIR_TAGS = ['p', 'br', 'em', 'strong', 'a', 'sup', 'sub', 'ul', 'ol', 'li', 'blockquote'];

  /**
   * Coverage % and repetition density per tafsir ID.
   *
   * Sourced from `tafsir-api-behavior.md` — full 114-surah audit 2026-04-21.
   * Keep in sync with memory file if Quran Foundation adds/changes tafsirs.
   */
  protected const TAFSIR_COVERAGE_META = [
    168 => ['coverage_pct' => 99.97, 'repetition' => 'medium'],
    169 => ['coverage_pct' => 96.70, 'repetition' => 'high'],
    817 => ['coverage_pct' => 31.00, 'repetition' => 'low'],
  ];

  protected QuranApiService $quranApi;

  public function __construct(QuranApiService $quran_api) {
    $this->quranApi = $quran_api;
  }

  public static function create(ContainerInterface $container): static {
    return new static($container->get('gablvm_core.quran_api'));
  }

  /**
   * Legacy /quran/lab/tafsir → /quran/tafsir permanent redirect.
   *
   * The lab path is preserved so older bookmarks and the email we sent
   * to Yahya (2026-04-18) still resolve, but they land on the canonical URL.
   */
  public function labRedirect(Request $request): RedirectResponse {
    $query = $request->getQueryString();
    $target = '/quran/tafsir' . ($query ? '?' . $query : '');
    return new RedirectResponse($target, 301);
  }

  public function display(Request $request): array {
    $language = $this->languageManager()->getCurrentLanguage()->getId();

    $surah_list = $this->quranApi->getSurahs($language);
    $tafsir_list = $this->quranApi->getTafsirResources($language);

    foreach ($tafsir_list as &$t) {
      $id = (int) ($t['id'] ?? 0);
      $meta = self::TAFSIR_COVERAGE_META[$id] ?? NULL;
      $t['coverage_pct'] = $meta['coverage_pct'] ?? NULL;
      $t['repetition'] = $meta['repetition'] ?? NULL;
    }
    unset($t);

    $current_surah = max(1, min(114, (int) $request->query->get('surah', 1)));
    $current_tafsir_id = (int) $request->query->get('tafsir', self::DEFAULT_TAFSIR_ID);
    $current_page = max(1, (int) $request->query->get('page', 1));
    $debug_mode = $request->query->get('debug') === '1';

    $valid_tafsir_ids = array_map(static fn($t) => (int) $t['id'], $tafsir_list);
    if ($valid_tafsir_ids && !in_array($current_tafsir_id, $valid_tafsir_ids, TRUE)) {
      $current_tafsir_id = $valid_tafsir_ids[0];
    }

    $current_surah_name = '';
    foreach ($surah_list as $s) {
      if ((int) ($s['id'] ?? 0) === $current_surah) {
        $current_surah_name = $s['name_simple'] ?? '';
        break;
      }
    }

    $current_tafsir_name = '';
    foreach ($tafsir_list as $t) {
      if ((int) ($t['id'] ?? 0) === $current_tafsir_id) {
        $current_tafsir_name = ($t['name'] ?? '') . ' by ' . ($t['author_name'] ?? '');
        break;
      }
    }

    $error = NULL;
    $all_verses = [];
    $verses_with_tafsir_count = 0;
    $arabic_response = $this->quranApi->getVersesArabicOnly($current_surah, $language);
    $tafsir_response = $this->quranApi->getVersesWithTafsir($current_surah, $current_tafsir_id, $language);

    if ($arabic_response && !empty($arabic_response['verses'])) {
      // Index tafsir verses by verse_key so we can merge coverage gaps.
      $tafsir_by_key = [];
      if ($tafsir_response && !empty($tafsir_response['verses'])) {
        foreach ($tafsir_response['verses'] as $tv) {
          $key = $tv['verse_key'] ?? '';
          if ($key !== '') {
            $tafsir_by_key[$key] = $tv['tafsirs'][0]['text'] ?? '';
          }
        }
      }

      // First pass: cheap metadata only. Defer sanitization to post-slice so
      // we don't Xss::filter all 286 Al-Baqarah blocks when rendering 20.
      foreach ($arabic_response['verses'] as $verse) {
        $verse_key = $verse['verse_key'] ?? '';
        $raw_tafsir_html = $tafsir_by_key[$verse_key] ?? '';
        $has_tafsir = $raw_tafsir_html !== '';
        if ($has_tafsir) {
          $verses_with_tafsir_count++;
        }
        $all_verses[] = [
          'verse_key' => $verse_key,
          'text_uthmani' => $verse['text_uthmani'] ?? '',
          'raw_tafsir_html' => $raw_tafsir_html,
          'has_tafsir' => $has_tafsir,
        ];
      }
    }
    else {
      $error = $this->t('Could not load verses for this surah. Try again in a minute.');
    }

    $total_verses = count($all_verses);
    // Flag the coverage note only when the gap is substantive. Tafsirs that
    // cover 99% of verses (e.g., Ibn Kathir missing 3 of 286 in Al-Baqarah)
    // don't need an alarming note — but Tazkirul Quran at 29% coverage does.
    $coverage_partial = $total_verses > 0
      && $verses_with_tafsir_count > 0
      && ($verses_with_tafsir_count / $total_verses) < 0.90;
    $total_pages = max(1, (int) ceil($total_verses / self::VERSES_PER_PAGE));
    $current_page = min($current_page, $total_pages);
    $offset = ($current_page - 1) * self::VERSES_PER_PAGE;
    $page_verses = array_slice($all_verses, $offset, self::VERSES_PER_PAGE);

    // Sanitize only the 20 verses we're rendering, not all 286.
    foreach ($page_verses as &$pv) {
      $pv['tafsir_html'] = $pv['has_tafsir']
        ? $this->sanitizeTafsirHtml($pv['raw_tafsir_html'])
        : '';
      unset($pv['raw_tafsir_html']);
    }
    unset($pv);

    // Look-ahead: for each verse in the surah, what's the next verse that
    // does have tafsir? Used to tell the user where to jump when they land
    // on an uncovered verse (Tazkirul Quran edge case, 69% of its verses).
    $next_covered_by_key = [];
    $next_covered = NULL;
    for ($i = count($all_verses) - 1; $i >= 0; $i--) {
      $v = $all_verses[$i];
      $next_covered_by_key[$v['verse_key']] = $next_covered;
      if ($v['has_tafsir']) {
        $next_covered = $v['verse_key'];
      }
    }

    // Group consecutive verses that share identical commentary so VoiceOver
    // reads the block once instead of N times. Classical tafsirs comment on
    // verse ranges; the API flattens that into per-verse duplication.
    $groups = $this->groupConsecutiveCommentary($page_verses, $next_covered_by_key);

    return [
      '#theme' => 'gablvm_quran_tafsir_lab',
      '#surahs' => $this->formatSurahsForSelect($surah_list),
      '#tafsir_resources' => $tafsir_list,
      '#current_surah' => $current_surah,
      '#current_surah_name' => $current_surah_name,
      '#current_tafsir_id' => $current_tafsir_id,
      '#current_tafsir_name' => $current_tafsir_name,
      '#current_page' => $current_page,
      '#total_pages' => $total_pages,
      '#total_verses' => $total_verses,
      '#verses_with_tafsir' => $verses_with_tafsir_count,
      '#coverage_partial' => $coverage_partial,
      '#verses' => $page_verses,
      '#groups' => $groups,
      '#error' => $error,
      '#debug_mode' => $debug_mode,
      '#attached' => [
        'library' => ['gablvm_core/quran_tafsir_lab', 'gablvm_core/listen_aloud'],
      ],
      '#cache' => [
        'contexts' => ['url.query_args:surah', 'url.query_args:tafsir', 'url.query_args:page', 'url.query_args:debug'],
        'max-age' => 3600,
      ],
    ];
  }

  /**
   * Collapse runs of consecutive verses that share the same commentary block.
   *
   * Each returned group has:
   *   - range_label: "Verse 11" or "Verses 11-20"
   *   - verses: list of {verse_key, text_uthmani}
   *   - tafsir_html: sanitized commentary (empty if has_tafsir is FALSE)
   *   - has_tafsir: TRUE if this group is covered
   *   - next_covered_verse_key: for uncovered groups, the next key that IS covered
   *   - is_grouped: TRUE if group spans >1 verse
   */
  protected function groupConsecutiveCommentary(array $page_verses, array $next_covered_by_key): array {
    $groups = [];
    $current = NULL;

    foreach ($page_verses as $pv) {
      // Hash on normalized plain-text so whitespace/HTML variation doesn't
      // split otherwise-identical commentary into false separate groups.
      $normalized = $pv['has_tafsir']
        ? trim(preg_replace('/\s+/', ' ', strip_tags($pv['tafsir_html'] ?? '')) ?? '')
        : '';
      $hash = $pv['has_tafsir'] ? md5($normalized) : '';

      if ($current === NULL || $current['hash'] !== $hash) {
        if ($current !== NULL) {
          $groups[] = $this->finalizeGroup($current, $next_covered_by_key);
        }
        $current = [
          'hash' => $hash,
          'has_tafsir' => $pv['has_tafsir'],
          'tafsir_html' => $pv['has_tafsir'] ? $pv['tafsir_html'] : '',
          'first_key' => $pv['verse_key'],
          'last_key' => $pv['verse_key'],
          'verses' => [
            ['verse_key' => $pv['verse_key'], 'text_uthmani' => $pv['text_uthmani']],
          ],
        ];
      }
      else {
        $current['last_key'] = $pv['verse_key'];
        $current['verses'][] = [
          'verse_key' => $pv['verse_key'],
          'text_uthmani' => $pv['text_uthmani'],
        ];
      }
    }
    if ($current !== NULL) {
      $groups[] = $this->finalizeGroup($current, $next_covered_by_key);
    }

    return $groups;
  }

  /**
   * Add display metadata to a group before emitting it to the template.
   */
  protected function finalizeGroup(array $group, array $next_covered_by_key): array {
    $count = count($group['verses']);
    $group['is_grouped'] = $count > 1;
    $group['verse_count'] = $count;
    $first_verse_num = (int) (explode(':', $group['first_key'])[1] ?? 0);
    $last_verse_num = (int) (explode(':', $group['last_key'])[1] ?? 0);
    $group['range_label'] = $count === 1
      ? 'Verse ' . $first_verse_num
      : 'Verses ' . $first_verse_num . ' to ' . $last_verse_num;
    $group['next_covered_verse_key'] = $next_covered_by_key[$group['last_key']] ?? NULL;
    unset($group['hash']);
    return $group;
  }

  /**
   * Sanitize tafsir HTML from Quran Foundation for screen-reader-friendly output.
   *
   * Strips inline styles, scripts, heading tags (which would compete with our
   * page headings), and color spans. Leaves paragraphs, lists, emphasis.
   */
  protected function sanitizeTafsirHtml(string $raw): string {
    // Demote headings instead of stripping — keeps the scholarly structure
    // but moves it below our verse-level h3 so rotor navigation stays clean.
    $demoted = preg_replace('/<h1\b[^>]*>/i', '<p><strong>', $raw);
    $demoted = preg_replace('/<\/h1>/i', '</strong></p>', $demoted);
    $demoted = preg_replace('/<h2\b[^>]*>/i', '<p><strong>', $demoted);
    $demoted = preg_replace('/<\/h2>/i', '</strong></p>', $demoted);
    $demoted = preg_replace('/<h[3-6]\b[^>]*>/i', '<p><em>', $demoted);
    $demoted = preg_replace('/<\/h[3-6]>/i', '</em></p>', $demoted);

    return Xss::filter($demoted, self::ALLOWED_TAFSIR_TAGS);
  }

  /**
   * Format surah list for the picker dropdown.
   */
  protected function formatSurahsForSelect(array $surahs): array {
    $out = [];
    foreach ($surahs as $s) {
      $id = (int) ($s['id'] ?? 0);
      if ($id < 1) {
        continue;
      }
      $out[] = [
        'id' => $id,
        'label' => sprintf('%d. %s (%s)', $id, $s['name_simple'] ?? '', $s['name_arabic'] ?? ''),
      ];
    }
    return $out;
  }

}
