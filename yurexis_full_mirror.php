<?php
/**
 * YUREXIS RP — дзеркалювання зі старого хоста прямо в ZIP-архів (стрімінг).
 * Файли НЕ зберігаються на веб-хості: все йде в браузер як один .zip.
 * PHP 7.3+, потрібні розширення curl і zlib.
 *
 * Відкрий: yurexis_zip_mirror.php?key=YRX-2026-MIRROR-9kQ7mP4x
 * Після використання ВИДАЛИ цей файл.
 */

@set_time_limit(0);
@ignore_user_abort(false);
@ini_set('memory_limit', '256M');
@ini_set('display_errors', '0');
error_reporting(E_ALL);

const ACCESS_KEY = 'YRX-2026-MIRROR-9kQ7mP4x';

const OLD_SCHEME_HOST = 'http://fastdl.stormrussia.com';
const NEW_BASE        = 'https://wh33552.web2.maze-tech.ru/';

/* Усі хости, URL яких вважаються «старим сервером» (шлях однаковий) */
$OLD_HOSTS = array('fastdl.stormrussia.com', 'fastdl.stormrussia.com');

const MAX_DISCOVERED_URLS = 50000;
const MAX_TEXT_BYTES      = 25 * 1024 * 1024; // текст більший за це йде без переписування
const ZIP_SOFT_LIMIT      = 3900000000;       // ZIP без ZIP64 тримає до ~4 ГБ

const OLD_BASIC_USER = '';
const OLD_BASIC_PASS = '';

$KNOWN_SEEDS = array(
    'app-config.json','app_info.json','app.json',
    'awards.json','black-pass.json','buttons-config.json','calendar.json',
    'car-colors.json','cars-and-accessories.json','cases.json','cinematic.json',
    'craft-filter-categories.json','craft-item-categories.json','craft.json',
    'donate-items.json','event.json','family_system.json',
    'fractionMenuDocuments.json','fractionMenuStore.json','fractionTask.json',
    'inventory.json','listOfYoutubers.json','market-delivery-filter.json',
    'market-filter-categories.json','market-vip-limits.json','newyear-awards.json',
    'newyear-gifts.json','skins.json','smi_editor_body.json','social-interaction.json',
    'spawnLocation.json','tanpin.json','tune-items.json','tune-screens.json',
    'tune-vinyls.json','vehicles.json','custom-touchscreen.json','gameSettings.json',
    'hash.json','client/hash.json','client/servers.json'
);

/* ====================== ZIP-стрімер ====================== */

class StreamZip
{
    public $offset = 0;
    private $entries = array();
    private $cur = null;
    private $ctx;
    private $crc;
    private $csize = 0;
    private $usize = 0;
    private $dosTime;
    private $dosDate;

    public function __construct()
    {
        $t = getdate();
        $this->dosTime = ($t['hours'] << 11) | ($t['minutes'] << 5) | ($t['seconds'] >> 1);
        $this->dosDate = (($t['year'] - 1980) << 9) | ($t['mon'] << 5) | $t['mday'];
    }

    private function out($s)
    {
        echo $s;
        $this->offset += strlen($s);
        flush();
    }

    public function start($name)
    {
        $name = ltrim(str_replace('\\', '/', $name), '/');
        $this->cur = array('name' => $name, 'offset' => $this->offset);
        $this->ctx = deflate_init(ZLIB_ENCODING_RAW, array('level' => 6));
        $this->crc = hash_init('crc32b');
        $this->csize = 0;
        $this->usize = 0;

        $this->out(pack('VvvvvvVVVvv', 0x04034b50, 20, 0x0808, 8,
            $this->dosTime, $this->dosDate, 0, 0, 0, strlen($name), 0) . $name);
    }

    public function write($data)
    {
        if ($data === '') {
            return;
        }
        hash_update($this->crc, $data);
        $this->usize += strlen($data);
        $c = deflate_add($this->ctx, $data, ZLIB_NO_FLUSH);
        if ($c !== '') {
            $this->csize += strlen($c);
            $this->out($c);
        }
    }

