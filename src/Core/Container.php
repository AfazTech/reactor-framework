<?php

namespace Reactor\Core;

use Reactor\Exceptions\ContainerResolutionException;

/**
 * A simple dependency injection container with support for:
 * - Singleton and transient bindings
 * - Contextual binding
 * - Tagging
 * - Automatic resolution with circular dependency detection.
 */
class Container
{
    /**
     * @var array<string, object>  Resolved singleton instances.
     */
    private array $instances = [];

    /**
     * @var array<string, \Closure>  Transient (factory) bindings.
     */
    private array $bindings = [];

    /**
     * @var array<string, \Closure>  Singleton factory bindings.
     */
    private array $singletons = [];

    /**
     * @var array<string, array<string, mixed>>  Contextual bindings: [concrete][abstract] => implementation.
     */
    private array $contextual = [];

    /**
     * @var array<string, array<int, string>>  Tags mapping: tag => list of abstracts.
     */
    private array $tags = [];

    /**
     * @var array<int, string>  Stack of classes being resolved to detect circular dependencies.
     */
    private array $resolving = [];

    /**
     * Register a singleton factory.
     *
     * @param string   $abstract The abstract identifier (class/interface name).
     * @param \Closure $factory  Factory that returns the singleton instance.
     */
    public function singleton(string $abstract, \Closure $factory): void
    {
        $this->singletons[$abstract] = $factory;
    }

    /**
     * Register a transient (non-singleton) factory.
     *
     * @param string   $abstract The abstract identifier.
     * @param \Closure $factory  Factory that returns a new instance each time.
     */
    public function bind(string $abstract, \Closure $factory): void
    {
        $this->bindings[$abstract] = $factory;
    }

    /**
     * Start a contextual binding builder for a given concrete class.
     *
     * @param string $concrete The class that needs a specific dependency.
     *
     * @return ContextualBindingBuilder
     */
    public function when(string $concrete): ContextualBindingBuilder
    {
        return new ContextualBindingBuilder($this, $concrete);
    }

    /**
     * Tag multiple abstractions under a single tag name.
     *
     * @param array<int, string> $abstracts List of abstract identifiers.
     * @param string             $tag       The tag name.
     */
    public function tag(array $abstracts, string $tag): void
    {
        if (!isset($this->tags[$tag])) {
            $this->tags[$tag] = [];
        }
        $this->tags[$tag] = array_unique(array_merge($this->tags[$tag], $abstracts));
    }

    /**
     * Resolve all tagged abstractions.
     *
     * @param string $tag The tag name.
     *
     * @return array<int, object> List of resolved instances.
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
     * Resolve an abstraction (class/interface) to an instance.
     *
     * @param string $abstract The abstract identifier.
     *
     * @return mixed The resolved instance.
     *
     * @throws ContainerResolutionException If the abstract cannot be resolved.
     */
    public function get(string $abstract)
    {
        // Return already resolved singleton.
        if (isset($this->instances[$abstract])) {
            return $this->instances[$abstract];
        }

        // Resolve singleton factory.
        if (isset($this->singletons[$abstract])) {
            $instance = $this->singletons[$abstract]($this);
            $this->instances[$abstract] = $instance;
            return $instance;
        }

        // Resolve transient binding.
        if (isset($this->bindings[$abstract])) {
            return $this->bindings[$abstract]($this);
        }

        // Auto-resolve via reflection.
        return $this->resolve($abstract);
    }

    /**
     * Automatically resolve a class using reflection.
     *
     * @param string $class The fully-qualified class name.
     *
     * @return object The resolved instance.
     *
     * @throws ContainerResolutionException If the class is not instantiable or dependencies cannot be resolved.
     */
    private function resolve(string $class): object
    {
        // Detect circular dependency.
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

        // No constructor → simple instantiation.
        if (!$constructor) {
            return $reflection->newInstance();
        }

        // Resolve constructor dependencies.
        $this->resolving[] = $class;
        $dependencies = [];

        try {
            foreach ($constructor->getParameters() as $parameter) {
                $type = $parameter->getType();

                // Built-in types or no type: try default value.
                if (!$type || $type->isBuiltin()) {
                    if ($parameter->isDefaultValueAvailable()) {
                        $dependencies[] = $parameter->getDefaultValue();
                        continue;
                    }
                    throw new ContainerResolutionException(
                        "Cannot resolve parameter \${$parameter->getName()} in {$class}"
                    );
                }

                $typeName = $type->getName();

                // Check contextual binding.
                $contextual = $this->resolveContextualBinding($class, $typeName);
                if ($contextual !== null) {
                    $dependencies[] = $contextual;
                    continue;
                }

                // Resolve recursively.
                $dependencies[] = $this->get($typeName);
            }
        } finally {
            array_pop($this->resolving);
        }

        return $reflection->newInstanceArgs($dependencies);
    }

    /**
     * Resolve a contextual binding for a specific concrete/abstract pair.
     *
     * @param string $concrete The class being constructed.
     * @param string $abstract The abstract dependency type.
     *
     * @return mixed|null The resolved dependency, or null if no contextual binding exists.
     */
    private function resolveContextualBinding(string $concrete, string $abstract)
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

    /**
     * Set a contextual binding.
     *
     * @param string $concrete       The class that needs the dependency.
     * @param string $abstract       The abstract dependency type.
     * @param mixed  $implementation The concrete implementation (class name, factory callable, or instance).
     */
    public function setContextualBinding(string $concrete, string $abstract, $implementation): void
    {
        $this->contextual[$concrete][$abstract] = $implementation;
    }

    /**
     * Check if an abstraction has been bound (singleton, transient, or instance).
     *
     * @param string $abstract The abstract identifier.
     *
     * @return bool True if a binding exists.
     */
    public function has(string $abstract): bool
    {
        return isset($this->bindings[$abstract]) ||
               isset($this->singletons[$abstract]) ||
               isset($this->instances[$abstract]);
    }
}

/**
 * Builder for the first step of contextual binding (when...).
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

    /**
     * Specify the abstract dependency that the concrete class needs.
     *
     * @param string $abstract The abstract type.
     *
     * @return ContextualBindingBuilderNeeds
     */
    public function needs(string $abstract): ContextualBindingBuilderNeeds
    {
        return new ContextualBindingBuilderNeeds($this->container, $this->concrete, $abstract);
    }
}

/**
 * Builder for the second step of contextual binding (needs...give).
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

    /**
     * Provide the concrete implementation for the dependency.
     *
     * @param mixed $implementation The implementation (class name, callable, or instance).
     */
    public function give($implementation): void
    {
        $this->container->setContextualBinding($this->concrete, $this->abstract, $implementation);
    }
}
