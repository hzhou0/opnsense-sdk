<?php

/**
 * Inverse of OPNsense\Mvc\Router::parsePath.
 *
 * parsePath rebuilds a PascalCase class/method fragment from a snake_case URL
 * segment via `str_replace('_', '', ucwords($segment, '_'))`. To emit a URL
 * segment that rebuilds to the *exact* class name (controller filenames are
 * case-sensitive on disk), we apply the camel->snake rule below.
 */

namespace OpnsenseApiIntrospect;

final class RouteDeriver
{
    /**
     * camel/Pascal -> snake.
     *
     * Insert `_` before any uppercase letter that is not at string start and is
     * followed by a lowercase letter, then lowercase the whole string.
     *
     * Verified round-trips (snake -> ucwords -> strip _ == original):
     *   DNat -> d_nat, SourceNat -> source_nat, OneToOne -> one_to_one,
     *   CpuUsage -> cpu_usage, HasyncStatus -> hasync_status.
     */
    public static function toSnake(string $pascal): string
    {
        $out = preg_replace('/(?<!^)([A-Z])(?=[a-z])/', '_$1', $pascal);
        return strtolower($out);
    }

    /** Reproduce parsePath's reconstruction, for round-trip verification. */
    public static function toPascal(string $snake): string
    {
        return str_replace('_', '', ucwords($snake, '_'));
    }

    /**
     * Derive the API path for a controller action.
     *
     * @param string   $module     module dir name, e.g. "Firewall"
     * @param string   $controller class short name incl. "Controller" suffix
     * @param string   $action     method name incl. "Action" suffix
     * @param string[] $pathParams ordered path parameter names (already snake)
     */
    public static function derive(
        string $module,
        string $controller,
        string $action,
        array $pathParams = []
    ): string {
        $moduleSeg = self::toSnake($module);
        $ctrlSeg = self::toSnake(self::stripSuffix($controller, 'Controller'));
        $actionSeg = self::toSnake(self::stripSuffix($action, 'Action'));

        $path = "/api/{$moduleSeg}/{$ctrlSeg}/{$actionSeg}";
        foreach ($pathParams as $name) {
            $path .= '/{' . $name . '}';
        }
        return $path;
    }

    private static function stripSuffix(string $value, string $suffix): string
    {
        if (str_ends_with($value, $suffix)) {
            return substr($value, 0, -strlen($suffix));
        }
        return $value;
    }
}
