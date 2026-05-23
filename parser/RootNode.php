<?php

namespace dokuwiki\plugin\prosemirror\parser;

/**
 * Root document node — maps to the top-level DokuWiki page
 */
class RootNode extends Node
{
    /** @var Node[] */
    protected $subnodes = [];

    /** @var array|null */
    protected $attr;

    /**
     * @param array      $data
     * @param Node|null  $ignored  (kept for interface compatibility)
     */
    public function __construct($data, Node $ignored = null)
    {
        $this->attr = $data['attrs'] ?? null;

        foreach ($data['content'] ?? [] as $node) {
            $this->subnodes[] = self::getSubNode($node, $this);
        }
    }

    /**
     * Return the full DokuWiki syntax for the document
     *
     * @return string
     */
    public function toSyntax()
    {
        $doc = '';
        foreach ($this->subnodes as $subnode) {
            $doc .= $subnode->toSyntax();
            $doc  = rtrim($doc);
            $doc .= "\n\n";
        }
        $doc .= $this->getMacroSyntax();
        return $doc;
    }

    /**
     * Get the syntax for each active macro
     *
     * Produces the syntax for the ~~NOCACHE~~ and ~~NOTOC~~ macros
     *
     * @return string empty string or one line per active macro
     */
    protected function getMacroSyntax()
    {
        $syntax = '';
        if (!empty($this->attr['nocache'])) {
            $syntax .= "~~NOCACHE~~\n";
        }
        if (!empty($this->attr['notoc'])) {
            $syntax .= "~~NOTOC~~\n";
        }
        return $syntax;
    }
}
