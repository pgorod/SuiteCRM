<?php

if (!defined('sugarEntry') || !sugarEntry) {
    die('Not A Valid Entry Point');
}

require_once 'modules/Home/controller.php';

// Pro tier add-ons require more code:
file_exists('custom/modules/Home/customHomeControllerExtensions.php') AND require_once 'custom/modules/Home/customHomeControllerExtensions.php';

class CustomHomeController extends HomeController
{

    public function action_saveHTMLField()
    {
        if ($_REQUEST['field'] && $_REQUEST['id'] && $_REQUEST['current_module']) {
            if (function_exists('customSaveField')) {
                echo customSaveField($_REQUEST['field'], $_REQUEST['id'], $_REQUEST['current_module'], $_REQUEST['value'],
                    $_REQUEST['view']);
            } else {
                echo saveField($_REQUEST['field'], $_REQUEST['id'], $_REQUEST['current_module'], $_REQUEST['value'],
                    $_REQUEST['view']);
            }
        }
    }

    public function action_RunWorkflowsFromViews() {
        $countRanOk = 0;
        $report = [];

        $module = $_REQUEST['current_module'];
        // Custom module Workflows called from subpanels are hard to get the actual module name, since their id uses the relationship name:
        $altModule = isset($GLOBALS['dictionary'][strtolower($module)]['relationships'][strtolower($module)]['lhs_module']) ?
            $GLOBALS['dictionary'][strtolower($module)]['relationships'][strtolower($module)]['lhs_module'] :
            $module;
        $query = "SELECT id FROM aow_workflow WHERE ((aow_workflow.flow_module = '" . $module . "') OR (aow_workflow.flow_module = '" . $altModule .
            "')) AND aow_workflow.status = 'Active' AND aow_workflow.run_when = 'From_View_Action' AND aow_workflow.deleted = 0 ";

        $result = $GLOBALS['db']->query($query, false);
        $flow = BeanFactory::newBean('AOW_WorkFlow');

        $uids = explode(',', $_REQUEST['uids']);
        while (($row = $GLOBALS['db']->fetchByAssoc($result)) != null) {
            foreach ($uids as $uid) {
                $bean = BeanFactory::getBean($module, $uid);
                if ($bean !== false) { // catches cases where our js failed to provide us with a valid module name
                    $flow->retrieve($row['id']);
                    if ($flow->check_valid_bean($bean)) {
                        if ($flow->run_actions($bean)) {
                            $countRanOk++;
                        }
                    }
                }
            }
        }
        $report[0] = $result->num_rows; // 'Applicable Workflows'
        $report[1] = count($uids); // 'Records examined'
        $report[2] = $countRanOk; // 'Records executed'
        $this->view = 'ajax';
        echo json_encode($report);
    }

    // for inline editing performance improvement:
    public function action_getInlineEditFieldInfo()
    {
        if ($_REQUEST['field'] && $_REQUEST['id'] && $_REQUEST['current_module']) {
            $validation = getValidationRules($_REQUEST['current_module'], $_REQUEST['field'], $_REQUEST['id']);
            $html = getEditFieldHTML($_REQUEST['current_module'], $_REQUEST['field'], $_REQUEST['field'], 'EditView', $_REQUEST['id']);
            if ($_REQUEST['type'] === 'relate' || $_REQUEST['type'] === 'parent') {
                $relateJS = getRelateFieldJS($_REQUEST['current_module'], $_REQUEST['field']);
            }
            echo json_encode(
                [ 'validationRules' => $validation,
                  'editFieldHTML'   => $html,
                  'relateFieldJS' => $relateJS ]
            );
        }
    }

    // when moving to Core, remove these functions:
    // public function action_getEditFieldHTML()
    // public function action_getValidationRules()
    // public function action_getRelateFieldJS()
}
