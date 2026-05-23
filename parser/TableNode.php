<?php

namespace dokuwiki\plugin\prosemirror\parser;

/**
 * Table block node — maps to DokuWiki table syntax
 */
class TableNode extends Node
{
    /** @var TableRowNode[] */
    protected $tableRows = [];

    /** @var array  rowspan carry-over state keyed by column index */
    protected $rowSpans = [];

    /** @var int total number of columns in the table */
    protected $numCols = 0;

    /**
     * @param array     $data
     * @param Node|null $parent
     */
    public function __construct($data, Node $parent = null)
    {
        foreach ($data['content'] ?? [] as $row) {
            $this->tableRows[] = new TableRowNode($row, $this);
        }
        $this->countColNum();
    }

    /**
     * Count the total number of columns in the table.
     *
     * Uses the first row, which cannot have cells omitted by a rowspan,
     * so the sum of its colspans gives the true column count.
     *
     * @return void
     */
    protected function countColNum()
    {
        if (empty($this->tableRows)) {
            $this->numCols = 0;
            return;
        }
        $this->numCols = $this->tableRows[0]->countCols();
    }

    /**
     * Return the total number of columns for this table
     *
     * @return int
     */
    public function getNumTableCols()
    {
        return $this->numCols;
    }

    /**
     * Return the DokuWiki table syntax for this node
     *
     * @return string
     */
    public function toSyntax()
    {
        $doc = '';
        foreach ($this->tableRows as $row) {
            $doc .= $row->toSyntax() . "\n";
        }
        return $doc;
    }

    /**
     * @return array
     */
    public function getRowSpans()
    {
        return $this->rowSpans;
    }

    /**
     * @param array $rowSpans
     */
    public function setRowSpans(array $rowSpans)
    {
        $this->rowSpans = $rowSpans;
    }
}
