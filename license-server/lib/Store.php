<?php

namespace LicenseServer;

use PDO;
use RuntimeException;

final class Store
{
    private PDO $pdo;

    public function __construct(string $database)
    {
        $directory = dirname($database);

        if (!is_dir($directory)) {
            mkdir($directory, 0775, true);
        }

        $this->pdo = new PDO('sqlite:' . $database, null, null, [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        ]);
        $this->pdo->exec('PRAGMA journal_mode = WAL');
        $this->pdo->exec('PRAGMA busy_timeout = 5000');
        $this->migrate();
    }

    public function pdo(): PDO
    {
        return $this->pdo;
    }

    private function migrate(): void
    {
        $this->pdo->exec("CREATE TABLE IF NOT EXISTS licenses (
            id TEXT PRIMARY KEY,
            key_hash TEXT NOT NULL UNIQUE,
            key_preview TEXT NOT NULL,
            plan TEXT NOT NULL,
            holder_name TEXT NULL,
            email TEXT NULL,
            status TEXT NOT NULL DEFAULT 'active',
            domain TEXT NULL,
            installation_id TEXT NULL,
            max_activations INTEGER NOT NULL DEFAULT 1,
            activation_count INTEGER NOT NULL DEFAULT 0,
            issued_at INTEGER NOT NULL,
            expires_at INTEGER NULL,
            revoked_at INTEGER NULL,
            last_seen_at INTEGER NULL,
            meta TEXT NULL
        )");

        $this->pdo->exec("CREATE TABLE IF NOT EXISTS activations (
            id TEXT PRIMARY KEY,
            license_id TEXT NOT NULL,
            installation_id TEXT NOT NULL,
            domain TEXT NULL,
            ip_hash TEXT NULL,
            first_seen_at INTEGER NOT NULL,
            last_seen_at INTEGER NOT NULL,
            UNIQUE (license_id, installation_id)
        )");

        $this->pdo->exec("CREATE TABLE IF NOT EXISTS nonces (
            nonce TEXT PRIMARY KEY,
            created_at INTEGER NOT NULL
        )");

        $this->pdo->exec("CREATE TABLE IF NOT EXISTS rate_limits (
            ip_hash TEXT PRIMARY KEY,
            window_start INTEGER NOT NULL,
            hits INTEGER NOT NULL
        )");

        $this->pdo->exec("CREATE TABLE IF NOT EXISTS audit_log (
            id TEXT PRIMARY KEY,
            action TEXT NOT NULL,
            license_id TEXT NULL,
            domain TEXT NULL,
            installation_id TEXT NULL,
            ip_hash TEXT NULL,
            detail TEXT NULL,
            created_at INTEGER NOT NULL
        )");

        $this->pdo->exec('CREATE INDEX IF NOT EXISTS licenses_domain_idx ON licenses (domain)');
        $this->pdo->exec('CREATE INDEX IF NOT EXISTS licenses_status_idx ON licenses (status)');
        $this->pdo->exec('CREATE INDEX IF NOT EXISTS activations_domain_idx ON activations (domain)');
    }

    public function licenseByKey(string $key): ?array
    {
        $statement = $this->pdo->prepare('SELECT * FROM licenses WHERE key_hash = ? LIMIT 1');
        $statement->execute([hash('sha256', $key)]);
        $row = $statement->fetch();

        return $row === false ? null : $row;
    }

    public function licenseById(string $id): ?array
    {
        $statement = $this->pdo->prepare('SELECT * FROM licenses WHERE id = ? LIMIT 1');
        $statement->execute([$id]);
        $row = $statement->fetch();

        return $row === false ? null : $row;
    }

    public function insertLicense(array $data): void
    {
        $statement = $this->pdo->prepare('INSERT INTO licenses (id, key_hash, key_preview, plan, holder_name, email, status, domain, installation_id, max_activations, activation_count, issued_at, expires_at, revoked_at, last_seen_at, meta) VALUES (:id, :key_hash, :key_preview, :plan, :holder_name, :email, :status, :domain, :installation_id, :max_activations, :activation_count, :issued_at, :expires_at, :revoked_at, :last_seen_at, :meta)');
        $statement->execute($data);
    }

    public function updateLicense(string $id, array $fields): void
    {
        if ($fields === []) {
            return;
        }

        $assignments = [];
        $params = ['id' => $id];

        foreach ($fields as $field => $value) {
            $assignments[] = $field . ' = :' . $field;
            $params[$field] = $value;
        }

        $statement = $this->pdo->prepare('UPDATE licenses SET ' . implode(', ', $assignments) . ' WHERE id = :id');
        $statement->execute($params);
    }

