# GABLVM Website User Guide

**Welcome to the Global Advocacy for Blind and Low Vision Muslims (GABLVM) Website**

This comprehensive guide covers everything you need to know about using and managing the GABLVM website. Whether you are a visitor using our Islamic tools, a contributor creating content, or an administrator managing the site, this guide provides clear, step-by-step instructions.

All features are designed with accessibility as a priority, following WCAG 2.1 Level AA standards to ensure full compatibility with screen readers and assistive technologies.

---

## Table of Contents

### Part 1: Islamic Tools (For All Visitors)
1. [Quick Start](#quick-start)
2. [Prayer Times](#prayer-times)
3. [Quran Player](#quran-player)
4. [Islamic Calendar](#islamic-calendar)
5. [Newsletter Subscription](#newsletter-subscription)
6. [Language Translation](#language-translation)

### Part 2: Content Management (For Contributors and Editors)
7. [Understanding User Roles](#understanding-user-roles)
8. [Creating and Editing Content](#creating-and-editing-content)
9. [Managing Events](#managing-events)
10. [Managing Resources](#managing-resources)
11. [Content Moderation Workflow](#content-moderation-workflow)

### Part 3: Administration (For Administrators)
12. [Site Administration Overview](#site-administration-overview)
13. [User Management](#user-management)
14. [Newsletter Administration](#newsletter-administration)
15. [Security Monitoring](#security-monitoring)
16. [Backup and Maintenance](#backup-and-maintenance)

### Part 4: Reference
17. [Accessibility Features](#accessibility-features)
18. [Third-Party Services and Privacy](#third-party-services-and-privacy)
19. [Troubleshooting](#troubleshooting)
20. [Frequently Asked Questions](#frequently-asked-questions)

---

# Part 1: Islamic Tools

## Quick Start

All Islamic tools are available to everyone without requiring an account or login.

### Accessing the Features

| Feature | Web Address | Purpose |
|---------|-------------|---------|
| Prayer Times | `/prayer-times` | Daily prayer times for your location |
| Quran Player | `/quran-listen` | Listen to Quran recitation with translations |
| Islamic Calendar | `/islam-calendar` | Hijri calendar with Islamic events |
| Newsletter | `/newsletter` | Subscribe to community updates |

### Compatibility

All pages work with:
- Screen readers: JAWS, NVDA, VoiceOver (macOS/iOS), TalkBack (Android)
- Keyboard navigation: Full functionality without a mouse
- Browser zoom: Text and layouts scale properly up to 200%
- High contrast modes: Windows High Contrast and forced-colors supported

---

## Prayer Times

### Overview

The Prayer Times feature calculates and displays the five daily Islamic prayer times based on your geographic location. It includes real-time countdown to the next prayer, the current Hijri date, and support for multiple calculation methods used by different Islamic organizations worldwide.

### How to Access

Navigate to `/prayer-times` on the website. No account is required.

### What You Will See

Upon loading the page, you will encounter:

1. **Next Prayer Display**: The name of the upcoming prayer, its time, and a live countdown showing hours, minutes, and seconds remaining.

2. **Prayer Times Table**: A list of all five daily prayers (Fajr, Dhuhr, Asr, Maghrib, Isha) plus Sunrise, with the next prayer highlighted.

3. **Date Information**: Both the Gregorian date (for example, "Friday, December 27, 2025") and the Hijri date (for example, "26 Jumada Al-Akhirah 1447 AH").

4. **Location Information**: Your current city and country, or the location you entered manually.

### Setting Your Location

#### Automatic Detection (Recommended)

1. When you visit the page, your browser will request permission to access your location.
2. Select "Allow" when prompted.
3. Prayer times will appear immediately based on your GPS coordinates.
4. The page will display your city and state name (for example, "Minneapolis, Minnesota").

Your browser will remember this permission, so you will not be asked again on future visits.

#### Manual Entry

If you prefer not to share your location, or if automatic detection is unavailable:

1. Scroll to the "Settings" section of the page.
2. Enter your city name in the City field.
3. Enter your country name in the Country field.
4. Select "Update Prayer Times."

### Calculation Methods

Different Islamic organizations use different astronomical methods to calculate prayer times. The differences primarily affect Fajr and Isha times.

To change the calculation method:

1. Scroll to the "Settings" section.
2. Select your preferred method from the "Calculation Method" dropdown.
3. Select "Update Prayer Times."

Available methods include:
- Islamic Society of North America (ISNA) - Default for users in North America
- Muslim World League (MWL) - Common international standard
- Umm Al-Qura University, Makkah - Official method of Saudi Arabia
- Egyptian General Authority of Survey - Common in Egypt and Africa
- University of Islamic Sciences, Karachi - Common in Pakistan and India
- And additional regional methods

### Asr Calculation School

Two schools of Islamic jurisprudence calculate Asr time differently:

- **Shafi/Maliki/Hanbali (Standard)**: Asr begins when an object's shadow equals its length plus the shadow at noon.
- **Hanafi**: Asr begins when an object's shadow equals twice its length plus the shadow at noon (results in a later Asr time).

To change this setting, select your school from the "Asr Calculation" dropdown and update.

### Tomorrow's Prayer Times

After Isha prayer time passes, the page automatically switches to display tomorrow's prayer times. You will see a message indicating "Showing tomorrow's prayer times" and the date will update accordingly.

### For Screen Reader Users

The prayer times page is optimized for screen reader navigation:

- **Announcements you will hear**: "Requesting your location," "Location detected," "Location access denied," and "Prayer time has arrived."
- **Silent updates**: The countdown timer updates every second but does not announce each update, preventing audio interruption. Navigate to the countdown element at any time to hear the current value.
- **Navigation**: Use heading navigation to move between sections. The prayer times are presented in a properly structured data table.

---

## Quran Player

### Overview

The Quran Player allows you to listen to recitations of the Holy Quran with verse-by-verse audio, Arabic text display, and optional English translations. Multiple world-renowned reciters are available, along with several respected English translations.

### How to Access

Navigate to `/quran-listen` on the website. No account is required.

### Selecting What to Listen To

Before playing, configure your preferences:

1. **Surah (Chapter)**: Select from all 114 surahs. The dropdown shows both the surah number and name.

2. **Reciter**: Choose from multiple Qaris including Mishari Rashid al-Afasy (default), Abdul Basit Abdul Samad, Mahmoud Khalil Al-Husary, and others.

3. **Translation**: Select your preferred English translation from options including M.A.S. Abdel Haleem, Saheeh International, Abdullah Yusuf Ali, and others.

4. **Show Translation**: Check this option to display the English translation below the Arabic text. Uncheck to show only Arabic.

### Playback Controls

The player provides a consistent set of controls:

| Control | Function |
|---------|----------|
| Play/Pause | Start or pause the current recitation |
| Seek Backward 30s | Jump back 30 seconds in the audio |
| Seek Backward 10s | Jump back 10 seconds in the audio |
| Seek Forward 10s | Jump forward 10 seconds in the audio |
| Seek Forward 30s | Jump forward 30 seconds in the audio |
| Speed | Adjust playback speed from 0.5x to 2x |

### Auto-Advance Feature

When enabled, the player will automatically proceed to the next surah after the current one completes. This allows for continuous listening without manual intervention.

### Display During Playback

While playing, the page displays:
- Current verse number and total verses (for example, "Verse 5 of 7")
- Arabic text of the current verse in a large, clear font
- English translation of the current verse (if enabled)
- Progress indicator showing your position in the surah

### Your Preferences Are Saved

The player remembers your selections:
- Last surah you were listening to
- Preferred reciter
- Preferred translation
- Translation display preference
- Playback speed

These settings are stored in your browser and will be restored when you return.

### For Screen Reader Users

The Quran Player is designed to minimize interruptions during recitation:

- **Announcements you will hear**: "Loading surah," "Ready, [number] verses, press Play to begin," "Playing," "Paused," and "Surah completed."
- **Silent updates**: Verse text changes and verse numbers update silently to avoid interrupting the Quran recitation. Navigate to these elements when you wish to read them.
- **Focus management**: After loading a surah, focus moves to the Play button so you can immediately begin with Enter or Space.

---

## Islamic Calendar

### Overview

The Islamic Calendar displays the Hijri calendar with day-by-day Gregorian equivalents, navigation between months and years, and a list of important Islamic events. The calendar includes two key features: an "Events Year" selector to find when Islamic events fall in any Gregorian year, and a "Calendar Authority" selector to choose the calculation method that matches your community's practice.

### How to Access

Navigate to `/islam-calendar` on the website. No account is required.

### What You Will See

The calendar page displays:

1. **Current Date Header**: Today's Hijri date (for example, "26 Jumada Al-Akhirah 1447 AH") and today's Gregorian date.

2. **Navigation Controls**: Four dropdown selectors and navigation buttons:
   - **Islamic Month**: Select any of the 12 Hijri months (Muharram through Dhul Hijjah)
   - **Islamic Year**: Select a Hijri year (current year plus or minus 5 years)
   - **Events Year**: Select a Gregorian year (current year through 5 years ahead) to see when Islamic events fall
   - **Calendar Authority**: Select the calculation method for determining when Islamic months begin (see "Choosing a Calendar Authority" below)

3. **Calendar Grid**: A monthly view showing each Hijri day with its corresponding Gregorian date. Today's date is highlighted, and Fridays (Jumu'ah) have a distinct appearance.

4. **Islamic Events**: A section showing events for the selected month, plus an expandable section showing all events for the selected Gregorian year.

### Finding When Ramadan Falls in a Specific Year

The Events Year feature solves a common challenge: determining when Islamic events occur in Gregorian terms without needing to know the Hijri year.

**Example: Finding Ramadan 2026**

1. Navigate to the Islamic Calendar page.
2. Locate the "Events Year" dropdown (the third dropdown in the navigation row).
3. Select "2026" from the dropdown.
4. Click "View."
5. Expand "View All Islamic Events for 2026" to see all events.
6. You will see "First Day of Ramadan: February 18, 2026."

The Events Year dropdown shows years from the previous year through five years ahead, automatically updating as time passes.

### Choosing a Calendar Authority

Different Islamic authorities can differ by one or two days in determining when months begin. This is because some authorities rely on actual moon sighting announcements, while others use astronomical calculations. The Calendar Authority dropdown allows you to select the method that matches your community's practice.

**Available Calendar Authorities:**

| Authority | Description | Best For |
|-----------|-------------|----------|
| **Saudi Arabia (Moon Sighting)** | Based on actual moon sighting announcements by the High Judiciary Council of Saudi Arabia | Communities that follow Saudi moon sighting |
| **Turkey (Diyanet)** | Turkish Diyanet astronomical calculations | Turkish communities and those following Diyanet |
| **Umm Al-Qura (Astronomical)** | Umm Al-Qura calendar based on astronomical calculations | General reference, planning ahead |
| **Mathematical Calculation** | Pure mathematical Hijri-Gregorian conversion | Academic or historical reference |

**How to Use:**

1. Navigate to the Islamic Calendar page.
2. Locate the "Calendar Authority" dropdown (the fourth dropdown in the navigation row).
3. Select your preferred authority.
4. Click "View."
5. All dates on the calendar will now reflect the selected authority's calculations.

**Important:** The Saudi Arabia (Moon Sighting) method is the default and is most commonly followed worldwide. However, your local mosque or community may follow a different authority. Consult your local Islamic center if you are unsure which method your community uses.

### Understanding the Calendar Grid

The calendar displays as a table with:
- **Column headers**: Days of the week from Sunday through Saturday
- **Cell contents**: The Hijri day number (large) and the full Gregorian date (smaller, below)
- **Today's date**: Highlighted with a distinct border and background color
- **Friday (Jumu'ah)**: Displayed with a subtle yellow background

### Quick Navigation

Below the dropdowns, three buttons provide quick navigation:
- **Previous**: Move to the previous Hijri month
- **Today**: Return to the current date and month
- **Next**: Move to the next Hijri month

### Islamic Events Covered

The calendar tracks ten major Islamic events:

| Event | Hijri Date |
|-------|------------|
| Islamic New Year | 1 Muharram |
| Day of Ashura | 10 Muharram |
| Mawlid an-Nabi | 12 Rabi al-Awwal |
| Isra and Mi'raj | 27 Rajab |
| Laylat al-Bara'at | 15 Sha'ban |
| First Day of Ramadan | 1 Ramadan |
| Laylat al-Qadr | 27 Ramadan |
| Eid al-Fitr | 1 Shawwal |
| Day of Arafah | 9 Dhul Hijjah |
| Eid al-Adha | 10 Dhul Hijjah |

### For Screen Reader Users

- The calendar is presented as a properly structured HTML table with row and column headers.
- Navigate cell by cell to hear both the Hijri day and Gregorian date for each day.
- Use heading navigation to move between major sections.
- All navigation controls have descriptive labels.
- The Events Year dropdown includes help text explaining its purpose.
- The Calendar Authority dropdown includes help text explaining that different authorities may differ by one or two days.

### Important Note on Date Accuracy

Hijri dates may vary by one or two days depending on the calendar authority used. The "Calendar Authority" dropdown allows you to select the method that matches your community's practice. Even with the correct authority selected, actual dates for significant events like Ramadan and Eid may be officially announced based on local moon sighting. Always confirm significant dates with your local Islamic center.

---

## Newsletter Subscription

### Overview

Subscribe to the GABLVM newsletter to receive community updates, event announcements, and Islamic content directly in your email.

### How to Subscribe

1. Navigate to `/newsletter` on the website.
2. Enter your email address in the subscription form.
3. Complete the math CAPTCHA (a simple arithmetic problem for accessibility).
4. Select "Subscribe."
5. Check your email for a confirmation message and click the confirmation link.

The newsletter uses double opt-in, meaning you must confirm your subscription via email before receiving newsletters. This protects against unwanted subscriptions.

### Managing Your Subscription

To unsubscribe or manage your preferences, click the "Manage subscription" link in any newsletter email you receive.

---

## Language Translation

### Overview

The website includes a language selector powered by Google Translate, allowing you to view content in over 45 languages.

### How to Use

1. Locate the language selector in the page header area.
2. Select your preferred language from the dropdown.
3. The page content will be translated automatically.
4. Select "English (Original)" to return to the original content.

### Important Considerations

- Translation is provided by Google Translate and may not be perfectly accurate for all content.
- Quranic Arabic text and Islamic terminology may not translate correctly.
- For religious content, always refer to the original Arabic or consult qualified Islamic scholars.
- A disclaimer modal appears when you first use the translator.

---

# Part 2: Content Management

This section is for users with Contributor, Content Editor, or Administrator roles.

## Understanding User Roles

The website uses five user roles with progressively increasing capabilities:

| Role | Capabilities |
|------|--------------|
| **Anonymous** | View published content, use Islamic tools, subscribe to newsletter |
| **Authenticated** | Same as Anonymous, plus access to their own profile |
| **Contributor** | Create draft content (Articles, Events, Resources). Cannot publish. |
| **Content Editor** | Review, edit, and publish content. Manage editorial workflow. |
| **Administrator** | Full site access including user management, configuration, and security |

### Logging In

1. Navigate to `/user/login` on the website.
2. Enter your username and password.
3. Complete the Turnstile CAPTCHA verification.
4. Select "Log in."

If you forget your password, select "Forgot your password?" on the login page and follow the instructions sent to your email.

---

## Creating and Editing Content

### Content Types

The website has five content types:

| Content Type | Purpose | URL Pattern |
|--------------|---------|-------------|
| **Article** | News, announcements, blog posts | `/news/[title]` |
| **Event** | Community gatherings, programs, meetings | `/events/[title]` |
| **Resource** | Documents, guides, external links | `/resources/[title]` |
| **Page** | Static pages (About, Contact, etc.) | `/[title]` |
| **Webform** | Interactive forms | Various |

### Creating New Content

1. Log in to the website.
2. In the administration toolbar, select "Content" then "Add content."
3. Choose the content type you wish to create.
4. Complete the required fields (marked with an asterisk).
5. Add optional content such as images, tags, or attachments.
6. At the bottom, select "Save" (Contributors) or choose a moderation state (Editors/Admins).

### Using the Text Editor

The website uses CKEditor 5 for rich text editing. The editor toolbar provides:

**Text Formatting:**
- Bold, Italic, Underline, Strikethrough
- Superscript, Subscript
- Remove formatting

**Structure:**
- Headings (levels 2 through 6)
- Bulleted and numbered lists
- Block quotes
- Horizontal lines

**Links and Media:**
- Insert links with the Link button
- Insert images with the Image button
- Insert media from the media library
- Insert tables

**Source:**
- Source editing for direct HTML access (use cautiously)

All toolbar buttons are accessible via keyboard. Tab through the toolbar and press Enter or Space to activate a button.

### Text Format Options

When creating content, you may select from two text formats:

- **Basic HTML**: Standard formatting with common HTML tags. Suitable for most content.
- **Full HTML**: Extended formatting including additional HTML elements. For experienced users.

Both formats now include full access to tables, horizontal lines, media embedding, and other features directly in the toolbar.

### Saving and Publishing

**For Contributors:**
- Your content saves as a "Draft" by default.
- An Editor or Administrator must review and publish your content.
- You can continue editing drafts until they are published.

**For Content Editors and Administrators:**
- Select "Published" from the moderation state dropdown to make content live immediately.
- Select "Draft" to save without publishing.
- Select "Archived" to remove content from the site while preserving it.

---

## Managing Events

Events have special fields for scheduling and organization:

### Event Fields

| Field | Description | Required |
|-------|-------------|----------|
| Title | Event name | Yes |
| Date and Time | When the event occurs | Yes |
| Location | Physical address or "Online" | Recommended |
| Description | Full details about the event | Yes |
| Event Status | Confirmed, Tentative, Cancelled | Yes |
| Registration Link | External URL for registration | Optional |

### Event Administration View

Administrators and Content Editors can access a specialized event management view:

1. Navigate to Content in the admin toolbar.
2. Select the "Events" tab.
3. Use filters to find events:
   - Event Timing: All, Past Events, or Upcoming Events
   - Title search
   - Published status
4. Select multiple events for bulk operations (publish, unpublish, delete).

This view displays events in a table with sortable columns and pagination.

---

## Managing Resources

Resources can include either external links or uploaded files.

### Resource Fields

| Field | Description | Required |
|-------|-------------|----------|
| Title | Resource name | Yes |
| Category | Classification (select from list) | Yes |
| Description | What this resource contains | Recommended |
| External Link | URL to external resource | One of these |
| Upload File | Direct file upload (PDF, DOC, etc.) | required |

### Supported File Types

When uploading files, the following formats are accepted:
- Documents: PDF, DOC, DOCX, TXT, RTF
- Spreadsheets: XLS, XLSX
- Presentations: PPT, PPTX

Maximum file size: 50 MB

### Resource Administration View

Similar to events, resources have a dedicated management view:

1. Navigate to Content in the admin toolbar.
2. Select the "Resources" tab.
3. Filter by category, title, or published status.
4. Use bulk operations to manage multiple resources.

---

## Content Moderation Workflow

Content follows an editorial workflow with three states:

### Workflow States

| State | Visibility | Who Can Set |
|-------|------------|-------------|
| **Draft** | Not visible to public | Contributors, Editors, Admins |
| **Published** | Visible to all visitors | Editors, Admins only |
| **Archived** | Not visible to public | Editors, Admins only |

### Workflow Process

1. **Contributor creates content**: Saves as Draft.
2. **Editor reviews**: Checks for accuracy, formatting, accessibility.
3. **Editor publishes**: Changes state to Published.
4. **Content goes live**: Visible on the website.
5. **Future updates**: Content can be archived rather than deleted.

### Finding Content to Review

Content Editors can find pending content:

1. Go to Content in the admin toolbar.
2. Filter by "Moderation state" = "Draft."
3. Review each item and either edit or publish.

---

# Part 3: Administration

This section is for users with the Administrator role.

## Site Administration Overview

Administrators access additional functions through the admin toolbar:

| Menu | Functions |
|------|-----------|
| **Content** | Manage all content, media, files |
| **Structure** | Menus, blocks, content types, taxonomies |
| **Appearance** | Theme settings |
| **Extend** | Enable/disable modules |
| **Configuration** | Site settings, regional settings, module configuration |
| **People** | User accounts and roles |
| **Reports** | System logs, status reports, security dashboard |

---

## User Management

### Creating User Accounts

1. Navigate to People in the admin toolbar.
2. Select "Add user."
3. Enter username, email address, and password.
4. Assign appropriate role(s).
5. Select "Create new account."

The user will receive a welcome email with login instructions.

### Assigning Roles

1. Navigate to People.
2. Find the user account.
3. Select "Edit."
4. Under "Roles," check the appropriate role(s).
5. Save.

Users receive an automatic email notification when their roles change, explaining their new capabilities.

### Password Management

- Users can reset their own passwords via the login page.
- Administrators can set new passwords via the user edit form.
- Passwords must meet minimum security requirements.

---

## Newsletter Administration

The newsletter system uses the Simplenews module.

### Viewing Subscribers

1. Navigate to People in the admin toolbar.
2. Select the "Newsletters" tab.
3. View subscriber list with status (subscribed, unsubscribed, unconfirmed).

### Creating Newsletter Issues

1. Navigate to Content.
2. Select "Add content" then "Simplenews issue."
3. Compose the newsletter content.
4. Select the newsletter to send to.
5. Save and send.

### Subscriber Management

- Subscribers manage their own subscriptions via email links.
- Administrators can manually add or remove subscribers.
- Double opt-in is required for all subscriptions.

---

## Security Monitoring

The website includes a custom security module that monitors for malicious activity.

### Accessing the Security Dashboard

1. Navigate to Reports in the admin toolbar.
2. Select "Security Dashboard."

### What Is Monitored

The security system detects:

| Threat Type | Examples |
|-------------|----------|
| WordPress scanning | Requests for `/wp-admin`, `/wp-login.php` |
| SQL injection attempts | `UNION SELECT`, `'; DROP` in requests |
| XSS attempts | `<script>` tags, `javascript:` URLs |
| Credential file probes | `.env`, `config.yaml`, `secrets.env` |
| Backdoor probes | `alfa.php`, `shell.php`, `adminfuns.php` |
| Excessive failed logins | Multiple failed attempts from same IP |

### Automatic Protection

When threats are detected:

1. The attempt is logged with full details.
2. If thresholds are exceeded, the IP address is automatically banned.
3. Banned IPs cannot access any part of the website.
4. Bans expire automatically after 7 days.

### Manual IP Management

To manually ban or unban IP addresses:

1. Go to Configuration then "Security Settings."
2. Enter the IP address to ban or review the banned list.
3. Provide a reason for manual bans.

### Reviewing Threats

The security dashboard shows:

- Recent threat attempts with timestamps
- Source IP addresses
- Type of threat detected
- Current ban status

Use this information to identify patterns and assess site security.

---

## Backup and Maintenance

### Automated Backups

The website has two backup systems:

**Daily Database Backups:**
- Run automatically via Backup and Migrate module
- Stored in `/home/gablvm/gablvm/backups/backup_migrate/`
- Retention: 7 daily backups

**Weekly Full Site Backups:**
- Run every Saturday at 2 AM
- Include database, files, code, and configuration
- Stored in `/home/gablvm/gablvm/backups/full/`
- Retention: 4 weekly backups

### Cache Management

Clear the site cache when:
- Content changes are not appearing
- Configuration changes seem ineffective
- After module updates

To clear cache:
1. Go to Configuration then "Performance."
2. Select "Clear all caches."

Or via command line: `drush cr`

### Checking System Status

1. Navigate to Reports then "Status report."
2. Review any warnings or errors.
3. Address issues marked in red or yellow.

### Automatic Updates

Security and minor updates run automatically every Sunday at 3 AM. Major version upgrades require manual intervention.

---

# Part 4: Reference

## Accessibility Features

### Standards Compliance

The website follows WCAG 2.1 Level AA guidelines with many Level AAA features.

### For Screen Reader Users

- Logical heading structure on all pages
- Descriptive labels on all form controls
- ARIA live regions for dynamic announcements
- Skip-to-main-content link at the top of each page
- Proper table markup for data tables
- No automatic audio (Quran player requires user action)

### For Keyboard Users

- All functionality available via keyboard
- Visible focus indicators on interactive elements
- Logical tab order
- Standard keyboard shortcuts (Tab, Enter, Space, arrows)
- No keyboard traps

### For Low Vision Users

- High contrast color combinations (minimum 4.5:1 ratio)
- Text resizes up to 200% without loss of functionality
- Respects system high contrast preferences
- Clear focus indicators (3px blue outline)
- No information conveyed by color alone

### Reduced Motion

For users sensitive to motion:
- Animations and transitions respect `prefers-reduced-motion` setting
- Countdown timers update without visual animation
- No flashing or strobing content

---

## Third-Party Services and Privacy

### External APIs

The website uses three external services:

**Quran Foundation API** (api-docs.quran.foundation)
- Provides Quran audio, Arabic text, and English translations
- Authenticated via OAuth2
- No personal information transmitted

**Aladhan API** (aladhan.com)
- Calculates prayer times and Hijri dates
- Receives only location coordinates or city/country names
- No personal information transmitted

**OpenStreetMap Nominatim** (nominatim.openstreetmap.org)
- Converts GPS coordinates to place names
- Receives only coordinates
- No personal information transmitted

### Data Collection

**What is NOT collected:**
- Personal names or identification
- Email addresses (except for newsletter subscription)
- Browsing history or behavior tracking
- IP addresses for profiling

**What IS stored locally in your browser:**
- Location preference (for prayer times)
- Quran player preferences (surah, reciter, translation)
- Language selection

### No Analytics Tracking

The website does not use:
- Google Analytics
- Facebook Pixel
- Advertising trackers
- User profiling tools

### Privacy Policy

For complete privacy information, visit the Privacy Policy page at `/privacy-policy`.

---

## Troubleshooting

### Prayer Times Issues

**Location not detected:**
- Check browser location permissions in Settings
- Use manual city/country entry as alternative
- Try a different browser

**Times seem incorrect:**
- Verify your location is accurate
- Try a different calculation method
- Check your device's date/time settings

### Quran Player Issues

**Audio not playing:**
- Check your device volume and browser tab mute status
- Verify internet connection
- Try a different reciter
- Refresh the page

**Slow loading:**
- Audio files vary in size by reciter
- Wait for loading to complete before playing
- Check internet connection speed

### Calendar Issues

**Events not showing for selected year:**
- Ensure "Events Year" dropdown matches your desired Gregorian year
- Click "View" after changing selections
- Note: Events show for the Gregorian year, not Hijri year

**Dates differ from local announcements:**
- Try selecting a different Calendar Authority that matches your community
- Saudi Arabia (Moon Sighting) follows actual Saudi announcements
- Turkey (Diyanet) follows Turkish Diyanet calculations
- Local dates may still vary by moon sighting - consult your local Islamic center for confirmed dates

### Login Issues

**Cannot log in:**
- Verify username and password
- Complete the CAPTCHA verification
- Use "Forgot password" to reset
- Contact administrator if problems persist

**Account locked:**
- Too many failed attempts triggers temporary lock
- Wait 1 hour or contact administrator

### General Issues

**Page not loading correctly:**
- Clear browser cache (Ctrl/Cmd + Shift + Delete)
- Disable browser extensions temporarily
- Try a different browser
- Check internet connection

**Screen reader not announcing:**
- Update screen reader software
- Check browser compatibility
- Try a different browser
- Report specific issues to administrators

---

## Frequently Asked Questions

### General Questions

**Do I need an account to use the Islamic tools?**
No. Prayer Times, Quran Player, and Islamic Calendar are available to everyone without registration.

**Is this website free?**
Yes, completely free with no paid features.

**What browsers are supported?**
Chrome, Firefox, Safari, Edge, and any modern browser with JavaScript enabled.

**Does this work on mobile devices?**
Yes, all features work on smartphones and tablets, including with mobile screen readers.

### Prayer Times Questions

**Why do prayer times differ from my local mosque?**
Mosques may use different calculation methods or local moon sighting. Select your community's preferred method in Settings, or consult your local Islamic center.

**Why does the page show tomorrow's times?**
After Isha time passes, the page automatically displays tomorrow's prayer schedule.

**Can I use this offline?**
No, prayer time calculation requires internet access to reach the Aladhan API.

### Quran Player Questions

**Can I download the audio?**
Audio files are streamed from the Quran Foundation API and are not available for download through this website.

**Why can't I hear anything?**
Check device volume, browser tab mute status, and internet connection. Try refreshing the page.

**Are the translations accurate?**
Translations are from respected Islamic scholars. However, no translation fully captures the Arabic original. Use translations for understanding, not as a replacement for the Arabic Quran.

### Calendar Questions

**Why are the dates different from what my mosque announced?**
Our calendar uses astronomical calculation. Many communities determine dates by local moon sighting, which can differ by one or two days.

**How do I find when Ramadan starts in 2026?**
Use the "Events Year" dropdown, select "2026," click "View," then check "View All Islamic Events for 2026." You will see "First Day of Ramadan" with its Gregorian date.

### Account Questions

**How do I get a contributor account?**
Contact the site administrator to request an account with contributor privileges.

**How do I change my password?**
Log in, click your username, select "Edit profile," and update your password.

**What if I forget my password?**
Use the "Forgot password" link on the login page. A reset link will be sent to your email.

---

## Credits and Acknowledgments

### Islamic Content Providers

- **Quran Foundation** - Quran audio, text, and translations
- **Aladhan API** - Prayer time calculations and Hijri calendar
- **OpenStreetMap/Nominatim** - Location services

### Development

Built with:
- Drupal 11 content management system
- PHP 8.4
- WCAG 2.1 accessibility guidelines

### Accessibility Testing

Tested with:
- JAWS and NVDA screen readers
- VoiceOver on macOS and iOS
- TalkBack on Android
- Keyboard-only navigation
- Multiple high contrast modes

---

## Version Information

**Guide Last Updated:** December 27, 2025

**Website Platform:** Drupal 11.3.1

**Custom Modules:** gablvm_core, gablvm_security

---

## Contact and Support

For technical support or to report accessibility issues, visit our Contact page or reach out through the information provided on the website.

We take accessibility seriously. If you encounter any barriers using this website, please report them so we can address them promptly.

---

*This guide was developed with accessibility as the primary consideration, written for users of all technical backgrounds, including those using screen readers and other assistive technologies.*

*May Allah accept your prayers and worship.*

**Jazakum Allahu Khairan** (May Allah reward you with goodness)
