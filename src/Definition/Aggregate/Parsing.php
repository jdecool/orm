<?php
declare(strict_types = 1);

namespace Formal\ORM\Definition\Aggregate;

use Formal\ORM\{
    Id,
    Definition\Contains,
    Definition\Types,
};
use Innmind\Reflection\{
    ReflectionClass,
    ReflectionProperty,
};
use Innmind\Type\ClassName;
use Innmind\Immutable\{
    Maybe,
    Set,
    Predicate\Instance,
};

/**
 * @internal
 * @template T of object
 */
final class Parsing
{
    /** @var class-string<T> */
    private string $class;
    /** @var Maybe<Identity<T>> */
    private Maybe $id;
    /** @var Set<Property<T, mixed>> */
    private Set $properties;
    /** @var Set<Entity> */
    private Set $entities;
    /** @var Set<Optional> */
    private Set $optionals;
    /** @var Set<Collection> */
    private Set $collections;

    /**
     * @param class-string<T> $class
     * @param Maybe<Identity<T>> $id
     * @param Set<Property<T, mixed>> $properties
     * @param Set<Entity> $entities
     * @param Set<Optional> $optionals
     * @param Set<Collection> $collections
     */
    private function __construct(
        string $class,
        Maybe $id,
        Set $properties,
        Set $entities,
        Set $optionals,
        Set $collections,
    ) {
        $this->class = $class;
        $this->id = $id;
        $this->properties = $properties;
        $this->entities = $entities;
        $this->optionals = $optionals;
        $this->collections = $collections;
    }

    /**
     * @internal
     * @template A of object
     *
     * @param class-string<A> $class
     *
     * @return self<A>
     */
    public static function of(string $class): self
    {
        /** @var Maybe<Identity<A>> */
        $id = Maybe::nothing();

        return new self($class, $id, Set::of(), Set::of(), Set::of(), Set::of());
    }

    /**
     * @param ReflectionProperty<T> $property
     *
     * @return self<T>
     */
    public function with(ReflectionProperty $property, Types $types): self
    {
        return $this
            ->parse($property, $types)
            ->match(
                fn($parsed) => match ($parsed::class) {
                    Identity::class => new self(
                        $this->class,
                        Maybe::just($parsed), // we can override here because we force the id property to be named "id" so there can only be one
                        $this->properties,
                        $this->entities,
                        $this->optionals,
                        $this->collections,
                    ),
                    Property::class => new self(
                        $this->class,
                        $this->id,
                        ($this->properties)($parsed),
                        $this->entities,
                        $this->optionals,
                        $this->collections,
                    ),
                    Entity::class => new self(
                        $this->class,
                        $this->id,
                        $this->properties,
                        ($this->entities)($parsed),
                        $this->optionals,
                        $this->collections,
                    ),
                    Optional::class => new self(
                        $this->class,
                        $this->id,
                        $this->properties,
                        $this->entities,
                        ($this->optionals)($parsed),
                        $this->collections,
                    ),
                    Collection::class => new self(
                        $this->class,
                        $this->id,
                        $this->properties,
                        $this->entities,
                        $this->optionals,
                        ($this->collections)($parsed),
                    ),
                },
                fn() => $this, // silently discard unparseable properties
            );
    }

    /**
     * @return Maybe<Identity<T>>
     */
    public function id(): Maybe
    {
        return $this->id;
    }

    /**
     * @return Set<Property<T, mixed>>
     */
    public function properties(): Set
    {
        return $this->properties;
    }

    /**
     * @return Set<Entity>
     */
    public function entities(): Set
    {
        return $this->entities;
    }

    /**
     * @return Set<Optional>
     */
    public function optionals(): Set
    {
        return $this->optionals;
    }

    /**
     * @return Set<Collection>
     */
    public function collections(): Set
    {
        return $this->collections;
    }

    /**
     * @param ReflectionProperty<T> $property
     *
     * @return Maybe<Identity<T>|Property<T, mixed>|Entity|Optional|Collection>
     */
    private function parse(ReflectionProperty $property, Types $types): Maybe
    {
        return $this
            ->parseId($property)
            ->otherwise(fn() => $this->parseProperty($this->class, $property, $types))
            ->otherwise(fn() => $this->parseOptional($property, $types))
            ->otherwise(fn() => $this->parseCollection($property, $types))
            ->otherwise(fn() => $this->parseEntity($property, $types));
    }

