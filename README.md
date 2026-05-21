# GABLVM Quran Foundation Hackathon submission

Custom Drupal 11 modules implementing the Quran Foundation v4 API integration on gablvm.org. This snapshot contains the verified-secret-free focused subset prepared for the QF Hackathon 2026-05-21 submission.

## Contents

- **gablvm_core**: Quran Foundation OAuth (PKCE, Apple Private Relay carve-out for missing email_verified claim), Quran Player (20 reciters, English audio, keyboard shortcuts), Prayer Times, Hijri Calendar, Tafsir Lab, Newsletter, YouTube Livestream Sync, Contact Form, and the full reading-sessions plus activity-days streak integration.
- **gablvm_security**: Threat detection, IP/CIDR banning, CSP headers, search-engine-bot exemption, email notification throttling, race-condition guards on threat recording.
- **gablvm_theme**: Olivero subtheme with WCAG 2.2 AA accessibility (zero violations baseline), manuscript aesthetic, VoiceOver-friendly content rules.

## API integration

OAuth2 with PKCE against Quran Foundation's v4 production environment. Token refresh, scope checks, secure session handling. The id_token branch handles Apple sign-ins which omit the email_verified claim, with a carve-out for @privaterelay.appleid.com addresses (intrinsically verified by Apple). Reading-session position log and activity-day daily-aggregate POSTs feed the Continue Reading panel and the streak engine respectively.

## Accessibility

Built for and by a blind developer using VoiceOver daily. No em-dashes, no markdown tables, no emojis, no box-drawing characters in any user-facing or VoiceOver-read content. Zero WCAG 2.2 AA violations across all pages. See `/quran/about-integration` on gablvm.org for the full integration documentation.

## Author

Yahya Abdikadir, founder of GABLVM (Global Advocacy of Blind and Low Vision Muslims).
