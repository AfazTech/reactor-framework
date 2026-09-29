<?php

namespace Reactor\Core;

use Reactor\Exceptions\ContainerResolutionException;

/**
 * Dependency injection container for the Reactor framework.
 *
 * This class is the public container contract. Its API is considered
 * stable for third-party packages and extensions; breaking changes to
 * the method signatures below should be treated as major releases.
 *
 * Supported lifecycles and binding styles:
 *
 * - bind()        transient — a fresh instance per resolve
 * - factory()     explicit alias of bind(), for readability
 * - singleton()   one shared instance per container
 * - scoped()      one instance per "scope"; cleared via
 *                 forgetScopedInstances(), useful for long-running workers
 * - instance()    register a pre-built object as-is
 * - alias()       map one abstract name onto another
 * - when()...give() contextual bindings for a specific consumer
 * - tag()/tagged() group multiple abstracts under one label
 *
 * Concrete arguments may be:
 *
 * - a \Closure receiving the container,
 * - a class-string (auto-resolved through the container),
 * - null (the abstract itself is treated as a class-string).
 *
 * Resolution order for an abstract:
 *
 *   1. registered instance (instance() or a resolved singleton)
 *   2. resolved scoped instance
 *   3. scoped binding factory
 *   4. singleton binding factory
 *   5. transient binding factory
 *   6. reflection-based auto-resolution
 */
class Container
{
    /**
     * @var array<string, object> Resolved singletons and instances
     *                            registered via instance().
     */
    private array $instances = [];

    /**
     * @var array<string, \Closure> Transient factory bindings.
     */
    private array $bindings = [];

    /**
     * @var array<string, \Closure> Singleton factory bindings.
     */
    private array $singletons = [];

    /**
     * @var array<string, \Closure> Scoped factory bindings.
     */
    private array $scoped = [];

    /**
     * @var array<string, object> Resolved scoped instances.
     */
    private array $scopedInstances = [];

    /**
     * @var array<string, string> Alias map: alias => real abstract.
     */
    private array $aliases = [];

    /**
     * @var array<string, array<string, mixed>> Contextual bindings:
     *                                          [concrete][abstract] => implementation.
     */
    private array $contextual = [];

    /**
     * @var array<string, array<int, string>> Tag map: tag => list of abstracts.
     */
    private array $tags = [];

    /**
     * @var array<int, string> Stack of classes being resolved, used to
     *                         detect circular dependencies.
     */
    private array $resolving = [];

    /**
     * Register a transient binding.
     *
     * @param string                    $abstract The abstract key.
     * @param \Closure|string|null      $concrete A factory closure, a
     *                                            class-string, or null
     *                                            to resolve $abstract as
     *                                            a class.
     */
    public function bind(string $abstract, \Closure|string|null $concrete = null): void
    {
        $concrete ??= $abstract;
        $this->bindings[$abstract] = $this->normalizeConcrete($concrete, $abstract);
    }

    /**
     * Alias of bind() with explicit "fresh instance per resolve" semantics.
     */
    public function factory(string $abstract, \Closure|string|null $concrete = null): void
    {
        $this->bind($abstract, $concrete);
    }

    /**
     * Register a singleton binding.
     */
    public function singleton(string $abstract, \Closure|string|null $concrete = null): void
    {
        $concrete ??= $abstract;
        $this->singletons[$abstract] = $this->normalizeConcrete($concrete, $abstract);
    }

    /**
     * Register a scoped binding.
     *
     * Scoped instances live for one "scope". In a long-running worker
     * the scope typically matches a single job or update; call
     * forgetScopedInstances() at scope boundaries.
     */
    public function scoped(string $abstract, \Closure|string|null $concrete = null): void
    {
        $concrete ??= $abstract;
        $this->scoped[$abstract] = $this->normalizeConcrete($concrete, $abstract);
    }

    /**
     * Register a pre-built object as-is.
     *
     * The instance is returned unchanged on every resolve and takes
     * precedence over any binding or factory for the same abstract.
     */
    public function instance(string $abstract, object $instance): void
    {
        $this->instances[$abstract] = $instance;
    }

