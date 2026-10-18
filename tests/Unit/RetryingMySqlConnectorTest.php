<?php

namespace Tests\Unit;

use App\Support\Database\RetryingMySqlConnector;
use PDOException;
use PHPUnit\Framework\TestCase;

class RetryingMySqlConnectorTest extends TestCase
{
    private function connector(array $outcomes): object
    {
        return new class($outcomes) extends RetryingMySqlConnector
        {
            public int $calls = 0;

            public function __construct(private array $outcomes) {}

            protected function createPdoConnection($dsn, $username, $password, $options)
            {
                $outcome = $this->outcomes[$this->calls++] ?? null;

                if ($outcome instanceof \Throwable) {
                    throw $outcome;
                }

                return $outcome;
            }
        };
    }

    protected function setUp(): void
    {
        RetryingMySqlConnector::$waits = [0, 0, 0];
    }

    public function test_a_refused_connection_is_retried_and_then_succeeds(): void
    {
        $pdo = (new \ReflectionClass(\PDO::class))->newInstanceWithoutConstructor();
        $c = $this->connector([
            new PDOException('SQLSTATE[HY000] [2002] Operation not permitted'),
            new PDOException('SQLSTATE[HY000] [1040] Too many connections'),
            $pdo,
        ]);

        $this->assertSame($pdo, $c->createConnection('d', ['username' => 'u', 'password' => 'p'], []));
        $this->assertSame(3, $c->calls);
    }

    public function test_it_gives_up_after_three_extra_tries_with_the_last_error(): void
    {
        $c = $this->connector(array_fill(0, 6, new PDOException('SQLSTATE[HY000] [2002] Operation not permitted')));

        try {
            $c->createConnection('d', [], []);
            $this->fail('expected the exception');
        } catch (PDOException $e) {
            $this->assertStringContainsString('[2002]', $e->getMessage());
        }

        $this->assertSame(4, $c->calls);
    }

    public function test_a_wrong_password_is_never_retried(): void
    {
        $c = $this->connector([new PDOException("SQLSTATE[HY000] [1045] Access denied for user 'x'")]);

        $this->expectException(PDOException::class);
        try {
            $c->createConnection('d', [], []);
        } finally {
            $this->assertSame(1, $c->calls);
        }
    }
}
