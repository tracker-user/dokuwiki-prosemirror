<?php

namespace dokuwiki\plugin\prosemirror\parser;

/**
 * Windows share (UNC path) link node — maps to DokuWiki [[\\server\share]] syntax
 */
class WindowsShareLinkNode extends LinkNode
{
    /**
     * Return the DokuWiki Windows-share link syntax for this node
     *
     * @return string
     */
    public function toSyntax()
    {
        $href = $this->attrs['href'] ?? '';

        // Strip the file:/// browser prefix that DokuWiki writes for UNC paths
        if (strncmp($href, 'file:///', 8) === 0) {
            $href = substr($href, 8);
        }

        // Convert forward slashes back to backslashes for the UNC path
        $href = str_replace('/', '\\', $href);

        return $this->getDefaultLinkSyntax($href);
    }

    /**
     * Render a Windows-share link into the prosemirror JSON node stack
     *
     * @param \renderer_plugin_prosemirror $renderer
     * @param string                       $link
     * @param string|null                  $title
     *
     * @return void
     */
    public static function render($renderer, $link, $title)
    {
        self::renderToJSON($renderer, 'other', $link, $title);
    }
}
