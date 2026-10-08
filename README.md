# Method Tools

Maintenance tools for WordPress sites built on [Method](https://github.com/pixelwatt/method). Each tool gets a tab under **Tools → Method Tools** and, if it rewrites content, a WP-CLI command.

Included tools:

- **Accordion Converter** — converts `core/accordion` blocks to `method/accordion` blocks, or the reverse.

Requirements: WordPress 6.0+, PHP 7.4+, and the `manage_options` capability (you can change this with the `method_tools_capability` filter). Tested on WordPress 6.0.17 (MariaDB), 6.8.3 and 7.1.3, with Method 2.0.0-beta27 and beta28.

## Install

Upload the `method-tools` folder to `wp-content/plugins/` and activate it. The plugin header includes `GitHub Plugin URI: https://github.com/pixelwatt/method-tools`, so Git Updater can deliver updates once that repository exists.

## Accordion Converter

The converter works directly on the raw block markup, so it does not depend on either block being registered. That means it runs on WordPress versions older than 6.9, where `core/accordion` doesn't exist yet. For example, content staged on a 6.9 site and imported into a 6.8 site can be converted to Method accordions.

### Workflow

1. Choose a **Direction**, the **post types** and **statuses** to include, and optionally an **ID list** to include or exclude.
   - The available post types are every type that uses the block editor, plus patterns, templates, template parts and navigation.
   - Templates and template parts are included only if they've been customized and saved to the database. Theme files are not changed.
2. Click **Scan (dry run)**. Nothing is saved. You see each affected post, how many accordions and items it has, and any notes.
3. Click **Convert N posts**. Each post is saved through `wp_update_post()`, so a revision is created and `save_post` hooks run as usual. The previous content is backed up first.
4. **Run history** lets you restore an entire run. A restore only touches posts that still have exactly the content the run wrote. Posts edited since the run are skipped, and you can force them if needed.

WP-CLI equivalents:

```
wp method-tools list
wp method-tools run accordion-converter --direction=core-to-method --post_type=page --status=publish,draft --dry-run
wp method-tools run accordion-converter --direction=core-to-method --include=12,48
wp method-tools runs
wp method-tools restore <run-id> [--force]
wp method-tools discard <run-id>
```

### How settings carry over

| core/accordion | method/accordion | Notes |
|---|---|---|
| heading title (rich text) | item `headline` | Method headlines support only bold and italic. Links, code and other formatting are unwrapped, the text is kept, and the item is flagged. |
| item `openByDefault` | accordion `closed` | Method can open the first item or none. If only item 1 is open, it stays open. If no items are open, `closed` is set. Any other combination is flagged. |
| heading `level` (default 3) | accordion `hTag` | One tag for the whole accordion. Method's default is h2, so h3 is written explicitly. Mixed levels are flagged. |
| panel inner blocks | body inner blocks | Copied byte for byte. Nested accordions inside are converted as well. |
| `className`, `align: wide`, `lock`, `metadata` | same | `align: full` has no Method equivalent and is dropped. |
| `autoclose` | — | Method always keeps one panel open at a time. Converting to core sets `autoclose: true` to match. |
| HTML anchor, colors, typography, border, spacing, icon settings | — | Dropped and listed in the post's notes. A lost anchor is flagged as a warning. |

Output is the exact markup each block's `save()` produces:

- **Method blocks:** the markup the installed Method saves. The item's panel `id` changed in beta28, from `collapse{n}` to `accordion-{accordionId}-collapse-{n}`. The converter writes the beta28 format when Method's `method_accordion_collapse_id()` helper exists. Otherwise (Method beta27 or older, or Method not loaded) it writes the old format, because beta27's editor rejects the new one. beta28 still accepts the old format through its deprecation and renders unique ids for it. Override with the `method_tools_accordion_panel_ids` filter (`'scoped'` or `'legacy'`).
- **Core blocks:** the markup WordPress 6.9 and 7.0 save. WordPress 7.1+ lists this markup as a deprecation and upgrades it silently the next time the post is saved.

Converted markup was validated with Gutenberg's own block validator for WordPress 6.9, 7.0 and trunk, and with Method's compiled block scripts (`tests/gutenberg-harness.cjs`).

### Safety model

- **Only accordions change.** Posts are parsed with an offset-preserving copy of core's block grammar, and the converted accordions are spliced into the original string. Every other byte stays the same, including blocks with unusual attributes that a `parse_blocks()` → `serialize_blocks()` round trip would rewrite.
- **Self-check before saving.** The converted post must parse cleanly and keep exactly the same non-accordion blocks with the same attributes. If not, the post is refused.
- **Unexpected markup is skipped, never guessed at.** An accordion is left alone with a warning when:
  - text, images or iframes sit in its wrapper markup;
  - it contains unexpected blocks;
  - its JSON is invalid;
  - its heading markup isn't recognized.
  
  Posts with malformed block delimiters or invalid UTF-8 are refused outright.
- **Saves:**
  - `kses` is lifted only for the plugin's own write. Running kses on the whole post would strip unrelated markup, such as iframes added by a super admin, whenever the user running the tool lacks `unfiltered_html`.
  - Converted headings go through a `wp_kses` allow-list.
  - Restores only write backups whose HMAC signature, keyed with the site's auth salts, proves this plugin created them.
- **Page templates.** A stale `_wp_page_template` left over from a previous theme no longer blocks or half-completes a save. The stored template is left untouched.

### Method versions before 2.0.0-beta28

- **Duplicate panel IDs.** Before beta28, every accordion numbers its panels `collapse1`, `collapse2`, …, so a page with two accordions has two of each. The second accordion's toggles then open the first accordion's panels. On those versions the tool shows a notice, and the converter flags every post that ends up with more than one Method accordion. beta28 fixes this at render time, including for content saved earlier.
- **PHP notice from `"type": "bool"`.** `closed` was declared `"type": "bool"`, which logs a `rest_validate_value_from_schema` notice under `WP_DEBUG`. Converted accordions usually set `closed`. Fixed in beta28.

## Adding a tool

A content tool extends `Method_Tools\Post_Tool`. The base plugin provides the targeting form, dry run, batched apply, backups and restore, REST routes and WP-CLI command.

```php
add_action( 'method_tools_register', function ( \Method_Tools\Plugin $plugin ) {
	$plugin->register( new My_Tool() );
} );

final class My_Tool extends \Method_Tools\Post_Tool {
	public function id() { return 'my-tool'; }
	public function label() { return 'My Tool'; }
	public function description() { return 'What it does.'; }
	public function option_fields() { return array(); }
	public function content_needles( array $options ) { return array( 'wp:method/thing' ); } // SQL LIKE prefilter
	public function transform( $content, array $options, $post ) {
		$result = new \Method_Tools\Transform_Result( $content );
		// …set $result->content / ->changed, ->bump( 'things' ), ->add( Transform_Result::WARNING, '…' )
		return $result;
	}
}
```

`transform()` must be pure, meaning it makes no writes. Building blocks:

- `Method_Tools\Blocks\Block_Document` — the offset-preserving parser.
- `Block_Markup` — editor-identical serialization helpers.

A tool that doesn't rewrite posts can extend `Method_Tools\Tool` and render its own panel.

Filters:

| Filter | Purpose |
|---|---|
| `method_tools_capability` | Capability for the page and the REST routes. Default `manage_options`. |
| `method_tools_batch_size` | Posts per apply or restore request. Default 10, maximum 50. |
| `method_tools_post_types` / `method_tools_post_statuses` | Targetable post types and statuses. |
| `method_tools_method_theme_slug` | Theme slug used to detect Method. Default `method`. |
| `method_tools_accordion_method_headline_tags` / `method_tools_accordion_core_title_tags` | `wp_kses` allow-lists for converted headings. |
| `method_tools_accordion_panel_ids` | Method panel id format to write: `'scoped'` (beta28+) or `'legacy'`. Detected by default. |

## Data and uninstall

- **Run history:** the `method_tools_runs` option. It isn't autoloaded.
- **Backups:** the `_method_tools_backup_{run}` post meta, one row per converted post. You can delete a run's backups from Run history or with `wp method-tools discard`.

Deleting the plugin removes both on every site of a network. Revisions are not affected.

## Tests

The tests live in `tests/` and are excluded from release archives.

- `edge-cases.php` and `convert-samples.php` don't write anything:
  `wp eval-file tests/edge-cases.php tests/fixtures/core-6.9-samples.json`
- `pipeline.php` creates and deletes its own posts. Run it only on a disposable local site.
- `gutenberg-harness.cjs` validates converted markup with the real `@wordpress/blocks` and `@wordpress/block-library` for a pinned WordPress release, together with Method's compiled block scripts. Setup instructions are in its header.

The fixtures are core accordion markup produced by Gutenberg 6.9's own serializer, plus Method accordion markup produced by Method's compiled beta27 and beta28 blocks.
