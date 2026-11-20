<?php

declare(strict_types=1);

/*
 * This file is part of Pactum – AVV/DPA for Contao.
 *
 * (c) pdir GmbH <https://pdir.de>
 *
 * @license LGPL-3.0-or-later
 */

namespace Pdir\PactumBundle\Tests\Functional;

use Doctrine\DBAL\Connection;
use Doctrine\DBAL\DriverManager;
use Doctrine\DBAL\Tools\DsnParser;
use PHPUnit\Framework\TestCase;

/**
 * Base class for tests against a real MySQL/MariaDB database. The schema is
 * created from the "sql" definitions of the bundle's DCA files, so the tests
 * check the same columns and keys Contao's migrate command creates.
 *
 * Set PACTUM_TEST_DATABASE_URL (e.g.
 * mysql://user:pass@127.0.0.1:3306/pactum_test) to run them; otherwise they are
 * skipped. All Pactum tables in that database are dropped and recreated.
 */
abstract class DatabaseTestCase extends TestCase
{
    protected const TABLES = [
        'tl_pactum_contractor',
        'tl_pactum_template',
        'tl_pactum_version',
        'tl_pactum_annex',
        'tl_pactum_subprocessor',
        'tl_pactum_invitation',
        'tl_pactum_agreement',
        'tl_pactum_audit_log',
    ];

    private static Connection|null $connection = null;

    protected function setUp(): void
    {
        $url = (string) getenv('PACTUM_TEST_DATABASE_URL');

        if ('' === $url) {
            $this->markTestSkipped('PACTUM_TEST_DATABASE_URL is not set.');
        }

        if (null === self::$connection) {
            $params = (new DsnParser(['mysql' => 'pdo_mysql', 'mariadb' => 'pdo_mysql']))->parse($url);
            self::$connection = DriverManager::getConnection([...$params, 'charset' => 'utf8mb4']);
            $this->createSchema(self::$connection);
        }

        foreach (self::TABLES as $table) {
            $this->getConnection()->executeStatement('TRUNCATE TABLE '.$table);
        }
    }

    protected function getConnection(): Connection
    {
        return self::$connection ?? throw new \LogicException('No database connection.');
    }

    private function createSchema(Connection $connection): void
    {
        foreach (self::TABLES as $table) {
            $dca = $this->loadDca($table);
            $columns = [];

            foreach ($dca['fields'] as $name => $field) {
                if (isset($field['sql']) && \is_string($field['sql'])) {
                    $columns[] = '`'.$name.'` '.$field['sql'];
                }
            }

            foreach ($dca['config']['sql']['keys'] ?? [] as $fields => $type) {
                $list = implode(', ', array_map(static fn (string $f): string => '`'.trim($f).'`', explode(',', (string) $fields)));

                $columns[] = match ($type) {
                    'primary' => 'PRIMARY KEY ('.$list.')',
                    'unique' => 'UNIQUE KEY `'.str_replace(',', '_', (string) $fields).'` ('.$list.')',
                    default => 'KEY `'.str_replace(',', '_', (string) $fields).'` ('.$list.')',
                };
            }

            $connection->executeStatement('DROP TABLE IF EXISTS '.$table);
            $connection->executeStatement(\sprintf('CREATE TABLE %s (%s) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci', $table, implode(', ', $columns)));
        }
    }

    /**
     * @return array{fields: array<string, array<string, mixed>>, config: array<string, mixed>}
     */
    private function loadDca(string $table): array
    {
        $GLOBALS['TL_DCA'] ??= [];
        require \dirname(__DIR__, 2).'/contao/dca/'.$table.'.php';

        /** @var array{fields: array<string, array<string, mixed>>, config: array<string, mixed>} $dca */
        $dca = $GLOBALS['TL_DCA'][$table];
        unset($GLOBALS['TL_DCA'][$table]);

        return $dca;
    }
}
