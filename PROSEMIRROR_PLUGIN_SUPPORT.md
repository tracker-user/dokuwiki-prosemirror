# Adding Plugin Support to ProseMirror

This guide explains how to make a DokuWiki syntax plugin render properly in the
ProseMirror WYSIWYG editor instead of showing raw syntax code.

**Note:** The prosemirror plugin's extension API is in ALPHA state and may change.

**Plugins with existing Prosemirror support** (for reference implementations):
ImgPaste, Diagrams, CatMenu, VisualIndex.

## Architecture Overview

### Data Flow

When a page is edited in ProseMirror, three conversions happen:

```
Wiki Syntax  --(PHP renderer)-->  ProseMirror JSON  --(JS schema)-->  Editor DOM
Editor DOM   --(JS state)------>  ProseMirror JSON  --(PHP parser)-->  Wiki Syntax
```

1. **Wiki Syntax to JSON (PHP):** DokuWiki parses the text into instructions, then
   the prosemirror renderer (`renderer.php`) builds a tree of `schema\Node` objects
   and serializes to JSON. For syntax plugins, the `plugin()` method fires a
   `PROSEMIRROR_RENDER_PLUGIN` event, then defaults to wrapping the raw match in
   a generic `dwplugin_inline` or `dwplugin_block` node (displayed as `<code>`).

2. **JSON to Editor (JS):** `schema.js` defines allowed ProseMirror node/mark types.
   `dwplugin_inline` and `dwplugin_block` are catch-all types that display raw syntax.
   NodeViews customize DOM rendering. The schema, nodeviews, and menu items are
   extensible via `window.Prosemirror.*` hooks.

3. **JSON to Wiki Syntax (PHP):** `parser\Node::getSubNode()` maps JSON node types to
   PHP parser classes. For unknown types, fires `PROSEMIRROR_PARSE_UNKNOWN`.
   `PluginNode` handles the generic `dwplugin_*` types by returning the text verbatim.

### Extension Points

**PHP events (in `prosemirror/` plugin code):**

| Event | When | Purpose |
|-------|------|---------|
| `PROSEMIRROR_RENDER_PLUGIN` | Rendering wiki syntax to JSON | Override default `dwplugin_*` wrapping |
| `PROSEMIRROR_PARSE_UNKNOWN` | Parsing JSON back to wiki syntax | Handle custom node types |

**JS global API (`window.Prosemirror`, available after `PROSEMIRROR_API_INITIALIZED`):**

| Property | Type | Purpose |
|----------|------|---------|
| `pluginSchemas` | `Function[]` | Each receives `(nodes, marks)` OrderedMaps, returns `{nodes, marks}` |
| `pluginNodeViews` | `Object` | Map of node type name to nodeview constructor function |
| `pluginMenuItemDispatchers` | `Array` | Menu item dispatchers added to the Plugin dropdown |
| `classes.KeyValueForm` | Class | Reusable form dialog for editing node attributes |
| `classes.MenuItem` | Class | Menu item constructor |
| `classes.AbstractMenuItemDispatcher` | Class | Base class for menu item dispatchers |
| `classes.DOMParser` | Class | ProseMirror's DOMParser for clipboard paste handling |
| `commands.setBlockTypeNoAttrCheck` | Function | Block-type command that skips attribute comparison |

**JS event:**
- `PROSEMIRROR_API_INITIALIZED` (jQuery on `document`) fires after the API is ready
  but before the editor is constructed. This is the correct time to push to
  `pluginSchemas`, `pluginNodeViews`, and `pluginMenuItemDispatchers`.

### Timing

The prosemirror `script.js` is auto-bundled by DokuWiki globally. It includes
`bundle.js` via `DOKUWIKI:include`. The load order is:

1. `bundle.js` runs `initializePublicAPI()` which creates `window.Prosemirror.*`
   and triggers `PROSEMIRROR_API_INITIALIZED`
2. Other plugins' `script.js` files run (in plugin-name alphabetical order)
3. When the user opens the editor, `enableProsemirror()` builds the schema by
   calling `getSpec()`, which iterates `window.Prosemirror.pluginSchemas`
4. NodeViews are collected via `getNodeViews()`, which spreads
   `window.Prosemirror.pluginNodeViews`
5. Menu items are collected via `MenuInitializer.collectMenuItems()`, which
   includes `window.Prosemirror.pluginMenuItemDispatchers`

Your plugin's `script.js` must listen for `PROSEMIRROR_API_INITIALIZED` to
register its extensions, since the API object may not exist at script load time.

