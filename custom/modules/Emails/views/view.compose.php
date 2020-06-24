<?php

if (!defined('sugarEntry') || !sugarEntry) {
    die('Not A Valid Entry Point');
}

require_once 'modules/Emails/views/view.compose.php';

class CustomEmailsViewCompose extends EmailsViewCompose
{

    public function displayNOT() // ABANDONED when I turned to solution from SugarFields
    {
        parent::display();

        // now let's replace the description_html field with a full editor
        require_once 'include/SuiteEditor/SuiteEditorConnector.php';
        global $current_user;
        $templateWidth = 900;
        $this->ev->th->ss->assign('template_width', $templateWidth);
        $this->ev->th->ss->assign('BODY_EDITOR', SuiteEditorConnector::getHtml(
            SuiteEditorConnector::getSuiteSettings(
                isset($this->ev->focus->description_html) ? html_entity_decode($this->ev->focus->description_html) : '', $templateWidth)));
        $this->ev->th->ss->assign('width_style', 'style="display:'.($current_user->getEditorType() != 'mozaik' ? 'none' : 'table-row').';"');

    }

    /**
     * Get EditView object
     * @return EditView
     */
    public function getEditView()
    {
        require_once 'custom/modules/Emails/include/ComposeView/CustomComposeView.php';
        return new ComposeView();
    }

}
