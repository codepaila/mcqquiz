<?php

namespace Quiznosis\Core;

use PDO;
use PDOException;
use RuntimeException;

/**
 * Database - PDO Singleton
 *
 * Features:
 * - Singleton connection
 * - Native prepared statements
 * - Persistent connections
 * - SSL support (Aiven)
 * - UTF-8 (via DSN)
 * - UTC timezone
 */
class Database
{
    private static ?PDO $pdo = null;

    /**
     * Get PDO instance.
     */
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
            $cfg['charset'] ?? 'utf8mb4'
        );

        $options = [
            PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES   => false,
            PDO::ATTR_STRINGIFY_FETCHES  => false,
            PDO::ATTR_TIMEOUT            => 10,
            PDO::ATTR_PERSISTENT         => false,
        ];

        // SSL (Aiven)
        if (
            !empty($cfg['ssl']) &&
            !empty($cfg['ssl_ca']) &&
            is_readable($cfg['ssl_ca'])
        ) {
            $options[PDO::MYSQL_ATTR_SSL_CA] = $cfg['ssl_ca'];

            if (defined('PDO::MYSQL_ATTR_SSL_VERIFY_SERVER_CERT')) {
                $options[PDO::MYSQL_ATTR_SSL_VERIFY_SERVER_CERT] = true;
            }
        }

        try {

            self::$pdo = new PDO(
                $dsn,
                $cfg['user'],
                $cfg['pass'],
                $options
            );

            // UTC timezone
            self::$pdo->exec("SET time_zone = '+00:00'");

            /**
             * Optional SQL mode.
             *
             * Aiven already provides a good default.
             * Uncomment ONLY if you specifically need to override it.
             */

            // self::$pdo->exec(
            //     "SET SESSION sql_mode='STRICT_TRANS_TABLES,NO_ENGINE_SUBSTITUTION'"
            // );

        } catch (PDOException $e) {

            throw new RuntimeException(
                'Database connection failed: ' . $e->getMessage(),
                (int) $e->getCode(),
                $e
            );

        }

        return self::$pdo;
    }

    /**
     * Execute callback inside transaction.
     */
    public static function transaction(callable $callback)
    {
        $pdo = self::pdo();

        try {

            $pdo->beginTransaction();

            $result = $callback($pdo);

            $pdo->commit();

            return $result;

        } catch (\Throwable $e) {

            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }

            throw $e;
        }
    }

    /**
     * Close connection.
     */
    public static function disconnect(): void
    {
        self::$pdo = null;
    }
}