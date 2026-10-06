<?php

declare(strict_types=1);

/**
 * Article share row + author byline (Nairametrics-inspired).
 *
 * Expects:
 * - string $canonical
 * - string $shareTitle
 * - int $readMins
 * - array $author from GuideAuthor::default()
 */

use App\GuideAuthor;

if (!isset($author) || !is_array($author)) {
    $author = GuideAuthor::default();
}

$canonical = (string) ($canonical ?? '');
$shareTitle = (string) ($shareTitle ?? '');
$readMins = max(1, (int) ($readMins ?? 1));

$shareUrl = rawurlencode($canonical);
$shareText = rawurlencode($shareTitle);

$xUrl = 'https://twitter.com/intent/tweet?url=' . $shareUrl . '&text=' . $shareText;
$fbUrl = 'https://www.facebook.com/sharer/sharer.php?u=' . $shareUrl;
$liUrl = 'https://www.linkedin.com/sharing/share-offsite/?url=' . $shareUrl;
$waUrl = 'https://api.whatsapp.com/send?text=' . rawurlencode($shareTitle . ' ' . $canonical);

$esc = static function (string $value): string {
    return htmlspecialchars($value, ENT_QUOTES, 'UTF-8');
};
?>
<div class="fg-byline" data-fg-byline>
  <div class="fg-share" role="group" aria-label="Share this guide">
    <a class="fg-share__btn fg-share__btn--x" href="<?= $esc($xUrl) ?>" target="_blank" rel="noopener noreferrer" aria-label="Share on X">
      <svg viewBox="0 0 24 24" aria-hidden="true"><path fill="currentColor" d="M18.244 2.25h3.308l-7.227 8.26 8.502 11.24H16.17l-4.714-6.231-5.401 6.231H2.744l7.727-8.913L1.254 2.25H8.08l4.253 5.622L18.244 2.25zm-1.161 17.52h1.833L7.084 4.126H5.117L17.083 19.77z"/></svg>
    </a>
    <a class="fg-share__btn fg-share__btn--facebook" href="<?= $esc($fbUrl) ?>" target="_blank" rel="noopener noreferrer" aria-label="Share on Facebook">
      <svg viewBox="0 0 24 24" aria-hidden="true"><path fill="currentColor" d="M22 12.07C22 6.48 17.52 2 11.93 2S1.86 6.48 1.86 12.07c0 5.02 3.66 9.18 8.44 9.93v-7.03H7.9v-2.9h2.4V9.84c0-2.37 1.4-3.69 3.56-3.69 1.03 0 2.12.19 2.12.19v2.34h-1.2c-1.18 0-1.55.74-1.55 1.49v1.79h2.64l-.42 2.9h-2.22V22c4.78-.75 8.44-4.91 8.44-9.93z"/></svg>
    </a>
    <a class="fg-share__btn fg-share__btn--linkedin" href="<?= $esc($liUrl) ?>" target="_blank" rel="noopener noreferrer" aria-label="Share on LinkedIn">
      <svg viewBox="0 0 24 24" aria-hidden="true"><path fill="currentColor" d="M20.45 20.45h-3.55v-5.57c0-1.33-.02-3.04-1.85-3.04-1.85 0-2.14 1.45-2.14 2.94v5.67H9.36V9h3.41v1.56h.05c.47-.9 1.64-1.85 3.37-1.85 3.6 0 4.27 2.37 4.27 5.46v6.28zM5.34 7.43a2.06 2.06 0 1 1 0-4.12 2.06 2.06 0 0 1 0 4.12zM7.12 20.45H3.56V9h3.56v11.45zM22 0H2C.9 0 0 .9 0 2v20c0 1.1.9 2 2 2h20c1.1 0 2-.9 2-2V2c0-1.1-.9-2-2-2z"/></svg>
    </a>
    <a class="fg-share__btn fg-share__btn--whatsapp" href="<?= $esc($waUrl) ?>" target="_blank" rel="noopener noreferrer" aria-label="Share on WhatsApp">
      <svg viewBox="0 0 24 24" aria-hidden="true"><path fill="currentColor" d="M17.47 14.38c-.3-.15-1.77-.87-2.04-.97-.27-.1-.47-.15-.67.15-.2.3-.77.97-.95 1.17-.17.2-.35.22-.65.07-.3-.15-1.26-.46-2.4-1.48-.89-.79-1.49-1.77-1.66-2.07-.17-.3-.02-.46.13-.61.13-.13.3-.35.45-.52.15-.17.2-.3.3-.5.1-.2.05-.37-.02-.52-.07-.15-.67-1.61-.92-2.21-.24-.58-.49-.5-.67-.51h-.57c-.2 0-.52.07-.79.37-.27.3-1.04 1.02-1.04 2.48s1.06 2.87 1.21 3.07c.15.2 2.1 3.2 5.08 4.49.71.31 1.26.49 1.69.63.71.23 1.36.2 1.87.12.57-.09 1.77-.72 2.02-1.42.25-.7.25-1.3.17-1.42-.07-.12-.27-.2-.57-.35zM12.04 21.8h-.01a9.8 9.8 0 0 1-5-.14l-.36-.13-3.72.98 1-3.63-.24-.37a9.83 9.83 0 0 1-1.51-5.26c0-5.43 4.42-9.85 9.86-9.85a9.8 9.8 0 0 1 6.97 2.89 9.8 9.8 0 0 1 2.88 6.97c0 5.43-4.42 9.85-9.85 9.85zm8.41-18.26A11.77 11.77 0 0 0 12.04 0C5.45 0 .1 5.35.1 11.94c0 2.1.55 4.15 1.6 5.96L0 24l6.26-1.64a11.9 11.9 0 0 0 5.77 1.47h.01c6.59 0 11.94-5.35 11.94-11.94 0-3.19-1.24-6.19-3.5-8.45z"/></svg>
    </a>
    <button type="button" class="fg-share__btn fg-share__btn--copy" data-fg-copy-link data-url="<?= $esc($canonical) ?>" aria-label="Copy link">
      <svg viewBox="0 0 24 24" aria-hidden="true" data-fg-copy-icon><path fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" d="M10 13a5 5 0 0 0 7.54.54l3-3a5 5 0 0 0-7.07-7.07l-1.72 1.71M14 11a5 5 0 0 0-7.54-.54l-3 3a5 5 0 0 0 7.07 7.07l1.71-1.71"/></svg>
      <span class="fg-share__copied" data-fg-copied hidden>Copied</span>
    </button>
  </div>

  <div class="fg-author-bar">
    <a class="fg-author" href="<?= $esc((string) $author['url']) ?>">
      <img
        class="fg-author__avatar"
        src="<?= $esc((string) $author['image']) ?>"
        alt="<?= $esc((string) $author['name']) ?>"
        width="56"
        height="56"
        loading="eager"
        decoding="async"
      >
        <span class="fg-author__text">
        <span class="fg-author__name"><?= $esc((string) $author['name']) ?></span>
      </span>
    </a>
    <p class="fg-author-bar__read"><?= (int) $readMins ?> min read</p>
  </div>
</div>
