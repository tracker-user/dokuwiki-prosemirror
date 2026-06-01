<?php

namespace dokuwiki\plugin\prosemirror\parser;

class TableCellNode extends Node
{
    protected $data;

    /** @var Node[] */
    protected $subnodes;

    public function __construct($data, Node $parent = null)
    {
        if (empty($data['content'])) {
            $data['content'] = [
                [
                    'type' => 'text',
                    'text' => ' ',
                ]
            ];
        }
        $this->data = $data;

        $previousNode = null;
        foreach ($data['content'] as $nodeData) {
            $newNode = static::getSubNode($nodeData, $this, $previousNode);
            $this->subnodes[] = $newNode;
            $previousNode = $newNode;
        }
    }

    public function toSyntax()
    {
        $prefix = '|';
        if ($this->isHeaderCell()) {
            $prefix = '^';
        }
        $doc = '';
        foreach ($this->subnodes as $subnode) {
            $doc .= $subnode->toSyntax();
        }
        [$paddingLeft, $paddingRight] = $this->calculateAlignmentPadding();
        return $prefix . $paddingLeft . trim($doc) . $paddingRight;
    }

    public function isHeaderCell()
    {
        return $this->data['type'] === 'table_header';
    }

    public function getRowSpan()
    {
        return $this->data['attrs']['rowspan'] ?? 1;
    }

    public function getColSpan()
    {
        return $this->data['attrs']['colspan'] ?? 1;
    }

    /**
     * Calculate the correct padding to align cell-content left, right or center
     *
     * @return String[] [left padding, right padding]
     */
    protected function calculateAlignmentPadding()
    {
        if (empty($this->data['attrs']['align'])) {
            return [' ', ' '];
        }
        if ($this->data['attrs']['align'] === 'right') {
            return ['  ', ' '];
        }
        if ($this->data['attrs']['align'] === 'center') {
            return ['  ', '  '];
        }
        if ($this->data['attrs']['align'] === 'left') {
            return [' ', '  '];
        }
        return [' ', ' '];
    }
}
