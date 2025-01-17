<?php
declare(strict_types = 1);

namespace Formal\ORM\Adapter\SQL;

use Formal\ORM\{
    Definition\Aggregate as Definition,
    Raw\Aggregate,
    Raw\Aggregate\Id,
    Raw\Diff,
    Specification\Property,
    Specification\Entity,
    Specification\Child,
    Specification\Just,
    Specification\Has,
    Specification\CrossMatch,
};
use Formal\AccessLayer\{
    Table,
    Table\Column,
    Query,
    Query\Delete,
    Query\Update,
    Query\Select,
    Query\Select\Join,
    Row,
};
use Innmind\Specification\{
    Specification,
    Not,
    Comparator,
    Composite,
    Operator,
    Sign,
};
use Innmind\Immutable\{
    Map,
    Maybe,
    Sequence,
};

/**
 * @psalm-immutable
 * @template T of object
 */
final class MainTable
{
    /** @var Definition<T> */
    private Definition $definition;
    private Table\Name\Aliased $name;
    private Select $select;
    private Select $contains;
    private Select $count;
    private Delete $delete;
    /** @var Map<non-empty-string, EntityTable> */
    private Map $entities;
    /** @var Map<non-empty-string, OptionalTable> */
    private Map $optionals;
    /** @var Map<non-empty-string, CollectionTable> */
    private Map $collections;

    /**
     * @param Definition<T> $definition
     */
    private function __construct(Definition $definition)
    {
        $this->definition = $definition;
        $this->name = Table\Name::of($definition->name())->as('entity');
        $entities = Map::of(
            ...$definition
                ->entities()
                ->map(fn($entity) => [
                    $entity->name(),
                    EntityTable::of($entity, $this->name),
                ])
                ->toList(),
        );
        $optionals = Map::of(
            ...$definition
                ->optionals()
                ->map(fn($entity) => [
                    $entity->name(),
                    OptionalTable::of($entity, $this->name),
                ])
                ->toList(),
        );
        $collections = Map::of(
            ...$definition
                ->collections()
                ->map(fn($collection) => [
                    $collection->name(),
                    CollectionTable::of($collection, $this->name),
                ])
                ->toList(),
        );
        $joins = $entities->values()->map(
            fn($table) => Join::left($table->name())->on(
                Column\Name::of($this->definition->id()->property())->in($this->name),
                $table->primaryKey()->name()->in($table->name()),
            ),
        );
        $select = $joins->reduce(
            Select::onDemand($this->name),
            static fn(Select $select, $join) => $select->join($join),
        );
        $this->select = $select
            ->columns(
                Column\Name::of($definition->id()->property())
                    ->in($this->name)
                    ->as('entity_'.$definition->id()->property()),
                ...$definition
                    ->properties()
                    ->map(
                        fn($property) => Column\Name::of($property->name())
                            ->in($this->name)
                            ->as('entity_'.$property->name()),
                    )
                    ->toList(),
                ...$entities
                    ->values()
                    ->flatMap(static fn($table) => $table->columns())
                    ->toList(),
            );
        // No need for this query to be lazy as the result is directly collapsed
        // to a boolean
        $this->contains = Select::from($this->name)
            ->columns(Column\Name::of($definition->id()->property())->in($this->name));
        // No need for this query to be lazy as the result is directly collapsed
        // to an int
        $this->count = $joins->reduce(
            Select::from($this->name)->count('count'),
            static fn(Select $select, $join) => $select->join($join),
        );
        $this->delete = $joins->reduce(
            Delete::from($this->name),
            static fn(Delete $delete, $join) => $delete->join($join),
        );
        $this->entities = $entities;
        $this->optionals = $optionals;
        $this->collections = $collections;
    }

    /**
     * @psalm-pure
     * @template A of object
     *
     * @param Definition<A> $definition
     *
     * @return self<A>
     */
    public static function of(Definition $definition): self
    {
        return new self($definition);
    }

    public function primaryKey(): Column
    {
        return Column::of(
            Column\Name::of($this->definition->id()->property()),
            Column\Type::uuid()->comment('UUID'),
        );
    }

    /**
     * @return Sequence<Column>
     */
    public function columnsDefinition(MapType $mapType): Sequence
    {
        return $this
            ->definition
            ->properties()
            ->map(static fn($property) => Column::of(
                Column\Name::of($property->name()),
                $mapType($property->type()),
            ));
    }

    public function name(): Table\Name\Aliased
    {
        return $this->name;
    }

    /**
     * @internal
     */
    public function select(Specification $specification = null): Select
    {
        return match ($specification) {
            null => $this->select,
            default => $this->select->where($this->where($specification)),
        };
    }

    /**
     * @internal
     */
    public function search(Specification $specification = null): Select
    {
        $select = $this
            ->select
            ->columns(
                Column\Name::of($this->definition->id()->property())
                    ->in($this->name),
            );

        return match ($specification) {
            null => $select,
            default => $select->where($this->where($specification)),
        };
    }

    /**
     * @internal
     */
    public function contains(): Select
    {
        return $this->contains;
    }

    /**
     * @internal
     */
    public function count(Specification $specification = null): Select
    {
        return match ($specification) {
            null => $this->count,
            default => $this->count->where($this->where($specification)),
        };
    }

