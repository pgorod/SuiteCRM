<?php

if (!defined('sugarEntry') || !sugarEntry) {
    die('Not A Valid Entry Point');
}

require_once 'include/SugarFields/Fields/Base/SugarFieldBase.php';

class CustomSugarFieldBase extends SugarFieldBase {

    public function save(&$bean, $params, $field, $properties, $prefix = '') {
        parent::save($bean, $params, $field, $properties, $prefix);

        require_once 'custom/pgr/SuiteReplacer.php';
        if (isset($bean->$field)) {
            $bean->$field = SuiteReplacer::getInstance()
                ->addCondition('shouldTriggerReplace', $bean->$field)
                ->addContext($bean, $field)
                ->addContext([ 'typed', $params ])
                ->replace(SuiteReplacer::undoCleanUp($bean->$field));
        }
    }

}