    /**
     * @param ReflectionProperty<T> $property
     *
     * @return Maybe<Identity<T>>
     */
    private function parseId(ReflectionProperty $property): Maybe
    {
        return Maybe::just($property)
            ->filter(static fn($property) => $property->name() === 'id')
            ->filter(static fn($property) => $property->type()->toString() === Id::class)
            ->map(fn($property) => Identity::of($property->name(), $this->class));
    }

    /**
     * @template A of object
     *
     * @param class-string<A> $class
     * @param ReflectionProperty<A> $property
     *
     * @return Maybe<Property<A, mixed>>
     */
    private function parseProperty(
        string $class,
        ReflectionProperty $property,
        Types $types,
    ): Maybe {
        return Maybe::just($property)
            ->exclude(static fn($property) => $property->name() === 'id')
            ->flatMap(static fn($property) => $types(
                $property->type()->type(),
                $property
                    ->attributes()
                    ->find(static fn($attribute) => $attribute->class() === Contains::class)
                    ->map(static fn($attribute) => $attribute->instance())
                    ->keep(Instance::of(Contains::class))
                    ->match(
                        static fn($contains) => $contains,
                        static fn() => null,
                    ),
            ))
            ->map(static fn($type) => Property::of(
                $class,
                $property->name(),
                $type,
            ));
    }

    /**
     * @param ReflectionProperty<T> $property
     *
     * @return Maybe<Entity>
     */
    private function parseEntity(ReflectionProperty $property, Types $types): Maybe
    {
        /**
         * @psalm-suppress ArgumentTypeCoercion
         * @psalm-suppress InvalidArgument
         */
        return Maybe::just($property)
            ->exclude(static fn($property) => $property->name() === 'id')
            ->filter(static fn($property) => $property->type()->type() instanceof ClassName)
            ->map(fn($property) => Entity::of(
                $property->type()->toString(),
                $property->name(),
                ReflectionClass::of($property->type()->toString())
                    ->properties()
                    ->flatMap(
                        fn($innerProperty) => $this->parseProperty(
                            $property->type()->toString(),
                            $innerProperty,
                            $types,
                        )
                            ->toSequence()
                            ->toSet(),
                    ),
            ));
    }

    /**
     * @param ReflectionProperty<T> $property
     *
     * @return Maybe<Optional>
     */
    private function parseOptional(ReflectionProperty $property, Types $types): Maybe
    {
        /**
         * @psalm-suppress ArgumentTypeCoercion
         * @psalm-suppress InvalidArgument
         */
        return Maybe::just($property)
            ->exclude(static fn($property) => $property->name() === 'id')
            ->filter(static fn($property) => $property->type()->type()->accepts(ClassName::of(Maybe::class)))
            ->flatMap(
                fn($property) => $property
                    ->attributes()
                    ->find(static fn($attribute) => $attribute->class() === Contains::class)
                    ->map(static fn($attribute) => $attribute->instance())
                    ->keep(Instance::of(Contains::class))
                    ->map(fn($contains) => Optional::of(
                        $contains->type()->toString(),
                        $property->name(),
                        ReflectionClass::of($contains->type()->toString())
                            ->properties()
                            ->flatMap(
                                fn($innerProperty) => $this->parseProperty(
                                    $property->type()->toString(),
                                    $innerProperty,
                                    $types,
                                )
                                    ->toSequence()
                                    ->toSet(),
                            ),
                    )),
            );
    }

    /**
     * @param ReflectionProperty<T> $property
     *
     * @return Maybe<Collection>
     */
    private function parseCollection(ReflectionProperty $property, Types $types): Maybe
    {
        /**
         * @psalm-suppress ArgumentTypeCoercion
         * @psalm-suppress InvalidArgument
         */
        return Maybe::just($property)
            ->exclude(static fn($property) => $property->name() === 'id')
            ->filter(static fn($property) => $property->type()->type()->accepts(ClassName::of(Set::class)))
            ->flatMap(
                fn($property) => $property
                    ->attributes()
                    ->find(static fn($attribute) => $attribute->class() === Contains::class)
                    ->map(static fn($attribute) => $attribute->instance())
                    ->keep(Instance::of(Contains::class))
                    ->map(fn($contains) => Collection::of(
                        $contains->type()->toString(),
                        $property->name(),
                        ReflectionClass::of($contains->type()->toString())
                            ->properties()
                            ->flatMap(
                                fn($innerProperty) => $this->parseProperty(
                                    $property->type()->toString(),
                                    $innerProperty,
                                    $types,
                                )
                                    ->toSequence()
                                    ->toSet(),
                            ),
                    )),
            );
    }
}