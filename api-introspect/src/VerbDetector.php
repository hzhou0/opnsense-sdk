<?php

/**
 * Heuristic HTTP verb detection. Verbs are enforced inside method bodies
 * (`$this->request->isPost()` etc.), never declared, so this is best-effort.
 */

namespace OpnsenseApiIntrospect;

final class VerbDetector
{
    /** @var array<string,string> cache: file => source */
    private array $sourceCache = [];

    /**
     * @return array{verbs:string[],heuristic:bool}
     */
    public function detect(\ReflectionMethod $method): array
    {
        $body = $this->methodBody($method);
        $verbs = [];

        if (preg_match('/->isPost\s*\(/', $body)) {
            $verbs[] = 'POST';
        }
        if (preg_match('/->isPut\s*\(/', $body)) {
            $verbs[] = 'PUT';
        }
        if (preg_match('/->isDelete\s*\(/', $body)) {
            $verbs[] = 'DELETE';
        }
        if (preg_match('/->isGet\s*\(/', $body)) {
            $verbs[] = 'GET';
        }

        if ($verbs === []) {
            // Convention: get*/search*/list* default to GET, others to POST.
            $name = lcfirst(substr($method->name, 0, -strlen('Action')));
            $verbs = preg_match('/^(get|search|list|export|download)/', $name)
                ? ['GET']
                : ['POST'];
        }

        return ['verbs' => array_values(array_unique($verbs)), 'heuristic' => true];
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
