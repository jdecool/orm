<?php
declare(strict_types = 1);

namespace Formal\ORM\Adapter\SQL;

use Formal\ORM\{
    Definition\Aggregate\Optional as Definition,
    Raw\Aggregate\Id,
    Raw\Aggregate\Property,
    Raw\Aggregate\Optional,
    Specification\Property as PropertySpecification,
};
use Formal\AccessLayer\{
    Table,
    Table\Column,
    Query,
    Query\Update,
    Query\Delete,
    Query\Select,
    Row,
};
use Innmind\Specification\{
    Sign,
    Specification,
};
use Innmind\Immutable\Sequence;

/**
 * @psalm-immutable
 * @template T of object
 */
final class OptionalTable
{
    /** @var Definition<T> */
    private Definition $definition;
    private Table\Name\Aliased $name;
    /** @var Sequence<Column\Name\Aliased> */
    private Sequence $columns;
    private Column\Name\Namespaced $id;
    private Select $select;

    /**
     * @param Definition<T> $definition
     */
    private function __construct(
        Definition $definition,
        Table\Name\Aliased $main,
    ) {
        $this->definition = $definition;
        $this->name = Table\Name::of($main->name()->toString().'_'.$definition->name())->as($definition->name());
        $this->columns = $definition
            ->properties()
            ->map(
                fn($property) => Column\Name::of($property->name())
                    ->in($this->name)
                    ->as($definition->name().'_'.$property->name()),
            );
        $this->id = Column\Name::of('aggregateId')->in($this->name);
        $this->select = Select::onDemand($this->name)->columns(
            $this->id,
            ...$this->columns->toList(),
        );
    }

    /**
     * @internal
     * @psalm-pure
     * @template A of object
     *
     * @param Definition<A> $definition
     *
     * @return self<A>
     */
    public static function of(
        Definition $definition,
        Table\Name\Aliased $main,
    ): self {
        return new self($definition, $main);
    }

    public function primaryKey(): Column
    {
        return Column::of(
            Column\Name::of('aggregateId'),
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
     * @return Sequence<Column\Name\Aliased>
     */
    public function columns(): Sequence
    {
        return $this->columns;
    }

    /**
     * @internal
     */
    public function select(Id $id): Select
    {
        return $this->select->where(PropertySpecification::of(
            'aggregateId',
            Sign::equality,
            $id->value(),
        ));
    }

    /**
     * @internal
     *
     * @param Sequence<Property> $properties
     */
    public function insert(Id $id, Sequence $properties): Query
    {
        return self::insertInto(
            $this->name->name(),
            $id,
            $properties,
        );
    }

    /**
     * @internal
     *
     * @return Sequence<Query>
     */
    public function update(Id $id, Optional|Optional\BrandNew $optional): Sequence
    {
        $name = $this->name;

        if ($optional instanceof Optional\BrandNew) {
            // No queries to make if the optional is brand new and no properties
            // as it means the state is the same
            return $optional
                ->properties()
                ->match(
                    static fn($properties) => Sequence::of(
                        self::insertInto($name->name(), $id, $properties),
                    ),
                    static fn() => Sequence::of(),
                );
        }

        return $optional->properties()->match(
            static fn($properties) => match ($properties->empty()) {
                true => Sequence::of(),
                false => Sequence::of(
                    Update::set(
                        $name,
                        Row::new(
                            ...$properties
                                ->map(static fn($property) => Row\Value::of(
                                    Column\Name::of($property->name()),
                                    $property->value(),
                                ))
                                ->toList(),
                        ),
                    )
                        ->where(PropertySpecification::of(
                            'aggregateId',
                            Sign::equality,
                            $id->value(),
                        )),
                ),
            },
            static fn() => Sequence::of(
                Delete::from($name)->where(PropertySpecification::of(
                    'aggregateId',
                    Sign::equality,
                    $id->value(),
                )),
            ),
        );
    }

    public function where(Specification $specification): Query
    {
        return Select::from($this->name)
            ->columns($this->id)
            ->where($specification);
    }

    public function whereAny(): Query
    {
        return Select::from($this->name)->columns($this->id);
    }

    /**
     * @psalm-pure
     *
     * @param Sequence<Property> $properties
     */
    private static function insertInto(
        Table\Name $name,
        Id $id,
        Sequence $properties,
    ): Query {
        return Query\Insert::into(
            $name,
            Row::new(
                Row\Value::of(
                    Column\Name::of('aggregateId'),
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
}
