<?php
if (!defined('DOKU_INC')) die();

/**
 * DokuWiki Plugin prosemirror (Action Component)
 *
 * Handles AJAX calls for link/media resolution and editor switching.
 *
 * @license GPL 2 http://www.gnu.org/licenses/gpl-2.0.html
 * @author  Andreas Gohr <gohr@cosmocode.de>
 */

use dokuwiki\Extension\ActionPlugin;
use dokuwiki\Extension\EventHandler;
use dokuwiki\Extension\Event;
use dokuwiki\plugin\prosemirror\parser\ImageNode;
use dokuwiki\plugin\prosemirror\parser\RSSNode;
use dokuwiki\plugin\prosemirror\parser\LocalLinkNode;
use dokuwiki\plugin\prosemirror\parser\InternalLinkNode;
use dokuwiki\plugin\prosemirror\parser\LinkNode;

class action_plugin_prosemirror_ajax extends ActionPlugin
{
    /**
     * Registers event handlers
     *
     * @param EventHandler $controller DokuWiki's event controller object
     *
     * @return void
     */
    public function register(EventHandler $controller)
    {
        $controller->register_hook('AJAX_CALL_UNKNOWN', 'BEFORE', $this, 'handleAjax');
        $controller->register_hook('AJAX_CALL_UNKNOWN', 'BEFORE', $this, 'switchEditors');
    }

    /**
     * Handle AJAX calls for link/media resolution
     *
     * Event: AJAX_CALL_UNKNOWN
     *
     * @param Event $event event object by reference
     *
     * @return void
     */
    public function handleAjax(Event $event)
    {
        if ($event->data !== 'plugin_prosemirror') {
            return;
        }
        $event->preventDefault();
        $event->stopPropagation();

        global $INPUT, $ID;
        $ID = cleanID($INPUT->str('id'));
        $responseData = [];

        foreach ($INPUT->arr('actions') as $action) {
            switch ($action) {
                case 'resolveInternalLink':
                    $inner = $INPUT->str('inner');
                    $responseData[$action] = $this->resolveInternalLink($inner, $ID);
                    break;

                case 'resolveInterWikiLink':
                    $inner = $INPUT->str('inner');
                    $parts = explode('>', $inner, 2);
                    [$shortcut, $reference] = count($parts) === 2 ? $parts : [$parts[0], ''];
                    $responseData[$action] = $this->resolveInterWikiLink($shortcut, $reference);
                    break;

                case 'resolveMedia':
                    $attrs = $INPUT->arr('attrs');
                    $responseData[$action] = [
                        'data-resolvedHtml' => ImageNode::resolveMedia(
                            $attrs['id'] ?? '',
                            $attrs['title'] ?? null,
                            $attrs['align'] ?? null,
                            $attrs['width'] ?? null,
                            $attrs['height'] ?? null,
                            $attrs['cache'] ?? null,
                            $attrs['linking'] ?? null
                        ),
                    ];
                    break;

                case 'resolveImageTitle':
                    $image = $INPUT->arr('image');
                    $responseData[$action] = [
                        'data-resolvedImage' => LinkNode::resolveImageTitle(
                            $ID,
                            $image['id'] ?? '',
                            $image['title'] ?? null,
                            $image['align'] ?? null,
                            $image['width'] ?? null,
                            $image['height'] ?? null,
                            $image['cache'] ?? null
                        ),
                    ];
                    break;

                case 'resolveRSS':
                    $attrs = json_decode($INPUT->str('attrs'), true);
                    if ($attrs === null) {
                        http_status(400, 'invalid attrs JSON');
                        return;
                    }
                    $responseData[$action] = RSSNode::renderAttrsToHTML($attrs);
                    break;

                default:
                    dokuwiki\Logger::getInstance(dokuwiki\Logger::LOG_DEBUG)->log(
                        __FILE__ . ':' . __LINE__,
                        'Unknown action: ' . $action
                    );
                    http_status(400, 'unknown action');
                    return;
            }
        }

        header('Content-Type: application/json');
        echo json_encode($responseData);
    }

