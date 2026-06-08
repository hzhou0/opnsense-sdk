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
 *   --out <file>           Write output to <file> instead of stdout.
 *   --openapi              Emit an OpenAPI 3 document instead of the raw
 *                          inventory JSON.
 *   --opnsense-root <dir>  Path to the opnsense checkout (default: ./opnsense,
 *                          or $OPNSENSE_ROOT).
 *   --module <Name>        Restrict to a single module dir (e.g. Firewall).
 *   -h, --help             Show this help.
 */

declare(strict_types=1);

use OpnsenseApiIntrospect\AclIndex;
use OpnsenseApiIntrospect\Bootstrap;
use OpnsenseApiIntrospect\ControllerDiscovery;
use OpnsenseApiIntrospect\Emitter\JsonEmitter;
use OpnsenseApiIntrospect\Emitter\OpenApiEmitter;
use OpnsenseApiIntrospect\ModelSchemaExtractor;
use OpnsenseApiIntrospect\NonModelScanner;
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

$discovery = new ControllerDiscovery($config);
$acl = new AclIndex($config);
$verbs = new VerbDetector();
$models = new ModelSchemaExtractor();
$scanner = new NonModelScanner();

$endpoints = [];
$stats = ['controllers' => 0, 'endpoints' => 0, 'skipped' => 0, 'dynamic' => 0];

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
        $stats['dynamic']++;
        $endpoints[] = [
            'path' => RouteDeriver::derive($controller->module, $controller->shortName, 'indexAction'),
            'module' => $controller->module,
            'controller' => $controller->shortName,
            'kind' => $controller->kind,
            'dynamic' => true,
            'note' => 'dynamic __call dispatch; actions not statically enumerable',
        ];
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

        $endpoint = [
            'path' => $path,
            'operationId' => operationId($controller, $action),
            'module' => $controller->module,
            'vendor' => $controller->vendor,
            'controller' => $controller->shortName,
            'action' => $action->name,
            'kind' => $controller->kind,
            'verbs' => $verbInfo['verbs'],
            'verbs_heuristic' => $verbInfo['heuristic'],
            'direction' => classifyDirection($action->name),
            'direction_heuristic' => true,
            'pathParams' => $pathParams,
            'privileges' => $acl->match($path),
        ];

        if ($modelSchema !== null) {
            $endpoint['model'] = $modelSchema;
        } elseif ($controller->kind === ControllerDiscovery::KIND_PLAIN) {
            $endpoint['bodyScan'] = $scanner->scan($action);
        }

        $endpoints[] = $endpoint;
        $stats['endpoints']++;
    }
}

usort($endpoints, static fn($a, $b) => strcmp($a['path'], $b['path']));

$meta = [
    'generator' => 'api-introspect',
    'generated_at' => gmdate('c'),
    'opnsense_root' => $bootstrap->root(),
    'opnsense_version' => detectVersion($bootstrap->root()),
    'acl_privileges_indexed' => $acl->count(),
    'stats' => $stats,
];

$output = $opts['openapi']
    ? (new OpenApiEmitter())->emit($endpoints, $meta)
    : (new JsonEmitter())->emit($endpoints, $meta);

if ($opts['out'] !== null) {
    file_put_contents($opts['out'], $output);
    fwrite(STDERR, "Wrote {$stats['endpoints']} endpoints to {$opts['out']}\n");
} else {
    fwrite(STDOUT, $output);
}

// ---------------------------------------------------------------------------

/** @return array<int,array{name:string,optional:bool,default:mixed}> */
function pathParams(ReflectionMethod $action): array
{
    $params = [];
    foreach ($action->getParameters() as $p) {
        $params[] = [
            'name' => RouteDeriver::toSnake($p->getName()),
            'optional' => $p->isOptional(),
            'default' => $p->isDefaultValueAvailable() ? $p->getDefaultValue() : null,
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

/** @return array{out:?string,openapi:bool,opnsense-root:?string,module:?string,help:bool} */
function parseArgs(array $argv): array
{
    $o = ['out' => null, 'openapi' => false, 'opnsense-root' => null, 'module' => null, 'help' => false];
    for ($i = 1; $i < count($argv); $i++) {
        switch ($argv[$i]) {
            case '--out': $o['out'] = $argv[++$i] ?? null; break;
            case '--openapi': $o['openapi'] = true; break;
            case '--opnsense-root': $o['opnsense-root'] = $argv[++$i] ?? null; break;
            case '--module': $o['module'] = $argv[++$i] ?? null; break;
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

      --out <file>           Write output to <file> instead of stdout
      --openapi              Emit OpenAPI 3 instead of raw inventory JSON
      --opnsense-root <dir>  opnsense checkout (default ./opnsense or \$OPNSENSE_ROOT)
      --module <Name>        Restrict to a single module dir (e.g. Firewall)
      -h, --help             Show this help

    TXT;
}
