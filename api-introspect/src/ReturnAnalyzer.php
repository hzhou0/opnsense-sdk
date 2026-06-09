<?php

/**
 * Static analysis of controller action bodies to recover the return shape that
 * untyped action methods do not declare.
 *
 * The OPNsense API controllers are highly regular: most actions return a base
 * CRUD wrapper (`$this->getBase('rule', 'rules.rule', $uuid)`, `searchBase`,
 * `setBase`, `delBase`, …) whose direction is fixed and whose body key / model
 * path are passed as string literals, or they return a literal array whose keys
 * are the response shape. Parsing the AST lets us read those facts directly
 * instead of guessing from the method name.
 */

namespace OpnsenseApiIntrospect;

use PhpParser\Node;
use PhpParser\NodeFinder;
use PhpParser\Parser;
use PhpParser\ParserFactory;

final class ReturnAnalyzer
{
    /**
     * Base wrapper => [direction, keyArgIndex|null, pathArgIndex|null, grid].
     * Arg indices follow the wrapper signatures in ApiMutableModelControllerBase
     * (and FilterBaseController for the rule-specific ones).
     */
    private const WRAPPERS = [
        'getBase'             => ['read', 0, 1, false],
        'searchBase'          => ['read', null, 0, true],
        'searchRecordsetBase' => ['read', null, 0, true],
        'setBase'             => ['write', 0, 1, false],
        'addBase'             => ['write', 0, 1, false],
        'delBase'             => ['command', null, 0, false],
        'toggleBase'          => ['command', null, 0, false],
        // FilterBase rule helpers operate on $categorysource implicitly. Only
        // the methods that actually exist in FilterBaseController are listed;
        // speculative names would silently activate with assumed arg indices if
        // a future version added them, so they are deliberately omitted (and
        // pinned by ContractGuard).
        'toggleRuleLogBase'   => ['command', null, null, false],
        'moveRuleBeforeBase'  => ['command', null, null, false],
        'uploadRulesBase'     => ['write', null, null, false],
        'downloadRulesBase'   => ['read', null, null, false],
    ];

    /**
     * Inherited base actions whose shape is fixed by the base implementation but
     * not statically parseable from the body: they build their result with a
     * dynamic `static::$internalModelName` key and never call a `*Base` wrapper.
     * Grounded on declaring-class identity instead -- an override changes the
     * declaring class, so a hit here proves the subclass did not replace the
     * method. Both serialise the whole model node tree under internalModelName;
     * the caller resolves that key/shape from the model. (Service-base actions
     * are deliberately excluded: they return configd output, not a model shape.)
     *
     * declaring class => [action => direction]
     */
    private const BASE_ACTIONS = [
        'OPNsense\\Base\\ApiMutableModelControllerBase' => [
            'getAction' => 'read',
            'setAction' => 'write',
        ],
    ];

    private Parser $parser;
    private NodeFinder $finder;

    /** @var array<string, Node\Stmt[]|null> parsed file AST cache */
    private array $astCache = [];

    public function __construct()
    {
        $this->parser = (new ParserFactory())->createForNewestSupportedVersion();
        $this->finder = new NodeFinder();
    }

    /**
     * @return array{
     *   source:string, direction:?string, grid:bool,
     *   key:?string, path:?string, literalKeys:array<int,string>
     * }
     */
    public function analyze(\ReflectionMethod $method): array
    {
        $unknown = [
            'source' => 'unknown', 'direction' => null, 'grid' => false,
            'key' => null, 'path' => null, 'literalKeys' => [],
        ];

        // Inherited, un-overridden base CRUD action: grounded authoritatively on
        // the declaring class rather than parsed from the (dynamic-keyed) body.
        $declaring = $method->getDeclaringClass()->getName();
        $baseAction = $method->getName();
        if (isset(self::BASE_ACTIONS[$declaring][$baseAction])) {
            return [
                'source' => 'base-action:' . $baseAction,
                'direction' => self::BASE_ACTIONS[$declaring][$baseAction],
                'grid' => false,
                // Body key is static::$internalModelName, resolved by the caller.
                'key' => null, 'path' => null, 'literalKeys' => [],
            ];
        }

        $node = $this->findMethodNode($method);
        if ($node === null || $node->stmts === null) {
            return $unknown;
        }

        // Track simple variable assignments so `return $result;` can be traced.
        $assignments = $this->collectAssignments($node->stmts);

        // Collect every returned expression.
        /** @var Node\Stmt\Return_[] $returns */
        $returns = $this->finder->findInstanceOf($node->stmts, Node\Stmt\Return_::class);

        $wrapperSpec = null;
        $literalKeys = [];
        foreach ($returns as $ret) {
            $expr = $ret->expr;
            if ($expr === null) {
                continue;
            }
            // Resolve a returned variable to its assigned expression(s).
            $exprs = $this->resolveExpr($expr, $assignments);
            foreach ($exprs as $e) {
                if ($spec = $this->wrapperCall($e)) {
                    // Prefer the first concrete wrapper return (the happy path);
                    // early `return ['result'=>'failed']` guards are ignored.
                    $wrapperSpec ??= $spec;
                } elseif ($e instanceof Node\Expr\Array_) {
                    foreach ($this->arrayKeys($e) as $k) {
                        $literalKeys[$k] = true;
                    }
                }
            }
        }

        if ($wrapperSpec !== null) {
            return $wrapperSpec + ['literalKeys' => array_keys($literalKeys)];
        }
        if ($literalKeys !== []) {
            return [
                'source' => 'literal', 'direction' => null, 'grid' => false,
                'key' => null, 'path' => null, 'literalKeys' => array_keys($literalKeys),
            ];
        }
        return $unknown;
    }