### Required and Optional Aspects

To add support for your plugin, you must implement three things:

1. **The schema** (JS) - define your node/mark types
2. **The renderer** (PHP) - convert wiki syntax to ProseMirror JSON
3. **The parser** (PHP) - convert ProseMirror JSON back to wiki syntax

Optional enhancements:

4. **NodeView** (JS) - custom DOM rendering for your nodes
5. **Menu items** (JS) - toolbar buttons for creating/editing your syntax
6. **Keybindings** (JS) - keyboard shortcuts (e.g., Ctrl+B for bold)
7. **Input rules** (JS) - auto-formatting as the user types

## OrderedMap API

The `nodes` and `marks` parameters in schema callbacks are **OrderedMap** instances,
not plain objects or arrays. Key methods:

| Method | Description |
|--------|-------------|
| `get(key)` | Get value by key, or `undefined` |
| `update(key, value, newKey?)` | Replace or add a binding; optionally rename |
| `remove(key)` | Return new map without the key |
| `addToStart(key, value)` | Insert at the beginning |
| `addToEnd(key, value)` | Append to the end |
| `addBefore(place, key, value)` | Insert before `place`; appends if `place` not found |
| `forEach(fn)` | Iterate: `fn(key, value)` |
| `append(map)` | Append non-overlapping keys from `map` |
| `prepend(map)` | Prepend non-overlapping keys from `map` |
| `subtract(map)` | Remove keys present in `map` |
| `size` | Number of entries |

Common usage in schema callbacks:

```javascript
window.Prosemirror.pluginSchemas.push(function (nodes, marks) {
    // Add a new node type
    nodes = nodes.addToEnd('mynode', { /* NodeSpec */ });

    // Modify an existing node type
    var existing = nodes.get('table_cell');
    existing.attrs.myattr = { default: null };
    nodes = nodes.update('table_cell', existing);

    // Add a new mark type
    marks = marks.addToEnd('mymark', { /* MarkSpec */ });

    // Remove a type
    marks = marks.remove('unwanted_mark');

    return { nodes: nodes, marks: marks };
});
```

## Decision: Mark vs Node

Choose the ProseMirror primitive based on the plugin's behavior:

### Use a Mark when:
- The plugin wraps inline text: `<tag param>content</tag>`
- Content inside can have nested formatting
- Examples: typography (`<fc>`, `<fs>`, `<bg>`, `<typo>`), wrap, color

### Use a Node when:
- The plugin produces standalone content (block or inline atom)
- The plugin replaces content rather than wrapping it
- Examples: include, gallery, struct, data entry

### Special cases:
- **cellbg** (`@color:`) modifies the parent table cell's attributes rather than
  producing its own DOM. This is best handled as a table cell attribute set during
  rendering, not as a separate node or mark.

## Implementation: Mark-Based Plugin

This is the pattern for plugins like typography that wrap text with formatting.
Example: `<fc #ff0000>red text</fc>` should show as red text in the editor.

### Step 1: Create the bridge action plugin

Create `action.php` in your plugin (or add to existing) with two event handlers.

```php
// In your plugin's action.php
use dokuwiki\Extension\ActionPlugin;
use dokuwiki\Extension\EventHandler;
use dokuwiki\Extension\Event;
use dokuwiki\plugin\prosemirror\schema\Node;
use dokuwiki\plugin\prosemirror\schema\Mark;

class action_plugin_YOURPLUGIN extends ActionPlugin
{
    public function register(EventHandler $controller)
    {
        // Only register if prosemirror is active
        if (!plugin_load('renderer', 'prosemirror')) return;

        $controller->register_hook(
            'PROSEMIRROR_RENDER_PLUGIN', 'BEFORE', $this, 'handleRender'
        );
        $controller->register_hook(
            'PROSEMIRROR_PARSE_UNKNOWN', 'BEFORE', $this, 'handleParse'
        );
    }
```

### Step 2: Handle rendering (Wiki Syntax to JSON)

The `PROSEMIRROR_RENDER_PLUGIN` event data contains:

```php
[
    'name'     => string,  // plugin name (e.g. 'typography_fontcolor')
    'data'     => array,   // data from handle() [state, tag_data, ...]
    'state'    => string,  // lexer state constant
    'match'    => string,  // raw matched text
    'renderer' => renderer_plugin_prosemirror,
]
```

For a mark-based plugin, you add/remove marks on the renderer:

