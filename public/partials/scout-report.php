<?php
/**
 * Written FPL summary card for player and club pages.
 *
 * Expects $scoutReport (array from PlayerScoutReport) and $scoutTitle (string).
 */
if (empty($scoutReport) || empty($scoutReport['paragraphs'])) {
    return;
}
$scoutEsc = static fn (string $v): string => htmlspecialchars($v, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
$scoutVerdict = (string) ($scoutReport['verdict'] ?? '');
$scoutUpdated = (string) ($scoutReport['updated'] ?? '');
$scoutIso = $scoutUpdated !== '' ? date('Y-m-d', strtotime($scoutUpdated) ?: time()) : date('Y-m-d');
?>
<section class="mf-scout<?= $scoutVerdict !== '' ? ' mf-scout--has-call' : '' ?>" data-mf-scout aria-labelledby="mf-scout-title">
  <div class="mf-scout__main">
    <h2 class="mf-scout__title" id="mf-scout-title"><?= $scoutEsc((string) $scoutTitle) ?></h2>
    <a class="mf-scout__byline" href="/author/chosen-esiwe">
      <img class="mf-scout__avatar" src="/images/authors/chosen-esiwe.jpg" alt="" width="40" height="40" loading="lazy" decoding="async">
      <span class="mf-scout__who">
        <span class="mf-scout__name">Chosen Esiwe</span>
        <span class="mf-scout__meta">Founder, Metro Football · Updated <time datetime="<?= $scoutEsc($scoutIso) ?>"><?= $scoutEsc($scoutUpdated) ?></time></span>
      </span>
    </a>
    <div class="mf-scout__text">
      <?php foreach ($scoutReport['paragraphs'] as $scoutPara): ?>
        <p><?= $scoutEsc((string) $scoutPara) ?></p>
      <?php endforeach; ?>
    </div>
    <p class="mf-scout__source">Every figure here comes from the official Fantasy Premier League data and refreshes as the week goes on. We judge minutes first, then form against others in the same position, price movement and the next five fixtures.</p>
  </div>
  <?php if ($scoutVerdict !== ''): ?>
    <aside class="mf-scout__call mf-scout__call--<?= $scoutEsc($scoutVerdict) ?>" aria-label="Our call">
      <p class="mf-scout__call-head">Our call</p>
      <p class="mf-scout__call-word"><?= $scoutEsc((string) ($scoutReport['verdict_label'] ?? '')) ?></p>
      <p class="mf-scout__call-text"><?= $scoutEsc((string) ($scoutReport['verdict_line'] ?? '')) ?></p>
    </aside>
  <?php endif; ?>
</section>
