<?php

if (!defined('sugarEntry') || !sugarEntry) {
    die('Not A Valid Entry Point');
}

require_once 'include/SugarFields/Fields/Text/SugarFieldText.php';

class CustomSugarFieldText extends SugarFieldText {

    public function save(&$bean, $params, $field, $properties, $prefix = '') {
        parent::save($bean, $params, $field, $properties, $prefix);

        require_once 'custom/pgr/SuiteReplacer.php';
        $autoAction = $bean->fetched_row ? 'auto_edit' : 'auto_new';
        if (isset($bean->field_defs[$field][$autoAction])) {
            $source = '{% set currentEdit = "' . $bean->$field . '" %}'.
                $bean->field_defs[$field][$autoAction];
        }
        elseif (SuiteReplacer::shouldTriggerReplace($bean->$field)) {
            $source = $bean->$field;
        }
        if (isset($source)) {
            $bean->$field = SuiteReplacer::quickReplace($source,
                SuiteReplacer:: buildRichContextFromBean($bean, $field), true);
        }
    }
}





