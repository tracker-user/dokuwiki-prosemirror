# ProseMirror Plugin for DokuWiki — local fork

A WYSIWYG editor for DokuWiki powered by [ProseMirror](https://prosemirror.net).

## Features

- Full WYSIWYG editing experience with live syntax validation
- Bidirectional conversion between DokuWiki syntax and ProseMirror JSON
- Support for all major DokuWiki syntax elements:
  - Headings (all 5 levels)
  - Formatting (bold, italic, underline, monospace, deleted, subscript, superscript)
  - Lists (bullet and ordered, with nesting)
  - Tables with colspan/rowspan
  - Links (internal, interwiki, external, email, Windows shares, local anchors)
  - Code blocks with language syntax highlighting
  - Blockquotes and preformatted text
  - Horizontal rules and hard breaks
  - Images and media (internal and external)
  - Footnotes, smileys, RSS feeds
  - Plugins (inline and block)
  - Document-level macros (~~NOCACHE~~, ~~NOTOC~~)
- Toggle between WYSIWYG and syntax editor modes
- Optional browser localStorage persistence for unsaved drafts
- Sentry integration for error logging (optional)

## Installation

1. Extract the plugin to your DokuWiki `lib/plugins/` directory:
   ```
   cd /path/to/dokuwiki/lib/plugins
   unzip prosemirror.zip
   ```

2. Enable the plugin in DokuWiki's admin panel (**Admin → Plugin Manager**).

3. Configure settings as needed (**Admin → Configuration Manager → Plugin Settings → ProseMirror**).

## Configuration

- **forceWYSIWYG**: Force all non-admin users to use the WYSIWYG editor (default: off)
- Additional configuration available via the DokuWiki admin panel

## Recent Changes

### v2.2.0

#### Bug Fixes
- **Whitespace-only plugin matches no longer create junk nodes**: `renderer.php::plugin()` now skips matches whose content is entirely whitespace. Previously, stray newlines between unintegrated plugin tags (e.g. `<searchtable>…<sortable>`) were each wrapped in a separate `dwplugin_block`, and `RootNode`'s `\n\n` separator would add more whitespace fragments on every editor round-trip — causing an extra block to appear in the visual editor on each syntax↔visual toggle. With this fix the node count is stable across round-trips.
- **Spurious empty top-level paragraphs removed**: `document_end()` now drops empty paragraphs that are direct children of the document (via `Node::removeEmptyChildParagraphs()`). These were left behind when an enclosing `<p>`'s only content was whitespace skipped by the fix above — they showed as blank lines in the visual editor and grew with each toggle. Structurally-required empty paragraphs (inside table cells, list items) are children of those nodes, not the document, so they are preserved; the mandatory placeholder paragraph for an otherwise-empty document is still added.

### v2.1.0

#### Bug Fixes
- **`@` error suppression removed** in `LinkNode`: replaced `@[$a, $b] = explode(...)` with explicit safe destructuring
- **`in_array` strict mode**: added `true` third argument in `renderer.php` to prevent type-coercion bugs
- **`error_log` debug output removed** from `QuoteNode`: no longer writes to PHP error log on unknown node types
- **Hardcoded English strings extracted** from link/media forms in `editor.php` to `lang/en/lang.php`

#### Modernization (PHP 8.3)
- `jsonSerialize(): mixed` return type added to `schema/Node` and `schema/Mark` — eliminates PHP 8.1+ deprecation notices
- `ProsemirrorException::$data` typed as `protected array` with typed `getExtraData(): array` return
- `conf/metadata.php`: `array()` syntax replaced with `[]`
- Cleaned up resolved FIXME comments throughout codebase

#### Translations
- **German**: Added 4 new strings (`legend:link type`, `legend:link name type`, `label:link name input`, `btn:ok`)
- **Russian**: Added 16 missing strings (link/caching legends, list/formatting labels, RSS labels)
- **Japanese**: Full translation added (`lang/ja/lang.php` and `lang/ja/settings.php`)

### v2.0.0

#### Bug Fixes
- **Null-safety guards**: Fixed crashes when rendering empty headings, code blocks, preformatted text, root documents, and tables
- **HeadingNode**: Now safely handles missing or empty content arrays
- **CodeBlockNode & PreformattedNode**: Added null-coalescing for missing content[0]
- **FootnoteNode**: Added proper JSON validation with descriptive error messages on decode failure
- **RootNode**: Safe handling of missing `content` key in input data
- **TableNode**: Fixed crash when encountering empty table rows during column counting
- **WindowsShareLinkNode**: Fixed unsafe `substr()` calls with proper prefix detection before stripping
- **AJAX link resolution**: Fixed destructuring bug in `explode('>')` when anchor is missing
- **AJAX media/image resolution**: Added null-safety on all untrusted input attributes (`??` coalescing)
- **AJAX RSS resolution**: Now validates JSON input before processing
- **Error output**: Fixed XSS vulnerability in error traces—now HTML-escaped
- **Page ID input**: Now properly sanitized via `cleanID()` instead of raw POST input
- **Datalist escaping**: Fixed HTML injection in code-language `<option>` values

#### Modernization & Best Practices
- Removed debug output: `var_dump()` and commented debug statements
- Added **DOKU_INC security guards** to all plugin entry points (non-namespaced files)
- Added comprehensive docblocks to all public methods
- Removed unnecessary pass-by-reference assignments (`&$parent`)
- Strict array comparisons in conditional logic (`in_array(..., true)`)
- Fixed method name typo: `addAddtionalForms` → `addAdditionalForms`

#### Firefox 78 Compatibility
- Verified full compatibility with Firefox 78 ESR
- No deprecated features used: no `#private` fields, no `??=`/`||=`/`&&=`, no `structuredClone`, no `Array.at`, no `Object.hasOwn`
- Safe use of: optional chaining (`?.`), nullish coalescing (`??`), `async/await`, `IntersectionObserver`, `fetch`, `Map/Set`

#### Testing
- **93 assertions** covering:
  - Plugin loading (helper, renderer initialization)
  - Renderer output for all 30+ node types and marks
  - Parser round-trip fidelity for all syntax elements
  - Full-document round-trip conversion
  - Error handling (invalid JSON, unknown node types)
  - Schema node and mark serialization
- All tests **passing** on DokuWiki Librarian (2025-05-14b) with PHP 8.3

## Compatibility

- **DokuWiki**: Librarian (2025-05-14b) and later
- **PHP**: 8.0+
- **Browsers**: Modern browsers with ES2017+ support
  - Firefox 78 ESR and later
  - Chrome/Edge 80+
  - Safari 13+
- **Optional**: Sentry plugin for error logging

## Architecture

The plugin is built in four layers:

1. **Helper** (`helper.php`): Public API for syntax conversion
2. **Renderer** (`renderer.php`): Converts DokuWiki instructions → ProseMirror JSON
3. **Parser** (`parser/`): Converts ProseMirror JSON → DokuWiki syntax (30+ node types)
4. **Schema** (`schema/`): JSON node/mark representation matching ProseMirror spec

## Plugin Support

Other DokuWiki syntax plugins can integrate with the ProseMirror editor so their
output renders visually instead of showing raw syntax code.

See **[PROSEMIRROR_PLUGIN_SUPPORT.md](PROSEMIRROR_PLUGIN_SUPPORT.md)** for a
step-by-step guide covering schema extension, renderer/parser hooks, nodeviews,
menu items, and roundtrip safety.

## Performance

- Bidirectional conversion is deterministic and repeatable
- No external API calls required
- All parsing is server-side for security
- Minimal JavaScript footprint (bundles ProseMirror libraries)

## Security

- Input validation on all AJAX endpoints
- HTML output properly escaped
- Page IDs sanitized via `cleanID()`
- Support for Sentry error logging without exposing user data

## License

GPL 2

## Author

Andreas Gohr <gohr@cosmocode.de>

Additional fixes and modernization by Claude Opus & Sonnet
