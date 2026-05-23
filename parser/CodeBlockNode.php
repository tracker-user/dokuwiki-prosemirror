<?php

namespace dokuwiki\plugin\prosemirror\parser;

/**
 * Code block node — maps to DokuWiki <code> syntax
 */
class CodeBlockNode extends Node
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
     * Return the DokuWiki <code> syntax for this node
     *
     * @return string
     */
    public function toSyntax()
    {
        $openingTag = '<code';
        if (!empty($this->data['attrs']['data-language'])) {
            $openingTag .= ' ' . $this->data['attrs']['data-language'];
        } else {
            $openingTag .= ' -';
        }
        if (!empty($this->data['attrs']['data-filename'])) {
            $openingTag .= ' ' . $this->data['attrs']['data-filename'];
        }
        $openingTag .= '>';

        // Guard against missing or empty content array
        $text = $this->data['content'][0]['text'] ?? '';

        return $openingTag . "\n" . $text . "\n</code>";
    }
}
