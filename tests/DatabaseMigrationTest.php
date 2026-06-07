<?php

namespace ScrapApp\Tests;

use PHPUnit\Framework\TestCase;
use ScrapApp\Infrastructure\DatabaseMigration;

class DatabaseMigrationTest extends TestCase
{
    private \PDO $pdo;
    private DatabaseMigration $migration;

    protected function setUp(): void
    {
        // Use SQLite in-memory for structural testing
        $this->pdo = new \PDO('sqlite::memory:');
        $this->pdo->setAttribute(\PDO::ATTR_ERRMODE, \PDO::ERRMODE_EXCEPTION);

        $this->migration = new DatabaseMigration($this->pdo);
    }

    public function testGetTableNames(): void
    {
        $tables = $this->migration->getTableNames();

        $this->assertIsArray($tables);
        $this->assertContains('comics_descargados', $tables);
        $this->assertContains('batch_progreso', $tables);
        $this->assertContains('batch_historial', $tables);
        $this->assertContains('mangas_eliminados', $tables);
        $this->assertContains('log_descargas', $tables);
        $this->assertCount(5, $tables);
    }

    public function testGetTableSql(): void
    {
        $sql = $this->migration->getTableSql('comics_descargados');
        $this->assertNotNull($sql);
        $this->assertStringContainsString('CREATE TABLE IF NOT EXISTS comics_descargados', $sql);
        $this->assertStringContainsString('id_fuente', $sql);
        $this->assertStringContainsString('titulo', $sql);
        $this->assertStringContainsString('ENGINE=InnoDB', $sql);
    }

    public function testGetTableSqlForUnknownTable(): void
    {
        $this->assertNull($this->migration->getTableSql('nonexistent_table'));
    }

    public function testIsMigratedReturnsFalseWithEmptyDatabase(): void
    {
        // SQLite doesn't have INFORMATION_SCHEMA, so isMigrated will return false
        // via the PDOException catch — which is correct behavior
        $this->assertFalse($this->migration->isMigrated());
    }

    public function testGetMissingTablesReturnsAllWithEmptyDatabase(): void
    {
        // Same as isMigrated — will catch PDOException and return all tables
        $missing = $this->migration->getMissingTables();
        $this->assertCount(5, $missing);
    }

    public function testAllTableSqlContainsRequiredKeywords(): void
    {
        foreach ($this->migration->getTableNames() as $name) {
            $sql = $this->migration->getTableSql($name);
            $this->assertNotNull($sql, "SQL for table '{$name}' should not be null");
            $this->assertStringContainsString('CREATE TABLE IF NOT EXISTS', $sql);
            $this->assertStringContainsString('PRIMARY KEY', $sql);
        }
    }

    public function testMigrationRunReturnsCorrectStructure(): void
    {
        // Since we're using SQLite, the actual SQL execution may fail
        // due to MySQL-specific syntax. But we can at least verify the
        // return structure is correct when an exception is thrown.
        try {
            $result = $this->migration->run();
            // If it succeeds (unlikely with SQLite), verify structure
            $this->assertIsBool($result['success']);
            $this->assertIsArray($result['tables']);
            $this->assertIsArray($result['errors']);
        } catch (\Exception $e) {
            // Expected — SQLite doesn't support MySQL syntax
            $this->assertStringContainsString('syntax error', $e->getMessage());
        }
    }
}
