<?php

if (!defined('sugarEntry') || !sugarEntry) {
    die('Not A Valid Entry Point');
}

require_once 'modules/EmailTemplates/EmailTemplate.php';

class CustomEmailTemplate extends EmailTemplate
{
    public function cleanBean() {
        SugarBean::cleanBean();
        $this->body_html = $GLOBALS['RAW_REQUEST']['body_html'];
    }
}
