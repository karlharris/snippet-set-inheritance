// Route the snippet grid / editor through the plugin's own endpoint, which
// returns the same payload as core plus inheritance info. Falls back to the
// core endpoint if the plugin route is unavailable (e.g. assets not rebuilt).
Shopware.Application.addServiceProviderDecorator('snippetSetService', (snippetSetService) => {
    const coreGetCustomList = snippetSetService.getCustomList.bind(snippetSetService);

    snippetSetService.getCustomList = function getCustomList(page = 1, limit = 25, filters = {}, sort = {}) {
        const headers = this.getBasicHeaders();
        const mergedSort = { sortBy: 'id', sortDirection: 'ASC', ...sort };

        return this.httpClient
            .post('/_action/scythe-snippet-set/list', { page, limit, filters, sort: mergedSort }, { headers })
            .then((response) => response.data)
            .catch(() => coreGetCustomList(page, limit, filters, sort));
    };

    return snippetSetService;
});
