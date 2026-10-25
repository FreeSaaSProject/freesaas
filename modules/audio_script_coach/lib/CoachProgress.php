<?php
declare(strict_types=1);

/**
 * Streaming progress for script-coach HTML iframe mode + stage list for JSON ticks.
 * Same pattern as audio_reports SummaryProgress / bi ReportProgress.
 */
final class CoachProgress
{
    /** @var bool */
    private static $live = false;

    /** @var bool */
    private static $collectOnly = false;

    /** @var float */
    private static $tStart = 0.0;

    /** @var list<array{msg:string,color:string,at:float}> */
    private static $stages = [];

    public static function enabled(): bool
    {
        return self::$live || self::$collectOnly;
    }

    public static function start(string $title = ''): void
    {
        self::$live = true;
        self::$collectOnly = false;
        self::$stages = [];
        self::$tStart = microtime(true);
        @ini_set('zlib.output_compression', '0');
        @ini_set('implicit_flush', '1');
        if (function_exists('apache_setenv')) {
            @apache_setenv('no-gzip', '1');
        }
        while (ob_get_level() > 0) {
            @ob_end_flush();
        }
        if (!headers_sent()) {
            header('Content-Type: text/html; charset=UTF-8');
            header('X-Accel-Buffering: no');
        }
        $t = $title !== '' ? htmlspecialchars($title, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') : 'Coach';
        echo '<!DOCTYPE html><html><head><meta charset="utf-8"><title>' . $t . '</title></head>'
            . '<body style="font-family:monospace;font-size:13px;padding:12px;background:#0f172a;color:#e2e8f0;">';
        echo '<div style="margin-bottom:10px;font-weight:bold;color:#93c5fd;">' . $t . '</div>';
        echo '<div id="asc-log"></div>';
        echo '<script>
function ascAdd(msg, color){
  var d=document.getElementById("asc-log");
  var line=document.createElement("div");
  line.style.color=color||"#e2e8f0";
  line.style.lineHeight="1.45";
  line.textContent=msg;
  d.appendChild(line);
  if(window.parent && typeof window.parent.ascProgressFromFrame==="function"){
    try{ window.parent.ascProgressFromFrame(msg, color||""); }catch(e){}
  }
  window.scrollTo(0, document.body.scrollHeight);
}
</script>' . "\n";
        @flush();
        if (class_exists('StageTiming', false)) {
            StageTiming::reset(microtime(true));
        }
    }

    public static function beginCollect(): void
    {
        self::$live = false;
        self::$collectOnly = true;
        self::$stages = [];
        self::$tStart = microtime(true);
        if (class_exists('StageTiming', false)) {
            StageTiming::reset(microtime(true));
        }
    }

    public static function mark(string $msg, string $color = '#86efac'): void
    {
        $line = '✓ ' . $msg;
        self::$stages[] = [
            'msg' => $line,
            'color' => $color,
            'at' => round(microtime(true) - (self::$tStart > 0 ? self::$tStart : microtime(true)), 3),
        ];
        if (class_exists('StageTiming', false)) {
            StageTiming::mark($msg);
        }
        if (!self::$live) {
            return;
        }
        echo '<script>ascAdd(' . json_encode($line, JSON_UNESCAPED_UNICODE) . ','
            . json_encode($color) . ');</script>' . "\n";
        @flush();
    }

    public static function note(string $msg, string $color = '#e2e8f0'): void
    {
        self::$stages[] = [
            'msg' => $msg,
            'color' => $color,
            'at' => round(microtime(true) - (self::$tStart > 0 ? self::$tStart : microtime(true)), 3),
        ];
        if (!self::$live) {
            return;
        }
        echo '<script>ascAdd(' . json_encode($msg, JSON_UNESCAPED_UNICODE) . ','
            . json_encode($color) . ');</script>' . "\n";
        @flush();
    }

    /**
     * @param array<string,mixed>|null $payload
     */
    public static function done(?array $payload = null): void
    {
        $total = self::$tStart > 0 ? round(microtime(true) - self::$tStart, 3) : 0.0;
        $mem = round(memory_get_peak_usage() / 1024, 1);
        $timingHtml = class_exists('StageTiming', false) ? StageTiming::reportHtml() : '';
        self::note('Done in ' . $total . ' s (' . $mem . ' KB)', '#93c5fd');
        if (self::$live) {
            if ($payload !== null) {
                $json = json_encode($payload, JSON_UNESCAPED_UNICODE);
                echo '<script type="application/json" id="asc-result">' . $json . '</script>' . "\n";
                echo '<script>
if(window.parent && typeof window.parent.ascWorkDone==="function"){
  try{
    var el=document.getElementById("asc-result");
    var data=JSON.parse(el.textContent);
    window.parent.ascWorkDone(data);
  }catch(e){ console&&console.error(e); }
}
</script>' . "\n";
            }
            echo '<div style="margin-top:14px;padding:10px;background:#1e293b;border:1px solid #334155;'
                . 'border-radius:6px;color:#cbd5e1;font-size:12px;">';
            echo '<b>Generation time:</b> [' . $total . ' s] [' . $mem . ' KB]';
            echo $timingHtml;
            echo '</div></body></html>';
            @flush();
        }
        self::$live = false;
        self::$collectOnly = false;
    }

    /** @return list<array{msg:string,color:string,at:float}> */
    public static function collectStages(): array
    {
        return self::$stages;
    }

    public static function reset(): void
    {
        self::$live = false;
        self::$collectOnly = false;
        self::$stages = [];
        self::$tStart = 0.0;
    }
}
