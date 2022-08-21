<?php

// This PowerFields file is only shipped with the Pro tier add-ons and extends custom/modules/Home/controller.php

// Called from action_saveHTMLField for Inline edits:
function customSaveField($field, $id, $module, $value)
{
    $bean = BeanFactory::getBean($module, $id); // this is repeated in the function called below. When moving to core, only once is needed

    require_once 'custom/pgr/SuiteReplacer.php';
    if (isset($value)) {
        $value = SuiteReplacer::getInstance()
                   ->addCondition('shouldTriggerReplace', $value)
                   ->addContext($bean, $field)
                   ->replace(SuiteReplacer::undoCleanUp($value));
    }
    return saveField($field, $id, $module, $value);

}
