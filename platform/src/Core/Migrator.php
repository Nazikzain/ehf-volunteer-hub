<?php
declare(strict_types=1);

namespace App\Core;

/**
 * Versioned SQL migrations (database/migrations/NNN_name.sql). Applied versions are recorded in
 * settings key 'schema.migrations' (keeps the 34-table model; decision TD-07).
 * Understands DELIMITER directives so trigger bodies work (same files run in mysql CLI / phpMyAdmin).
 */
final class Migrator
{
    public function __construct(private Database $db)
    {
    }

    public static function split(string $sql): array
    {
        $statements = [];
        $delim = ';';
        $buf = '';
        foreach (preg_split('/\R/', $sql) as $line) {
            if (preg_match('/^\s*DELIMITER\s+(\S+)\s*$/i', $line, $m)) {
                $delim = $m[1];
                continue;
            }
            if ($buf === '' && (trim($line) === '' || str_starts_with(ltrim($line), '--'))) {
                continue;
            }
            $buf .= $line . "\n";
            if (str_ends_with(rtrim($line), $delim)) {
                $stmt = trim(substr(rtrim($buf), 0, -strlen($delim)));
                if ($stmt !== '') {
                    $statements[] = $stmt;
                }
                $buf = '';
            }
        }
        if (trim($buf) !== '') {
            $statements[] = trim($buf);
        }
        return $statements;
    }

    public function applied(): array
    {
        try {
            $v = $this->db->value("SELECT value FROM settings WHERE `key` = 'schema.migrations'");
        } catch (\PDOException) {
            return [];
        }
        return $v ? json_decode((string) $v, true) : [];
    }

    /** @return string[] newly applied migration names */
    public function migrate(string $dir): array
    {
        $done = $this->applied();
        $files = glob(rtrim($dir, '/') . '/*.sql');
        sort($files);
        $new = [];
        foreach ($files as $f) {
            $name = basename($f, '.sql');
            if (in_array($name, $done, true)) {
                continue;
            }
            foreach (self::split((string) file_get_contents($f)) as $stmt) {
                $this->db->pdo->exec($stmt); // DDL auto-commits in MySQL/MariaDB
            }
            $done[] = $name;
            $new[] = $name;
            $this->db->run("INSERT INTO settings (`key`, value, updated_by, updated_at) VALUES ('schema.migrations', :v, NULL, :u)
                ON DUPLICATE KEY UPDATE value = VALUES(value), updated_at = VALUES(updated_at)", ['v' => json_encode($done), 'u' => now_utc()]);
        }
        return $new;
    }
}