    public function finish()
    {
        $c = deflate_add($this->ctx, '', ZLIB_FINISH);
        if ($c !== '') {
            $this->csize += strlen($c);
            $this->out($c);
        }
        $crc = hexdec(hash_final($this->crc));
        $this->out(pack('VVVV', 0x08074b50, $crc, $this->csize, $this->usize));

        $this->cur['crc'] = $crc;
        $this->cur['csize'] = $this->csize;
        $this->cur['usize'] = $this->usize;
        $this->entries[] = $this->cur;
        $this->cur = null;
    }

    public function addString($name, $content)
    {
        $this->start($name);
        $this->write($content);
        $this->finish();
    }

    public function close()
    {
        $cdStart = $this->offset;
        foreach ($this->entries as $e) {
            $this->out(pack('VvvvvvvVVVvvvvvVV', 0x02014b50, 20, 20, 0x0808, 8,
                $this->dosTime, $this->dosDate, $e['crc'], $e['csize'], $e['usize'],
                strlen($e['name']), 0, 0, 0, 0, 0, $e['offset']) . $e['name']);
        }
        $cdSize = $this->offset - $cdStart;
        $n = count($this->entries);
        $this->out(pack('VvvvvVVv', 0x06054b50, 0, 0, $n, $n, $cdSize, $cdStart, 0));
    }
}

/* ====================== Шляхи й посилання ====================== */

