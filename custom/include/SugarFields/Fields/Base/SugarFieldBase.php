<?php

if (!defined('sugarEntry') || !sugarEntry) {
    die('Not A Valid Entry Point');
}

require_once 'include/SugarFields/Fields/Base/SugarFieldBase.php';

class CustomSugarFieldBase extends SugarFieldBase {

    public function save(&$bean, $params, $field, $properties, $prefix = '') {
        parent::save($bean, $params, $field, $properties, $prefix);

        require_once 'custom/pgr/SuiteReplacer.php';
        if (SuiteReplacer::shouldTriggerReplace($bean->$field)) {
            $bean->$field = SuiteReplacer::quickReplace($bean->$field,
                SuiteReplacer:: buildRichContextFromBean($bean, $field), true);
        }
    }
}
