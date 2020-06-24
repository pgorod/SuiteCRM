<?php

if (!isset($hook_array) || !is_array($hook_array)) {
    $hook_array = array();
}
if (!isset($hook_array['after_ui_frame']) || !is_array($hook_array['after_ui_frame'])) {
    $hook_array['after_ui_frame'] = array();
}
$hook_array['after_ui_frame'][] = array(99, 'Add pgr custom js', 'custom/Extension/application/AddPgrJs.php','PgrAfterUIHook', 'add_pgr_js');
