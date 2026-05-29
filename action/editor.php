<?php
if (!defined('DOKU_INC')) die();

/**
 * DokuWiki Plugin prosemirror (Action Component)
 *
 * Injects the WYSIWYG editor toggle button, the prosemirror JSON hidden field,
 * and the auxiliary link/media forms into the edit page.
 *
 * @license GPL 2 http://www.gnu.org/licenses/gpl-2.0.html
 * @author  Andreas Gohr <gohr@cosmocode.de>
 */

use dokuwiki\Extension\ActionPlugin;
use dokuwiki\Extension\EventHandler;
use dokuwiki\Extension\Event;
use dokuwiki\Form\Form;
use dokuwiki\Form\ButtonElement;

class action_plugin_prosemirror_editor extends ActionPlugin
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
        $controller->register_hook('ACTION_HEADERS_SEND', 'BEFORE', $this, 'forceWYSIWYG');
        $controller->register_hook('ACTION_HEADERS_SEND', 'AFTER', $this, 'addJSINFO');
        $controller->register_hook('FORM_EDIT_OUTPUT', 'BEFORE', $this, 'addDataAndToggleButton');
        $controller->register_hook('TPL_ACT_RENDER', 'AFTER', $this, 'addAdditionalForms');
    }

    /**
     * If the current user is forced to use the WYSIWYG editor, set the cookie accordingly
     *
     * Triggered by event: ACTION_HEADERS_SEND
     *
     * @param Event $event
     * @param mixed $param
     *
     * @return void
     */
    public function forceWYSIWYG(Event $event, $param)
    {
        if ($this->isForceWYSIWYG()) {
            set_doku_pref('plugin_prosemirror_useWYSIWYG', true);
        }
    }

    /**
     * Add the editor toggle button and, if WYSIWYG is active, the page JSON
     *
     * Triggered by event: FORM_EDIT_OUTPUT
     *
     * @param Event $event event object
     * @param mixed $param
     *
     * @return void
     */
    public function addDataAndToggleButton(Event $event, $param)
    {
        if (!$this->allowWYSIWYG()) {
            return;
        }

        /** @var Form $form */
        $form = $event->data;

        // Return early when the textarea is read-only
        if ($this->isReadOnly($form)) {
            return;
        }

        $useWYSIWYG = get_doku_pref('plugin_prosemirror_useWYSIWYG', false);

        $prosemirrorJSON = '';
        if ($useWYSIWYG) {
            global $TEXT;
            $instructions = p_get_instructions($TEXT);
            try {
                $prosemirrorJSON = p_render('prosemirror', $instructions, $info);
            } catch (Throwable $e) {
                $errorMsg = 'Rendering the page\'s syntax for the WYSIWYG editor failed: ' . $e->getMessage();

                /** @var helper_plugin_prosemirror $helper */
                $helper = plugin_load('helper', 'prosemirror');
                if ($helper->tryToLogErrorToSentry($e, ['text' => $TEXT])) {
                    $errorMsg .= ' -- The error has been logged to Sentry.';
                }

                msg($errorMsg, -1);
                return;
            }
        }

        $form->addElement($this->buildToggleButton(), 0);
        $form->setHiddenField('prosemirror_json', $prosemirrorJSON);
        $form->addHTML('<div class="prosemirror_wrapper" id="prosemirror__editor"></div>', 1);
    }

    /**
     * Create the button to toggle the WYSIWYG editor
     *
     * The button is hidden when the WYSIWYG editor is forced on the user.
     *
     * @return ButtonElement
     */
    protected function buildToggleButton()
    {
        $button = new ButtonElement('prosemirror', $this->getLang('switch_editors'));
        $button->attr('type', 'button');
        $button->addClass('button plugin_prosemirror_useWYSIWYG');
        if ($this->isForceWYSIWYG()) {
            $button->attr('style', 'display: none;');
        }
        return $button;
    }

    /**
     * Determine if the current user is forced to use the WYSIWYG editor
     *
     * @return bool
     */
    protected function isForceWYSIWYG()
    {
        return $this->getConf('forceWYSIWYG') && !auth_ismanager();
    }

    /**
     * Return false when editing a non-section/non-page target (e.g. edittable, data plugin)
     *
     * @return bool
     */
    protected function allowWYSIWYG()
    {
        global $INPUT;
        return !$INPUT->has('target') || $INPUT->str('target') === 'section';
    }

    /**
     * Output the auxiliary link form, media form, and code-language datalist
     *
     * Triggered by event: TPL_ACT_RENDER
     *
     * @param Event $event
     *
     * @return void
     */
    public function addAdditionalForms(Event $event)
    {
        if (!$this->allowWYSIWYG()) {
            return;
        }

        if (!in_array($event->data, ['edit', 'preview'], true)) {
            return;
        }

        // ── Link form ──────────────────────────────────────────────────────────
        $linkForm = new Form([
            'class' => 'plugin_prosemirror_linkform',
            'id' => 'prosemirror-linkform',
            'style' => 'display: none;',
        ]);
        $linkForm->addFieldsetOpen('Links')->addClass('js-link-fieldset');
        $iwOptions = array_keys(getInterwiki());
        $linkForm->addDropdown('iwshortcut', $iwOptions, 'InterWiki')->attr('required', 'required');

        $linkForm->addButtonHTML('linkwiz', inlineSVG(DOKU_PLUGIN . 'prosemirror/images/link.svg'))->attrs([
            'type' => 'button',
            'class' => 'js-open-linkwiz linkform_linkwiz',
        ]);
        $linkForm->addTextInput('linktarget', $this->getLang('link target'))->attrs([
            'required' => 'required',
            'autofocus' => 'autofocus',
        ]);

        $linkForm->addTagOpen('div')->addClass('radio-wrapper');
        $linkForm->addTagOpen('fieldset');
        $linkForm->addTagOpen('legend');
        $linkForm->addHTML($this->getLang('legend:link type'));
        $linkForm->addTagClose('legend');
        $linkForm->addRadioButton('linktype', $this->getLang('type:wiki page'))->val('internallink');
        $linkForm->addRadioButton('linktype', $this->getLang('type:interwiki'))->val('interwikilink');
        $linkForm->addRadioButton('linktype', $this->getLang('type:email'))->val('emaillink');
        $linkForm->addRadioButton('linktype', $this->getLang('type:external'))
            ->val('externallink')
            ->attr('checked', 'checked');
        $linkForm->addRadioButton('linktype', $this->getLang('type:other'))->val('other');
        $linkForm->addTagClose('fieldset');
        $linkForm->addTagClose('div');

        $linkForm->addTagOpen('div')->addClass('radio-wrapper');
        $linkForm->addTagOpen('fieldset');
        $linkForm->addTagOpen('legend');
        $linkForm->addHTML($this->getLang('legend:link name type'));
        $linkForm->addTagClose('legend');
        $linkForm->addRadioButton('nametype', $this->getLang('type:automatic title'))
            ->val('automatic')
            ->attr('checked', 'checked');
        $linkForm->addRadioButton('nametype', $this->getLang('type:custom title'))->val('custom');
        $linkForm->addRadioButton('nametype', $this->getLang('type:image'))->val('image');
        $linkForm->addTextInput('linkname', $this->getLang('label:link name input'))
            ->attr('placeholder', $this->getLang('placeholder:link name'));
        $linkForm->addTagOpen('div')->addClass('js-media-wrapper');
        $linkForm->addTagClose('div');
        $linkForm->addTagClose('fieldset');
        $linkForm->addTagClose('div');

        $linkForm->addFieldsetClose();
        $linkForm->addButton('ok-button', $this->getLang('btn:ok'))->attr('type', 'submit');
        $linkForm->addButton('cancel-button', $this->getLang('cancel'))->attr('type', 'button');

        echo $linkForm->toHTML();

        // ── Media form ─────────────────────────────────────────────────────────
        $mediaForm = new Form([
            'class' => 'plugin_prosemirror_mediaform',
            'id' => 'prosemirror-mediaform',
            'style' => 'display: none;',
        ]);
        $mediaForm->addFieldsetOpen($this->getLang('legend:media'))->addClass('js-media-fieldset');
        $mediaForm->addButtonHTML(
            'mediamanager',
            inlineSVG(DOKU_PLUGIN . 'prosemirror/images/file-image-outline.svg')
        )->attrs([
            'type' => 'button',
            'class' => 'js-open-mediamanager mediaform_mediamanager',
        ]);
        $mediaForm->addTextInput('mediatarget', $this->getLang('media target'))->attrs([
            'required' => 'required',
            'autofocus' => 'autofocus',
        ]);
        $mediaForm->addTextInput('mediacaption', $this->getLang('label:caption'));

        $mediaForm->addTagOpen('div')->addClass('image-properties');
        $mediaForm->addTagOpen('p');
        $mediaForm->addHTML($this->getLang('label:image_properties'));
        $mediaForm->addTagClose('p');

        $mediaForm->addTagOpen('div')->addClass('input-wrapper');
        $mediaForm->addTagOpen('fieldset');
        $mediaForm->addTagOpen('legend');
        $mediaForm->addHTML($this->getLang('legend:size'));
        $mediaForm->addTagClose('legend');
        $mediaForm->addTextInput('width', $this->getLang('label:width'))->attr('type', 'number');
        $mediaForm->addTextInput('height', $this->getLang('label:height'))->attr('type', 'number');
        $mediaForm->addTagClose('fieldset');
        $mediaForm->addTagClose('div');

        $mediaForm->addTagOpen('div')->addClass('input-wrapper');
        $mediaForm->addTagOpen('fieldset');
        $mediaForm->addTagOpen('legend');
        $mediaForm->addHTML($this->getLang('legend:alignment'));
        $mediaForm->addTagClose('legend');
        $mediaForm->addRadioButton('alignment', $this->getLang('label:default alignment'))
            ->val('')
            ->attr('checked', 'checked');
        $mediaForm->addRadioButton('alignment', $this->getLang('label:float left'))->val('left');
        $mediaForm->addRadioButton('alignment', $this->getLang('label:center alignment'))->val('center');
        $mediaForm->addRadioButton('alignment', $this->getLang('label:float right'))->val('right');
        $mediaForm->addTagClose('fieldset');
        $mediaForm->addTagClose('div');

        $mediaForm->addTagOpen('div')->addClass('input-wrapper');
        $mediaForm->addTagOpen('fieldset');
        $mediaForm->addTagOpen('legend');
        $mediaForm->addHTML($this->getLang('legend:linking'));
        $mediaForm->addTagClose('legend');
        $mediaForm->addRadioButton('linking', $this->getLang('label:default linking'))
            ->val('details')
            ->attr('checked', 'checked');
        $mediaForm->addRadioButton('linking', $this->getLang('label:direct linking'))->val('direct');
        $mediaForm->addRadioButton('linking', $this->getLang('label:nolink'))->val('nolink');
        $mediaForm->addRadioButton('linking', $this->getLang('label:linkonly'))->val('linkonly');
        $mediaForm->addTagClose('fieldset');
        $mediaForm->addTagClose('div');

        $mediaForm->addTagOpen('div')->addClass('input-wrapper');
        $mediaForm->addTagOpen('fieldset');
        $mediaForm->addTagOpen('legend');
        $mediaForm->addHTML($this->getLang('legend:caching'));
        $mediaForm->addTagClose('legend');
        $mediaForm->addRadioButton('caching', $this->getLang('label:default caching'))
            ->val('')
            ->attr('checked', 'checked');
        $mediaForm->addRadioButton('caching', $this->getLang('label:recache'))->val('recache');
        $mediaForm->addRadioButton('caching', $this->getLang('label:nocache'))->val('nocache');
        $mediaForm->addTagClose('fieldset');
        $mediaForm->addTagClose('div');

        $mediaForm->addTagClose('div'); // end .image-properties

        $mediaForm->addFieldsetClose();
        $mediaForm->addButton('ok-button', $this->getLang('btn:ok'))->attr('type', 'submit');
        $mediaForm->addButton('cancel-button', $this->getLang('cancel'))->attr('type', 'button');

        echo $mediaForm->toHTML();

        // ── Code-language datalist ─────────────────────────────────────────────
        // phpcs:disable
        $languages = explode(' ', '4cs 6502acme 6502kickass 6502tasm 68000devpac abap actionscript3 actionscript ada aimms algol68 apache applescript apt_sources arm asm asp asymptote autoconf autohotkey autoit avisynth awk bascomavr bash basic4gl batch bf biblatex bibtex blitzbasic bnf boo caddcl cadlisp ceylon cfdg cfm chaiscript chapel cil c_loadrunner clojure c_mac cmake cobol coffeescript c cpp cpp-qt cpp-winapi csharp css cuesheet c_winapi dart dcl dcpu16 dcs delphi diff div dos dot d ecmascript eiffel email epc e erlang euphoria ezt f1 falcon fo fortran freebasic freeswitch fsharp gambas gdb genero genie gettext glsl gml gnuplot go groovy gwbasic haskell haxe hicest hq9plus html html4strict html5 icon idl ini inno intercal io ispfpanel java5 java javascript jcl j jquery julia kixtart klonec klonecpp kotlin latex lb ldif lisp llvm locobasic logtalk lolcode lotusformulas lotusscript lscript lsl2 lua m68k magiksf make mapbasic mathematica matlab mercury metapost mirc mk-61 mmix modula2 modula3 mpasm mxml mysql nagios netrexx newlisp nginx nimrod nsis oberon2 objc objeck ocaml-brief ocaml octave oobas oorexx oracle11 oracle8 oxygene oz parasail parigp pascal pcre perl6 perl per pf phix php-brief php pic16 pike pixelbender pli plsql postgresql postscript povray powerbuilder powershell proftpd progress prolog properties providex purebasic pycon pys60 python qbasic qml q racket rails rbs rebol reg rexx robots rpmspec rsplus ruby rust sas sass scala scheme scilab scl sdlbasic smalltalk smarty spark sparql sql standardml stonescript swift systemverilog tclegg tcl teraterm texgraph text thinbasic tsql twig typoscript unicon upc urbi uscript vala vbnet vb vbscript vedit verilog vhdl vim visualfoxpro visualprolog whitespace whois winbatch xbasic xml xojo xorg_conf xpp yaml z80 zxbasic');
        // phpcs:enable
        $datalistHTML = '<datalist id="codelanguages">';
        foreach ($languages as $language) {
            $datalistHTML .= '<option value="' . hsc($language) . '">';
        }
        $datalistHTML .= '</datalist>';
        echo $datalistHTML;
    }

    /**
     * Provide the current smiley configuration to Javascript via JSINFO
     *
     * Triggered by event: ACTION_HEADERS_SEND (AFTER)
     *
     * @return void
     */
    public function addJSINFO()
    {
        global $JSINFO;
        $JSINFO['SMILEY_CONF'] = getSmileys();
    }

    /**
     * Returns true if the edit form's textarea is read-only
     *
     * @param Form $form
     *
     * @return bool
     */
    protected function isReadOnly($form)
    {
        if (is_a($form, Form::class)) {
            $textareaPos = $form->findPositionByType('textarea');
            return $textareaPos !== false && !empty($form->getElementAt($textareaPos)->attr('readonly'));
        }

        // Legacy Doku_Form support
        /** @var \Doku_Form $form */
        $textareaPos = $form->findElementByType('wikitext');
        return $textareaPos !== false && !empty($form->getElementAt($textareaPos)['readonly']);
    }
}

// vim:ts=4:sw=4:et:
