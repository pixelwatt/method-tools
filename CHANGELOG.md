# Changelog

## 0.1.1 — 2026-10-08

- Accordion Converter writes Method 2.0.0-beta28's scoped panel ids (`accordion-{accordionId}-collapse-{n}`) when the installed Method provides `method_accordion_collapse_id()`. On older Method versions it keeps writing `collapse{n}`, which beta27's editor requires. New filter: `method_tools_accordion_panel_ids`.
- The "more than one Method accordion on this post" warning only appears on Method versions before beta28. On those versions the tool also shows a notice suggesting the update.
- Tests: beta28 fixtures, both panel-id formats validated against Method beta27 and beta28 blocks.

## 0.1.0 — 2026-10-06

- Initial release.
- Framework: tool registry (`method_tools_register`), Tools → Method Tools admin page, `Post_Tool` base with targeting (block-editor post types, site-editor types, statuses, ID include/exclude), SQL-prefiltered candidate scan, dry run, batched apply through `wp_update_post()`, signed per-run backups with restore, REST API (`method-tools/v1`) and WP-CLI (`wp method-tools`).
- Offset-preserving block parser (`Blocks\Block_Document`) so tools rewrite only the blocks they target.
- Accordion Converter: `core/accordion` ⇄ `method/accordion`, independent of block registration (works before WordPress 6.9).
