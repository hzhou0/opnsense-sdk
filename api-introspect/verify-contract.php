<?php

/**
 * verify-contract — check (or re-bless) the base-method contract lock.
 *
 * The emitted spec's soundness depends on the behaviour of a fixed set of
 * OPNsense base methods (see ContractGuard). This boots the framework, reflects
 * that surface, and compares its normalised-AST fingerprints against
 * base-contract.lock.json.
 *
 * Usage:
 *   php verify-contract.php [--opnsense-root <dir>]   # check; exit 1 on drift
 *   php verify-contract.php --update                  # re-bless the lock
 */

declare(strict_types=1);

use OpnsenseApiIntrospect\Bootstrap;
use OpnsenseApiIntrospect\ContractGuard;

$autoload = __DIR__ . '/vendor/autoload.php';
if (!is_file($autoload)) {
    fwrite(STDERR, "Dependencies not installed. Run `composer install` in " . __DIR__ . "\n");
    exit(1);
}
require $autoload;

$opts = ['opnsense-root' => null, 'update' => false, 'help' => false];
for ($i = 1; $i < count($argv); $i++) {
    switch ($argv[$i]) {
        case '--opnsense-root': $opts['opnsense-root'] = $argv[++$i] ?? null; break;
        case '--update': $opts['update'] = true; break;
        case '-h':
        case '--help': $opts['help'] = true; break;
        default:
            fwrite(STDERR, "Unknown option: {$argv[$i]}\n");
            exit(2);
    }
}

if ($opts['help']) {
    fwrite(STDOUT, <<<TXT
    verify-contract — check or re-bless the base-method contract lock

    Usage: php verify-contract.php [options]

      --opnsense-root <dir>  opnsense checkout (default ./opnsense or \$OPNSENSE_ROOT)
      --update               re-bless the lock from the current checkout
      -h, --help             show this help

    TXT);
    exit(0);
}

$bootstrap = Bootstrap::locate($opts['opnsense-root']);
$bootstrap->boot();
$version = detectVersion($bootstrap->root());
fwrite(STDERR, "Booted OPNsense from {$bootstrap->root()} ({$version})\n");

$guard = new ContractGuard();
$lockPath = __DIR__ . '/base-contract.lock.json';

if ($opts['update']) {
    $lock = $guard->buildLock($version);
    file_put_contents($lockPath, json_encode($lock, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . "\n");
    $n = count($lock['contracts']);
    fwrite(STDOUT, "Blessed {$n} base-method contracts -> base-contract.lock.json ({$version})\n");
    exit(0);
}

if (!is_file($lockPath)) {
    fwrite(STDERR, "No lock file. Run `php verify-contract.php --update` to bless the current base methods.\n");
    exit(2);
}

$lock = json_decode((string)file_get_contents($lockPath), true);
$drift = $guard->check($lock['contracts'] ?? []);

if ($drift === []) {
    $n = count($guard->fingerprint());
    fwrite(STDOUT, "OK: {$n} base-method contracts verified (lock blessed against {$lock['opnsense_version']}).\n");
    exit(0);
}

fwrite(STDERR, "Contract drift detected — the emitted spec may be unsound:\n");
foreach ($drift as $key => $d) {
    fwrite(STDERR, "  [{$d['status']}] {$key}: {$d['detail']}\n");
}
fwrite(STDERR, "\nReview each change against the assumption it backs (ReturnAnalyzer / OpenApiEmitter),\n");
fwrite(STDERR, "then `php verify-contract.php --update` to re-bless.\n");
exit(1);

// ---------------------------------------------------------------------------

function detectVersion(string $root): string
{
    $out = @shell_exec(sprintf('git -C %s describe --tags 2>/dev/null', escapeshellarg($root)));
    return $out ? trim($out) : 'unknown';
}