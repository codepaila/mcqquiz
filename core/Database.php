<?php
namespace Quiznosis\Core;

use PDO;
use PDOException;
use RuntimeException;

/**
 * Database — thin PDO singleton.
 * Use Database::pdo() to get the PDO instance anywhere in the app.
 */
class Database
{
    private static ?PDO $pdo = null;

    public static function pdo(): PDO
    {
        if (self::$pdo instanceof PDO) {
            return self::$pdo;
        }

        $cfg = App::config('db');
        $dsn = sprintf(
            'mysql:host=%s;port=%d;dbname=%s;charset=%s',
            $cfg['host'],
            $cfg['port'],
            $cfg['name'],
            $cfg['charset']
        );

        try {
            // self::$pdo = new PDO($dsn, $cfg['user'], $cfg['pass'], [
            //     PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
            //     PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            //     PDO::ATTR_EMULATE_PREPARES   => false,
            //     PDO::ATTR_STRINGIFY_FETCHES  => false,
            // ]);
            $options = [
                PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                PDO::ATTR_EMULATE_PREPARES => false,
                PDO::ATTR_STRINGIFY_FETCHES => false,
                PDO::ATTR_TIMEOUT => 10,
            ];

            if (
                !empty($cfg['ssl']) &&
                !empty($cfg['ssl_ca']) &&
                file_exists($cfg['ssl_ca'])
            ) {
                $options[PDO::MYSQL_ATTR_SSL_CA] = $cfg['ssl_ca'];
            }

            self::$pdo = new PDO(
                $dsn,
                $cfg['user'],
                $cfg['pass'],
                $options
            );
            // sql_mode tuned for sanity
            self::$pdo->exec("SET sql_mode='STRICT_TRANS_TABLES,NO_ZERO_DATE,NO_ZERO_IN_DATE,ERROR_FOR_DIVISION_BY_ZERO'");
        } catch (PDOException $e) {
            throw new RuntimeException('Database connection failed: ' . $e->getMessage(), (int) $e->getCode(), $e);
        }

        return self::$pdo;
    }

    /**
     * Wraps a callable in a transaction. Rolls back on exception.
     */
    public static function transaction(callable $fn)
    {
        $pdo = self::pdo();
        $pdo->beginTransaction();
        try {
            $result = $fn($pdo);
            $pdo->commit();
            return $result;
        } catch (\Throwable $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            throw $e;
        }
    }
}
