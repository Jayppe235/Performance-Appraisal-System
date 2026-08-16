<?php
declare(strict_types=1);

final class PmasDatabaseSessionHandler implements SessionHandlerInterface
{
    public function open(string $path, string $name): bool
    {
        return true;
    }

    public function close(): bool
    {
        return true;
    }

    public function read(string $id): string|false
    {
        $statement = db()->prepare('SELECT payload FROM user_sessions WHERE session_id = :session_id AND expires_at > NOW() LIMIT 1');
        $statement->execute(['session_id' => $id]);
        $payload = $statement->fetchColumn();
        return $payload === false ? '' : (string)$payload;
    }

    public function write(string $id, string $data): bool
    {
        $lifetime = max(300, (int)ini_get('session.gc_maxlifetime'));
        $statement = db()->prepare(
            'INSERT INTO user_sessions (session_id, payload, expires_at) VALUES (:session_id, :payload, DATE_ADD(NOW(), INTERVAL ' . $lifetime . ' SECOND)) '
            . 'ON DUPLICATE KEY UPDATE payload = VALUES(payload), expires_at = VALUES(expires_at)'
        );
        return $statement->execute(['session_id' => $id, 'payload' => $data]);
    }

    public function destroy(string $id): bool
    {
        $statement = db()->prepare('DELETE FROM user_sessions WHERE session_id = :session_id');
        return $statement->execute(['session_id' => $id]);
    }

    public function gc(int $max_lifetime): int|false
    {
        return db()->exec('DELETE FROM user_sessions WHERE expires_at <= NOW()');
    }
}

function configure_database_sessions(): void
{
    if (!IS_PRODUCTION || session_status() !== PHP_SESSION_NONE) {
        return;
    }

    session_set_save_handler(new PmasDatabaseSessionHandler(), true);
}
