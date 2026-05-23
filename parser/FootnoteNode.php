<?php

namespace dokuwiki\plugin\prosemirror\parser;

use dokuwiki\plugin\prosemirror\ProsemirrorException;

/**
 * Footnote inline node — maps to DokuWiki (( ... )) syntax
 */
class FootnoteNode extends Node
{
    /** @var TextNode[] */
    protected $subnodes = [];

    /** @var Node */
    protected $parent;

    /**
     * @param array $data
     * @param Node  $parent
     *
     * @throws ProsemirrorException when the embedded JSON is invalid or missing
     */
    public function __construct($data, Node $parent)
    {
        $this->parent = $parent;

        $json = $data['attrs']['contentJSON'] ?? '';
        if ($json === '') {
            return;
        }

        $contentDoc = json_decode($json, true);
        if ($contentDoc === null) {
            $e = new ProsemirrorException(
                'Invalid footnote contentJSON: ' . json_last_error_msg(),
                0
            );
            $e->addExtraData('json', $json);
            throw $e;
        }

        foreach ($contentDoc['content'] ?? [] as $subnode) {
            $this->subnodes[] = self::getSubNode($subnode, $this);
        }
    }

    /**
     * Return the DokuWiki footnote syntax for this node
     *
     * @return string
     */
    public function toSyntax()
    {
        $doc = '';
        foreach ($this->subnodes as $subnode) {
            $doc .= $subnode->toSyntax() . "\n\n";
        }
        return "((\n" . rtrim(ltrim($doc, "\n")) . "\n))";
    }
}
