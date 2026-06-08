<?php

/**
 * Standalone test for the correctness-critical camel<->snake transform.
 * Run: php tests/RouteDeriverTest.php  (exits non-zero on failure)
 */

declare(strict_types=1);

require __DIR__ . '/../vendor/autoload.php';

use OpnsenseApiIntrospect\RouteDeriver;

$failures = 0;
function check(string $label, $expected, $actual): void
{
    global $failures;
    if ($expected === $actual) {
        fwrite(STDOUT, "  ok   {$label}\n");
    } else {
        $failures++;
        fwrite(STDOUT, sprintf("  FAIL %s: expected %s, got %s\n", $label, var_export($expected, true), var_export($actual, true)));
    }
}

// camel/Pascal -> snake
$snakeCases = [
    'DNat' => 'd_nat',
    'SourceNat' => 'source_nat',
    'OneToOne' => 'one_to_one',
    'CpuUsage' => 'cpu_usage',
    'HasyncStatus' => 'hasync_status',
    'Alias' => 'alias',
    'Filter' => 'filter',
];
foreach ($snakeCases as $pascal => $snake) {
    check("toSnake({$pascal})", $snake, RouteDeriver::toSnake($pascal));
}

// Round-trip: snake must rebuild to the original Pascal via parsePath's rule.
foreach ($snakeCases as $pascal => $snake) {
    check("roundtrip({$pascal})", $pascal, RouteDeriver::toPascal(RouteDeriver::toSnake($pascal)));
}

// Full path derivation
check(
    'derive Firewall/DNatController/searchRuleAction',
    '/api/firewall/d_nat/search_rule',
    RouteDeriver::derive('Firewall', 'DNatController', 'searchRuleAction')
);
check(
    'derive with path param',
    '/api/firewall/filter/get_rule/{uuid}',
    RouteDeriver::derive('Firewall', 'FilterController', 'getRuleAction', ['uuid'])
);

fwrite(STDOUT, $failures === 0 ? "\nAll passed.\n" : "\n{$failures} failure(s).\n");
exit($failures === 0 ? 0 : 1);
