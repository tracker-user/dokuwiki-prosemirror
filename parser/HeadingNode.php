<?php

namespace dokuwiki\plugin\prosemirror\parser;

/**
 * Heading block node (levels 1–5 in DokuWiki)
 */
class HeadingNode extends Node
{
    /** @var Node */
    protected $parent;

    /** @var int */
    protected $level;

    /** @var string */
    protected $text;

    /**
     * @param array $data
     * @param Node  $parent
     */
    public function __construct($data, Node $parent)
    {
        // Guard: skip empty or missing content
        if (empty($data['content'][0]['text']) || trim($data['content'][0]['text']) === '') {
            return;
        }

        $this->parent = $parent;
        $this->level  = (int) $data['attrs']['level'];
        $this->text   = $data['content'][0]['text'];
    }

    /**
     * Return the DokuWiki heading syntax for this node
     *
     * @return string
     */
    public function toSyntax()
    {
        if ($this->text === null) {
            return '';
        }

        $wrapper = [
            1 => '======',
            2 => '=====',
            3 => '====',
            4 => '===',
            5 => '==',
        ];

        $w = $wrapper[$this->level] ?? '==';
        return $w . ' ' . $this->text . ' ' . $w;
    }
}