    /**
     * Resolve an interwiki link to a URL and CSS class
     *
     * @param string $shortcut  the interwiki shortcut (e.g. "wp")
     * @param string $reference the page reference within that wiki
     *
     * @return array{url: string, resolvedClass: string}
     */
    protected function resolveInterWikiLink($shortcut, $reference)
    {
        $xhtml_renderer = p_get_renderer('xhtml');
        $xhtml_renderer->interwiki = getInterwiki();
        $url = $xhtml_renderer->_resolveInterWiki($shortcut, $reference, $exists);
        return [
            'url' => $url,
            'resolvedClass' => 'interwikilink interwiki iw_' . $shortcut,
        ];
    }

    /**
     * Resolve an internal or local anchor link
     *
     * @param string $inner  the raw link target (may start with #)
     * @param string $curId  the current page ID
     *
     * @return array
     */
    protected function resolveInternalLink($inner, $curId)
    {
        if (isset($inner[0]) && $inner[0] === '#') {
            return LocalLinkNode::resolveLocalLink($inner, $curId);
        }
        return InternalLinkNode::resolveLink($inner, $curId);
    }

    /**
     * Handle AJAX calls for switching between WYSIWYG and syntax editor
     *
     * Event: AJAX_CALL_UNKNOWN
     *
     * @param Event $event event object by reference
     *
     * @return void
     */
    public function switchEditors(Event $event)
    {
        if ($event->data !== 'plugin_prosemirror_switch_editors') {
            return;
        }
        $event->preventDefault();
        $event->stopPropagation();

        global $INPUT, $ID;
        $ID = cleanID($INPUT->str('id'));

        if ($INPUT->bool('getJSON')) {
            // Syntax editor → WYSIWYG: convert wikitext to prosemirror JSON
            $text = $INPUT->str('data');
            $instructions = p_get_instructions($text);
            try {
                $prosemirrorJSON = p_render('prosemirror', $instructions, $info);
            } catch (Throwable $e) {
                $errorMsg = 'Rendering the page\'s syntax for the WYSIWYG editor failed: ';
                $errorMsg .= $e->getMessage();

                /** @var helper_plugin_prosemirror $helper */
                $helper = plugin_load('helper', 'prosemirror');
                if ($helper->tryToLogErrorToSentry($e, ['text' => $text])) {
                    $errorMsg .= ' -- The error has been logged to Sentry.';
                } else {
                    $errorMsg .= '<code>' . hsc($e->getFile()) . ':' . (int)$e->getLine() . '</code>';
                    $errorMsg .= '<pre>' . hsc($e->getTraceAsString()) . '</pre>';
                }

                http_status(500);
                header('Content-Type: application/json');
                echo json_encode(['error' => $errorMsg]);
                return;
            }
            $responseData = ['json' => $prosemirrorJSON];
        } else {
            // WYSIWYG → Syntax editor: convert prosemirror JSON to wikitext
            /** @var helper_plugin_prosemirror $helper */
            $helper = plugin_load('helper', 'prosemirror');
            $json   = $INPUT->str('data');
            try {
                $syntax = $helper->getSyntaxFromProsemirrorData($json);
            } catch (Throwable $e) {
                $errorMsg  = 'Parsing the data generated by Prosemirror failed with message: "';
                $errorMsg .= $e->getMessage() . '"';

                if ($helper->tryToLogErrorToSentry($e, ['json' => $json])) {
                    $errorMsg .= ' -- The error has been logged to Sentry.';
                }

                http_status(500);
                header('Content-Type: application/json');
                echo json_encode(['error' => $errorMsg]);
                return;
            }
            $responseData = ['text' => $syntax];
        }

        header('Content-Type: application/json');
        echo json_encode($responseData);
    }
}
