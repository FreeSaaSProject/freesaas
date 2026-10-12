<?php
declare(strict_types=1);
/** @var array<string,mixed> $legend */
?>
<p class="lede"><?= te('bi.family.legend_page_lede') ?></p>
<p><a href="/bi/families/new"><?= te('bi.index.new_family') ?></a></p>
<pre class="bi-legend-pre mono"><?= e(json_encode($legend, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT) ?: '{}') ?></pre>
