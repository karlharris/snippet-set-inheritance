<?php

declare(strict_types=1);

namespace Scythe\SnippetSetInheritance;

use Doctrine\DBAL\Connection;
use Shopware\Core\Framework\Plugin;
use Shopware\Core\Framework\Plugin\Context\ActivateContext;
use Shopware\Core\Framework\Plugin\Context\DeactivateContext;
use Shopware\Core\Framework\Plugin\Context\InstallContext;
use Shopware\Core\Framework\Plugin\Context\UninstallContext;

class ScytheSnippetSetInheritance extends Plugin
{
    public function install(InstallContext $installContext): void
    {
        parent::install($installContext);
    }

    public function activate(ActivateContext $activateContext): void
    {
        parent::activate($activateContext);
    }

    public function deactivate(DeactivateContext $deactivateContext): void
    {
        parent::deactivate($deactivateContext);
    }

    public function uninstall(UninstallContext $uninstallContext): void
    {
        parent::uninstall($uninstallContext);

        if ($uninstallContext->keepUserData()) {
            return;
        }

        // Reverse Migration1789200000AddParentIdToSnippetSet: drop the column +
        // FK this plugin added to the core `snippet_set` table.
        /** @var Connection $connection */
        $connection = $this->container->get(Connection::class);

        $schemaManager = $connection->createSchemaManager();
        if (!\in_array('snippet_set', $schemaManager->listTableNames(), true)) {
            return;
        }

        $columns = $schemaManager->listTableColumns('snippet_set');
        if (!\array_key_exists('parent_id', $columns)) {
            return;
        }

        $foreignKeys = $schemaManager->listTableForeignKeys('snippet_set');
        foreach ($foreignKeys as $foreignKey) {
            if (\in_array('parent_id', $foreignKey->getLocalColumns(), true)) {
                $connection->executeStatement(
                    \sprintf('ALTER TABLE `snippet_set` DROP FOREIGN KEY `%s`', $foreignKey->getName())
                );
            }
        }

        $connection->executeStatement('ALTER TABLE `snippet_set` DROP COLUMN `parent_id`');
    }
}