    /**
     * Register an alias pointing to another abstract.
     *
     * @throws \InvalidArgumentException When the alias would form a cycle.
     */
    public function alias(string $alias, string $abstract): void
    {
        if ($alias === $abstract) {
            throw new \InvalidArgumentException("Cannot alias {$abstract} to itself.");
        }

        // Walk the chain starting from $abstract; if it eventually leads
        // back to $alias, adding the alias would create a cycle.
        $current = $abstract;
        $seen = [];
        while (true) {
            if ($current === $alias) {
                throw new \InvalidArgumentException(
                    "Cannot register alias {$alias}: it would create a circular alias chain."
                );
            }
            if (isset($seen[$current])) {
                throw new \InvalidArgumentException(
                    "Existing alias chain already contains a cycle at {$current}."
                );
            }
            $seen[$current] = true;

            if (!isset($this->aliases[$current])) {
                break;
            }
            $current = $this->aliases[$current];
        }

        $this->aliases[$alias] = $abstract;
    }

    /**
     * Forget a previously registered or resolved instance.
     *
     * Does not remove the underlying binding; a singleton factory will
     * run again on the next resolve.
     */
    public function forgetInstance(string $abstract): void
    {
        unset($this->instances[$abstract]);
    }

    /**
     * Forget every resolved scoped instance, starting a new scope.
     */
    public function forgetScopedInstances(): void
    {
        $this->scopedInstances = [];
    }

    /**
     * Start a contextual binding builder for a given concrete class.
     */
    public function when(string $concrete): ContextualBindingBuilder
    {
        return new ContextualBindingBuilder($this, $concrete);
    }

    /**
     * Tag multiple abstractions under a single tag name.
     *
     * @param array<int, string> $abstracts
     */
    public function tag(array $abstracts, string $tag): void
    {
        if (!isset($this->tags[$tag])) {
            $this->tags[$tag] = [];
        }
        $this->tags[$tag] = array_unique(array_merge($this->tags[$tag], $abstracts));
    }

    /**
     * Resolve all abstractions registered under the given tag.
     *
     * @return array<int, mixed>
     */
    public function tagged(string $tag): array
    {
        if (!isset($this->tags[$tag])) {
            return [];
        }

        $instances = [];
        foreach ($this->tags[$tag] as $abstract) {
            $instances[] = $this->get($abstract);
        }

        return $instances;
    }

    /**
     * Resolve an abstract to its instance.
     *
     * @throws ContainerResolutionException
     */
    public function get(string $abstract): mixed
    {
        $abstract = $this->resolveAlias($abstract);

        if (isset($this->instances[$abstract])) {
            return $this->instances[$abstract];
        }

        if (isset($this->scopedInstances[$abstract])) {
            return $this->scopedInstances[$abstract];
        }

        if (isset($this->scoped[$abstract])) {
            $instance = $this->scoped[$abstract]($this);
            $this->scopedInstances[$abstract] = $instance;
            return $instance;
        }

        if (isset($this->singletons[$abstract])) {
            $instance = $this->singletons[$abstract]($this);
            $this->instances[$abstract] = $instance;
            return $instance;
        }

        if (isset($this->bindings[$abstract])) {
            return $this->bindings[$abstract]($this);
        }

        return $this->resolve($abstract);
    }

    /**
     * Check whether an abstract has any binding, instance, or alias
     * registered.
     */
    public function has(string $abstract): bool
    {
        $abstract = $this->resolveAlias($abstract);

        return isset($this->bindings[$abstract])
            || isset($this->singletons[$abstract])
            || isset($this->scoped[$abstract])
            || isset($this->instances[$abstract])
            || isset($this->scopedInstances[$abstract]);
    }

    /**
     * Set a contextual binding (used internally by ContextualBindingBuilder).
     *
     * @internal
     */
    public function setContextualBinding(string $concrete, string $abstract, mixed $implementation): void
    {
        $this->contextual[$concrete][$abstract] = $implementation;
    }

    /**
     * Resolve an alias chain to its final abstract.
     */
    private function resolveAlias(string $abstract): string
    {
        $seen = [];
        while (isset($this->aliases[$abstract])) {
            if (isset($seen[$abstract])) {
                // alias() prevents cycles, but a stray mutation via
                // reflection could still land here; fail loudly.
                throw new ContainerResolutionException(
                    "Circular alias detected while resolving {$abstract}."
                );
            }
            $seen[$abstract] = true;
            $abstract = $this->aliases[$abstract];
        }
        return $abstract;
    }

