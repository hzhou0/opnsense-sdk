<?php

/**
 * Instantiate a model and walk its resolved BaseField tree into a JSON-able
 * field schema. This is where most of the value lives: the authoritative
 * type/constraint data is assembled at runtime from the model XML.
 *
 * The walk is recursive so that ArrayField collections (firewall rules, NAT
 * rules, route entries, ...) are expanded into their per-row field schema via
 * the array's template node, rather than appearing as opaque leaves. Empty
 * collections still carry a template, so the row schema is available without
 * any configured data.
 */

namespace OpnsenseApiIntrospect;

final class ModelSchemaExtractor
{
    /** Guard against pathological / self-referential nesting. */
    private const MAX_DEPTH = 6;

    /**
     * @return array{
     *   model:?string, bodyKey:?string, categorysource:?string,
     *   payloadKey:?string, payload:array<int,array<string,mixed>>,
     *   fields:array<int,array<string,mixed>>, error:?string,
     *   options_runtime:bool
     * }
     */
    public function extract(DiscoveredController $controller): array
    {
        $result = [
            'model' => null,
            'bodyKey' => null,
            'categorysource' => $controller->staticProp('categorysource'),
            'payloadKey' => null,
            'payload' => [],
            'fields' => [],
            'error' => null,
            'options_runtime' => false,
        ];

        $modelClass = null;
        if ($controller->kind === ControllerDiscovery::KIND_MODEL) {
            $modelClass = $controller->staticProp('internalModelClass');
            $result['bodyKey'] = $controller->staticProp('internalModelName');
        } elseif ($controller->kind === ControllerDiscovery::KIND_SERVICE) {
            $modelClass = $controller->staticProp('internalServiceClass');
        }

        if (!is_string($modelClass) || $modelClass === '' || !class_exists($modelClass)) {
            $result['error'] = 'no model class';
            return $result;
        }
        $result['model'] = $modelClass;

        // Some models reach out to configd during construction; degrade
        // gracefully when it is unavailable (option enums become empty).
        try {
            $model = (new \ReflectionClass($modelClass))->newInstance();
        } catch (\Throwable $e) {
            $result['error'] = 'instantiation failed: ' . $e->getMessage();
            return $result;
        }

        try {
            // __get on the model reflects to its root ContainerField, so
            // iterateItems() yields the top-level field nodes.
            $result['fields'] = $this->walk($model, 0, $result);
            [$result['payloadKey'], $result['payload']] = $this->computePayload(
                $model,
                $result['categorysource'],
                $result['bodyKey'],
                $result
            );
        } catch (\Throwable $e) {
            $result['error'] = 'field walk failed: ' . $e->getMessage();
        }

        return $result;
    }

    /**
     * Determine the field set that the standard CRUD actions actually carry in
     * their request/response body, and the key it is wrapped under.
     *
     * The base controllers wrap the body under a key and operate on a node
     * path: `getBase($key, $path, $uuid)` / `addBase($key, $path)`. For
     * FilterBase-style controllers that path is `$categorysource` and the key
     * is its last segment (e.g. rules.rule -> key "rule"). For ordinary
     * collection controllers the model's single top-level ArrayField is the
     * collection and the key is the model name. Settings-style models (no array
     * collection) expose the whole model under the model name.
     *
     * @param array{options_runtime:bool} $result by-ref accumulator for flags
     * @return array{0:?string,1:array<int,array<string,mixed>>} [payloadKey, fields]
     */
    private function computePayload(object $model, ?string $categorysource, ?string $bodyKey, array &$result): array
    {
        $path = $categorysource ?: null;
        $node = null;

        if ($path !== null) {
            $node = $this->navigate($model, $path);
        } else {
            // Auto-detect a single top-level collection (e.g. group, alias).
            $arrays = [];
            foreach ($model->iterateItems() as $name => $child) {
                if (is_object($child) && $this->callBool($child, 'isArrayType')) {
                    $arrays[(string)$name] = $child;
                }
            }
            if (count($arrays) === 1) {
                $path = (string)array_key_first($arrays);
                $node = $arrays[$path];
            }
        }

        if ($node !== null && $this->callBool($node, 'isArrayType') && method_exists($node, 'getTemplateNode')) {
            $segments = explode('.', (string)$path);
            $key = end($segments) ?: $bodyKey;
            try {
                $template = $node->getTemplateNode();
                if (is_object($template)) {
                    return [$key, $this->walk($template, 0, $result)];
                }
            } catch (\Throwable) {
                // fall through to whole-model view
            }
        }

        // Settings-style model: the whole model is the body.
        return [$bodyKey, $result['fields']];
    }

    /** Navigate a dotted model path via __get; null if any step is missing. */
    private function navigate(object $model, string $path): ?object
    {
        $node = $model;
        foreach (explode('.', $path) as $segment) {
            try {
                $next = $node->$segment;
            } catch (\Throwable) {
                return null;
            }
            if (!is_object($next)) {
                return null;
            }
            $node = $next;
        }
        return $node;
    }