```php
public function handleRender(Event $event)
{
    $data = $event->data;
    // Check if this event is for your plugin
    if ($data['name'] !== 'typography_fontcolor') return;

    $state = $data['data'][0] ?? null;
    $renderer = $data['renderer'];

    switch ($state) {
        case DOKU_LEXER_ENTER:
            // Extract the attribute (e.g. color value) from parsed data
            $tagData = $data['data'][1] ?? [];
            $color = $tagData['color'] ?? '';

            // Push a mark with attributes onto the renderer's mark stack.
            // The mark type name must match what you define in the JS schema.
            $renderer->marks['typo_fontcolor'] = [
                'color' => $color,
                'syntax_open' => $data['match'],  // preserve original syntax
            ];
            break;

        case DOKU_LEXER_EXIT:
            // Record closing syntax before removing
            if (isset($renderer->marks['typo_fontcolor'])) {
                $renderer->marks['typo_fontcolor']['syntax_close'] = $data['match'];
            }
            unset($renderer->marks['typo_fontcolor']);
            break;
    }

    // Prevent default dwplugin wrapping
    $event->preventDefault();
}
```

**Important:** The renderer's `$marks` array is checked by `cdata()` — every
text node created while a mark is active gets that mark attached. The mark type
name you use here becomes the `type` field in the JSON mark object.

However, the default `cdata()` only creates `Mark` objects from the array keys
(no attributes). For marks with attributes, you must intercept `cdata()` or
produce custom mark objects. The simplest approach: since the renderer iterates
`array_keys($this->marks)` and creates `new Mark($markType)` with no attrs,
you need to override this behavior.

**Recommended approach for marks with attributes:**

Rather than fighting the renderer's mark system, use a direct approach in
the PROSEMIRROR_RENDER_PLUGIN handler:

```php
case DOKU_LEXER_ENTER:
    // Store mark data for later use. The cdata() method will
    // not automatically handle attributes, so we track state manually.
    $renderer->prosemirrorPluginMarks['typo_fontcolor'] = [
        'attrs' => ['color' => $color],
        'syntax_open' => $data['match'],
    ];
    break;
```

Then you also need to hook into text node creation. A simpler alternative is
to use the renderer's existing mark mechanism and handle attributes in
a custom way (see Approach B below).

### Approach A: Custom mark with attributes (recommended for simple cases)

When the mark only needs the opening/closing syntax preserved and has a visual
CSS attribute (like color), the cleanest pattern is:

1. Add the mark type to `$renderer->marks` (key only, value = 1)
2. Store the opening syntax in a separate property on the renderer
3. In the JS schema, define the mark with `toDOM` that applies the visual style
4. In the PHP parser, read the mark's attrs and reconstruct the syntax

For this to work, you need to extend how marks carry attributes. Since the
`renderer.php`'s `cdata()` method creates `new Mark($markType)` with no attrs,
and we need attributes, we must produce the text nodes ourselves:

```php
case DOKU_LEXER_ENTER:
    $tagData = $data['data'][1] ?? [];
    $color = $tagData['color'] ?? '';
    // Store mark info on the renderer for use during text rendering
    $renderer->marks['typo_fontcolor'] = 1;
    // Store attributes separately
    $renderer->prosemirrorMarkAttrs['typo_fontcolor'] = [
        'color' => $color,
        'syntax_open' => $data['match'],
    ];
    break;

case DOKU_LEXER_EXIT:
    $syntaxClose = $data['match'];
    // Store closing syntax in attrs before removing
    if (isset($renderer->prosemirrorMarkAttrs['typo_fontcolor'])) {
        $renderer->prosemirrorMarkAttrs['typo_fontcolor']['syntax_close'] = $syntaxClose;
    }
    unset($renderer->marks['typo_fontcolor']);
    unset($renderer->prosemirrorMarkAttrs['typo_fontcolor']);
    break;
```

The problem: `cdata()` creates `new Mark($markType)` without attrs. You must
also hook into text creation to attach attrs. Since the renderer doesn't provide
a hook for this, the practical solution is:

### Approach B: Treat as inline node (simpler, no bundle rebuild)

For plugins where the formatted syntax is `<tag param>content</tag>`, represent
the entire construct as a custom inline node with the rendered HTML stored as an
attribute. This avoids modifying the mark system entirely:

```php
case DOKU_LEXER_ENTER:
    $tagData = $data['data'][1] ?? [];
    // Build the node and push it
    $node = new Node('typo_fontcolor');
    $node->attr('syntax_open', $data['match']);
    // Parse and store CSS-relevant attrs
    $color = $tagData['color'] ?? '';
    $node->attr('color', $color);
    $renderer->addToNodestackTop($node);
    // Content will be added as children by subsequent cdata() calls
    break;

case DOKU_LEXER_EXIT:
    $renderer->nodestack->current()->attr('syntax_close', $data['match']);
    $renderer->dropFromNodeStack('typo_fontcolor');
    break;
```

