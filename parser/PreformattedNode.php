<?php

namespace dokuwiki\plugin\prosemirror\parser;

/**
 * Preformatted text node — maps to DokuWiki two-space indentation syntax
 */
class PreformattedNode extends Node
{
    /** @var Node */
    protected $parent;

    /** @var array */
    protected $data;

    /**
     * @param array $data
     * @param Node  $parent
     */
    public function __construct($data, Node $parent)
    {
        $this->parent = $parent;
        $this->data   = $data;
    }

    /**
     * Return the DokuWiki preformatted syntax for this node
     *
     * @return string
     */
    public function toSyntax()
    {
        // Guard against missing or empty content array
        $text  = $this->data['content'][0]['text'] ?? '';
        $lines = explode("\n", $text);
        return '  ' . implode("\n  ", $lines);
    }
}
