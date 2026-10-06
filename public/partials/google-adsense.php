<?php

declare(strict_types=1);

use App\Env;

$clientId = trim((string) Env::get('ADSENSE_CLIENT_ID', ''));
if ($clientId === '') {
    return;
}
?>
<script async src="https://pagead2.googlesyndication.com/pagead/js/adsbygoogle.js?client=ca-pub-9101848035950927"
     crossorigin="anonymous"></script>
