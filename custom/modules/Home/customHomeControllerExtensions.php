<?php

// This PowerFields file is only shipped with the Pro tier add-ons and extends custom/modules/Home/controller.php

// Called from action_saveHTMLField for Inline edits:
function customSaveField($field, $id, $module, $value)
{

    $bean = BeanFactory::getBean($module, $id); // this is repeated in the function called below. When moving to core, only once is needed

    require_once 'custom/pgr/SuiteReplacer.php';

    if (!empty($bean->field_defs[$field]['auto_edit'])) {
        $source = '{% set currentEdit = "' . $value . '" %}'.
                 $bean->field_defs[$field]['auto_edit'];
    }
    elseif (SuiteReplacer::shouldTriggerReplace($value)) {
        $source = $value;
    }
    if (isset($source)) {
        $value = SuiteReplacer::quickReplace($source,
            SuiteReplacer::buildRichContextFromBean($bean, $field), true);
    }
    return saveField($field, $id, $module, $value);

}
