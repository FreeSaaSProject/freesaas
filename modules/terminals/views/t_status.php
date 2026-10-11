<?php
declare(strict_types=1);
/** @var array<string,mixed> $payload */
?>
<section class="card">
    <h1><?= e(t('terminals.t.status_title')) ?></h1>
    <pre class="codeblock"><?= e(json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE)) ?></pre>
</section>
