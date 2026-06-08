<?php

namespace OpnsenseApiIntrospect;

/** Immutable value object describing one discovered API controller. */
final class DiscoveredController
{
    /**
     * @param \ReflectionMethod[] $actions
     */
    public function __construct(
        public readonly string $fqcn,
        public readonly string $vendor,
        public readonly string $module,
        public readonly string $shortName,
        public readonly string $kind,
        public readonly \ReflectionClass $reflection,
        public readonly array $actions,
        public readonly bool $instantiable,
        public readonly bool $dynamic,
    ) {
    }

    /** Read a (possibly protected/private) static property, or null. */
    public function staticProp(string $name): mixed
    {
        if (!$this->reflection->hasProperty($name)) {
            return null;
        }
        $p = $this->reflection->getProperty($name);
        $p->setAccessible(true);
        return $p->isStatic() ? $p->getValue() : null;
    }
}
