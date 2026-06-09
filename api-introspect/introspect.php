<?php

/**
 * api-introspect — standalone OPNsense API inventory generator.
 *
 * Boots the OPNsense MVC framework from the sibling `opnsense` git submodule
 * (no files are added inside that checkout) and walks the live controller /
 * model classes via reflection to produce a machine-readable API inventory.
 *
 * Usage:
 *   php introspect.php [options]
 *
 * Options:
 *   --out <file>           Write the OpenAPI 3 document to <file> (default stdout).
 *   --opnsense-root <dir>  Path to the opnsense checkout (default: ./opnsense,
 *                          or $OPNSENSE_ROOT).
 *   --module <Name>        Restrict to a single module dir (e.g. Firewall).
 *   -h, --help             Show this help.
 */

declare(strict_types=1);

use OpnsenseApiIntrospect\AclIndex;
use OpnsenseApiIntrospect\Bootstrap;
use OpnsenseApiIntrospect\ContractGuard;
use OpnsenseApiIntrospect\ControllerDiscovery;
use OpnsenseApiIntrospect\Emitter\OpenApiEmitter;
use OpnsenseApiIntrospect\ModelSchemaExtractor;
use OpnsenseApiIntrospect\NonModelScanner;
use OpnsenseApiIntrospect\ReturnAnalyzer;
use OpnsenseApiIntrospect\RouteDeriver;
use OpnsenseApiIntrospect\VerbDetector;

$autoload = __DIR__ . '/vendor/autoload.php';
if (!is_file($autoload)) {
    fwrite(STDERR, "Dependencies not installed. Run `composer install` in " . __DIR__ . "\n");
    exit(1);
}
require $autoload;

$opts = parseArgs($argv);
if ($opts['help']) {
    fwrite(STDOUT, helpText());
    exit(0);
}

$bootstrap = Bootstrap::locate($opts['opnsense-root']);
$config = $bootstrap->boot();
fwrite(STDERR, "Booted OPNsense from {$bootstrap->root()}\n");

// Pre-flight: the spec's soundness depends on the behaviour of a fixed set of
// base methods. If any has drifted from the blessed lock, the emitted shapes
// may be silently wrong, so fail fast unless explicitly overridden.
guardContract($opts['allow-contract-drift']);

$discovery = new ControllerDiscovery($config);
$acl = new AclIndex($config);
$verbs = new VerbDetector();
$models = new ModelSchemaExtractor();
$scanner = new NonModelScanner();
$returns = new ReturnAnalyzer();

$endpoints = [];
$stats = [
    'controllers' => 0,
    'endpoints' => 0,
    'skipped' => 0,
    'dynamic' => 0,
    // How each endpoint's direction was determined (provenance distribution).
    'direction_source' => [],
    'direction' => [],
];

foreach ($discovery->discover() as $controller) {
    if ($opts['module'] !== null && strcasecmp($controller->module, $opts['module']) !== 0) {
        continue;
    }
    $stats['controllers']++;

    if (!$controller->instantiable && !$controller->dynamic && $controller->actions === []) {
        $stats['skipped']++;
        continue;
    }

    // Model/service schema is per-controller; extract once.
    $modelSchema = null;
    if (in_array($controller->kind, [ControllerDiscovery::KIND_MODEL, ControllerDiscovery::KIND_SERVICE], true)) {
        $modelSchema = $models->extract($controller);
    }

    if ($controller->dynamic && $controller->actions === []) {
        // __call dispatch: actions are not statically enumerable, so there is
        // nothing to emit. Counted for the run stats only.
        $stats['dynamic']++;
        continue;
    }

    foreach ($controller->actions as $action) {
        $pathParams = pathParams($action);
        $verbInfo = $verbs->detect($action);

        $path = RouteDeriver::derive(
            $controller->module,
            $controller->shortName,
            $action->name,
            array_column($pathParams, 'name')
        );

        // Recover the return shape from the action body. A resolved base-wrapper
        // call gives an authoritative direction (and often the exact body key /
        // model path); otherwise fall back to the method-name heuristic.
        $ret = $returns->analyze($action);
        $direction = $ret['direction'] ?? classifyDirection($action->name);

        $endpoint = [
            'path' => $path,
            'operationId' => operationId($controller, $action),
            'module' => $controller->module,
            'controller' => $controller->shortName,
            'action' => $action->name,
            'verbs' => $verbInfo['verbs'],
            'verbs_heuristic' => $verbInfo['heuristic'],
            'direction' => $direction,
            'direction_source' => $ret['direction'] !== null ? $ret['source'] : 'name-heuristic',
            'grid' => $ret['grid'],
            'pathParams' => $pathParams,
            'privileges' => $acl->match($path),
        ];
        if ($ret['literalKeys'] !== []) {
            $endpoint['returnKeys'] = $ret['literalKeys'];
        }

        if ($modelSchema !== null) {
            // Per-action copy: when the body path/key was parsed from the action,
            // use it directly instead of the per-controller categorysource guess.
            $epModel = $modelSchema;
            if (str_starts_with($ret['source'], 'base-action:')) {
                // Inherited get/setAction serialise the whole model node tree
                // under internalModelName (not a single collection row).
                $epModel['payloadKey'] = $epModel['bodyKey'];
                $epModel['payload'] = $models->wholeModelFields($epModel['model']);
            } elseif ($ret['path'] !== null && ($pf = $models->fieldsForPath($epModel['model'], $ret['path'])) !== null) {
                $epModel['payloadKey'] = $ret['key'] ?? $pf[0];
                $epModel['payload'] = $pf[1];
            } elseif ($ret['key'] !== null) {
                $epModel['payloadKey'] = $ret['key'];
            }
            $endpoint['model'] = $epModel;
        } elseif ($controller->kind === ControllerDiscovery::KIND_PLAIN) {
            $endpoint['bodyScan'] = $scanner->scan($action);
        }

        $endpoints[] = $endpoint;
        $stats['endpoints']++;
        $stats['direction_source'][$endpoint['direction_source']] =
            ($stats['direction_source'][$endpoint['direction_source']] ?? 0) + 1;
        $stats['direction'][$direction] = ($stats['direction'][$direction] ?? 0) + 1;
    }
}

