<?php declare(strict_types=1);

namespace Scythe\SnippetSetInheritance\Migration;

use Doctrine\DBAL\Connection;
use Shopware\Core\Framework\Migration\MigrationStep;

/**
 * Adds `snippet_set.parent_id`: a nullable, self-referencing FK. `ON DELETE SET
 * NULL` so deleting a parent set neither blocks on nor cascades into its
 * children — they just lose the link.
 */
class Migration1789200000AddParentIdToSnippetSet extends MigrationStep
{
    public function getCreationTimestamp(): int
    {
        return 1789200000;
    }

    public function update(Connection $connection): void
    {
        $columns = $connection->createSchemaManager()->listTableColumns('snippet_set');

        if (!\array_key_exists('parent_id', $columns)) {
            $connection->executeStatement(
                'ALTER TABLE `snippet_set` ADD COLUMN `parent_id` BINARY(16) NULL AFTER `iso`'
            );
        }

        $foreignKeys = $connection->createSchemaManager()->listTableForeignKeys('snippet_set');
        foreach ($foreignKeys as $foreignKey) {
            if (\in_array('parent_id', $foreignKey->getLocalColumns(), true)) {
                return;
            }
        }

        $connection->executeStatement(
            'ALTER TABLE `snippet_set`
                ADD CONSTRAINT `fk.snippet_set.parent_id`
                FOREIGN KEY (`parent_id`) REFERENCES `snippet_set` (`id`)
                ON DELETE SET NULL ON UPDATE CASCADE'
        );
    }

    public function updateDestructive(Connection $connection): void
    {
        // Column/FK teardown lives in ScytheSnippetSetInheritance::uninstall().
    }
}
