<?php

/**
 * includes/Core/Database.php
 * ==========================================================================
 * Shared PDO access layer.
 *
 * Wraps a single PDO instance with the platform's standard options (real
 * prepared statements, exceptions, associative fetch) and adds ergonomic
 * helpers for the query patterns used across every DMF application:
 * select/selectOne/scalar/execute/insert plus transaction management.
 *
 * "Connection pool ready": named connections are registered once via
 * addConnection() and instantiated lazily through connection(), so a single
 * process can hold several distinct databases without global coupling — the
 * seam where a pooling driver could later slot in.
 *
 * There is no ORM and no query builder by design; callers write plain SQL with
 * positional `?` parameters, matching the canonical implementation.
 * ==========================================================================
 */

declare(strict_types=1);

namespace DMF\Core;

use PDO;
use PDOException;
use PDOStatement;
use RuntimeException;
use Throwable;

final class Database
{
    /** @var array<string,array<string,mixed>> Registered connection configs. */
    private static array $configs = [];

    /** @var array<string,Database> Instantiated connections (the "pool"). */
    private static array $pool = [];

    private PDO $pdo;

    /**
     * @param array<string,mixed> $config host, port, database, charset,
     *                                     username, password, options, persistent
     */
    public function __construct(array $config, private ?Logger $logger = null)
    {
        $this->pdo = $this->connect($config);
    }

    // ── Pool registry ─────────────────────────────────────────────────────

    /**
     * Register a named connection's configuration without connecting yet.
     *
     * @param array<string,mixed> $config
     */
    public static function addConnection(string $name, array $config): void
    {
        self::$configs[$name] = $config;
        unset(self::$pool[$name]);
    }

    /**
     * Get (lazily creating) a named connection.
     */
    public static function connection(string $name = 'default', ?Logger $logger = null): self
    {
        if (isset(self::$pool[$name])) {
            return self::$pool[$name];
        }
        if (!isset(self::$configs[$name])) {
            throw new RuntimeException("Database connection '{$name}' is not configured.");
        }
        return self::$pool[$name] = new self(self::$configs[$name], $logger);
    }

    /**
     * Drop all pooled connections (e.g. between test cases).
     */
    public static function purge(): void
    {
        self::$pool = [];
    }

    // ── Connection ────────────────────────────────────────────────────────

    /**
     * @param array<string,mixed> $config
     */
    private function connect(array $config): PDO
    {
        $host = (string) ($config['host'] ?? '127.0.0.1');
        $port = (string) ($config['port'] ?? '3306');
        $name = (string) ($config['database'] ?? '');
        $charset = (string) ($config['charset'] ?? 'utf8mb4');

        $dsn = "mysql:host={$host};port={$port};dbname={$name};charset={$charset}";

        $options = [
            PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES   => false,
            PDO::ATTR_PERSISTENT         => (bool) ($config['persistent'] ?? false),
        ];

        /** @var array<int,mixed> $extra */
        $extra = is_array($config['options'] ?? null) ? $config['options'] : [];
        $options = $extra + $options;

        try {
            return new PDO($dsn, (string) ($config['username'] ?? ''), (string) ($config['password'] ?? ''), $options);
        } catch (PDOException $e) {
            $this->logger?->critical('Database connection failed: {message}', ['message' => $e->getMessage()]);
            // Fail closed — never leak DSN/credentials to the caller.
            throw new RuntimeException('Database connection failed.', (int) $e->getCode());
        }
    }

    /**
     * The underlying PDO instance for advanced use.
     */
    public function pdo(): PDO
    {
        return $this->pdo;
    }

    // ── Queries (prepared statements) ─────────────────────────────────────

    /**
     * Prepare and execute a statement, returning it.
     *
     * @param array<int|string,mixed> $params
     */
    public function query(string $sql, array $params = []): PDOStatement
    {
        try {
            $stmt = $this->pdo->prepare($sql);
            $stmt->execute($params);
            return $stmt;
        } catch (PDOException $e) {
            $this->logger?->error('Query failed: {message}', ['message' => $e->getMessage(), 'sql' => $sql]);
            throw $e;
        }
    }

    /**
     * Fetch all rows.
     *
     * @param array<int|string,mixed> $params
     * @return array<int,array<string,mixed>>
     */
    public function select(string $sql, array $params = []): array
    {
        return $this->query($sql, $params)->fetchAll();
    }

    /**
     * Fetch the first row, or null.
     *
     * @param array<int|string,mixed> $params
     * @return array<string,mixed>|null
     */
    public function selectOne(string $sql, array $params = []): ?array
    {
        $row = $this->query($sql, $params)->fetch();
        return $row === false ? null : $row;
    }

    /**
     * Fetch a single scalar value from the first column of the first row.
     *
     * @param array<int|string,mixed> $params
     */
    public function scalar(string $sql, array $params = []): mixed
    {
        $value = $this->query($sql, $params)->fetchColumn();
        return $value === false ? null : $value;
    }

    /**
     * Execute a write and return the number of affected rows.
     *
     * @param array<int|string,mixed> $params
     */
    public function execute(string $sql, array $params = []): int
    {
        return $this->query($sql, $params)->rowCount();
    }

    /**
     * Execute an INSERT and return the new auto-increment id.
     *
     * @param array<int|string,mixed> $params
     */
    public function insert(string $sql, array $params = []): string
    {
        $this->query($sql, $params);
        return $this->pdo->lastInsertId();
    }

    public function lastInsertId(): string
    {
        return $this->pdo->lastInsertId();
    }

    // ── Transactions ──────────────────────────────────────────────────────

    public function beginTransaction(): void
    {
        $this->pdo->beginTransaction();
    }

    public function commit(): void
    {
        $this->pdo->commit();
    }

    public function rollBack(): void
    {
        if ($this->pdo->inTransaction()) {
            $this->pdo->rollBack();
        }
    }

    public function inTransaction(): bool
    {
        return $this->pdo->inTransaction();
    }

    /**
     * Run a callback inside a transaction, committing on success and rolling
     * back on any throwable. The callback receives this Database instance and
     * its return value is passed through.
     *
     * @template T
     * @param callable(Database):T $callback
     * @return T
     */
    public function transaction(callable $callback): mixed
    {
        $this->beginTransaction();
        try {
            $result = $callback($this);
            $this->commit();
            return $result;
        } catch (Throwable $e) {
            $this->rollBack();
            $this->logger?->error('Transaction rolled back: {message}', ['message' => $e->getMessage()]);
            throw $e;
        }
    }
}