### Step 3: Define JS schema

In your plugin's `script.js`:

```javascript
jQuery(document).on('PROSEMIRROR_API_INITIALIZED', function () {
    // 1. Add schema definition
    window.Prosemirror.pluginSchemas.push(function (nodes, marks) {
        // For a mark-based approach:
        marks = marks.addToEnd('typo_fontcolor', {
            attrs: {
                color: { default: '' },
                syntax_open: { default: '<fc>' },
                syntax_close: { default: '</fc>' },
            },
            // How to render in the editor
            toDOM: function (mark) {
                return ['span', { style: 'color: ' + mark.attrs.color }, 0];
            },
            // How to parse from pasted HTML
            parseDOM: [{
                tag: 'span[style]',
                getAttrs: function (dom) {
                    var color = dom.style.color;
                    if (!color) return false;
                    return { color: color };
                },
            }],
        });

        // For a node-based approach (Approach B):
        nodes = nodes.addToEnd('typo_fontcolor', {
            content: 'inline*',    // can contain text and inline nodes
            marks: '_',            // allow all marks inside
            inline: true,
            group: 'inline',
            attrs: {
                color: { default: '' },
                syntax_open: { default: '<fc>' },
                syntax_close: { default: '</fc>' },
            },
            toDOM: function (node) {
                return ['span', { style: 'color: ' + node.attrs.color }, 0];
            },
        });

        return { nodes: nodes, marks: marks };
    });

    // 2. (Optional) Add a nodeview for custom rendering
    // window.Prosemirror.pluginNodeViews.typo_fontcolor = function (node, view, getPos) {
    //     return new MyCustomView(node, view, getPos);
    // };

    // 3. (Optional) Add menu items
    // window.Prosemirror.pluginMenuItemDispatchers.push(MyMenuItemDispatcher);
});
```

### Step 4: Handle parsing (JSON to Wiki Syntax)

Register a handler for `PROSEMIRROR_PARSE_UNKNOWN`. The event data contains:

```php
[
    'node'     => array,     // the JSON node data (type, attrs, content, marks)
    'parent'   => Node,      // parent parser node
    'previous' => Node|null, // previous sibling (for inline mark tracking)
    'newNode'  => null,      // set this to your Node instance
]
```

You must: (1) prevent the default, (2) set `newNode` to your `Node` instance:

```php
public function handleParse(Event $event)
{
    $nodeData = $event->data['node'];
    if ($nodeData['type'] !== 'typo_fontcolor') return;

    $attrs = $nodeData['attrs'] ?? [];
    $syntaxOpen = $attrs['syntax_open'] ?? '<fc>';
    $syntaxClose = $attrs['syntax_close'] ?? '</fc>';

    // Build inner content by recursing into children
    $innerSyntax = '';
    if (!empty($nodeData['content'])) {
        foreach ($nodeData['content'] as $child) {
            $childNode = \dokuwiki\plugin\prosemirror\parser\Node::getSubNode(
                $child, $event->data['parent']
            );
            $innerSyntax .= $childNode->toSyntax();
        }
    }

    // Create a custom node that returns the reconstructed syntax
    $event->data['newNode'] = new class($syntaxOpen, $innerSyntax, $syntaxClose)
        extends \dokuwiki\plugin\prosemirror\parser\Node
    {
        private $syntax;
        public function __construct($open, $inner, $close)
        {
            $this->syntax = $open . $inner . $close;
        }
        public function toSyntax() { return $this->syntax; }
    };

    $event->preventDefault();
}
```

### Step 5: Handle mark parsing (if using marks)

If you used a mark rather than a node, the mark data appears on text nodes
in the JSON. The existing `parser\Mark.php` handles known mark types via
`$openingMarks` and `$closingMarks` arrays. For custom marks, you need to
add entries or handle them in the `PROSEMIRROR_PARSE_UNKNOWN` event.

Since marks are embedded in text nodes (not standalone nodes), you need to
add your mark type to `parser\Mark.php`'s static arrays:

```php
// In parser\Mark.php (must modify prosemirror plugin directly):
protected static $openingMarks = [
    // ... existing marks ...
    'typo_fontcolor' => '<fc>',  // default, overridden by attrs
];
```