    public function allLicenses(int $limit = 200): array
    {
        $statement = $this->pdo->prepare('SELECT * FROM licenses ORDER BY issued_at DESC LIMIT ' . max(1, min(1000, $limit)));
        $statement->execute();

        return $statement->fetchAll();
    }

    public function activation(string $licenseId, string $installationId): ?array
    {
        $statement = $this->pdo->prepare('SELECT * FROM activations WHERE license_id = ? AND installation_id = ? LIMIT 1');
        $statement->execute([$licenseId, $installationId]);
        $row = $statement->fetch();

        return $row === false ? null : $row;
    }

    public function insertActivation(array $data): void
    {
        $statement = $this->pdo->prepare('INSERT INTO activations (id, license_id, installation_id, domain, ip_hash, first_seen_at, last_seen_at) VALUES (:id, :license_id, :installation_id, :domain, :ip_hash, :first_seen_at, :last_seen_at)');
        $statement->execute($data);
    }

    public function touchActivation(string $id, ?string $domain, ?string $ipHash): void
    {
        $statement = $this->pdo->prepare('UPDATE activations SET domain = ?, ip_hash = ?, last_seen_at = ? WHERE id = ?');
        $statement->execute([$domain, $ipHash, time(), $id]);
    }

    public function deleteActivation(string $licenseId, string $installationId): bool
    {
        $statement = $this->pdo->prepare('DELETE FROM activations WHERE license_id = ? AND installation_id = ?');
        $statement->execute([$licenseId, $installationId]);

        return $statement->rowCount() > 0;
    }

    public function countActivations(string $licenseId): int
    {
        $statement = $this->pdo->prepare('SELECT COUNT(*) FROM activations WHERE license_id = ?');
        $statement->execute([$licenseId]);

        return (int) $statement->fetchColumn();
    }

    public function acceptNonce(string $nonce): bool
    {
        $this->pdo->prepare('DELETE FROM nonces WHERE created_at < ?')->execute([time() - 900]);
        $statement = $this->pdo->prepare('INSERT OR IGNORE INTO nonces (nonce, created_at) VALUES (?, ?)');
        $statement->execute([$nonce, time()]);

        return $statement->rowCount() === 1;
    }

    public function allow(string $ipHash, int $limit): bool
    {
        $now = time();
        $window = intdiv($now, 60);
        $statement = $this->pdo->prepare('SELECT * FROM rate_limits WHERE ip_hash = ? LIMIT 1');
        $statement->execute([$ipHash]);
        $row = $statement->fetch();

        if ($row === false || (int) $row['window_start'] !== $window) {
            $statement = $this->pdo->prepare('INSERT INTO rate_limits (ip_hash, window_start, hits) VALUES (?, ?, 1) ON CONFLICT(ip_hash) DO UPDATE SET window_start = excluded.window_start, hits = 1');
            $statement->execute([$ipHash, $window]);

            return true;
        }

        if ((int) $row['hits'] >= $limit) {
            return false;
        }

        $this->pdo->prepare('UPDATE rate_limits SET hits = hits + 1 WHERE ip_hash = ?')->execute([$ipHash]);

        return true;
    }

    public function audit(string $action, ?string $licenseId, ?string $domain, ?string $installationId, ?string $ipHash, array $detail = []): void
    {
        $statement = $this->pdo->prepare('INSERT INTO audit_log (id, action, license_id, domain, installation_id, ip_hash, detail, created_at) VALUES (?, ?, ?, ?, ?, ?, ?, ?)');
        $statement->execute([
            bin2hex(random_bytes(12)),
            $action,
            $licenseId,
            $domain,
            $installationId,
            $ipHash,
            json_encode($detail, JSON_UNESCAPED_SLASHES),
            time(),
        ]);
    }

    public function stats(): array
    {
        $total = (int) $this->pdo->query('SELECT COUNT(*) FROM licenses')->fetchColumn();
        $active = (int) $this->pdo->query("SELECT COUNT(*) FROM licenses WHERE status = 'active'")->fetchColumn();
        $activations = (int) $this->pdo->query('SELECT COUNT(*) FROM activations')->fetchColumn();

        return [
            'licenses' => $total,
            'active' => $active,
            'activations' => $activations,
        ];
    }
}
