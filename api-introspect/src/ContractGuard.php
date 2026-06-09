<?php

/**
 * Guards the soundness of the emitted spec against upstream drift.
 *
 * The analyzer and emitter encode assumptions about the *behaviour* of a small
 * set of OPNsense base methods: the CRUD wrappers (argument order + serialised
 * shape), the inherited get/setAction envelope, the grid fetch, and the field
 * node serialisation. `ControllerDiscovery` already hard-fails on structural
 * drift (layout / namespace / base class); this guards the complementary risk —
 * a base method whose body changes meaning across versions, which would let the
 * spec keep claiming a shape that no longer holds.
 *
 * Each method in the surface is fingerprinted by its *normalised AST* (comments,
 * formatting and purely-syntactic variants stripped) and checked against a
 * checked-in lock. A mismatch is reported as drift so a human re-verifies the
 * assumption and re-blesses the lock; the tool never silently adapts.
 */

namespace OpnsenseApiIntrospect;

use PhpParser\Node;
use PhpParser\NodeFinder;
use PhpParser\NodeTraverser;
use PhpParser\NodeVisitorAbstract;
use PhpParser\Parser;
use PhpParser\ParserFactory;
use PhpParser\PrettyPrinter;

final class ContractGuard
{
    /**
     * The contract surface: every base method whose behaviour the analyzer or
     * emitter depends on. Keep in sync with ReturnAnalyzer::WRAPPERS /
     * ::BASE_ACTIONS and the emitter's response-shape assumptions.
     *
     * @var array<string,string[]> fully-qualified class => method names
     */
    private const SURFACE = [
        // CRUD wrappers (arg order) + inherited get/setAction envelope + the
        // save()/validate() result shape the emitter encodes for writes.
        'OPNsense\\Base\\ApiMutableModelControllerBase' => [
            'getAction', 'setAction', 'getBase', 'setBase', 'addBase',
            'delBase', 'toggleBase', 'searchBase', 'save', 'validate',
        ],
        // searchRecordsetBase (grid envelope) is declared one level up.
        'OPNsense\\Base\\ApiControllerBase' => ['searchRecordsetBase'],
        // FilterBase rule helpers the analyzer recognises.
        'OPNsense\\Firewall\\Api\\FilterBaseController' => [
            'moveRuleBeforeBase', 'toggleRuleLogBase', 'downloadRulesBase', 'uploadRulesBase',
        ],
        // Grid row serialisation (flat values + %field labels).
        'OPNsense\\Base\\UIModelGrid' => ['fetch'],
        // Node-tree serialisation that drives request vs response leaf shapes.
        'OPNsense\\Base\\FieldTypes\\BaseField' => ['getNodes', 'getNodeData'],
        'OPNsense\\Base\\FieldTypes\\BaseListField' => ['getValues', 'setValues'],
    ];

    private Parser $parser;
    private NodeFinder $finder;
    private PrettyPrinter\Standard $printer;

    public function __construct()
    {
        $this->parser = (new ParserFactory())->createForNewestSupportedVersion();
        $this->finder = new NodeFinder();
        $this->printer = new PrettyPrinter\Standard();
    }

    /**
     * Fingerprint the whole surface.
     *
     * @return array<string,array{fingerprint:string,params:string[],error?:string}>
     */
    public function fingerprint(): array
    {
        $out = [];
        foreach (self::SURFACE as $class => $methods) {
            foreach ($methods as $method) {
                $out[$class . '::' . $method] = $this->fingerprintMethod($class, $method);
            }
        }
        ksort($out);
        return $out;
    }

    /**
     * Build a lockable snapshot of the surface.
     *
     * @return array{opnsense_version:string,generated_at:string,contracts:array<string,mixed>}
     */
    public function buildLock(string $version): array
    {
        return [
            'opnsense_version' => $version,
            'generated_at' => gmdate('c'),
            'contracts' => $this->fingerprint(),
        ];
    }

