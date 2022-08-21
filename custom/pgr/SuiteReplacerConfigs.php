<?php

// Twig Tags appear with this sort of brackets: {% someTag %}
$tags = ['if', 'for', 'apply', 'set', 'do'];

// Twig filters are appended to expressions like this: expression|filter
$filters = [
    // default Twig filters:
    'upper', 'escape', 'date', 'date_modify', 'first', 'last', 'join', 'map', 'filter', 'reduce', 'raw', 'trim',
    'inline_css', 'nl2br', 'spaceless', 'number_format', 'format_currency',
    // custom, defined in SuiteReplacerFilters:
    'related', 'photo', 'render', 'topdf', 'attach'];

// Twig functions are called with argument lists like this: someFunction(args)
$functions = [
    // default Twig functions:
    'range', 'source', 'include', 'date',
    // custom functions, defined in SuiteReplacerFunctions:
    'owner', 'bean', 'attach', 'recent', 'cancel'];

// PHP Objects can expose their methods if allowed here. Be mindful of security implications.
$methods = [
    'contact' => ['getTitle', 'getBody'],
    'SugarPHPMailer' => ['AddAddress', 'AddCC', 'AddBCC'],
];

// PHP Objects can expose their properties if allowed here. Used for giving access to fields.
$properties = [
    'aCase' => ['name', 'description', 'status', 'state', 'case_number', 'resolution'],
    'account' => ['name'],
    'AOS_PDF_Templates' => ['name', 'description', 'date_modified'],
    'aos_products' => ['name', 'price', 'photo'],
    'AOS_Quotes' => ['name', 'total_amt', 'date_modified'],
    'contact' => ['id', 'name', 'assigned_user_id', 'emailAddress', 'date_modified', 'sex_c', 'salutation',
                  'email1', 'phone_mobile', 'first_name', 'last_name'],
    'lead' => ['name', 'assigned_user_id', 'emailAddress', 'date_modified', 'sex_c', 'salutation', 'email1'],
    'note' => ['name'],
    'SugarEmailAddress' => ['addresses'],
    'user' => ['name', 'phone_work', 'phone_mobile'],
];

