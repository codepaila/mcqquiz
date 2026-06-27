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
 * - UTF-8 everywhere
 * - Strict SQL mode
 * - Automatic timezone
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

        try {

            $options = [

                // Throw exceptions
                PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,

                // Return associative arrays
                PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,

                // Use native prepared statements
                PDO::ATTR_EMULATE_PREPARES => false,

                // Don't convert numbers to strings
                PDO::ATTR_STRINGIFY_FETCHES => false,

                // Connection timeout
                PDO::ATTR_TIMEOUT => 10,

                // Persistent connections
                PDO::ATTR_PERSISTENT => true,
            ];

            /**
             * SSL (Aiven / Managed MySQL)
             */
            if (
                !empty($cfg['ssl']) &&
                !empty($cfg['ssl_ca']) &&
                is_readable($cfg['ssl_ca'])
            ) {
                $options[PDO::MYSQL_ATTR_SSL_CA] = $cfg['ssl_ca'];

                // Verify server certificate
                if (defined('PDO::MYSQL_ATTR_SSL_VERIFY_SERVER_CERT')) {
                    $options[PDO::MYSQL_ATTR_SSL_VERIFY_SERVER_CERT] = true;
                }
            }

            self::$pdo = new PDO(
                $dsn,
                $cfg['user'],
                $cfg['pass'],
                $options
            );

            /**
             * Character set
             */
            self::$pdo->exec("
                SET NAMES utf8mb4
                COLLATE utf8mb4_unicode_ci
            ");

            /**
             * Timezone
             */
            self::$pdo->exec("SET time_zone = '+00:00'");

            /**
             * SQL Mode
             */
            self::$pdo->exec("
                SET SESSION sql_mode =
                'STRICT_TRANS_TABLES,
                ERROR_FOR_DIVISION_BY_ZERO,
                NO_ENGINE_SUBSTITUTION'
            ");

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