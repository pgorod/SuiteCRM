<?php
/**
 *
 * SugarCRM Community Edition is a customer relationship management program developed by
 * SugarCRM, Inc. Copyright (C) 2004-2013 SugarCRM Inc.
 *
 * SuiteCRM is an extension to SugarCRM Community Edition developed by SalesAgility Ltd.
 * Copyright (C) 2011 - 2018 SalesAgility Ltd.
 *
 * This program is free software; you can redistribute it and/or modify it under
 * the terms of the GNU Affero General Public License version 3 as published by the
 * Free Software Foundation with the addition of the following permission added
 * to Section 15 as permitted in Section 7(a): FOR ANY PART OF THE COVERED WORK
 * IN WHICH THE COPYRIGHT IS OWNED BY SUGARCRM, SUGARCRM DISCLAIMS THE WARRANTY
 * OF NON INFRINGEMENT OF THIRD PARTY RIGHTS.
 *
 * This program is distributed in the hope that it will be useful, but WITHOUT
 * ANY WARRANTY; without even the implied warranty of MERCHANTABILITY or FITNESS
 * FOR A PARTICULAR PURPOSE. See the GNU Affero General Public License for more
 * details.
 *
 * You should have received a copy of the GNU Affero General Public License along with
 * this program; if not, see http://www.gnu.org/licenses or write to the Free
 * Software Foundation, Inc., 51 Franklin Street, Fifth Floor, Boston, MA
 * 02110-1301 USA.
 *
 * You can contact SugarCRM, Inc. headquarters at 10050 North Wolfe Road,
 * SW2-130, Cupertino, CA 95014, USA. or at email address contact@sugarcrm.com.
 *
 * The interactive user interfaces in modified source and object code versions
 * of this program must display Appropriate Legal Notices, as required under
 * Section 5 of the GNU Affero General Public License version 3.
 *
 * In accordance with Section 7(b) of the GNU Affero General Public License version 3,
 * these Appropriate Legal Notices must retain the display of the "Powered by
 * SugarCRM" logo and "Supercharged by SuiteCRM" logo. If the display of the logos is not
 * reasonably feasible for technical reasons, the Appropriate Legal Notices must
 * display the words "Powered by SugarCRM" and "Supercharged by SuiteCRM".
 */

if (!defined('sugarEntry') || !sugarEntry) {
    die('Not A Valid Entry Point');
}

require_once 'include/SugarFields/Fields/Base/SugarFieldBase.php';


class SugarFieldEditor extends SugarFieldBase {

    public function setup($parentFieldArray, $vardef, $displayParams, $tabindex, $twopass = true) {

        parent::setup($parentFieldArray, $vardef, $displayParams, $tabindex, $twopass);
        $editor = "";
        if (isset($vardef['type']) && $vardef['type'] === "editor") {
            if (!isset($displayParams['htmlescape'])) {
                $displayParams['htmlescape'] = false;
            }
            /*
             if ($_REQUEST['action'] === "ComposeView") {
                require_once("include/SugarTinyMCE.php");
                $tiny = new SugarTinyMCE();
                $editor = $tiny->getInstance($vardef['name'], 'email_compose_light');
            }
            $this->ss->assign("tinymce", $editor);
            */

            // now let's replace the description_html field with a full editor
            require_once get_custom_file_if_exists('include/SuiteEditor/SuiteEditorConnector.php');
            global $current_user;
            $templateWidth = 900;
            $editorSettings = SuiteEditorConnector::getSuiteSettings(
                isset($GLOBALS["app"]->controller->bean->description_html) ?
                    html_entity_decode($GLOBALS["app"]->controller->bean->description_html)
                    : '',
                $templateWidth);

            // tell TinyMCE which element to attach to: the one we're currently handling:
            if (isset($vardef['name'])) {
                $editorSettings['textareaId'] = $vardef['name'];
                $editorSettings['elementId'] = $vardef['name'];
                $editorSettings['pedro'] = 'was here';
                //$editorSettings['init_instance_callback'] = 'function(editor) {console.log("!!! Editor: " + editor.id + " is now initialized.")}';
            }
            $this->ss->assign('template_width', $templateWidth);
            $this->ss->assign('editor', SuiteEditorConnector::getHtml($editorSettings));
            $this->ss->assign('width_style', 'style="display:'.($current_user->getEditorType() != 'mozaik' ? 'none' : 'table-row').';"');
        } else {
            $this->ss->assign("editor", $editor);
        }
    }

    /**
     * @param string $parentFieldArrayexi
     * @param array $vardef
     * @param array $displayParams
     * @param integer $tabindex
     * @return string
     */
    public function getDetailViewSmarty($parentFieldArray, $vardef, $displayParams, $tabindex)
    {
        $sugarCleaner = new SugarCleaner();
        $vardef['value'] = $sugarCleaner::cleanHtml($this->getVardefValue($vardef));

        $this->setup($parentFieldArray, $vardef, $displayParams, $tabindex);

        return $this->fetch($this->findTemplate('DetailView'));
    }

    /**
     * @param string $parentFieldArray
     * @param array $vardef
     * @param array $displayParams
     * @param integer $tabindex
     * @return string
     */
    public function getEditViewSmarty($parentFieldArray, $vardef, $displayParams, $tabindex)
    {
        $sugarCleaner = new SugarCleaner();
        $vardef['value'] = $sugarCleaner::cleanHtml($this->getVardefValue($vardef));

        $this->setup($parentFieldArray, $vardef, $displayParams, $tabindex);

        return $this->fetch($this->findTemplate('EditView'));
    }

    /**
     * @param string $parentFieldArray
     * @param array $vardef
     * @param array $displayParams
     * @param integer $tabindex
     * @return string
     */
    public function getSearchViewSmarty($parentFieldArray, $vardef, $displayParams, $tabindex)
    {
        $sugarCleaner = new SugarCleaner();
        $vardef['value'] = $sugarCleaner::cleanHtml($this->getVardefValue($vardef));

        $this->setup($parentFieldArray, $vardef, $displayParams, $tabindex);

        return $this->fetch($this->findTemplate('DetailView'));
    }

    /**
     * @param array $vardef
     * @return mixed
     */
    private function getVardefValue($vardef)
    {
        if (empty($vardef['value'])) {
            if (!empty($vardef['default'])) {
                return $vardef['default'];
            } elseif (!empty($vardef['default_value'])) {
                return $vardef['default_value'];
            }
            LoggerManager::getLogger()->warn('Vardef has no value');
        }

        return utf8_decode($vardef['value']);
    }
}
