import template from './sw-settings-snippet-set-list.html.twig';

const {
    Data: { Criteria },
} = Shopware;

/**
 * Adds the "parent set" column to the inline snippet-set grid. A freshly added
 * set drops straight into inline edit, so this one column covers both create
 * and edit. The self-exclusion in the select is only a convenience —
 * SnippetSetWriteValidationSubscriber is what actually enforces it.
 */
Shopware.Component.override('sw-settings-snippet-set-list', {
    template,

    computed: {
        snippetSetColumns() {
            const columns = this.$super('snippetSetColumns');

            columns.push({
                property: 'parentId',
                label: this.$t('scythe-snippet-set-inheritance.setList.columnParent'),
                inlineEdit: 'string',
                allowResize: true,
                sortable: false,
            });

            return columns;
        },
    },

    methods: {
        scytheParentSetCriteria(item) {
            const criteria = new Criteria(1, 25);

            if (item?.id) {
                criteria.addFilter(Criteria.not('AND', [Criteria.equals('id', item.id)]));
            }

            criteria.addSorting(Criteria.sort('name', 'ASC'));

            return criteria;
        },

        scytheParentSetName(item) {
            if (!item?.parentId) {
                return '';
            }

            const parent = this.snippetSets.find((set) => set.id === item.parentId);

            return parent ? parent.name : '';
        },
    },
});