    /**
     * @internal
     *
     * @param Sequence<Aggregate\Property> $properties
     */
    public function insert(
        Id $id,
        Sequence $properties,
    ): Query {
        return Query\Insert::into(
            $this->name->name(),
            Row::new(
                Row\Value::of(
                    Column\Name::of($this->definition->id()->property()),
                    $id->value(),
                ),
                ...$properties
                    ->map(static fn($property) => Row\Value::of(
                        Column\Name::of($property->name()),
                        $property->value(),
                    ))
                    ->toList(),
            ),
        );
    }

    /**
     * @internal
     *
     * @return Maybe<Query>
     */
    public function update(Diff $data): Maybe
    {
        $table = $this->name->name();
        $id = $this->definition->id()->property();

        return Maybe::just($data->properties())
            ->filter(static fn($properties) => !$properties->empty())
            ->map(
                static fn($properties) => Update::set(
                    $table,
                    Row::new(
                        ...$properties
                            ->map(static fn($property) => Row\Value::of(
                                Column\Name::of($property->name()),
                                $property->value(),
                            ))
                            ->toList(),
                    ),
                )->where(Property::of(
                    $id,
                    Sign::equality,
                    $data->id()->value(),
                )),
            );
    }

    /**
     * @internal
     */
    public function delete(Specification $specification = null): Delete
    {
        return match ($specification) {
            null => $this->delete,
            default => $this->delete->where($this->where($specification)),
        };
    }

    /**
     * @return Sequence<EntityTable>
     */
    public function entities(): Sequence
    {
        return $this->entities->values();
    }

    /**
     * @param non-empty-string $name
     *
     * @return Maybe<EntityTable>
     */
    public function entity(string $name): Maybe
    {
        return $this->entities->get($name);
    }

    /**
     * @return Sequence<OptionalTable>
     */
    public function optionals(): Sequence
    {
        return $this->optionals->values();
    }

    /**
     * @param non-empty-string $name
     *
     * @return Maybe<OptionalTable>
     */
    public function optional(string $name): Maybe
    {
        return $this->optionals->get($name);
    }

    /**
     * @return Sequence<CollectionTable>
     */
    public function collections(): Sequence
    {
        return $this->collections->values();
    }

    /**
     * @param non-empty-string $name
     *
     * @return Maybe<CollectionTable>
     */
    public function collection(string $name): Maybe
    {
        return $this->collections->get($name);
    }

    private function where(Specification $specification): Specification
    {
        if ($specification instanceof Not) {
            return $this->where($specification->specification())->not();
        }

        if ($specification instanceof Composite) {
            $left = $this->where($specification->left());
            $right = $this->where($specification->right());

            return match ($specification->operator()) {
                Operator::and => $left->and($right),
                Operator::or => $left->or($right),
            };
        }

        if ($specification instanceof Entity) {
            return $this->whereEntity($specification);
        }

        if ($specification instanceof Child) {
            return SubQuery::of(
                \sprintf('entity.%s', $this->definition->id()->property()),
                $this
                    ->collection($specification->collection())
                    ->match(
                        static fn($collection) => $collection->where($specification->specification()),
                        static fn() => throw new \LogicException("Unkown collection '{$specification->collection()}'"),
                    ),
            );
        }

        if ($specification instanceof Just) {
            return SubQuery::of(
                \sprintf('entity.%s', $this->definition->id()->property()),
                $this
                    ->optional($specification->optional())
                    ->match(
                        static fn($optional) => $optional->where($specification->specification()),
                        static fn() => throw new \LogicException("Unkown optional '{$specification->optional()}'"),
                    ),
            );
        }

        if ($specification instanceof Has) {
            return SubQuery::of(
                \sprintf('entity.%s', $this->definition->id()->property()),
                $this
                    ->optional($specification->optional())
                    ->match(
                        static fn($optional) => $optional->whereAny(),
                        static fn() => throw new \LogicException("Unkown optional '{$specification->optional()}'"),
                    ),
            );
        }

        if ($specification instanceof CrossMatch) {
            return Comparator\Property::of(
                'entity.'.$specification->property(),
                $specification->sign(),
                $specification->value(),
            );
        }

        if (!($specification instanceof Property)) {
            $class = $specification::class;

            throw new \LogicException("Unsupported specification '$class'");
        }

        return Property::of(
            'entity.'.$specification->property(),
            $specification->sign(),
            $specification->value(),
        );
    }

    private function whereEntity(Entity $specification): Specification
    {
        $underlying = $specification->specification();

        if ($underlying instanceof Not) {
            return $this
                ->whereEntity(Entity::of(
                    $specification->entity(),
                    $underlying->specification(),
                ))
                ->not();
        }

        if ($underlying instanceof Composite) {
            $left = $this->whereEntity(Entity::of(
                $specification->entity(),
                $underlying->left(),
            ));
            $right = $this->whereEntity(Entity::of(
                $specification->entity(),
                $underlying->right(),
            ));

            return match ($underlying->operator()) {
                Operator::and => $left->and($right),
                Operator::or => $left->or($right),
            };
        }

        if ($underlying instanceof CrossMatch) {
            return Comparator\Property::of(
                \sprintf(
                    '%s.%s',
                    $specification->entity(),
                    $underlying->property(),
                ),
                $underlying->sign(),
                $underlying->value(),
            );
        }

        if (!($underlying instanceof Property)) {
            $class = $underlying::class;

            throw new \LogicException("Unsupported specification '$class'");
        }

        return Property::of(
            \sprintf(
                '%s.%s',
                $specification->entity(),
                $underlying->property(),
            ),
            $underlying->sign(),
            $underlying->value(),
        );
    }
}
