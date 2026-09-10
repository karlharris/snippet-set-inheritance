<?php declare(strict_types=1);

// Boots against the shop's shared vendor/. The plugin is a plain filesystem
// plugin under custom/plugins/ — TestBootstrapper discovers and installs it,
// and DbalKernelPluginLoader registers its namespace from the plugin DB row.
// Shared by pure unit tests (mocked collaborators, no DB) and integration
// tests (real Shopware kernel + isolated `<database>_test` schema).
//
// If a run fails with "No source registered for ScytheSnippetSetInheritance",
// a stale compiled test container is the cause: `rm -rf var/cache/*test*`.
require dirname(__DIR__, 4) . '/vendor/autoload.php';

(new Shopware\Core\TestBootstrapper())
    ->addActivePlugins('ScytheSnippetSetInheritance')
    ->bootstrap();

// `addActivePlugins()` only takes effect during the *first* run's install().
// Keep the `_test` schema's plugin state + migrations current on every run,
// independent of that guard.
(function (): void {
    $kernel = Shopware\Core\Framework\Test\TestCaseBase\KernelLifecycleManager::getKernel();
    $container = $kernel->getContainer()->get('test.service_container');

    /** @var \Shopware\Core\Framework\Plugin\PluginService $pluginService */
    $pluginService = $container->get(\Shopware\Core\Framework\Plugin\PluginService::class);
    $context = \Shopware\Core\Framework\Context::createDefaultContext();

    // The `_test` schema may predate this plugin (it is only auto-discovered on
    // the very first bootstrap run). Make sure it is known before touching it.
    $pluginService->refreshPlugins($context, new \Composer\IO\NullIO());

    $plugin = $pluginService->getPluginByName('ScytheSnippetSetInheritance', $context);

    if ($plugin->getInstalledAt() === null || !$plugin->getActive()) {
        /** @var \Shopware\Core\Framework\Plugin\PluginLifecycleService $lifecycleService */
        $lifecycleService = $container->get(\Shopware\Core\Framework\Plugin\PluginLifecycleService::class);
        $lifecycleService->installPlugin($plugin, $context);
        $lifecycleService->activatePlugin($plugin, $context);
    }

    /** @var \Shopware\Core\Framework\Migration\MigrationCollectionLoader $loader */
    $loader = $container->get(\Shopware\Core\Framework\Migration\MigrationCollectionLoader::class);
    $migrations = $loader->collect('ScytheSnippetSetInheritance');
    $migrations->sync();
    $migrations->migrateInPlace();

    Shopware\Core\Framework\Test\TestCaseBase\KernelLifecycleManager::ensureKernelShutdown();
})();
