<?php
/**
 * src/Infrastructure/DatabaseConnection.php
 *
 * Pure PDO connection factory.
 * Reads config.json directly — no DDL, no side effects.
 *
 * @package ScrapApp\Infrastructure
 */

namespace ScrapApp\Infrastructure;

class DatabaseConnection
{
    /** @var \PDO|null Singleton instance */
    private static ?\PDO $instance = null;

    /** @var array|null Cached config */
    private static ?array $config = null;

    /**
     * Path to the configuration file.
     */
    private static function configPath(): string
    {
        return __DIR__ . '/../../config.json';
    }

    /**
     * Load database configuration from config.json.
     *
     * @return array{host:string, name:string, user:string, pass:string, charset:string}
     * @throws \RuntimeException If config.json is missing or invalid.
     */
    public static function loadConfig(): array
    {
        if (self::$config !== null) {
            return self::$config;
        }

        $path = self::configPath();

        if (!file_exists($path)) {
            throw new \RuntimeException(
                'No configuration file found. Please run setup.php first.'
            );
        }

        $raw  = file_get_contents($path);
        $data = json_decode($raw, true);

        if (!is_array($data) || empty($data['db_host'])) {
            throw new \RuntimeException(
                'Invalid configuration file. Please re-run setup.php.'
            );
        }

        self::$config = [
            'host'    => $data['db_host'],
            'name'    => $data['db_name'] ?? 'comics_db',
            'user'    => $data['db_user'] ?? 'root',
            'pass'    => $data['db_pass'] ?? '',
            'charset' => 'utf8mb4',
        ];

        return self::$config;
    }

    /**
     * Create a new PDO connection from config.json.
     *
     * @param int $timeout Connection timeout in seconds.
     * @return \PDO
     * @throws \PDOException On connection failure.
     */
    public static function createConnection(int $timeout = 5): \PDO
    {
        $cfg = self::loadConfig();

        $dsn = sprintf(
            'mysql:host=%s;dbname=%s;charset=%s',
            $cfg['host'],
            $cfg['name'],
            $cfg['charset']
        );

        $pdo = new \PDO($dsn, $cfg['user'], $cfg['pass'], [
            \PDO::ATTR_ERRMODE            => \PDO::ERRMODE_EXCEPTION,
            \PDO::ATTR_DEFAULT_FETCH_MODE => \PDO::FETCH_ASSOC,
            \PDO::ATTR_EMULATE_PREPARES   => false,
            \PDO::ATTR_TIMEOUT            => $timeout,
        ]);

        return $pdo;
    }

    /**
     * Get or create the singleton PDO connection.
     *
     * @param bool $forceNew Force a new connection instead of returning the singleton.
     * @return \PDO
     * @throws \PDOException On connection failure.
     */
    public static function getConnection(bool $forceNew = false): \PDO
    {
        if ($forceNew || self::$instance === null) {
            self::$instance = self::createConnection();
        }
        return self::$instance;
    }

    /**
     * Close the singleton connection and reset config cache.
     */
    public static function reset(): void
    {
        self::$instance = null;
        self::$config   = null;
    }
}