    /**
     * Recursively describe a container's children. Plain containers are
     * flattened; ArrayField collections become a single entry carrying the
     * row schema under `items`.
     *
     * @param array{options_runtime:bool} $result by-ref accumulator for flags
     * @return array<int,array<string,mixed>>
     */
    private function walk(object $container, int $depth, array &$result): array
    {
        $fields = [];
        if ($depth > self::MAX_DEPTH) {
            return $fields;
        }

        foreach ($container->iterateItems() as $name => $child) {
            if (!is_object($child)) {
                continue;
            }

            if ($this->callBool($child, 'isArrayType')) {
                $fields[] = $this->describeArray((string)$name, $child, $depth, $result);
            } elseif ($this->callBool($child, 'isContainer')) {
                // Grouping container (e.g. "general", "rules"): flatten its
                // children up into this level.
                foreach ($this->walk($child, $depth + 1, $result) as $f) {
                    $fields[] = $f;
                }
            } else {
                $fields[] = $this->describeField((string)$name, $child, $result);
            }
        }

        return $fields;
    }

    /**
     * @param array{options_runtime:bool} $result
     * @return array<string,mixed>
     */
    private function describeArray(string $name, object $field, int $depth, array &$result): array
    {
        $entry = [
            'name' => $name,
            'reference' => $this->reference($field),
            'type' => (new \ReflectionClass($field))->getShortName(),
            'array' => true,
            'items' => [],
        ];

        if (method_exists($field, 'getTemplateNode')) {
            try {
                $template = $field->getTemplateNode();
                if (is_object($template)) {
                    $entry['items'] = $this->walk($template, $depth + 1, $result);
                }
            } catch (\Throwable $e) {
                $entry['items_error'] = $e->getMessage();
            }
        }

        return $entry;
    }

    /**
     * @param array{options_runtime:bool} $result
     * @return array<string,mixed>
     */
    private function describeField(string $name, object $field, array &$result): array
    {
        // How the field serializes in getNodes(): a scalar string, or an
        // option map {key => {value, selected}}. This drives the request vs
        // response representation in the emitter.
        $nodeData = $this->rawNodeData($field);
        $valueType = is_array($nodeData) ? 'map' : 'scalar';

        $entry = [
            'name' => $name,
            'reference' => $this->reference($field),
            'type' => (new \ReflectionClass($field))->getShortName(),
            'required' => $this->callBool($field, 'isRequired'),
            'array' => false,
            'valueType' => $valueType,
            'multiSelect' => (bool)$this->internalProp($field, 'internalMultiSelect'),
            'description' => $this->callString($field, 'getDescription'),
            'default' => $this->internalDefault($field),
            'validators' => $this->validators($field),
            'validationMessage' => $this->internalProp($field, 'internalValidationMessage'),
        ];

        if ($valueType === 'map') {
            $options = array_keys($nodeData);
            $entry['options'] = $options;
            if ($options === []) {
                $entry['options_runtime'] = true;
                $result['options_runtime'] = true;
            }
        }

        return $entry;
    }

    private function reference(object $field): ?string
    {
        foreach (['internalReference', '__reference'] as $candidate) {
            $v = $this->internalProp($field, $candidate);
            if (is_string($v) && $v !== '') {
                return $v;
            }
        }
        return null;
    }

    /**
     * Raw getNodeData(): an option map {key => {value, selected}} for list-style
     * fields, otherwise a scalar string. Returns '' when unavailable.
     */
    private function rawNodeData(object $field): mixed
    {
        if (!method_exists($field, 'getNodeData')) {
            return '';
        }
        try {
            return $field->getNodeData();
        } catch (\Throwable) {
            return '';
        }
    }

    /** @return string[] short class names of attached validators */
    private function validators(object $field): array
    {
        if (!method_exists($field, 'getValidators')) {
            return [];
        }
        try {
            $validators = $field->getValidators();
        } catch (\Throwable) {
            return [];
        }
        $names = [];
        foreach ((array)$validators as $v) {
            if (is_object($v)) {
                $names[] = (new \ReflectionClass($v))->getShortName();
            }
        }
        return array_values(array_unique($names));
    }

    private function internalDefault(object $field): mixed
    {
        $v = $this->internalProp($field, 'internalValue');
        if ($v !== null && $v !== '') {
            return $v;
        }
        return $this->internalProp($field, 'internalDefaultValue');
    }

    private function internalProp(object $field, string $name): mixed
    {
        $rc = new \ReflectionClass($field);
        while ($rc) {
            if ($rc->hasProperty($name)) {
                $p = $rc->getProperty($name);
                $p->setAccessible(true);
                return $p->getValue($field);
            }
            $rc = $rc->getParentClass();
        }
        return null;
    }

    private function callBool(object $field, string $method): ?bool
    {
        if (!method_exists($field, $method)) {
            return null;
        }
        try {
            return (bool)$field->$method();
        } catch (\Throwable) {
            return null;
        }
    }

    private function callString(object $field, string $method): ?string
    {
        if (!method_exists($field, $method)) {
            return null;
        }
        try {
            $v = $field->$method();
            return is_scalar($v) ? (string)$v : null;
        } catch (\Throwable) {
            return null;
        }
    }
}
