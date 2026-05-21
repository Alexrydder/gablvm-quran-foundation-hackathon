# GABLVM Core Module

Essential Islamic features for the Global Advocacy of Blind and Low Vision Muslims (GABLVM) community website.

## Overview

This custom Drupal module provides accessible Islamic tools designed with full WCAG 2.1 Level AA accessibility compliance:

1. **Quran Player** - Listen to and read the Holy Quran with multiple reciters and translations
2. **Prayer Times** - Accurate prayer times for any location worldwide
3. **Hijri Calendar** - Islamic calendar with event information
4. **Language Selector** - Google Translate integration with 45+ languages
5. **Newsletter** - Email subscription with accessible Math CAPTCHA

## Requirements

- Drupal 11.x
- PHP 8.2 or higher
- No contributed module dependencies (standalone custom module)

## External APIs Used

| API | Purpose | Documentation |
|-----|---------|---------------|
| Quran.com API | Quran audio, text, translations | https://api-docs.quran.com |
| Quran Foundation API | Authenticated API (unlimited) | https://api-docs.quran.foundation |
| Aladhan API | Prayer times calculation | https://aladhan.com/prayer-times-api |
| Nominatim (OpenStreetMap) | Reverse geocoding for location names | https://nominatim.org |
| Google Translate | Page translation | https://translate.google.com |

### Quran API Modes

The module supports three API modes (configured at /admin/config/services/gablvm):

| Mode | URL | Authentication | Rate Limits |
|------|-----|----------------|-------------|
| Free Public | api.quran.com/api/v4 | None required | Standard |
| Production | apis.quran.foundation/content/api/v4 | OAuth2 credentials | Unlimited* |
| Development | apis-prelive.quran.foundation/content/api/v4 | OAuth2 credentials | Unlimited* |

*With registered credentials from api-docs.quran.foundation

## Features

### Quran Player (/quran-listen)

- 114 Surahs with Arabic text and English translations
- Multiple reciters (verse-by-verse and chapter reciters)
- Progress bar with seek control (YouTube-style seeking)
- Playback speed control (0.5x to 2x)
- Gapless playback mode (full surah audio)
- Verse-by-verse audio playback
- English audio translation (Ibrahim Walk) after each Arabic verse
- Auto-advance to next Surah option
- Continuous playback between verses
- Keyboard accessible controls
- Screen reader announcements for playback status

**English Translation Reciter Compatibility:**

The "Play English audio translation after each verse" feature requires reciters with verse-by-verse audio files. Only the following reciters support this feature:

| Reciter ID | Name |
|------------|------|
| 1 | Abdul Basit Abdul Samad (Mujawwad) |
| 2 | Abdul Basit Abdul Samad (Murattal) |
| 3 | Abdur-Rahman as-Sudais |
| 4 | Abu Bakr al-Shatri |
| 5 | Hani ar-Rifai |
| 6 | Mahmoud Khalil Al-Husary |
| 7 | Mishari Rashid al-Afasy |
| 9 | Muhammad Siddiq al-Minshawi |
| 10 | Saud ash-Shuraym |
| 12 | Mahmoud Khalil Al-Husary (Muallim) |

Other reciters (Saad al-Ghamdi, Maher al-Muaiqly, etc.) only have chapter/gapless audio and do not support the English translation feature. The checkbox is automatically disabled for unsupported reciters.

### Prayer Times (/prayer-times)

- "Use My Location" button for explicit location detection
- Automatic location detection (with user permission)
- Manual city/country entry as fallback
- 14 calculation methods (ISNA, MWL, Umm Al-Qura, etc.)
- Shafi and Hanafi school support
- Real-time countdown to next prayer
- Dynamic date switch when all prayers have passed
- Automatic midnight refresh for new day's times
- Screen reader announcements when prayer time arrives

### Hijri Calendar (/islam-calendar)

- Current Hijri date display with Gregorian equivalent
- Monthly calendar grid view
- Islamic events and holidays
- Month/year navigation
- Collapsible keyboard shortcuts help
- Zero-cache for accurate date display

### Language Selector (All Pages)

- Dropdown menu at top of every page
- 60 languages including Turkic (Azerbaijani, Uzbek, Kazakh, etc.) and Balkan (Albanian, Bosnian, etc.) languages
- English names for screen reader pronunciation
- First-time disclaimer modal warning about machine translation accuracy
- Accessible design (keyboard navigable, ARIA compliant)
- Remembers user preference in localStorage

### Newsletter (/newsletter)

- Professional subscription page with hero section and benefits list
- Two-column layout: benefits on left, subscription form on right
- Simplified form: email field + Math CAPTCHA + Subscribe button
- FAQ section with 4 common questions
- Recent issues display (shows up to 3 published newsletter issues)
- Double opt-in confirmation email flow
- Subscription management at /simplenews/subscriptions

