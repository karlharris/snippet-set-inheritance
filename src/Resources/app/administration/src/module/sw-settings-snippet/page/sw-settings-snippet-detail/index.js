import template from './sw-settings-snippet-detail.html.twig';
import './sw-settings-snippet-detail.scss';

Shopware.Component.override('sw-settings-snippet-detail', {
    template,

    methods: {
        // Carry the decorator's inheritance fields onto the dummy snippets the
        // core builds — core's applySnippetsToDummies() doesn't know about them.
        applySnippetsToDummies(snippets) {
            this.$super('applySnippetsToDummies', snippets);

            this.snippets.forEach((dummySnippet) => {
                const realSnippet = snippets.find((snippet) => dummySnippet.setId === snippet.setId);

                dummySnippet.inherited = Boolean(realSnippet?.inherited);
                dummySnippet.inheritedFromSnippetSetId = realSnippet?.inheritedFromSnippetSetId ?? null;
                dummySnippet.inheritedFromSnippetSetName = realSnippet?.inheritedFromSnippetSetName ?? null;
            });
        },
    },
});
