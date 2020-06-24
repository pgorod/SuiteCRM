<?php


if (!defined('sugarEntry') || !sugarEntry) {
    die('Not a valid entry point');
}

class PgrAfterUIHook
{

    function add_pgr_js()
    {
        // Exclusions:
        if (isset($GLOBALS["app"]) && $GLOBALS["app"]->controller->view === 'ajax') {
            return;
        }
        if (isset($_REQUEST['module'])) {
            $excludedModules = ['Home', 'Favorites', 'Administration', 'Reminders', 'ModuleBuilder', 'EmailMan'];
            if (in_array($_REQUEST['module'], $excludedModules)) {
                return;
            }
        }
        if (isset($_REQUEST['action'])) {
            $excludedActions = ['getFromFields', 'getDefaultSignatures', 'getEmailSignatures', 'send'];
            if (in_array($_REQUEST['action'], $excludedActions)) {
                return;
            }
        }

        // Add code in two blocks: one dynamic and inserted inline, the other static and inserted as src reference:
        $recordId = isset($_REQUEST['record']) ? $_REQUEST['record'] : '';
        $recordModule = isset($_REQUEST['module']) ? $_REQUEST['module'] : '';
        $inlineDynamicJS =
            <<<INLINE_DYNAMIC_JS
            function InitRuntimeArgs() { 
                var RuntimeArgs = new Array();
                RuntimeArgs['module'] = '$recordModule';
                RuntimeArgs['record'] = '$recordId';
                RuntimeArgs['controllerAction'] = '{$GLOBALS['app']->controller->action}';
                return RuntimeArgs;
            }
INLINE_DYNAMIC_JS;
        echo "<script type='text/javascript'>$inlineDynamicJS</script>";
        echo "<script type='text/javascript' src='custom/pgr/js/RunWorkflowsFromViews.js'></script>";
    }
}
