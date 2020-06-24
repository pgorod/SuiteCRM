<?php

// Generic file to allow using custom includes in viewdefs files.
// Suppose there's a file in core in modules/Emails/metadata/composeviewdefs.php which defines some JS includes.
// You place this file where we are now, unchanged, inside custom/modules/Emails/metadata/composeviewdefs.php
// Now place your customized include files in the same place of the originals, but under 'custom/'
// These custom versions, if present, will be included by view.

// The present file will be included from include/EditView/EditView2.php, Setup method, or from a sub-class of it.

// include the "parent" viewdefs file (same file as this one, but removing 'custom/' from the start:
$nonCustom = (substr($metadataFile, 0, 7) === 'custom/') ? substr($metadataFile, 7) : '';
if (file_exists($nonCustom)) {
    require_once $nonCustom;

    // iterates the includes and replaces the reference with custom file if they exist:
    foreach ($viewdefs[$this->module][$this->view]['templateMeta']['includes'] as $key => $inc) {
        if (file_exists('custom/' . $inc['file'])) {
            $viewdefs[$this->module][$this->view]['templateMeta']['includes'][$key] = ['file' => 'custom/' . $inc['file']];
        }
    }
}
