# Changelog

## 0.1.0 — 2026-10-06

- Initial release.
- Framework: tool registry (`method_tools_register`), Tools → Method Tools admin page, `Post_Tool` base with targeting (block-editor post types, site-editor types, statuses, ID include/exclude), SQL-prefiltered candidate scan, dry run, batched apply through `wp_update_post()`, signed per-run backups with restore, REST API (`method-tools/v1`) and WP-CLI (`wp method-tools`).
- Offset-preserving block parser (`Blocks\Block_Document`) so tools rewrite only the blocks they target.
- Accordion Converter: `core/accordion` ⇄ `method/accordion`, independent of block registration (works before WordPress 6.9).
