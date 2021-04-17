<?php
declare(strict_types = 1);

namespace Tests\Formal\ORM\Repository;

use Formal\ORM\{
    Repository\SQL,
    Repository,
    Definition\Aggregate,
    SQL\Types,
    SQL\CreateTable,
    Id,
};
use Formal\AccessLayer\{
    Connection\PDO,
    Query,
    Table,
};
use Innmind\Url\Url;
use PHPUnit\Framework\TestCase;
use Innmind\BlackBox\{
    PHPUnit\BlackBox,
    Set,
};
use Example\Formal\ORM\User;

class SQLTest extends TestCase
{
    use BlackBox;

    private $allowMutation;
    private $connection;
    private $types;
    private $aggregate;
    private $repository;

    public function setUp(): void
    {
        $this->allowMutation = false;
        $this->connection = new PDO(Url::of('mysql://root:root@127.0.0.1:3306/example'));
        $this->types = new Types(...Types::default());
        $this->aggregate = Aggregate::of(User::class)
            ->exclude('doNotPersist');
        $this->repository = new SQL(
            User::class,
            $this->aggregate,
            $this->connection,
            $this->types,
            fn() => $this->allowMutation,
        );
    }

    public function testInterface()
    {
        $this->assertInstanceOf(
            Repository::class,
            $this->repository,
        );
    }

    public function testReturnNothingWhenGettingUnknownId()
    {
        $this->reset();
        $this
            ->forAll(Set\Uuid::any())
            ->then(function($uuid) {
                $this->assertFalse($this->repository->get(Id::of($uuid))->match(
                    static fn() => true,
                    static fn() => false,
                ));
            });
    }

    public function testThrowWhenTryingToAddWhenNotInTransaction()
    {
        $this
            ->forAll(
                Set\Uuid::any(),
                Set\Strings::madeOf(Set\Chars::alphanumerical()),
            )
            ->then(function($uuid, $username) {
                $this->reset();
                $this->allowMutation = false;

                $user = new User(Id::of($uuid), $username);

                try {
                    $this->repository->add($user);
                    $this->fail('it should throw');
                } catch (\LogicException $e) {
                    $this->assertSame(
                        'Trying to mutate the repository outside of a transaction',
                        $e->getMessage(),
                    );
                    $this->assertCount(0, $this->repository->all());
                }
            });
    }

    public function testAdd()
    {
        $this
            ->forAll(
                Set\Uuid::any(),
                Set\Strings::madeOf(Set\Chars::alphanumerical()),
            )
            ->then(function($uuid, $username) {
                $this->reset();
                $this->allowMutation = true;

                $user = new User(Id::of($uuid), $username);

                $this->assertCount(0, $this->repository->all());
                $this->assertNull($this->repository->add($user));
                $this->assertTrue($this->repository->get(Id::of($uuid))->match(
                    static fn() => true,
                    static fn() => false,
                ));
                $aggregate = $this->repository->get(Id::of($uuid))->match(
                    static fn($aggregate) => $aggregate,
                    static fn() => null,
                );
                $this->assertTrue($aggregate->equals($user));
                $this->assertCount(1, $this->repository->all());
            });
    }

    public function testThrowWhenTryingToRemoveWhenNotInTransaction()
    {
        $this
            ->forAll(Set\Uuid::any())
            ->then(function($uuid) {
                $this->reset();
                $this->allowMutation = false;

                try {
                    $this->repository->remove(Id::of($uuid));
                    $this->fail('it should throw');
                } catch (\LogicException $e) {
                    $this->assertSame(
                        'Trying to mutate the repository outside of a transaction',
                        $e->getMessage(),
                    );
                    $this->assertCount(0, $this->repository->all());
                }
            });
    }

    public function testRemove()
    {
        $this
            ->forAll(
                Set\Uuid::any(),
                Set\Strings::madeOf(Set\Chars::alphanumerical()),
            )
            ->then(function($uuid, $username) {
                $this->reset();
                $this->allowMutation = true;

                $user = new User(Id::of($uuid), $username);

                $this->assertCount(0, $this->repository->all());
                $this->assertNull($this->repository->add($user));
                $this->assertNull($this->repository->remove(Id::of($uuid)));
                $this->assertFalse($this->repository->get(Id::of($uuid))->match(
                    static fn() => true,
                    static fn() => false,
                ));
                $this->assertCount(0, $this->repository->all());
            });
    }

    public function testAll()
    {
        $this
            ->forAll(Set\Sequence::of(
                new Set\Randomize(Set\Composite::mutable(
                    static fn($uuid, $username) => new User(Id::of($uuid), $username),
                    Set\Uuid::any(),
                    Set\Strings::madeOf(Set\Chars::alphanumerical()),
                )),
                Set\Integers::between(0, 5),
            ))
            ->then(function($users) {
                $this->reset();
                $this->allowMutation = true;

                foreach ($users as $user) {
                    $this->repository->add($user);
                }

                $all = $this->repository->all();

                $this->assertSame(User::class, $all->type());
                $this->assertCount(\count($users), $all);
            });
    }

    private function reset(): void
    {
        ($this->connection)(Query\DropTable::ifExists(new Table\Name($this->aggregate->name())));
        $create = new CreateTable($this->types);
        ($this->connection)($create($this->aggregate));
    }
}