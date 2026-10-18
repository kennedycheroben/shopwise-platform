<?php

namespace App\Database;

use PDO;
use PDOException;
use RuntimeException;
use Throwable;

/**
 * Database Connection Manager (PDO Singleton)
 */
class Database
{
    private static ?PDO $instance = null;

    /**
     * Private constructor to prevent direct instantiation
     */
    private function __construct()
    {
    }

    /**
     * Get or initialize the PDO connection instance
     *
     * @param array|null $config Optional database configuration override
     * @return PDO
     */
    public static function getInstance(?array $config = null): PDO
    {
        if (self::$instance === null) {
            if ($config === null) {
                $appConfig = require dirname(__DIR__, 2) . '/config/config.php';
                $config = $appConfig['db'] ?? [];
            }

            $host     = $config['host'] ?? '127.0.0.1';
            $port     = $config['port'] ?? 3306;
            $database = $config['database'] ?? 'shopwise_db';
            $charset  = $config['charset'] ?? 'utf8mb4';
            $username = $config['username'] ?? 'root';
            $password = $config['password'] ?? '';

            $dsn = "mysql:host={$host};port={$port};dbname={$database};charset={$charset}";

            $options = [
                PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                PDO::ATTR_EMULATE_PREPARES   => false,
            ];

            try {
                self::$instance = new PDO($dsn, $username, $password, $options);
            } catch (PDOException $e) {
                error_log("Database connection failure: " . $e->getMessage());
                throw new RuntimeException("Could not connect to the database. Please verify configuration settings.");
            }
        }

        return self::$instance;
    }

    /**
     * Begin a transaction
     */
    public static function beginTransaction(): bool
    {
        return self::getInstance()->beginTransaction();
    }

    /**
     * Commit active transaction
     */
    public static function commit(): bool
    {
        return self::getInstance()->commit();
    }

    /**
     * Rollback active transaction
     */
    public static function rollBack(): bool
    {
        if (self::getInstance()->inTransaction()) {
            return self::getInstance()->rollBack();
        }
        return false;
    }

    /**
     * Execute a callback inside an atomic transaction
     *
     * @template T
     * @param callable(PDO): T $callback
     * @return T
     * @throws Throwable
     */
    public static function transaction(callable $callback)
    {
        $pdo = self::getInstance();
        $isRoot = !$pdo->inTransaction();

        if ($isRoot) {
            $pdo->beginTransaction();
        }

        try {
            $result = $callback($pdo);
            if ($isRoot) {
                $pdo->commit();
            }
            return $result;
        } catch (Throwable $e) {
            if ($isRoot && $pdo->inTransaction()) {
                $pdo->rollBack();
            }
            throw $e;
        }
    }

    /**
     * Prevent object cloning
     */
    private function __clone()
    {
    }

    /**
     * Prevent object unserialization
     */
    public function __wakeup()
    {
        throw new RuntimeException("Cannot unserialize singleton");
    }
}