    /**
     * @param array<string, Node\Expr[]> $assignments
     * @return Node\Expr[] candidate expressions for a returned value
     */
    private function resolveExpr(Node\Expr $expr, array $assignments): array
    {
        if ($expr instanceof Node\Expr\Variable && is_string($expr->name)) {
            return $assignments[$expr->name] ?? [$expr];
        }
        return [$expr];
    }

    /**
     * Map `$var = <expr>` (and `$var['k'] = ...`) within the method body.
     *
     * @param Node[] $stmts
     * @return array<string, Node\Expr[]>
     */
    private function collectAssignments(array $stmts): array
    {
        $out = [];
        /** @var Node\Expr\Assign[] $assigns */
        $assigns = $this->finder->findInstanceOf($stmts, Node\Expr\Assign::class);
        foreach ($assigns as $assign) {
            $var = $assign->var;
            if ($var instanceof Node\Expr\Variable && is_string($var->name)) {
                $out[$var->name][] = $assign->expr;
            } elseif (
                $var instanceof Node\Expr\ArrayDimFetch
                && $var->var instanceof Node\Expr\Variable
                && is_string($var->var->name)
                && $assign->expr instanceof Node\Expr
            ) {
                // `$result['rows'] = ...` contributes a key to a literal-ish shape.
                $key = $var->dim instanceof Node\Scalar\String_ ? $var->dim->value : null;
                if ($key !== null) {
                    $out[$var->var->name][] = new Node\Expr\Array_([
                        new Node\ArrayItem($assign->expr, new Node\Scalar\String_($key)),
                    ]);
                }
            }
        }
        return $out;
    }

    /**
     * @return array{source:string,direction:string,grid:bool,key:?string,path:?string}|null
     */
    private function wrapperCall(Node\Expr $expr): ?array
    {
        if (
            !$expr instanceof Node\Expr\MethodCall
            || !$expr->var instanceof Node\Expr\Variable
            || $expr->var->name !== 'this'
            || !$expr->name instanceof Node\Identifier
        ) {
            return null;
        }
        $method = $expr->name->name;
        if (!isset(self::WRAPPERS[$method])) {
            return null;
        }
        [$direction, $keyIdx, $pathIdx, $grid] = self::WRAPPERS[$method];

        return [
            'source' => 'wrapper:' . $method,
            'direction' => $direction,
            'grid' => $grid,
            'key' => $this->stringArg($expr, $keyIdx),
            'path' => $this->stringArg($expr, $pathIdx),
        ];
    }

    private function stringArg(Node\Expr\MethodCall $call, ?int $index): ?string
    {
        if ($index === null || !isset($call->args[$index])) {
            return null;
        }
        $arg = $call->args[$index];
        if ($arg instanceof Node\Arg && $arg->value instanceof Node\Scalar\String_) {
            return $arg->value->value;
        }
        return null;
    }

    /** @return string[] literal string keys of an array literal */
    private function arrayKeys(Node\Expr\Array_ $array): array
    {
        $keys = [];
        foreach ($array->items as $item) {
            if ($item instanceof Node\ArrayItem && $item->key instanceof Node\Scalar\String_) {
                $keys[] = $item->key->value;
            }
        }
        return $keys;
    }

    private function findMethodNode(\ReflectionMethod $method): ?Node\Stmt\ClassMethod
    {
        // Analyse the method where it is *declared* (covers inherited base actions).
        $declaring = $method->getDeclaringClass();
        $file = $declaring->getFileName();
        if ($file === false) {
            return null;
        }
        $ast = $this->ast($file);
        if ($ast === null) {
            return null;
        }

        /** @var Node\Stmt\ClassMethod[] $methods */
        $methods = $this->finder->findInstanceOf($ast, Node\Stmt\ClassMethod::class);
        foreach ($methods as $m) {
            if (strcasecmp($m->name->name, $method->getName()) === 0) {
                return $m;
            }
        }
        return null;
    }

    /** @return Node\Stmt[]|null */
    private function ast(string $file): ?array
    {
        if (array_key_exists($file, $this->astCache)) {
            return $this->astCache[$file];
        }
        try {
            $ast = $this->parser->parse((string)file_get_contents($file));
        } catch (\Throwable) {
            $ast = null;
        }
        return $this->astCache[$file] = $ast;
    }
}
