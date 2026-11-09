<?php
declare(strict_types=1);

namespace App\Core;

use PDO;
use PDOStatement;

/** Thin PDO wrapper: prepared statements only, UTC session, strict mode, deadlock retry. */
final class Database
{
    private static ?Database $instance = null;
    private int $depth = 0;

    public function __construct(public readonly PDO $pdo)
    {
    }

    public static function get(): Database
    {
        if (self::$instance === null) {
            self::$instance = self::connect(
                (string) env('DB_HOST', '127.0.0.1'), (int) env('DB_PORT', '3306'),
                (string) env('DB_NAME', 'ehf_volunteer_hub'), (string) env('DB_USER', 'root'),
                (string) env('DB_PASS', ''), env('DB_SOCKET')
            );
        }
        return self::$instance;
    }

    public static function setInstance(?Database $db): void
    {
        self::$instance = $db;
    }

    public static function connect(string $host, int $port, string $name, string $user, string $pass, ?string $socket = null): Database
    {
        $dsn = $socket ? "mysql:unix_socket={$socket};dbname={$name};charset=utf8mb4"
                       : "mysql:host={$host};port={$port};dbname={$name};charset=utf8mb4";
        $pdo = new PDO($dsn, $user, $pass, [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES => false,
            PDO::ATTR_STRINGIFY_FETCHES => false,
        ]);
        $pdo->exec("SET time_zone = '+00:00', NAMES utf8mb4 COLLATE utf8mb4_unicode_ci, "
            . "sql_mode = 'STRICT_TRANS_TABLES,ERROR_FOR_DIVISION_BY_ZERO,NO_ENGINE_SUBSTITUTION,NO_ZERO_DATE,NO_ZERO_IN_DATE'");
        return new Database($pdo);
    }

    public function run(string $sql, array $params = []): PDOStatement
    {
        $st = $this->pdo->prepare($sql);
        foreach ($params as $k => $v) {
            $name = is_int($k) ? $k + 1 : (str_starts_with($k, ':') ? $k : ':' . $k);
            $type = match (true) {
                is_int($v) => PDO::PARAM_INT,
                is_bool($v) => PDO::PARAM_BOOL,
                $v === null => PDO::PARAM_NULL,
                default => PDO::PARAM_STR,
            };
            $st->bindValue($name, $v, $type);
        }
        $st->execute();
        return $st;
    }

    public function all(string $sql, array $params = []): array
    {
        return $this->run($sql, $params)->fetchAll();
    }

    public function one(string $sql, array $params = []): ?array
    {
        $row = $this->run($sql, $params)->fetch();
        return $row === false ? null : $row;
    }

    public function value(string $sql, array $params = []): mixed
    {
        $v = $this->run($sql, $params)->fetchColumn();
        return $v === false ? null : $v;
    }

    /** Insert a row; column names are code-controlled (never user input). Returns the new id. */
    public function insert(string $table, array $data): int
    {
        $cols = array_keys($data);
        $sql = sprintf('INSERT INTO `%s` (%s) VALUES (%s)', $table,
            implode(', ', array_map(fn($c) => "`{$c}`", $cols)),
            implode(', ', array_map(fn($c) => ":{$c}", $cols)));
        $this->run($sql, $data);
        return (int) $this->pdo->lastInsertId();
    }

    public function update(string $table, array $data, string $where, array $whereParams = []): int
    {
        $set = implode(', ', array_map(fn($c) => "`{$c}` = :set_{$c}", array_keys($data)));
        $params = [];
        foreach ($data as $k => $v) {
            $params["set_{$k}"] = $v;
        }
        return $this->run("UPDATE `{$table}` SET {$set} WHERE {$where}", $params + $whereParams)->rowCount();
    }

    public function inTransaction(): bool
    {
        return $this->pdo->inTransaction();
    }

    /**
     * Run $fn in a transaction. Nested calls join the outer transaction.
     * InnoDB deadlocks (1213) and lock wait timeouts (1205) are retried up to 3 times (Design §6.2).
     */
    public function transaction(callable $fn, int $attempts = 3): mixed
    {
        if ($this->depth > 0) {
            $this->depth++;
            try {
                return $fn($this);
            } finally {
                $this->depth--;
            }
        }
        for ($i = 1; ; $i++) {
            $this->pdo->beginTransaction();
            $this->depth = 1;
            try {
                $result = $fn($this);
                $this->pdo->commit();
                $this->depth = 0;
                return $result;
            } catch (\Throwable $e) {
                if ($this->pdo->inTransaction()) {
                    $this->pdo->rollBack();
                }
                $this->depth = 0;
                $code = $e instanceof \PDOException ? (int) ($e->errorInfo[1] ?? 0) : 0;
                if (in_array($code, [1213, 1205], true) && $i < $attempts) {
                    usleep(50000 * $i);
                    continue;
                }
                throw $e;
            }
        }
    }
}
