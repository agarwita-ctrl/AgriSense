<?php
/**
 * AgriSense - Database connection
 *
 * Thin singleton around PDO. Every query in the application goes through a
 * prepared statement (requirement 21 / rule 7); this class exposes small
 * helpers so callers never have to concatenate SQL with user input.
 */

class Database
{
    private static ?PDO $pdo = null;

    /** Never instantiated - all access is static. */
    private function __construct() {}

    /**
     * Shared PDO handle. Throws DatabaseException when the server is
     * unreachable so callers can render a friendly message.
     */
    public static function pdo(): PDO
    {
        if (self::$pdo instanceof PDO) {
            return self::$pdo;
        }

        $dsn = sprintf(
            'mysql:host=%s;port=%s;dbname=%s;charset=%s',
            DB_HOST,
            DB_PORT,
            DB_NAME,
            DB_CHARSET
        );

        try {
            self::$pdo = new PDO($dsn, DB_USER, DB_PASS, [
                PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                // Real prepared statements, not client side interpolation.
                PDO::ATTR_EMULATE_PREPARES   => false,
                PDO::ATTR_STRINGIFY_FETCHES  => false,
            ]);
            // Keep PHP and MySQL on the same wall clock.
            self::$pdo->exec("SET time_zone = '" . self::offset() . "'");
        } catch (PDOException $e) {
            error_log('[AgriSense] Database connection failed: ' . $e->getMessage());
            throw new DatabaseException(
                'The system cannot reach the database right now. '
                . 'Please make sure MySQL is running in the XAMPP Control Panel.',
                0,
                $e
            );
        }

        return self::$pdo;
    }

    /** Current UTC offset in the +HH:MM form MySQL expects. */
    private static function offset(): string
    {
        $seconds = (new DateTimeZone(APP_TIMEZONE))
            ->getOffset(new DateTime('now', new DateTimeZone('UTC')));
        $sign = $seconds < 0 ? '-' : '+';
        $seconds = abs($seconds);

        return sprintf('%s%02d:%02d', $sign, intdiv($seconds, 3600), intdiv($seconds % 3600, 60));
    }

    /** Run a prepared statement and return the PDOStatement. */
    public static function run(string $sql, array $params = []): PDOStatement
    {
        $stmt = self::pdo()->prepare($sql);
        $stmt->execute($params);

        return $stmt;
    }

    /** First matching row, or null. */
    public static function first(string $sql, array $params = []): ?array
    {
        $row = self::run($sql, $params)->fetch();

        return $row === false ? null : $row;
    }

    /** All matching rows. */
    public static function all(string $sql, array $params = []): array
    {
        return self::run($sql, $params)->fetchAll();
    }

    /** First column of the first row, or null. */
    public static function scalar(string $sql, array $params = [])
    {
        $value = self::run($sql, $params)->fetchColumn();

        return $value === false ? null : $value;
    }

    /** Execute a write and return the number of affected rows. */
    public static function execute(string $sql, array $params = []): int
    {
        return self::run($sql, $params)->rowCount();
    }

    /** Execute an INSERT and return the new primary key. */
    public static function insert(string $sql, array $params = []): int
    {
        self::run($sql, $params);

        return (int) self::pdo()->lastInsertId();
    }

    /** Run a callback inside a transaction, rolling back on any exception. */
    public static function transaction(callable $callback)
    {
        $pdo = self::pdo();
        $pdo->beginTransaction();
        try {
            $result = $callback($pdo);
            $pdo->commit();

            return $result;
        } catch (Throwable $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            throw $e;
        }
    }
}

/** Raised when the database itself is unavailable. */
class DatabaseException extends RuntimeException {}
