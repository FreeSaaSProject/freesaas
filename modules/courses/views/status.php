<?php
declare(strict_types=1);
/** @var array<string,mixed> $payload */
?>
<h1><?= te('courses.status.title') ?></h1>
<pre class="code-block"><?= e(json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE)) ?></pre>
