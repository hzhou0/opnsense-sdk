<?php

/**
 * Parse every ACL/ACL.xml under the model dirs (and plugins) into a list of
 * privileges with their URL glob patterns, then match endpoint paths against
 * them.
 */

namespace OpnsenseApiIntrospect;

use OPNsense\Core\AppConfig;

final class AclIndex
{
    /** @var array<int,array{name:string,id:string,patterns:string[],regex:string[]}> */
    private array $privileges = [];

    public function __construct(private AppConfig $config)
    {
        $this->load();
    }

    private function searchRoots(): array
    {
        $app = $this->config->application;
        $roots = [];
        foreach (['modelsDir', 'controllersDir', 'pluginsDir'] as $key) {
            $val = $app->$key ?? null;
            foreach ((is_array($val) ? $val : [$val]) as $dir) {
                $real = $dir ? realpath($dir) : false;
                if ($real) {
                    $roots[$real] = true;
                }
            }
        }
        return array_keys($roots);
    }

    private function load(): void
    {
        $seen = [];
        foreach ($this->searchRoots() as $root) {
            foreach ($this->rglob($root, 'ACL.xml') as $file) {
                if (isset($seen[$file]) || basename(dirname($file)) !== 'ACL') {
                    continue;
                }
                $seen[$file] = true;
                $this->parse($file);
            }
        }
    }

    /** @return string[] */
    private function rglob(string $root, string $filename): array
    {
        $out = [];
        $it = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($root, \FilesystemIterator::SKIP_DOTS)
        );
        foreach ($it as $f) {
            if ($f->getFilename() === $filename) {
                $out[] = $f->getPathname();
            }
        }
        return $out;
    }

    private function parse(string $file): void
    {
        if (!function_exists('simplexml_load_file')) {
            return; // SimpleXML unavailable; ACL matching degrades to empty.
        }
        $xml = @simplexml_load_file($file);
        if ($xml === false) {
            return;
        }
        foreach ($xml->children() as $id => $node) {
            $patterns = [];
            foreach ($node->patterns->pattern ?? [] as $p) {
                $patterns[] = (string)$p;
            }
            $this->privileges[] = [
                'name' => (string)($node->name ?? $id),
                'id' => (string)$id,
                'patterns' => $patterns,
                'regex' => array_map([$this, 'globToRegex'], $patterns),
            ];
        }
    }

    private function globToRegex(string $glob): string
    {
        $g = ltrim($glob, '/');
        $re = preg_quote($g, '#');
        $re = str_replace('\*', '[^/]*', $re); // single * matches a path segment chunk
        return '#^' . $re . '$#i';
    }

    /**
     * @return array<int,array{name:string,id:string}> matching privileges
     */
    public function match(string $apiPath): array
    {
        // Patterns are over `api/...` without path params; strip them.
        $needle = ltrim(preg_replace('#/\{[^}]+\}#', '', $apiPath), '/');
        $hits = [];
        foreach ($this->privileges as $priv) {
            foreach ($priv['regex'] as $re) {
                if (preg_match($re, $needle)) {
                    $hits[$priv['id']] = ['name' => $priv['name'], 'id' => $priv['id']];
                    break;
                }
            }
        }
        return array_values($hits);
    }

    public function count(): int
    {
        return count($this->privileges);
    }
}
