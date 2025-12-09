<?php

namespace Apoio19\Crm\Models;

use \PDO;
use \PDOException;

class Database
{
    private static ?PDO $instance = null;
    private static array $config = [];

    // Private constructor to prevent direct instantiation.
    private function __construct() {}

    // Prevent cloning of the instance.
    private function __clone() {}

    // Prevent unserialization of the instance.
    public function __wakeup()
    {
        throw new \Exception("Cannot unserialize a singleton.");
    }

    /**
     * Get the PDO database connection instance (Singleton).
     *
     * @return PDO The PDO instance.
     * @throws PDOException If connection fails.
     */
    public static function getInstance(): PDO
    {
        if (self::$instance === null) {
            self::loadConfig(); // Load config if not already loaded

            $host = self::$config["host"] ?? "127.0.0.1";
            if ($host === 'localhost') {
                $host = '127.0.0.1';
            }
            $port = self::$config["port"] ?? 3306;
            $db   = self::$config["database"] ?? "crm_apoio19"; // Default DB name
            $user = self::$config["username"] ?? "root";
            $pass = self::$config["password"] ?? "";
            $charset = self::$config["charset"] ?? "utf8mb4";

            $dsn = "mysql:host={$host};port={$port};dbname={$db};charset={$charset}";
            $options = [
                PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                PDO::ATTR_EMULATE_PREPARES   => false,
            ];

            try {
                self::$instance = new PDO($dsn, $user, $pass, $options);
            } catch (PDOException $e) {
                // Log the error securely, don't expose details to the user
                error_log("Database Connection Error: " . $e->getMessage());
                // Throw a more generic exception or handle it gracefully
                throw new PDOException("Database connection failed.", (int)$e->getCode());
            }
        }

        return self::$instance;
    }

    /**
     * Load database configuration from environment variables.
     * Uses test variables if APP_ENV is 'testing'.
     */
    private static function loadConfig(): void
    {
        $isTesting = ($_ENV["APP_ENV"] ?? "production") === "testing";

        // Define keys for normal and test environments
        $keys = ["host", "port", "database", "username", "password", "charset"];
        $envPrefix = $isTesting ? "DB_TEST_" : "DB_";
        $fallbackPrefix = "DB_";

        foreach ($keys as $key) {
            $upperKey = strtoupper($key);
            $testEnvVar = $envPrefix . $upperKey;
            $prodEnvVar = $fallbackPrefix . $upperKey;

            // Prioritize test-specific env var if testing, then standard, then null
            $value = $_ENV[$testEnvVar] ?? $_ENV[$prodEnvVar] ?? null;

            // Sanitize value: remove whitespace and quotes
            if ($value !== null) {
                $value = trim($value, " \t\n\r\0\x0B\"'");
            }

            self::$config[$key] = $value;
        }

        // Provide some defaults if not set via ENV
        self::$config["host"] = self::$config["host"] ?? "127.0.0.1";
        // Force 127.0.0.1 if localhost is used
        if (self::$config["host"] === 'localhost') {
            self::$config["host"] = "127.0.0.1";
        }
        self::$config["port"] = self::$config["port"] ?? 3306;
        self::$config["database"] = self::$config["database"] ?? ($isTesting ? "crm_apoio19_test" : "crm_apoio19");
        self::$config["username"] = self::$config["username"] ?? "root";
        self::$config["password"] = self::$config["password"] ?? "";
        self::$config["charset"] = self::$config["charset"] ?? "utf8mb4";
    }

    /**
     * Allow setting a mock PDO instance for testing purposes.
     * NOTE: Use with caution, primarily for unit tests where DB interaction needs mocking.
     * 
     * @param PDO|null $mockInstance The mock PDO instance or null to reset.
     */
    public static function setInstance(?PDO $mockInstance): void
    {
        if (($_ENV["APP_ENV"] ?? "production") === "testing") {
            self::$instance = $mockInstance;
        } else {
            throw new \Exception("Cannot set mock instance outside of testing environment.");
        }
    }
}