This is why **Approach B (inline node)** is simpler for external plugins:
it avoids modifying the prosemirror plugin's core files.

## Implementation: Node-Based Plugin

For plugins that produce block or inline content (not wrapping text).

### Example: cellbg (`@color:`)

cellbg is a special case: it modifies the parent table cell rather than
producing its own visible content. It uses `DOKU_LEXER_SPECIAL`.

```php
// In action handler for PROSEMIRROR_RENDER_PLUGIN
public function handleRender(Event $event)
{
    if ($event->data['name'] !== 'cellbg') return;

    $pluginData = $event->data['data'];
    $color = $pluginData[1] ?? 'yellow';
    $renderer = $event->data['renderer'];

    // Set background color on the current table cell node
    $currentNode = $renderer->nodestack->current();
    // Walk up to find the table_cell or table_header
    // The current node is likely 'paragraph' inside a cell
    $cellNode = $this->findParentCell($renderer);
    if ($cellNode) {
        $cellNode->attr('data-cellbg', $color);
        $cellNode->attr('cellbg-syntax', $event->data['match']);
    }

    $event->preventDefault();
}
```

For standalone block nodes (e.g., an include or gallery), the pattern is:

```php
case DOKU_LEXER_SPECIAL:
    $renderer->clearBlock(); // close any open paragraph
    $node = new Node('myplugin_block');
    $node->attr('data-param1', $value1);
    $node->attr('syntax', $data['match']); // preserve raw syntax for roundtrip
    $renderer->addToNodestack($node);  // add() not addTop() for leaf nodes
    break;
```

### JS schema for block nodes

```javascript
nodes = nodes.addToEnd('myplugin_block', {
    group: 'substitution_block',  // or 'protected_block'
    atom: true,                    // true if it has no editable content
    attrs: {
        'data-param1': { default: '' },
        syntax: { default: '' },
        renderedHTML: { default: null },  // for server-rendered preview
    },
    toDOM: function (node) {
        if (node.attrs.renderedHTML) {
            var wrapper = document.createElement('div');
            wrapper.innerHTML = node.attrs.renderedHTML;
            return wrapper;
        }
        return ['div', { class: 'myplugin-placeholder' }, node.attrs.syntax];
    },
});
```

### NodeView for server-rendered preview

For complex plugins where the visual output can't be replicated in JS,
fetch the rendered HTML from the server via AJAX:

```javascript
window.Prosemirror.pluginNodeViews.myplugin_block = function (node, view, getPos) {
    return new MyPluginView(node, view, getPos);
};

// MyPluginView follows the pattern of RSSView:
// 1. Extend AbstractNodeView (exposed at window.AbstractNodeView)
// 2. In renderNode(), check for renderedHTML attr
// 3. If missing, POST to ajax.php to get rendered HTML
// 4. Dispatch setNodeMarkup to store the result
```

You can add a custom AJAX action by hooking `AJAX_CALL_UNKNOWN` or by using
the existing `plugin_prosemirror` AJAX endpoint if you add a handler.

## Node Groups in the Schema

The doc node accepts: `(block | baseonly | container | protected_block | substitution_block)+`

| Group | Purpose | Examples |
|-------|---------|----------|
| `block` | Standard blocks | paragraph |
| `baseonly` | Special top-level only | heading |
| `container` | Blocks that contain other blocks | lists, tables, blockquote |
| `protected_block` | Code-like blocks (no marks, `code: true`) | code_block, preformatted, dwplugin_block |
| `substitution_block` | Atom blocks replaced by rendered content | rss |
| `inline` | Inline elements | text, link, image, smiley, dwplugin_inline |

List items accept: `(paragraph | protected_block | substitution_block)+`
Table cells accept: `(paragraph | protected_block | substitution_block)+`

## NodeSpec and MarkSpec Properties

### NodeSpec (passed to `nodes.addToEnd()`)

| Property | Type | Description |
|----------|------|-------------|
| `content` | `string` | Content expression: what children are allowed. Regex-like: `"text*"`, `"paragraph+"`, `"(paragraph \| blockquote)+"`, `"inline{2,5}"` |
| `marks` | `string` | Which marks are allowed: `"_"` = all (default), `""` = none, `"bold italic"` = specific marks |
| `group` | `string` | Node group for use in content expressions (e.g., `"block"`, `"inline"`) |
| `inline` | `boolean` | Whether this is an inline node |
| `atom` | `boolean` | If `true`, node is treated as a single unit (not directly editable) |
| `code` | `boolean` | If `true`, content is treated as code (no marks, monospace) |
| `defining` | `boolean` | If `true`, node type is preserved when content is replaced |
| `isolating` | `boolean` | If `true`, prevents cursor from entering/leaving via arrow keys |
| `draggable` | `boolean` | If `true`, node can be dragged |
| `attrs` | `object` | Attribute definitions: `{ attrName: { default: value } }`. Attrs without defaults are required |
| `toDOM` | `function(node)` | Returns DOM spec: `["tag", { attr: val }, 0]` where `0` = content hole. Leaf nodes omit `0` |
| `parseDOM` | `array` | Parse rules for pasting: `[{ tag: "div.myclass", getAttrs: fn, priority: 60 }]` |