// Stable ordering so the distributions read deterministically in the output.
ksort($stats['direction_source']);
ksort($stats['direction']);

usort($endpoints, static fn($a, $b) => strcmp($a['path'], $b['path']));

$meta = [
    'generator' => 'api-introspect',
    'generated_at' => gmdate('c'),
    'opnsense_root' => $bootstrap->root(),
    'opnsense_version' => detectVersion($bootstrap->root()),
    'acl_privileges_indexed' => $acl->count(),
    'stats' => $stats,
];

$output = (new OpenApiEmitter())->emit($endpoints, $meta);

if ($opts['out'] !== null) {
    file_put_contents($opts['out'], $output);
    fwrite(STDERR, "Wrote {$stats['endpoints']} endpoints to {$opts['out']}\n");
} else {
    fwrite(STDOUT, $output);
}

// ---------------------------------------------------------------------------

/** @return array<int,array{name:string,optional:bool}> */
function pathParams(ReflectionMethod $action): array
{
    $params = [];
    foreach ($action->getParameters() as $p) {
        $params[] = [
            'name' => RouteDeriver::toSnake($p->getName()),
            'optional' => $p->isOptional(),
        ];
    }
    return $params;
}

function operationId(object $controller, ReflectionMethod $action): string
{
    return "{$controller->module}.{$controller->shortName}.{$action->name}";
}

/**
 * Classify an action by the body direction its base-controller wrapper implies:
 *   read    -> returns the model node shape (response body, no request body)
 *   write   -> consumes the model node shape (request body), returns a status
 *   command -> state change keyed by uuid (no model body), returns a status
 *   unknown -> custom action; shape not statically derivable
 */
function classifyDirection(string $action): string
{
    $name = lcfirst(substr($action, 0, -strlen('Action')));
    $prefixes = [
        'read' => ['get', 'search', 'list', 'find', 'export', 'download', 'dump', 'show'],
        'write' => ['set', 'add', 'update', 'save', 'import', 'upload', 'store'],
        'command' => [
            'del', 'delete', 'remove', 'toggle', 'move', 'apply', 'reconfigure',
            'restart', 'start', 'stop', 'reload', 'enable', 'disable', 'sync',
        ],
    ];
    foreach ($prefixes as $direction => $list) {
        foreach ($list as $p) {
            if (str_starts_with($name, $p)) {
                return $direction;
            }
        }
    }
    return 'unknown';
}

function detectVersion(string $root): string
{
    $out = @shell_exec(sprintf('git -C %s describe --tags 2>/dev/null', escapeshellarg($root)));
    return $out ? trim($out) : 'unknown';
}

/**
 * Verify the base-method contracts against the blessed lock. Aborts on drift
 * unless overridden, since drift can make the emitted spec silently unsound.
 */
function guardContract(bool $allowDrift): void
{
    $guard = new ContractGuard();
    $lockPath = __DIR__ . '/base-contract.lock.json';
    if (!is_file($lockPath)) {
        fwrite(STDERR, "No base-contract lock. Run `php verify-contract.php --update` to bless the base methods.\n");
        if (!$allowDrift) {
            exit(3);
        }
        return;
    }
    $lock = json_decode((string)file_get_contents($lockPath), true);
    $drift = $guard->check($lock['contracts'] ?? []);
    if ($drift === []) {
        return;
    }
    fwrite(STDERR, "Base-method contract drift — emitted shapes may be unsound:\n");
    foreach ($drift as $key => $d) {
        fwrite(STDERR, "  [{$d['status']}] {$key}: {$d['detail']}\n");
    }
    fwrite(STDERR, "Re-verify, then `php verify-contract.php --update` to re-bless"
        . ($allowDrift ? " (continuing: --allow-contract-drift).\n" : ", or pass --allow-contract-drift.\n"));
    if (!$allowDrift) {
        exit(3);
    }
}

/** @return array{out:?string,opnsense-root:?string,module:?string,allow-contract-drift:bool,help:bool} */
function parseArgs(array $argv): array
{
    $o = ['out' => null, 'opnsense-root' => null, 'module' => null, 'allow-contract-drift' => false, 'help' => false];
    for ($i = 1; $i < count($argv); $i++) {
        switch ($argv[$i]) {
            case '--out': $o['out'] = $argv[++$i] ?? null; break;
            case '--opnsense-root': $o['opnsense-root'] = $argv[++$i] ?? null; break;
            case '--module': $o['module'] = $argv[++$i] ?? null; break;
            case '--allow-contract-drift': $o['allow-contract-drift'] = true; break;
            case '-h':
            case '--help': $o['help'] = true; break;
            default:
                fwrite(STDERR, "Unknown option: {$argv[$i]}\n");
                exit(2);
        }
    }
    return $o;
}

function helpText(): string
{
    return <<<TXT
    api-introspect — OPNsense API inventory generator

    Usage: php introspect.php [options]

      --out <file>           Write the OpenAPI 3 document to <file> (default stdout)
      --opnsense-root <dir>  opnsense checkout (default ./opnsense or \$OPNSENSE_ROOT)
      --module <Name>        Restrict to a single module dir (e.g. Firewall)
      --allow-contract-drift Continue even if base-method contracts have drifted
      -h, --help             Show this help

    TXT;
}
