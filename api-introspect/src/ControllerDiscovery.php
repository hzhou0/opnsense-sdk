<?php

/**
 * Enumerate API controllers across every configured controllers directory
 * (covers core and plugins), classify them by base class, and list their
 * `*Action` methods.
 */

namespace OpnsenseApiIntrospect;

use OPNsense\Core\AppConfig;

final class ControllerDiscovery
{
    public const KIND_MODEL = 'model';        // ApiMutableModelControllerBase
    public const KIND_SERVICE = 'service';    // ApiMutableServiceControllerBase
    public const KIND_PLAIN = 'plain';        // ApiControllerBase

    private const BASE_MODEL = 'OPNsense\\Base\\ApiMutableModelControllerBase';
    private const BASE_SERVICE = 'OPNsense\\Base\\ApiMutableServiceControllerBase';
    private const BASE_PLAIN = 'OPNsense\\Base\\ApiControllerBase';

    public function __construct(private readonly AppConfig $config)
    {
    }

    /** @return string[] absolute controller directories */
    private function controllerDirs(): array
    {
        $dir = $this->config->application->controllersDir;
        $dirs = is_array($dir) ? $dir : [$dir];
        return array_values(array_filter(array_map('realpath', $dirs)));
    }

    /**
     * @return DiscoveredController[]
     */
    public function discover(): array
    {
        // OPNsense has no route table or controller registry: routing is pure
        // convention + filesystem probing. At request time the front controller
        // (src/opnsense/www/api.php:35) builds Router('/api/', 'Api') and calls
        // routeRequest() (Mvc/Router.php:116), which maps
        // /api/<module>/<controller>/<action> to a class in two steps:
        //   - parsePath()       (Mvc/Router.php:166): element 0 -> namespace,
        //                        element 1 -> "<Controller>Controller",
        //                        element 2 -> "<action>Action", rest -> params.
        //   - resolveNamespace() (Mvc/Router.php:60): globs the same
        //                        `application.controllersDir`, then stat-checks
        //                        `<Vendor>/<Module>/Api/<Controller>.php`
        //                        (is_file at Mvc/Router.php:97).
        // It then dispatches the action by reflection (Dispatcher, Mvc/Router.php:137).
        // A controller is "registered" simply by existing at that path -- that is
        // how plugins add APIs, with no wiring.
        //
        // Discovery here is that exact rule run in reverse: instead of URL->file on
        // demand, we glob file->URL for every controller. Globbing the same
        // controllersDir is therefore canonical, not an approximation -- there is no
        // more authoritative source. The glob mirrors the Router's structure: 1st
        // `*` = vendor, 2nd `*` = module/namespace, `/Api/` = the 'Api' suffix.
        // Files nested deeper are unroutable (parsePath only reads element 0/1), so
        // we ignore them.
        $found = [];
        foreach ($this->controllerDirs() as $base) {
            // <Vendor>/<Module>/Api/<Class>Controller.php
            foreach (glob($base . '/*/*/Api/*Controller.php') ?: [] as $file) {
                $controller = $this->reflectFile($base, $file);
                $found[$controller->fqcn] = $controller;
            }
        }
        ksort($found);
        return array_values($found);
    }

    /**
     * Core-only, so these are hard invariants, not skips: a failure means upstream
     * drifted and discovery needs refactoring. Layout + FQCN follow from the
     * dispatch convention (a routable file must resolve to this class); the API-base
     * check is the stronger assumption our schema extraction relies on. Thrown, not
     * assert()ed, so they can't be compiled out via zend.assertions.
     */
    private function reflectFile(string $base, string $file): DiscoveredController
    {
        $rel = substr($file, strlen($base) + 1);          // Vendor/Module/Api/XController.php
        $parts = explode('/', $rel);
        if (count($parts) < 4) {
            throw new \RuntimeException("Unexpected controller path layout: '{$rel}'.");
        }
        [$vendor, $module] = $parts;
        $class = basename($file, '.php');
        $fqcn = "{$vendor}\\{$module}\\Api\\{$class}";

        if (!class_exists($fqcn)) {
            throw new \RuntimeException("Controller file '{$file}' does not define class '{$fqcn}'.");
        }

        $rc = new \ReflectionClass($fqcn);
        $kind = $this->classify($rc);
        if ($kind === null) {
            throw new \RuntimeException("Controller '{$fqcn}' extends no known API base.");
        }

        $instantiable = $rc->isInstantiable();
        $dynamic = $rc->hasMethod('__call');

        $actions = [];
        if (!$rc->isAbstract()) {
            // Public *Action methods, including those inherited from the base
            // controllers (the standard CRUD actions a subclass never redeclares).
            foreach ($rc->getMethods(\ReflectionMethod::IS_PUBLIC) as $m) {
                if (str_ends_with($m->name, 'Action')) {
                    $actions[$m->name] = $m;
                }
            }
        }

        return new DiscoveredController(
            fqcn: $fqcn,
            module: $module,
            shortName: $class,
            kind: $kind,
            reflection: $rc,
            actions: array_values($actions),
            instantiable: $instantiable,
            dynamic: $dynamic,
        );
    }

    private function classify(\ReflectionClass $rc): ?string
    {
        $chain = [];
        for ($c = $rc; $c; $c = $c->getParentClass()) {
            $chain[] = $c->getName();
        }
        if (in_array(ltrim(self::BASE_MODEL, '\\'), $chain, true)) {
            return self::KIND_MODEL;
        }
        if (in_array(ltrim(self::BASE_SERVICE, '\\'), $chain, true)) {
            return self::KIND_SERVICE;
        }
        if (in_array(ltrim(self::BASE_PLAIN, '\\'), $chain, true)) {
            return self::KIND_PLAIN;
        }
        return null;
    }
}