### MarkSpec (passed to `marks.addToEnd()`)

| Property | Type | Description |
|----------|------|-------------|
| `attrs` | `object` | Attribute definitions (same as NodeSpec) |
| `inclusive` | `boolean` | If `true` (default), typing at the mark edge extends it. Set `false` for link-like marks |
| `excludes` | `string` | Space-separated mark types that cannot coexist. `"_"` = excludes all other marks |
| `group` | `string` | Mark group name |
| `toDOM` | `function(mark)` | Returns DOM spec: `["span", { style: "color: red" }, 0]` |
| `parseDOM` | `array` | Parse rules: `[{ tag: "span[style]", getAttrs: fn }, { style: "color", getAttrs: fn }]` |

### Content expressions

Content expressions use regex-like syntax with node type names and groups:

- `"paragraph"` - exactly one paragraph
- `"paragraph*"` - zero or more
- `"paragraph+"` - one or more
- `"(paragraph \| blockquote)+"` - one or more of either
- `"heading paragraph+"` - heading followed by paragraphs
- `"inline*"` - zero or more nodes from the `inline` group
- `"text*"` - zero or more text nodes

### parseDOM rule properties

| Property | Description |
|----------|-------------|
| `tag` | CSS selector: `"div"`, `"span.myclass"`, `"p[data-type]"` |
| `style` | CSS property name: `"color"`, `"font-size"` |
| `getAttrs` | `function(dom)` returning attrs object, `false` to skip, or `null`/`undefined` to match |
| `priority` | Number (default 50). Higher = checked first. Use 60+ to override default rules |

## File Layout for a Prosemirror Bridge

The bridge code lives inside the target plugin itself (not inside the
prosemirror plugin directory). This way the bridge is only active when both
the target plugin and prosemirror are installed.

```
yourplugin/
  action.php        -- existing or new; add PROSEMIRROR_RENDER_PLUGIN
                       and PROSEMIRROR_PARSE_UNKNOWN handlers
  script.js         -- DokuWiki auto-bundles this globally; use it to
                       register schema/nodeview/menu extensions
  style.css         -- (optional) editor styles for your custom nodes
```

**No rebuild of the prosemirror bundle is required.** All extensions use the
public API exposed via `window.Prosemirror`.

### Guard your code

Both PHP and JS bridge code must be guarded so it only runs when prosemirror
is actually available:

**PHP:**
```php
public function register(EventHandler $controller)
{
    if (!plugin_load('renderer', 'prosemirror')) return;
    $controller->register_hook('PROSEMIRROR_RENDER_PLUGIN', 'BEFORE', $this, 'handleRender');
    $controller->register_hook('PROSEMIRROR_PARSE_UNKNOWN', 'BEFORE', $this, 'handleParse');
}
```

**JS:**
```javascript
jQuery(document).on('PROSEMIRROR_API_INITIALIZED', function () {
    // Safe: API exists
    window.Prosemirror.pluginSchemas.push(function (nodes, marks) {
        // ...
        return { nodes: nodes, marks: marks };
    });
});
```

## Adding Menu Items

To add toolbar buttons in the prosemirror editor, push dispatchers to
`window.Prosemirror.pluginMenuItemDispatchers`.

### Toggle mark button (like bold/italic)

