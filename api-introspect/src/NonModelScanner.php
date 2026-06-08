<?php

/**
 * Best-effort parameter discovery for plain ApiControllerBase actions that have
 * no model. Static-scan the method body for request accessor keys. Results are
 * untyped and unreliable by construction.
 */

namespace OpnsenseApiIntrospect;

final class NonModelScanner
{
    /** @var array<string,array> file => lines */
    private array $sourceCache = [];

    /**
     * @return array{params:string[],reliable:bool}
     */
    public function scan(\ReflectionMethod $method): array
    {
        $body = $this->methodBody($method);
        $keys = [];

        $patterns = [
            '/->getPost\(\s*[\'"]([^\'"]+)[\'"]/',
            '/->get\(\s*[\'"]([^\'"]+)[\'"]/',
            '/->hasPost\(\s*[\'"]([^\'"]+)[\'"]/',
            '/->getQuery\(\s*[\'"]([^\'"]+)[\'"]/',
            '/\$_POST\[\s*[\'"]([^\'"]+)[\'"]\]/',
            '/\$_REQUEST\[\s*[\'"]([^\'"]+)[\'"]\]/',
        ];
        foreach ($patterns as $re) {
            if (preg_match_all($re, $body, $m)) {
                foreach ($m[1] as $k) {
                    $keys[$k] = true;
                }
            }
        }

        $rawBody = (bool)preg_match('/getJsonRawBody|getRawBody/', $body);
        if ($rawBody && $keys === []) {
            $keys['<json-raw-body>'] = true;
        }

        return ['params' => array_keys($keys), 'reliable' => false];
    }

    private function methodBody(\ReflectionMethod $method): string
    {
        $file = $method->getFileName();
        if ($file === false) {
            return '';
        }
        $src = $this->sourceCache[$file] ??= (file($file) ?: []);
        $start = $method->getStartLine() - 1;
        $end = $method->getEndLine();
        return implode('', array_slice($src, $start, $end - $start));
    }
}
