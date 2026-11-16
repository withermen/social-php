<?php

namespace Social\Database;

use PDO;
use PDOStatement;

final class Database
{
    private PDO $pdo;

    public function __construct(array $config)
    {
        $driver = strtolower((string) ($config['driver'] ?? 'sqlite'));

        if ($driver === 'sqlite') {
            $path = (string) ($config['database'] ?? ':memory:');

            if ($path !== ':memory:' && !is_dir(dirname($path))) {
                mkdir(dirname($path), 0775, true);
            }

            $this->pdo = new PDO('sqlite:' . $path, null, null, $this->options());
            $this->pdo->exec('PRAGMA foreign_keys = ON');
            $this->pdo->exec('PRAGMA journal_mode = WAL');
            $this->pdo->exec('PRAGMA busy_timeout = 5000');
        } elseif ($driver === 'mysql') {
            $dsn = sprintf(
                'mysql:host=%s;port=%d;dbname=%s;charset=utf8mb4',
                $config['host'] ?? '127.0.0.1',
                (int) ($config['port'] ?? 3306),
                $config['name'] ?? 'social'
            );
            $this->pdo = new PDO($dsn, (string) ($config['user'] ?? 'root'), (string) ($config['password'] ?? ''), $this->options());
        } else {
            throw new \RuntimeException('Unsupported database driver: ' . $driver);
        }
    }

    public static function inMemory(): self
    {
        return new self(['driver' => 'sqlite', 'database' => ':memory:']);
    }

    public function pdo(): PDO
    {
        return $this->pdo;
    }

    public function driver(): string
    {
        return (string) $this->pdo->getAttribute(PDO::ATTR_DRIVER_NAME);
    }

    public function run(string $sql, array $params = []): PDOStatement
    {
        $statement = $this->pdo->prepare($sql);
        $statement->execute($params);

        return $statement;
    }

    public function fetch(string $sql, array $params = []): ?array
    {
        $row = $this->run($sql, $params)->fetch(PDO::FETCH_ASSOC);

        return $row === false ? null : $row;
    }

    public function fetchAll(string $sql, array $params = []): array
    {
        return $this->run($sql, $params)->fetchAll(PDO::FETCH_ASSOC);
    }

    public function value(string $sql, array $params = []): mixed
    {
        $value = $this->run($sql, $params)->fetchColumn();

        return $value === false ? null : $value;
    }

    public function insert(string $table, array $data): void
    {
        $columns = array_keys($data);
        $this->run(
            'INSERT INTO ' . $this->ident($table) . ' (' . implode(', ', array_map([$this, 'ident'], $columns)) . ') VALUES (' . implode(', ', array_fill(0, count($columns), '?')) . ')',
            array_values($data)
        );
    }

    public function update(string $table, array $data, string $where, array $params = []): void
    {
        $sets = [];

        foreach (array_keys($data) as $column) {
            $sets[] = $this->ident($column) . ' = ?';
        }

        $this->run(
            'UPDATE ' . $this->ident($table) . ' SET ' . implode(', ', $sets) . ' WHERE ' . $where,
            array_merge(array_values($data), $params)
        );
    }

    public function delete(string $table, string $where, array $params = []): void
    {
        $this->run('DELETE FROM ' . $this->ident($table) . ' WHERE ' . $where, $params);
    }

    public function transaction(callable $callback): mixed
    {
        $this->pdo->beginTransaction();

        try {
            $result = $callback($this);
            $this->pdo->commit();

            return $result;
        } catch (\Throwable $exception) {
            if ($this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            }

            throw $exception;
        }
    }

    public function migrate(): void
    {
        (new Migrator($this))->run();
    }

    private function ident(string $name): string
    {
        if (!preg_match('/^[a-zA-Z0-9_]+$/', $name)) {
            throw new \InvalidArgumentException('Invalid identifier: ' . $name);
        }

        return '`' . $name . '`';
    }

    private function options(): array
    {
        return [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES => false,
        ];
    }
}