```javascript
jQuery(document).on('PROSEMIRROR_API_INITIALIZED', function () {
    var MenuItem = window.Prosemirror.classes.MenuItem;
    var AbstractMenuItemDispatcher = window.Prosemirror.classes.AbstractMenuItemDispatcher;

    var MyMarkDispatcher = {
        isAvailable: function (schema) {
            return !!schema.marks.typo_fontcolor;
        },
        getMenuItem: function (schema) {
            // Create an icon element (inline SVG or img)
            var icon = document.createElement('span');
            icon.innerHTML = '<svg viewBox="0 0 24 24">...</svg>';

            return new MenuItem({
                command: function (state, dispatch) {
                    // Custom command: prompt for color, apply mark
                    // Or use prosemirror-commands toggleMark if no prompt needed
                    if (!state.selection.empty && dispatch) {
                        var markType = schema.marks.typo_fontcolor;
                        var color = prompt('Color:');
                        if (color) {
                            dispatch(state.tr.addMark(
                                state.selection.from,
                                state.selection.to,
                                markType.create({ color: color })
                            ));
                        }
                    }
                    return true;
                },
                icon: icon,
                label: 'Font Color',
            });
        },
    };

    window.Prosemirror.pluginMenuItemDispatchers.push(MyMarkDispatcher);
});
```

### Insert node button

```javascript
var MyNodeDispatcher = {
    isAvailable: function (schema) {
        return !!schema.nodes.myplugin_block;
    },
    getMenuItem: function (schema) {
        return new MenuItem({
            command: window.Prosemirror.commands.setBlockTypeNoAttrCheck(
                schema.nodes.myplugin_block,
                { syntax: '{{myplugin>default}}' }
            ),
            icon: myIcon,
            label: 'My Plugin',
        });
    },
};
```

### Using KeyValueForm for attribute editing

```javascript
var KeyValueForm = window.Prosemirror.classes.KeyValueForm;

// Create a form with fields
var form = new KeyValueForm('Edit My Plugin', [
    { label: 'URL', type: 'url', name: 'url', required: true, value: '' },
    { label: 'Max Items', type: 'number', name: 'max', value: 5 },
    {
        label: 'Style', type: 'select', name: 'style',
        options: [
            { label: 'Default', value: 'default' },
            { label: 'Compact', value: 'compact' },
        ],
    },
]);

// Show the form as a jQuery UI dialog
form.show();

// Listen for submit
form.on('submit', function (e) {
    e.preventDefault();
    var attrs = form.$form.serializeArray().reduce(function (acc, item) {
        acc[item.name] = item.value;
        return acc;
    }, {});
    form.hide();
    // Use attrs to dispatch a transaction...
});
```

## Keybindings and Input Rules

These are optional enhancements registered via the schema callback or
separate plugin mechanisms.

### Keybindings

ProseMirror keybindings are set via the `keymap` plugin. Since the prosemirror
plugin doesn't expose its keymap for extension, you can add your own keymap
plugin via the schema callback (the schema is built before plugins, but you
can store a reference and add a plugin later). In practice, most plugin
integrations rely on menu items rather than keybindings.

### Input Rules

Input rules auto-format text as the user types (e.g., `**text**` auto-applies
bold). These are defined in the prosemirror plugin's `inputrules.js` and are
not currently extensible via the public API. Custom input rules would require
modifying the prosemirror bundle.

## Schema and Mark Type Naming

Use a prefix based on the plugin name to avoid collisions:

- Marks: `typo_fontcolor`, `typo_bgcolor`, `typo_fontsize`
- Nodes: `cellbg_color`, `include_block`, `gallery_block`

## Parser Mark Integration

The prosemirror parser's `Mark.php` maintains static arrays mapping mark types
to their DokuWiki opening/closing syntax. For custom marks added by external
plugins, these arrays don't include your types.

**Options for handling custom marks during JSON-to-syntax parsing:**

1. **Store syntax in mark attrs** (`syntax_open`, `syntax_close`). Then handle
   the mark in `PROSEMIRROR_PARSE_UNKNOWN` or by extending `Mark.php`.

2. **Use inline nodes instead of marks** (Approach B). Avoids the mark parsing
   system entirely. The `PROSEMIRROR_PARSE_UNKNOWN` handler receives the full
   node data including children.

3. **Modify `parser\Mark.php`** to support dynamic mark registration. This
   requires changing the prosemirror plugin itself.

Option 2 is recommended for external plugin bridges because it requires no
changes to the prosemirror plugin.

## Roundtrip Safety

The critical requirement: editing in ProseMirror must not corrupt or lose the
plugin syntax. To ensure this:

1. **Store the original syntax** in node/mark attrs (`syntax_open`, `syntax_close`,
   or `syntax` for atoms). The parser reconstructs syntax from these attrs rather
   than trying to regenerate it from parsed parameters.

2. **Handle all lexer states** (ENTER, UNMATCHED, EXIT for formatting;
   SPECIAL for substitutions). Missing states cause syntax loss.

3. **Test the roundtrip:** Edit a page with the plugin syntax in ProseMirror,
   switch to syntax editor, verify the syntax is preserved.

