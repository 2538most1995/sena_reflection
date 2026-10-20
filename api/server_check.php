<?php
require_once __DIR__ . '/../config.php';

$user = getAuthenticatedUserOrNull();
if (!$user || ($user['role'] ?? '') !== 'district') {
    http_response_code(403);
    header('Content-Type: text/plain; charset=UTF-8');
    exit('Forbidden');
}

header('Content-Type: text/html; charset=UTF-8');

function checkRunCommand($command)
{
    if (!is_callable('exec')) {
        return ['ok' => false, 'exit' => null, 'output' => ['PHP exec() is disabled']];
    }

    $output = [];
    $exitCode = 0;
    @exec($command . ' 2>&1', $output, $exitCode);
    return ['ok' => $exitCode === 0, 'exit' => $exitCode, 'output' => $output];
}

function checkFirstLine(array $result)
{
    $output = $result['output'] ?? [];
    return trim((string) ($output[0] ?? ''));
}

function checkStatus($ok)
{
    return $ok ? '<span class="ok">OK</span>' : '<span class="fail">FAIL</span>';
}

function checkValue($value)
{
    return htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8');
}

function checkFindManagedChrome()
{
    $cacheDirs = [
        getenv('PUPPETEER_CACHE_DIR') ?: '',
        dirname(__DIR__) . '/tmp/puppeteer-cache',
        getenv('HOME') ? rtrim((string) getenv('HOME'), '/') . '/.cache/puppeteer' : '',
        '/var/www/vhosts/krumost.com/.cache/puppeteer',
    ];

    foreach ($cacheDirs as $cacheDir) {
        if ($cacheDir === '') {
            continue;
        }
        $matches = glob(rtrim($cacheDir, '/') . '/chrome/*/chrome-linux64/chrome') ?: [];
        rsort($matches, SORT_NATURAL);
        foreach ($matches as $match) {
            if (is_file($match) || is_executable($match)) {
                return $match;
            }
        }
    }

    return '';
}

$nodePath = defined('SENA_NODE_BIN') ? (string) SENA_NODE_BIN : '';
$chromePath = defined('SENA_CHROME_BIN') ? (string) SENA_CHROME_BIN : '';
if ($chromePath === '') {
    $chromePath = checkFindManagedChrome();
}

$nodeResult = $nodePath !== ''
    ? checkRunCommand(escapeshellarg($nodePath) . ' --version')
    : checkRunCommand('command -v node && node --version');

$npmResult = $nodePath !== ''
    ? checkRunCommand(escapeshellarg(dirname($nodePath) . '/npm') . ' --version')
    : checkRunCommand('command -v npm && npm --version');

$chromeResult = $chromePath !== ''
    ? checkRunCommand(escapeshellarg($chromePath) . ' --version')
    : ['ok' => false, 'exit' => null, 'output' => ['Chrome path not found']];

$lddResult = $chromePath !== ''
    ? checkRunCommand('ldd ' . escapeshellarg($chromePath) . ' | grep "not found"')
    : ['ok' => false, 'exit' => null, 'output' => ['Chrome path not found']];
$missingLibraries = $lddResult['output'] ?? [];
$lddHasMissing = !empty($missingLibraries);

$checks = [
    ['PHP version', true, PHP_VERSION],
    ['Project path', is_dir(dirname(__DIR__)), dirname(__DIR__)],
    ['open_basedir', true, ini_get('open_basedir') ?: '(none)'],
    ['exec()', is_callable('exec'), is_callable('exec') ? 'enabled' : 'disabled'],
    ['shell_exec()', is_callable('shell_exec'), is_callable('shell_exec') ? 'enabled' : 'disabled'],
    ['proc_open()', function_exists('proc_open'), function_exists('proc_open') ? 'enabled' : 'disabled'],
    ['SENA_NODE_BIN', $nodePath !== '', $nodePath ?: '(empty)'],
    ['Node runnable', $nodeResult['ok'], checkFirstLine($nodeResult) ?: implode("\n", $nodeResult['output'])],
    ['npm runnable', $npmResult['ok'], checkFirstLine($npmResult) ?: implode("\n", $npmResult['output'])],
    ['node_modules/puppeteer', is_dir(dirname(__DIR__) . '/node_modules/puppeteer'), is_dir(dirname(__DIR__) . '/node_modules/puppeteer') ? 'installed' : 'missing'],
    ['node_modules/puppeteer-core', is_dir(dirname(__DIR__) . '/node_modules/puppeteer-core'), is_dir(dirname(__DIR__) . '/node_modules/puppeteer-core') ? 'installed' : 'missing'],
    ['Client PDF mode', true, 'html2canvas + jsPDF in browser'],
    ['Chrome path', $chromePath !== '', $chromePath ?: '(not found)'],
    ['Chrome runnable', $chromeResult['ok'], checkFirstLine($chromeResult) ?: implode("\n", $chromeResult['output'])],
    ['Chrome missing libraries', !$lddHasMissing, $lddHasMissing ? implode("\n", $missingLibraries) : 'none'],
    ['tmp writable', is_writable(dirname(__DIR__) . '/tmp') || @mkdir(dirname(__DIR__) . '/tmp', 0777, true), dirname(__DIR__) . '/tmp'],
];
?>
<!doctype html>
<html lang="th">
<head>
<meta charset="utf-8">
<title>Server Check</title>
<style>
body{font-family:Arial,sans-serif;line-height:1.45;margin:24px;color:#172554}
table{border-collapse:collapse;width:100%;max-width:1100px}
td,th{border:1px solid #dbeafe;padding:8px;text-align:left;vertical-align:top}
th{background:#eff6ff}
.ok{color:#047857;font-weight:700}.fail{color:#b91c1c;font-weight:700}
pre{white-space:pre-wrap;margin:0;font-family:ui-monospace,SFMono-Regular,Menlo,monospace}
</style>
</head>
<body>
<h1>Server Check</h1>
<table>
<thead><tr><th>Check</th><th>Status</th><th>Value</th></tr></thead>
<tbody>
<?php foreach ($checks as [$label, $ok, $value]): ?>
<tr>
<td><?php echo checkValue($label); ?></td>
<td><?php echo checkStatus((bool) $ok); ?></td>
<td><pre><?php echo checkValue($value); ?></pre></td>
</tr>
<?php endforeach; ?>
</tbody>
</table>
</body>
</html>