    /**
     * Normalize a concrete argument into a factory closure.
     */
    private function normalizeConcrete(\Closure|string $concrete, string $abstract): \Closure
    {
        if ($concrete instanceof \Closure) {
            return $concrete;
        }

        // Class-string: defer resolution to the container so that the
        // concrete class's own bindings (if any) are respected. When the
        // concrete equals the abstract, resolve by reflection to avoid
        // re-entering the same binding.
        if ($concrete === $abstract) {
            return fn(Container $c) => $c->resolve($concrete);
        }

        return fn(Container $c) => $c->get($concrete);
    }

    /**
     * Automatically resolve a class using reflection.
     *
     * @throws ContainerResolutionException
     */
    private function resolve(string $class): object
    {
        if (in_array($class, $this->resolving, true)) {
            $chain = implode(' -> ', $this->resolving) . ' -> ' . $class;
            throw new ContainerResolutionException(
                "Circular dependency detected: {$chain}. Please check your dependencies."
            );
        }

        $reflection = new \ReflectionClass($class);

        if (!$reflection->isInstantiable()) {
            if ($reflection->isInterface()) {
                throw new ContainerResolutionException("Interface {$class} has no binding registered.");
            }
            throw new ContainerResolutionException("Class {$class} is not instantiable.");
        }

        $constructor = $reflection->getConstructor();

        if (!$constructor) {
            return $reflection->newInstance();
        }

        $this->resolving[] = $class;
        $dependencies = [];

        try {
            foreach ($constructor->getParameters() as $parameter) {
                $type = $parameter->getType();

                // Union and intersection types cannot be auto-resolved.
                if ($type instanceof \ReflectionUnionType || $type instanceof \ReflectionIntersectionType) {
                    if ($parameter->isDefaultValueAvailable()) {
                        $dependencies[] = $parameter->getDefaultValue();
                        continue;
                    }
                    throw new ContainerResolutionException(
                        "Cannot auto-resolve union/intersection parameter \${$parameter->getName()} in {$class}"
                    );
                }

                // No type declaration at all.
                if (!$type instanceof \ReflectionNamedType) {
                    if ($parameter->isDefaultValueAvailable()) {
                        $dependencies[] = $parameter->getDefaultValue();
                        continue;
                    }
                    throw new ContainerResolutionException(
                        "Cannot resolve parameter \${$parameter->getName()} in {$class}"
                    );
                }

                // Built-in (scalar, array, etc.) — try default value.
                if ($type->isBuiltin()) {
                    if ($parameter->isDefaultValueAvailable()) {
                        $dependencies[] = $parameter->getDefaultValue();
                        continue;
                    }
                    throw new ContainerResolutionException(
                        "Cannot resolve parameter \${$parameter->getName()} in {$class}"
                    );
                }

                $typeName = $type->getName();

                $contextual = $this->resolveContextualBinding($class, $typeName);
                if ($contextual !== null) {
                    $dependencies[] = $contextual;
                    continue;
                }

                $dependencies[] = $this->get($typeName);
            }
        } finally {
            array_pop($this->resolving);
        }

        return $reflection->newInstanceArgs($dependencies);
    }

    /**
     * Resolve a contextual binding for a specific concrete/abstract pair.
     */
    private function resolveContextualBinding(string $concrete, string $abstract): mixed
    {
        if (!isset($this->contextual[$concrete][$abstract])) {
            return null;
        }

        $binding = $this->contextual[$concrete][$abstract];

        if (is_string($binding) && class_exists($binding)) {
            return $this->get($binding);
        }

        if (is_callable($binding)) {
            return $binding($this);
        }

        return $binding;
    }
}

/**
 * Builder for the first step of contextual binding (when...).
 *
 * @internal
 */
class ContextualBindingBuilder
{
    private Container $container;
    private string $concrete;

    public function __construct(Container $container, string $concrete)
    {
        $this->container = $container;
        $this->concrete = $concrete;
    }

    public function needs(string $abstract): ContextualBindingBuilderNeeds
    {
        return new ContextualBindingBuilderNeeds($this->container, $this->concrete, $abstract);
    }
}

/**
 * Builder for the second step of contextual binding (needs...give).
 *
 * @internal
 */
class ContextualBindingBuilderNeeds
{
    private Container $container;
    private string $concrete;
    private string $abstract;

    public function __construct(Container $container, string $concrete, string $abstract)
    {
        $this->container = $container;
        $this->concrete = $concrete;
        $this->abstract = $abstract;
    }

    public function give(mixed $implementation): void
    {
        $this->container->setContextualBinding($this->concrete, $this->abstract, $implementation);
    }
}
