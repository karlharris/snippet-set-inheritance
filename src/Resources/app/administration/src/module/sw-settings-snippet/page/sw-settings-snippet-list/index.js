import template from './sw-settings-snippet-list.html.twig';

// Template-only: the badge fields come from the enriched
// POST /api/_action/snippet-set response and core's prepareGrid() keeps them.
Shopware.Component.override('sw-settings-snippet-list', {
    template,
});