    /**
     * Compare the live surface against a locked snapshot.
     *
     * @param array<string,array{fingerprint:string,params:string[]}> $lock
     * @return array<string,array{status:string,detail:string}> drift (empty = clean)
     */
    public function check(array $lock): array
    {
        $current = $this->fingerprint();
        $drift = [];

        foreach ($current as $key => $cur) {
            $expected = $lock[$key] ?? null;
            if ($expected === null) {
                $drift[$key] = ['status' => 'unlocked', 'detail' => 'in surface but absent from lock; re-bless'];
                continue;
            }
            if (!empty($cur['error'])) {
                $drift[$key] = ['status' => 'missing', 'detail' => $cur['error'] . ' (present when locked)'];
                continue;
            }
            if ($cur['fingerprint'] !== ($expected['fingerprint'] ?? '')) {
                $drift[$key] = [
                    'status' => 'changed',
                    'detail' => 'body/signature changed' . $this->paramDelta($expected['params'] ?? [], $cur['params']),
                ];
            }
        }

        // Locked methods no longer in the surface: informational (prune the lock).
        foreach ($lock as $key => $_) {
            if (!isset($current[$key])) {
                $drift[$key] = ['status' => 'removed', 'detail' => 'locked method dropped from surface; prune lock'];
            }
        }

        ksort($drift);
        return $drift;
    }

    /** @return array{fingerprint:string,params:string[],error?:string} */
    private function fingerprintMethod(string $class, string $method): array
    {
        $empty = ['fingerprint' => '', 'params' => []];
        if (!class_exists($class) && !trait_exists($class) && !interface_exists($class)) {
            return $empty + ['error' => 'class not found'];
        }
        try {
            $rm = new \ReflectionMethod($class, $method);
        } catch (\Throwable) {
            return $empty + ['error' => 'method not found'];
        }
        $node = $this->findMethodNode($rm);
        if ($node === null) {
            return $empty + ['error' => 'source not parseable'];
        }
        $params = array_map(static fn(\ReflectionParameter $p) => $p->getName(), $rm->getParameters());
        return ['fingerprint' => hash('sha256', $this->normalize($node)), 'params' => $params];
    }

    /**
     * Locate the method node where it is declared. Parses fresh (the tree is
     * mutated during normalisation and then discarded).
     */
    private function findMethodNode(\ReflectionMethod $rm): ?Node\Stmt\ClassMethod
    {
        $file = $rm->getFileName();
        if ($file === false) {
            return null;
        }
        try {
            $ast = $this->parser->parse((string)file_get_contents($file));
        } catch (\Throwable) {
            return null;
        }
        if ($ast === null) {
            return null;
        }
        $declaringShort = $rm->getDeclaringClass()->getShortName();
        /** @var Node\Stmt\ClassLike $cls */
        foreach ($this->finder->findInstanceOf($ast, Node\Stmt\ClassLike::class) as $cls) {
            if ($cls->name === null || strcasecmp($cls->name->name, $declaringShort) !== 0) {
                continue;
            }
            /** @var Node\Stmt\ClassMethod $m */
            foreach ($this->finder->findInstanceOf($cls->stmts, Node\Stmt\ClassMethod::class) as $m) {
                if (strcasecmp($m->name->name, $rm->getName()) === 0) {
                    return $m;
                }
            }
        }
        return null;
    }

    /**
     * Normalise a method to a canonical string: strip every node attribute
     * (comments, source positions, and syntactic-only variants like array/quote
     * style or numeric base), then pretty-print. Semantics are preserved; pure
     * formatting and documentation changes are not flagged.
     */
    private function normalize(Node\Stmt\ClassMethod $node): string
    {
        $traverser = new NodeTraverser();
        $traverser->addVisitor(new class extends NodeVisitorAbstract {
            public function enterNode(Node $node)
            {
                $node->setAttributes([]);
                return null;
            }
        });
        $traverser->traverse([$node]);
        return $this->printer->prettyPrint([$node]);
    }

    /** @param string[] $was @param string[] $now */
    private function paramDelta(array $was, array $now): string
    {
        if ($was === $now) {
            return '';
        }
        return ' — params [' . implode(', ', $was) . '] -> [' . implode(', ', $now) . ']';
    }
}