# 0.1.1
- Internal: the storefront now resolves inheritance through the official `storefront.snippets.post` extension point and the Administration through a dedicated API endpoint, instead of decorating the core snippet service. No functional change.

# 0.1.0
- Adds an optional parent set to every snippet set. Snippet keys not maintained in a set fall back to the effective value of its parent set (recursively), then to a configurable system-wide fallback set, before the set's own base file.
- Self-references and cycles in the parent chain are rejected on save; a maximum chain depth is configurable.
- The single-snippet editor and the snippet grid show which set an inherited value comes from.
- Translation catalogs of dependent child/grandchild/... sets are invalidated automatically when a set or its parent link changes.