## Configuration

Navigate to **Administration > Configuration > Web services > GABLVM Settings** (/admin/config/services/gablvm)

### Settings Available

- **Feature Toggles**: Enable/disable individual features
- **Greeting Messages**: Custom messages for each feature page
- **Prayer Times Defaults**: Default calculation method and school
- **Display Options**: Show/hide translation text in Quran player

## File Structure

```
gablvm_core/
├── config/
│   └── install/                    # Default configuration
│       └── views.view.blog.yml     # Blog view config
├── css/
│   ├── quran-player.css            # Quran player styles
│   ├── prayer-times.css            # Prayer times styles
│   ├── hijri-calendar.css          # Calendar styles
│   ├── language-selector.css       # Language dropdown styles
│   ├── newsletter-page.css         # Newsletter page styles
│   ├── global.css                  # Site-wide enhancements
│   └── admin-forms.css             # Admin form styles
├── js/
│   ├── quran-player.js             # Quran player with progress/speed
│   ├── prayer-times.js             # Prayer times with midnight refresh
│   ├── hijri-calendar.js           # Calendar interactions
│   ├── language-selector.js        # Google Translate with disclaimer
│   └── admin-forms.js              # Admin form enhancements
├── src/
│   ├── Controller/
│   │   ├── QuranPlayerController.php
│   │   ├── PrayerTimesController.php
│   │   ├── HijriCalendarController.php
│   │   └── NewsletterController.php
│   ├── Form/
│   │   └── SettingsForm.php
│   ├── Plugin/
│   │   └── Block/
│   │       └── LanguageSelectorBlock.php
│   └── Service/
│       └── QuranApiService.php
├── templates/
│   ├── gablvm-quran-player.html.twig
│   ├── gablvm-prayer-times.html.twig
│   ├── gablvm-hijri-calendar.html.twig
│   ├── gablvm-language-selector.html.twig
│   └── gablvm-newsletter-page.html.twig
├── gablvm_core.info.yml
├── gablvm_core.module
├── gablvm_core.routing.yml
├── gablvm_core.services.yml
├── gablvm_core.libraries.yml
└── README.md
```

## Routes

| Path | Description |
|------|-------------|
| /quran-listen | Quran Player page |
| /prayer-times | Prayer Times page |
| /islam-calendar | Hijri Calendar page |
| /newsletter | Newsletter subscription page |
| /blog | Blog articles listing |
| /contact-us | Contact form |
| /admin/config/services/gablvm | Module settings |

## Accessibility Features

All features are WCAG 2.1 Level AA compliant:

- Full keyboard navigation
- ARIA live regions for dynamic content
- Screen reader announcements
- High contrast mode support (`prefers-contrast: high`)
- Reduced motion support (`prefers-reduced-motion: reduce`)
- Focus management with visible indicators
- Semantic HTML structure
- Skip links and landmarks
- 44px minimum touch targets
- English language names in selector for screen reader pronunciation
- Math CAPTCHA instead of image CAPTCHA

## Caching

- Prayer times: 15-minute cache
- Quran data: 1-hour cache
- Hijri calendar: No cache (max-age: 0) for accurate dates
- Reverse geocoding: 30-day cache

## Troubleshooting

### Prayer times not updating

- Clear Drupal cache: `drush cr`
- Check browser console for JavaScript errors
- Verify internet connectivity for API calls

### Location not detected

- Ensure HTTPS is enabled (required for geolocation)
- Check browser permissions for location access
- Use manual city/country entry as fallback

### Audio not playing

- Check browser autoplay policies
- Verify audio URL is accessible
- Try a different reciter
- Check playback speed setting

### Language translation not working

- Clear browser localStorage
- Ensure Google Translate script loads (check console)
- Try refreshing the page after selecting language

### Newsletter emails showing HTML tags

- Check mailsystem config: `drush config:get mailsystem.settings`
- Simplenews should use `php_mail` formatter
- Run: `drush config:set mailsystem.settings modules.simplenews.none.formatter php_mail -y`

### Newsletter not sending

- Check SMTP settings: `drush config:get smtp.settings`
- View mail logs: `drush watchdog:show --type=mail`
- Verify simplenews cron is running: `drush cron`
- Check spool: Admin > Config > Web services > Simplenews > Status

---

## Credits

- Quran data provided by Quran.com (https://quran.com)
- Prayer times by Aladhan (https://aladhan.com)
- Geocoding by OpenStreetMap Nominatim (https://nominatim.org)
- Translation by Google Translate (https://translate.google.com)

---

*For development history, see [CHANGELOG.md](../../../CHANGELOG.md)*
