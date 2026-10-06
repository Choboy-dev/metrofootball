<?php

declare(strict_types=1);

use App\SocialLinks;

$variant = isset($mfSocialVariant) ? (string) $mfSocialVariant : 'footer';
$urls = SocialLinks::urls();
$classes = 'mf-social' . ($variant !== 'footer' ? ' mf-social--' . preg_replace('/[^a-z0-9-]/', '', $variant) : ' mf-social--footer');
?>
<div class="<?php echo htmlspecialchars($classes, ENT_QUOTES, 'UTF-8'); ?>" aria-label="Follow Metro Football">
  <p class="mf-social__label">Follow us</p>
  <ul class="mf-social__list">
    <li>
      <a class="mf-social__link" href="<?php echo htmlspecialchars($urls['facebook'], ENT_QUOTES, 'UTF-8'); ?>" aria-label="Metro Football on Facebook" target="_blank" rel="noopener noreferrer">
        <svg class="mf-social__icon" viewBox="0 0 24 24" aria-hidden="true"><path fill="currentColor" d="M13.5 22v-8.7h2.9l.44-3.4h-3.34V7.9c0-.98.27-1.65 1.68-1.65h1.8V3.1c-.31 0-1.38-.13-2.63-.13-2.6 0-4.38 1.58-4.38 4.48v2.5H7.5v3.4h3.09V22h3.91Z"/></svg>
      </a>
    </li>
    <li>
      <a class="mf-social__link" href="<?php echo htmlspecialchars($urls['x'], ENT_QUOTES, 'UTF-8'); ?>" aria-label="Metro Football on X" target="_blank" rel="noopener noreferrer">
        <svg class="mf-social__icon" viewBox="0 0 24 24" aria-hidden="true"><path fill="currentColor" d="M17.687 3H20.5l-7.43 8.49L21.5 21h-6.05l-4.74-5.52L5.2 21H2.38l7.94-9.08L2.5 3h6.2l4.28 4.98L17.687 3Zm-2.12 16.2h1.72L8.57 4.67H6.73l8.837 14.53Z"/></svg>
      </a>
    </li>
    <li>
      <a class="mf-social__link" href="<?php echo htmlspecialchars($urls['instagram'], ENT_QUOTES, 'UTF-8'); ?>" aria-label="Metro Football on Instagram" target="_blank" rel="noopener noreferrer">
        <svg class="mf-social__icon" viewBox="0 0 24 24" aria-hidden="true"><path fill="currentColor" d="M7.8 2h8.4A5.8 5.8 0 0 1 22 7.8v8.4A5.8 5.8 0 0 1 16.2 22H7.8A5.8 5.8 0 0 1 2 16.2V7.8A5.8 5.8 0 0 1 7.8 2Zm0 2A3.8 3.8 0 0 0 4 7.8v8.4A3.8 3.8 0 0 0 7.8 20h8.4a3.8 3.8 0 0 0 3.8-3.8V7.8A3.8 3.8 0 0 0 16.2 4H7.8Zm9.65 1.5a1.05 1.05 0 1 1 0 2.1 1.05 1.05 0 0 1 0-2.1ZM12 7a5 5 0 1 1 0 10 5 5 0 0 1 0-10Zm0 2a3 3 0 1 0 0 6 3 3 0 0 0 0-6Z"/></svg>
      </a>
    </li>
  </ul>
</div>