## Renderer API Reference

Key methods on `renderer_plugin_prosemirror` (available as `$event->data['renderer']`):

```php
// Node stack operations
$renderer->nodestack                    // NodeStack instance
$renderer->addToNodestack(Node $node)   // add as child of current
$renderer->addToNodestackTop(Node $node) // add as child and push (for containers)
$renderer->dropFromNodeStack($type)      // pop and verify type
$renderer->getCurrentMarks()             // get active marks array

// Creating nodes
$node = new Node('typename');
$node->attr('key', 'value');
$node->addChild($childNode);
$node->setText('text content');  // only for text nodes
$node->addMark(new Mark('marktype'));

// Creating marks
$mark = new Mark('typename');
$mark->attr('key', 'value');
```

## JS Compatibility

All JS must work on Firefox 78 ESR. See CLAUDE.md for the full list, but key
restrictions:

- No `#privateField`, `??=`, `||=`, `&&=`
- No `structuredClone()`, `.at()`, `Object.hasOwn()`
- No native `<dialog>`/`showModal()`
- Use `var` or `let`/`const`; avoid class private fields
- `?.` and `??` are OK
- jQuery is available as `jQuery` (not `$`)

## Step-by-Step Checklist

For each plugin you want to support:

1. [ ] Identify the plugin's syntax pattern(s) and lexer states
2. [ ] Decide: mark, inline node, block node, or table-cell attribute
3. [ ] In the plugin's `action.php`, add `PROSEMIRROR_RENDER_PLUGIN` handler
4. [ ] In the plugin's `action.php`, add `PROSEMIRROR_PARSE_UNKNOWN` handler
5. [ ] In the plugin's `script.js`, register schema extension via `pluginSchemas`
6. [ ] (Optional) Add nodeview via `pluginNodeViews`
7. [ ] (Optional) Add menu items via `pluginMenuItemDispatchers`
8. [ ] (Optional) Add editor-specific CSS in `style.css`
9. [ ] (Optional) Add keybindings for common actions
10. [ ] Test roundtrip: wiki syntax -> prosemirror -> wiki syntax (must be identical)
11. [ ] Test visual rendering in the editor matches the rendered page
12. [ ] Test paste from rendered HTML (if `parseDOM` is defined)
13. [ ] PHP lint: `docker exec dokuwiki-docker-dokuwiki-1 php -l /storage/lib/plugins/<name>/action.php`

## Reference: Existing Prosemirror Node Types

Built-in nodes in the prosemirror schema:

| Node Type | Group | Inline | Content | Purpose |
|-----------|-------|--------|---------|---------|
| `doc` | - | no | `(block\|baseonly\|container\|protected_block\|substitution_block)+` | Root |
| `paragraph` | block | no | `inline*` | Paragraph |
| `heading` | baseonly | no | `text*` (no marks) | Headings h1-h5 |
| `text` | inline | yes | - | Text content |
| `hard_break` | inline | yes | - (leaf) | Line break |
| `horizontal_rule` | block | no | - (leaf) | `----` |
| `image` | inline | yes | - (leaf) | Internal/external images |
| `link` | inline | yes | - (leaf, atom) | All link types |
| `footnote` | inline | yes | - (atom) | Footnotes |
| `smiley` | inline | yes | - (atom) | Smileys |
| `code_block` | protected_block | no | `text*` | Code blocks |
| `preformatted` | protected_block | no | `text*` | Preformatted |
| `blockquote` | container | no | `(block\|blockquote\|protected_block)+` | Blockquotes |
| `bullet_list` | container | no | `list_item+` | Unordered lists |
| `ordered_list` | container | no | `list_item+` | Ordered lists |
| `table` | container | no | `table_row+` | Tables |
| `rss` | substitution_block | no | - (atom) | RSS feeds |
| `dwplugin_block` | protected_block | no | `text*` | Generic plugin (block) |
| `dwplugin_inline` | inline | yes | `text*` | Generic plugin (inline) |

Built-in marks:

| Mark Type | Syntax | HTML |
|-----------|--------|------|
| `strong` | `**text**` | `<strong>` |
| `em` | `//text//` | `<em>` |
| `code` | `''text''` | `<code>` |
| `underline` | `__text__` | `<u>` |
| `deleted` | `<del>text</del>` | `<del>` |
| `subscript` | `<sub>text</sub>` | `<sub>` |
| `superscript` | `<sup>text</sup>` | `<sup>` |
| `unformatted` | `%%text%%` | `<span class="unformatted">` |