function html($v) { return htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8'); }

function normalizePath($path)
{
    $path = rawurldecode((string)$path);
    $path = str_replace('\\', '/', $path);
    $path = preg_replace('~[?#].*$~', '', $path);
    $parts = array();
    foreach (explode('/', ltrim($path, '/')) as $part) {
        if ($part === '' || $part === '.') continue;
        if ($part === '..') { array_pop($parts); continue; }
        $part = preg_replace('~[\x00-\x1F\x7F]~', '', $part);
        if ($part !== '') $parts[] = $part;
    }
    return implode('/', $parts);
}

function isSkippedPath($path)
{
    $path = '/' . ltrim(normalizePath($path), '/');
    foreach (array('/pay/', '/transactions/', '/.git/', '/.svn/', '/cgi-bin/') as $p) {
        if (stripos($path, $p) === 0) return true;
    }
    return false;
}

function oldUrlForPath($path)
{
    return rtrim(OLD_SCHEME_HOST, '/') . '/' . str_replace('%2F', '/', rawurlencode(normalizePath($path)));
}

function oldHostPathFromUrl($url)
{
    global $OLD_HOSTS;
    $url = html_entity_decode(trim((string)$url), ENT_QUOTES, 'UTF-8');
    if ($url === '') return null;
    if (strpos($url, '//') === 0) $url = 'http:' . $url;
    if (!preg_match('~^https?://~i', $url)) return null;
    $parts = @parse_url($url);
    if (!is_array($parts) || !isset($parts['host'])) return null;
    foreach ($OLD_HOSTS as $h) {
        if (strcasecmp($parts['host'], $h) === 0) {
            return normalizePath(isset($parts['path']) ? $parts['path'] : '');
        }
    }
    return null;
}

function resolveRelativeReference($ref, $currentPath)
{
    $ref = trim(html_entity_decode((string)$ref, ENT_QUOTES, 'UTF-8'));
    $ref = trim($ref, " \t\n\r\0\x0B\"'");
    if ($ref === '' || $ref[0] === '#' ||
        preg_match('~^(data|javascript|mailto|tel):~i', $ref)) return null;

    $abs = oldHostPathFromUrl($ref);
    if ($abs !== null) return $abs;
    if (preg_match('~^https?://~i', $ref) || strpos($ref, '//') === 0) return null;

    $ref = preg_replace('~[?#].*$~', '', $ref);
    if ($ref === '') return null;
    if ($ref[0] === '/') return normalizePath($ref);

    $dir = dirname(normalizePath($currentPath));
    if ($dir === '.') $dir = '';
    return normalizePath(($dir !== '' ? $dir . '/' : '') . $ref);
}

function looksLikeFileReference($v)
{
    $v = trim((string)$v);
    if ($v === '' || strlen($v) > 2000) return false;
    if (oldHostPathFromUrl($v) !== null) return true;
    return (bool)preg_match('~(?:^|/)[^/?#]+\.(?:json|zip|rar|7z|apk|obb|dat|db|sqlite|pak|img|bin|so|txd|dff|bpc|astc|png|jpe?g|webp|gif|svg|mp3|ogg|wav|mp4|webm|ttf|otf|xml|txt|cfg|ini|css|js|html?)(?:[?#].*)?$~i', $v);
}

function walkJsonForReferences($node, $currentPath, &$refs)
{
    if (is_string($node)) {
        if (looksLikeFileReference($node)) {
            $r = resolveRelativeReference($node, $currentPath);
            if ($r !== null) $refs[$r] = true;
        }
        return;
    }
    if (!is_array($node)) return;

    if (isset($node['path'], $node['name']) && is_string($node['path']) && is_string($node['name'])) {
        $r = resolveRelativeReference(rtrim($node['path'], '/') . '/' . ltrim($node['name'], '/'), $currentPath);
        if ($r !== null) $refs[$r] = true;
    }

    foreach ($node as $key => $value) {
        if (is_string($value)) {
            $k = strtolower((string)$key);
            if (preg_match('~(?:url|uri|link|file|path|src|source|download|archive|image|icon|logo|music|sound|video|texture|config|manifest|hash|cdn|server)~', $k)
                || looksLikeFileReference($value)) {
                $r = resolveRelativeReference($value, $currentPath);
                if ($r !== null) $refs[$r] = true;
            }
        }
        walkJsonForReferences($value, $currentPath, $refs);
    }
}

function discoverReferences($content, $currentPath, $ext)
{
    global $OLD_HOSTS;
    $refs = array();

    if ($ext === 'json') {
        $json = json_decode($content, true);
        if (is_array($json)) walkJsonForReferences($json, $currentPath, $refs);
    }

    $hostRegex = implode('|', array_map(function ($h) { return preg_quote($h, '~'); }, $OLD_HOSTS));
    if (preg_match_all('~https?://(?:' . $hostRegex . ')/[^\s"\'<>\\\\]+~i', $content, $m)) {
        foreach ($m[0] as $u) {
            $p = oldHostPathFromUrl(rtrim($u, '.,);]'));
            if ($p !== null) $refs[$p] = true;
        }
    }

    $patterns = array(
        '~(?:src|href|data-src|content)\s*=\s*["\']([^"\']+)["\']~i',
        '~url\(\s*["\']?([^)"\']+)~i',
        '~["\']([^"\']+\.(?:json|zip|rar|7z|apk|obb|dat|db|sqlite|pak|img|bin|bpc|astc|png|jpe?g|webp|gif|svg|mp3|ogg|wav|mp4|webm|ttf|otf|xml|txt|cfg|ini|css|js|html?)(?:\?[^"\']*)?)["\']~i'
    );
    foreach ($patterns as $pt) {
        if (preg_match_all($pt, $content, $m)) {
            foreach ($m[1] as $r0) {
                $r = resolveRelativeReference($r0, $currentPath);
                if ($r !== null) $refs[$r] = true;
            }
        }
    }
    return array_keys($refs);
}

function rewriteText($content, $path)
{
    global $OLD_HOSTS;
    $search = array();
    $replace = array();
    foreach ($OLD_HOSTS as $h) {
        foreach (array('http', 'https') as $sch) {
            $search[]  = $sch . '://' . $h . '/';
            $replace[] = NEW_BASE;
            $search[]  = $sch . ':\/\/' . $h . '\/';
            $replace[] = str_replace('/', '\/', NEW_BASE);
        }
    }
    $out = str_replace($search, $replace, $content);

    if (basename($path) === 'app-config.json') {
        $json = json_decode($out, true);
        if (is_array($json)) {
            $json['cdnUrl'] = rtrim(NEW_BASE, '/') . '/client/';
            $enc = json_encode($json, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT);
            if ($enc !== false) $out = $enc . "\n";
        }
    }
    return $out;
}

function hasTextExtension($path)
{
    $ext = strtolower(pathinfo($path, PATHINFO_EXTENSION));
    return in_array($ext, array('json','txt','xml','html','htm','css','js','csv','ini','cfg','conf',
        'properties','md','yml','yaml','plist','svg'), true);
}

/* ====================== Завантаження файлу в ZIP ====================== */

/**
 * Повертає array(status => ok|missing|fail, http, error, text => string|null)
 * text заповнений, якщо файл текстовий (для пошуку посилань).
 */
function fetchIntoZip($path, StreamZip $zip)
{
    $url = oldUrlForPath($path);
    $textMode = hasTextExtension($path);
    $buffer = '';
    $started = false;
    $lastError = '';
    $code = 0;

    for ($attempt = 1; $attempt <= 2; $attempt++) {
        $buffer = '';
        if ($started) break; // уже почали писати — повторювати не можна

        $ch = curl_init($url);
        $opts = array(
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_MAXREDIRS => 5,
            CURLOPT_CONNECTTIMEOUT => 20,
            CURLOPT_TIMEOUT => 0,
            CURLOPT_USERAGENT => 'YUREXIS-RP-Public-Mirror/3.0',
            CURLOPT_HTTPHEADER => array('Accept: */*', 'Accept-Encoding: identity'),
            CURLOPT_ENCODING => 'identity',
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_SSL_VERIFYHOST => 2,
            CURLOPT_WRITEFUNCTION => function ($c, $data) use (&$buffer, &$started, &$textMode, $zip, $path) {
                $http = (int)curl_getinfo($c, CURLINFO_HTTP_CODE);
                if ($http < 200 || $http >= 300) {
                    return strlen($data); // тіло помилки викидаємо
                }
                if ($textMode) {
                    $buffer .= $data;
                    if (strlen($buffer) > MAX_TEXT_BYTES) {
                        $textMode = false;
                        $zip->start($path);
                        $started = true;
                        $zip->write($buffer);
                        $buffer = '';
                    }
                    return strlen($data);
                }
                if (!$started) {
                    $zip->start($path);
                    $started = true;
                }
                $zip->write($data);
                return strlen($data);
            }
        );
        if (OLD_BASIC_USER !== '') {
            $opts[CURLOPT_HTTPAUTH] = CURLAUTH_BASIC;
            $opts[CURLOPT_USERPWD] = OLD_BASIC_USER . ':' . OLD_BASIC_PASS;
        }
        curl_setopt_array($ch, $opts);

        $ok = curl_exec($ch);
        $lastError = curl_error($ch);
        $code = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        if ($ok !== false) break;
        if (!$started) { usleep(300000); }
    }

    if ($code === 404 || $code === 410) {
        return array('status' => 'missing', 'http' => $code);
    }
    if ($code === 401 || $code === 403) {
        return array('status' => 'fail', 'http' => $code, 'error' => 'Старий сервер заборонив доступ.');
    }
    if ($code < 200 || $code >= 300) {
        return array('status' => 'fail', 'http' => $code,
            'error' => $lastError !== '' ? $lastError : ('HTTP ' . $code));
    }

    if ($started) {
        $zip->finish();
        $res = array('status' => 'ok', 'http' => $code, 'text' => null);
        if ($lastError !== '') {
            $res['error'] = 'Файл міг бути обірваний: ' . $lastError;
        }
        return $res;
    }

    if ($textMode) {
        if ($lastError !== '') {
            return array('status' => 'fail', 'http' => $code, 'error' => $lastError);
        }
        $ext = strtolower(pathinfo($path, PATHINFO_EXTENSION));
        $refs = discoverReferences($buffer, $path, $ext);
        $rewritten = rewriteText($buffer, $path);
        $zip->addString($path, $rewritten);
        return array('status' => 'ok', 'http' => $code, 'refs' => $refs,
            'rewritten' => ($rewritten !== $buffer), 'size' => strlen($rewritten));
    }

    // порожній файл
    $zip->addString($path, '');
    return array('status' => 'ok', 'http' => $code, 'text' => null);
}

/* ====================== Основний процес ====================== */

function runMirror($seeds)
{
    $zip = new StreamZip();

    $queue = array();
    $seen = array();
    foreach ($seeds as $s) {
        $s = normalizePath($s);
        if ($s !== '' && !isSkippedPath($s) && !isset($seen[$s])) {
            $queue[] = $s;
            $seen[$s] = true;
        }
    }

    $report = array(
        'generated_at' => gmdate('c'),
        'old_host' => OLD_SCHEME_HOST . '/',
        'new_base' => NEW_BASE,
        'downloaded' => array(),
        'missing' => array(),
        'failed' => array(),
        'rewritten' => array(),
        'stopped_by_size_limit' => false
    );

    while (!empty($queue)) {
        if ($zip->offset > ZIP_SOFT_LIMIT) {
            $report['stopped_by_size_limit'] = true;
            $report['unprocessed'] = $queue;
            break;
        }
        if (connection_aborted()) {
            return;
        }

        $path = array_shift($queue);
        $res = fetchIntoZip($path, $zip);

        if ($res['status'] === 'ok') {
            $report['downloaded'][] = $path;
            if (!empty($res['rewritten'])) $report['rewritten'][] = $path;
            if (!empty($res['error']))     $report['failed'][$path] = $res['error'];

            if (!empty($res['refs'])) {
                foreach ($res['refs'] as $ref) {
                    if ($ref === '' || isSkippedPath($ref) || strlen($ref) > 1000) continue;
                    if (isset($seen[$ref])) continue;
                    if (count($seen) >= MAX_DISCOVERED_URLS) continue;
                    $seen[$ref] = true;
                    $queue[] = $ref;
                }
            }
        } elseif ($res['status'] === 'missing') {
            $report['missing'][$path] = 'HTTP ' . $res['http'];
        } else {
            $report['failed'][$path] = array('http' => $res['http'], 'error' => isset($res['error']) ? $res['error'] : '');
        }
    }

    $report['downloaded_count'] = count($report['downloaded']);
    $zip->addString('_yurexis-mirror-report.json',
        json_encode($report, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT) . "\n");
    $zip->close();
}

/* ====================== Вхід ====================== */

$key = isset($_GET['key']) ? (string)$_GET['key'] : '';
if (!hash_equals(ACCESS_KEY, $key)) {
    http_response_code(403);
    header('Content-Type: text/plain; charset=UTF-8');
    exit("403 Forbidden\n");
}

if (!function_exists('curl_init') || !function_exists('deflate_init')) {
    header('Content-Type: text/plain; charset=UTF-8');
    exit("Потрібні розширення PHP: curl і zlib (PHP 7.0+).\n");
}

if (isset($_GET['action']) && $_GET['action'] === 'download') {
    @ini_set('zlib.output_compression', '0');
    @ini_set('output_buffering', '0');
    while (ob_get_level() > 0) { @ob_end_clean(); }

    header('Content-Type: application/zip');
    header('Content-Disposition: attachment; filename="yurexis-mirror-' . gmdate('Ymd-His') . '.zip"');
    header('Cache-Control: no-store');
    header('X-Accel-Buffering: no');
    header('Content-Encoding: identity');

    runMirror($KNOWN_SEEDS);
    exit;
}

header('Content-Type: text/html; charset=UTF-8');
?>
<!doctype html>
<html lang="uk">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<title>YUREXIS RP — ZIP-дзеркало</title>
<style>
body{font-family:Arial,sans-serif;background:#101114;color:#f1f3f5;margin:0;padding:18px}
.card{max-width:760px;margin:auto;background:#1b1d22;border:1px solid #30343c;border-radius:14px;padding:20px}
a.btn{display:inline-block;background:#4c82ff;color:#fff;text-decoration:none;border-radius:10px;padding:14px 20px;font-weight:700}
code{background:#111318;padding:2px 5px;border-radius:5px;word-break:break-all}
.muted{color:#aeb4bf}
</style>
</head>
<body>
<div class="card">
    <h1>YUREXIS RP — завантаження архівом</h1>
    <p class="muted">Джерело: <code><?=html(OLD_SCHEME_HOST)?>/</code>. Файли не зберігаються на хостингу — ZIP стрімиться прямо в твій браузер.</p>
    <p><a class="btn" href="?key=<?=html(rawurlencode(ACCESS_KEY))?>&action=download">Скачати ZIP на пристрій</a></p>
    <p class="muted">Не закривай вкладку, доки браузер не покаже, що завантаження завершене.
    Каталог <code>/pay/</code> пропускається. Звіт лежить усередині архіву: <code>_yurexis-mirror-report.json</code>.</p>
    <p><strong>Після завершення видали цей PHP-файл із хостингу.</strong></p>
</div>
</body>
</html>