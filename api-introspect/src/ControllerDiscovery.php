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

    public function __construct(private AppConfig $config)
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
        $found = [];
        foreach ($this->controllerDirs() as $base) {
            // <Vendor>/<Module>/Api/<Class>Controller.php
            foreach (glob($base . '/*/*/Api/*Controller.php') ?: [] as $file) {
                $controller = $this->reflectFile($base, $file);
                if ($controller !== null) {
                    $found[$controller->fqcn] = $controller;
                }
            }
        }
        ksort($found);
        return array_values($found);
    }

    private function reflectFile(string $base, string $file): ?DiscoveredController
    {
        $rel = substr($file, strlen($base) + 1);          // Vendor/Module/Api/XController.php
        $parts = explode('/', $rel);
        if (count($parts) < 4) {
            return null;
        }
        [$vendor, $module] = $parts;
        $class = basename($file, '.php');
        $fqcn = "{$vendor}\\{$module}\\Api\\{$class}";

        if (!class_exists($fqcn)) {
            return null;
        }

        $rc = new \ReflectionClass($fqcn);
        $kind = $this->classify($rc);
        if ($kind === null) {
            return null; // not an API controller
        }

        $instantiable = $rc->isInstantiable();
        $dynamic = $rc->hasMethod('__call');

        $actions = [];
        if (!$rc->isAbstract()) {
            foreach ($rc->getMethods(\ReflectionMethod::IS_PUBLIC) as $m) {
                if ($m->class !== $fqcn && !str_ends_with($m->name, 'Action')) {
                    // keep inherited actions too, but only *Action methods
                }
                if (str_ends_with($m->name, 'Action')) {
                    $actions[$m->name] = $m;
                }
            }
        }

        return new DiscoveredController(
            fqcn: $fqcn,
            vendor: $vendor,
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
