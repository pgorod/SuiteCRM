<?php


class SuiteReplacer {
    /**
     * Official expression for variables, extended with underscore
     * @see http://php.net/manual/en/language.variables.basics.php
     */
    const DOLLAR_VAR_PATTERN = '/\$([a-zA-Z_\x7f-\xff]+_[a-zA-Z0-9_\x7f-\xff]*)/';

    const TWIG_VAR_PATTERN = '/\{\{(?!%)\s*((?:(?!\.)[^\s])*)\s*(?<!%)\}\}|\{%\s*(?:\s(?!endfor)(\w+))+\s*%\}/i';

    private $twig;
    private $assignments;
    public $files2Attach = [];

    public function __construct() {

    }

    private function buildSandboxPolicy() {
        $tags = ['if', 'for', 'apply', 'set'];
        $filters = ['upper', 'escape', 'date', 'date_modify', 'first', 'last', 'join', 'map', 'raw',
                    'inline_css', 'nl2br', 'spaceless', 'number_format', 'format_currency'];
        // custom, defined by us:
        $filters[] = 'related';
        $filters[] = 'photo';
        $filters[] = 'render';
        $filters[] = 'topdf';
        $filters[] = 'attach';
        $methods = [
            'contact' => ['getTitle', 'getBody'],
        ];
        $properties = [
            'contact' => ['id', 'name', 'assigned_user_id', 'emailAddress', 'date_modified', 'sex_c', 'salutation',
                'email1', 'phone_mobile', 'first_name', 'last_name'],
            'lead' => ['name', 'assigned_user_id', 'emailAddress', 'date_modified', 'sex_c', 'salutation', 'email1'],
            'aos_products' => ['name', 'price', 'photo'],
            'SugarEmailAddress' => ['addresses'],
            'account' => ['name'],
            'user' => ['name', 'phone_work', 'phone_mobile'],
            'AOS_Quotes' => ['name', 'total_amt', 'date_modified'],
            'AOS_PDF_Templates' => ['name', 'description', 'date_modified'],
        ];
        $functions = ['range', 'source', 'include', 'date'];
        // add custom functions, defined by us:
        $functions[] = 'owner';
        $functions[] = 'bean';
        $functions[] = 'attach';
        $functions[] = 'recent';
        return new \Twig\Sandbox\SecurityPolicy($tags, $filters, $methods, $properties, $functions);
    }

    // Converts old-school dollar variables into twig tags
    public function twigifyOldVars($dollarVarString) {
        $twigified = preg_replace(self::DOLLAR_VAR_PATTERN, '{{ $1 }}', $dollarVarString);
        return $twigified;
    }

    public static function undoCleanUp($overZealouslyCleanedUpString) {
        if (!function_exists('replaceInCode')) {
            function replaceInCode($row) {
                $replace = array(
                    '&quot;' => '"',
                    '&gt;' => '>',
                    '&lt;' => '<',
                    '&#039;' => '\'',
                );
                $text = str_replace(array_keys($replace), array_values($replace), $row[1]);
                return '{{' . $text . '}}';
            }
        }
        if (!function_exists('replaceInCode2')) {
            function replaceInCode2($row) {
                $replace = array(
                    '&quot;' => '"',
                    '&gt;' => '>',
                    '&lt;' => '<',
                    '&#039;' => '\'',
                );
                $text = str_replace(array_keys($replace), array_values($replace), $row[1]);
                return '{%' . $text . '%}';
            }
        }
        // undo replaces of all those codes, but only inside Twig tags:
        $ret = preg_replace_callback("#{%(.*?)%}#s",'replaceInCode2', $overZealouslyCleanedUpString);
        $ret = preg_replace_callback("#{{(.*?)}}#s",'replaceInCode', $ret);
        return $ret;
    }

    public function validateTemplate($twigObject, $template, $name = 'Pedro') {
        //$template = '{{' . $template; // test breaking the template
        try {
            $twigObject->parse($twigObject->tokenize(new \Twig\Source($template, $name)));
        } catch (\Twig\Error\SyntaxError $e) {
            // $template contains one or more syntax errors
            $GLOBALS['log']->warning('Twig template failed syntax validation: ' . $e->getMessage());
        }
    }

    private function configureTwig() {
        //$twig->getExtension(\Twig\Extension\CoreExtension::class)->setTimezone('Europe/Lisbon');
        $sandbox = new \Twig\Extension\SandboxExtension($this->buildSandboxPolicy(), true); // true makes ALL templates go through sandbox
        $this->twig->addExtension($sandbox);

        //$twig = new \Twig\Environment(...);
        $this->twig->addExtension(new Twig\Extra\CssInliner\CssInlinerExtension());
        $this->twig->addExtension(new Twig\Extra\Intl\IntlExtension());

        // add custom Twig filters and functions from class static methods
        $filter = new \Twig\TwigFilter('related', [$this, 'related']);
        $this->twig->addFilter($filter);
        $filter = new \Twig\TwigFilter('photo', [$this, 'photo'], array('is_safe' => array('html')));
        $this->twig->addFilter($filter);
        $filter = new \Twig\TwigFilter('render', [$this, 'render']);
        $this->twig->addFilter($filter);
        $filter = new \Twig\TwigFilter('topdf', [$this, 'topdf']);
        $this->twig->addFilter($filter);
        $filter = new \Twig\TwigFilter('attach', [$this, 'attachFilter']);
        $this->twig->addFilter($filter);

        $function = new \Twig\TwigFunction('owner', [$this, 'owner']);
        $this->twig->addFunction($function);
        $function = new \Twig\TwigFunction('bean', [$this, 'bean']);
        $this->twig->addFunction($function);
        $function = new \Twig\TwigFunction('attach', [$this, 'attachFunction']);
        $this->twig->addFunction($function);
        $function = new \Twig\TwigFunction('recent', [$this, 'recent']);
        $this->twig->addFunction($function);
    }

    // this is a new Twig extension filter our users can use in their templates
    public static function related($focus, $relatedModule) {
        // Security reminder: treat parameters as untrusted user-provided content:
        $relatedModule = strtolower(htmlspecialchars($relatedModule));

        if (!($focus instanceof SugarBean)) {
            throw new Twig\Error\Error('Missing context for related record of type "' . $relatedModule . '"');
        }
        try {
            // let's facilitate this for end-users and be really loose with syntax, let them neglect case, plurals, and prefixes.
            // if we can figure out which module is meant, we'll use it:
            if (in_array(strtolower($relatedModule), ['emailaddress', 'emailaddresses', 'email_address'])) {
                $relatedModule = 'email_addresses';
            }
            $variations = [];
            $variations[] = $relatedModule;
            if (substr($relatedModule, -1) !== 's') {
                $variations[] = $relatedModule . 's';
            }
            $prefixedModules = [
                'am_projecttemplates', 'am_tasktemplates', 'aobh_businesshours', 'aod_index', 'aod_indexevent', 'aok_knowledgebase',
                'aok_knowledge_base_categories', 'aop_case_events', 'aop_case_updates', 'aor_charts', 'aor_conditions', 'aor_fields',
                'aor_reports', 'aor_scheduled_reports', 'aos_contracts', 'aos_invoices', 'aos_line_item_groups', 'aos_pdf_templates',
                'aos_products', 'aos_quotes', 'aos_products_quotes', 'aos_product_categories', 'aow_actions', 'aow_conditions',
                'aow_processed', 'aow_workflow', 'fp_events', 'fp_event_locations', 'jjwg_areas', 'jjwg_maps', 'jjwg_markers'
            ];
            // adds variations for simplified module names:
            foreach ($prefixedModules as $prefixedModule) {
                if ((strpos($prefixedModule, $relatedModule) !== false) &&
                    (!in_array($prefixedModule, $variations))) {
                    $variations[] = $prefixedModule;
                }
            }
            // adds variations for custom relationships with names like leads_aos_quotes_1:
            foreach ($focus->field_name_map as $key=>$field) {
                if ((strpos($focus->table_name . '_' . $key, $relatedModule) !== false) &&
                    (!in_array($key, $variations))) {
                    $variations[] = $key;
                }
            }
            // tries each variation until one works:
            foreach ($variations as $varkey=>$variation) {
                $linkedBeans = $focus->get_linked_beans($variation);
                if ($varkey >= 1) {
                    // traverse relationship tables in one simplified step (e.g. quotes > products_quotes > products)
                    foreach ($linkedBeans as $key=>$linked) {
                        $linkedLinkedBeans = $linked->get_linked_beans($variations[$varkey - 1]);
                        if (count($linkedLinkedBeans) === 1) {
                            $linkedBeans[$key] = $linkedLinkedBeans[0];
                        }
                    }
                }
                if ($relatedModule === 'email_addresses') {
                    foreach ($linkedBeans as $key=>$linked) {
                        $linkedBeans[$key] = $linkedBeans[$key]->email_address;
                    }
                }
                if (count($linkedBeans) > 0) {
                    break;
                }
            }
        }
        catch (Exception $e) {
            throw new Twig\Error\Error('Error retrieving related records of type "' . $relatedModule . '" from context.');
        }
        if (!count($linkedBeans)) {
            throw new Twig\Error\Error('No related records of type "' . $relatedModule . '" found.');
        }
        return $linkedBeans;
    }

    protected function copyToPublicDir($sourceId, $subDir = '', $destName = '')
    {
        $subDir = 'public/' . rtrim($subDir, '/');
        $destName = ($destName === '' ? $sourceId : $destName);
        $toFile = "$subDir/$destName";

        if (file_exists($toFile)) {
            return $toFile;
        }
        $fromFile = 'upload://' . $sourceId;
        if (!file_exists(UploadStream::path($fromFile))) {
            $fromFile = $fromFile . '_c';
            $toFile = $toFile . '_c';
            if (!file_exists($fromFile)) {
                throw new Exception('Can\'t find source file to copy.');
            }
        }
        if (!file_exists('public')) {
            sugar_mkdir('public', 02550); // mode will be ignored on Windows...
        }
        if (!file_exists("$subDir")) {
            sugar_mkdir("$subDir", 02550);
        }
        $fdata = file_get_contents($fromFile);
        if (!file_put_contents($toFile, $fdata)) {
            throw new Exception('Can\'t write to file while copying.');
        }
        return $toFile;
    }

    // this is a new Twig extension filter our users can use in their templates
    // Gets a photo field (either core or custom field) from bean and inserts it into template
    public function photo($focus) {
        global $sugar_config;
        $width = 80;

        if (!($focus instanceof SugarBean)) {
            throw new Twig\Error\Error('Missing bean context when getting photo.');
        }
        try {
            $savedFile = $this->copyToPublicDir($focus->id . '_photo', 'emailImages');

            $site = $sugar_config['site_url'];
            $site = rtrim($site, '/') . '/';
$site = 'http://voipalameda.no-ip.org:201/';

            //$ret = $site . 'index.php?entryPoint=emailImage&id=' . $focus->id . '_photo';
            $ret = '<img src="' . $site . $savedFile . '" alt="{ image }" width="' . $width . '">';

            return '' . $ret . '';
// <img src="http://localhost:8080/index.php?entryPoint=emailImage&id=e5014bcf-8569-cc3e-eefe-5be1d240e904_photo&type=Leads" alt="missing image" width="150">
        }
        catch (Exception $e) {
            throw new Twig\Error\Error('Error when getting photo of record of type "' . $focus->module_name . '"');
        }
    }

    // this is a new Twig extension function our users can use in their templates
    public static function owner($email) {
        // Security reminder: treat parameters as untrusted user-provided content:
        if (is_array($email)) {
            // loose syntax - this is a facilitator for to, cc and bcc arrays,
            // so you can write owner(cc|first) instead of owner((cc|first).email)
            $email = isset($email['email']) ? $email['email'] : '';
        }
        if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
            throw new Twig\Error\Error('Error: invalid email address used in "owner" function.');
        }
        $email = $GLOBALS['db']->quoted($email);

        // get the best bean that matches that email address, using weights by module type, and preferring primary addresses:
        $sql = <<<SQL
            SELECT ea.email_address, eabr.bean_module AS module, eabr.bean_id AS id, eabr.primary_address, 
                CASE
                  WHEN eabr.bean_module = 'Accounts'  THEN 5
                  WHEN eabr.bean_module = 'Contacts'  THEN 4
                  WHEN eabr.bean_module = 'Leads'     THEN 3
                  WHEN eabr.bean_module = 'Prospects' THEN 2
                  ELSE 1
                END + 100 * eabr.primary_address as points
            FROM `email_addr_bean_rel` eabr
            LEFT JOIN email_addresses ea ON eabr.email_address_id = ea.id
            WHERE ea.email_address = $email
            ORDER BY points DESC
            LIMIT 1
SQL;

        try {
            $results = $GLOBALS['db']->query($sql, true);
            $row = $GLOBALS['db']->fetchByAssoc($results); // LIMIT 1 ensures always a single result max
            $bean = BeanFactory::getBean($row['module'], $row['id']);
        }
        catch (Exception $e) {
            throw new Twig\Error\Error('Error retrieving owner of email "' . $email . '.');
        }
        if (!is_object($bean) || $bean->id === '') {
            throw new Twig\Error\Error('Error retrieving owner of email "' . $email . '.');
        }
        return $bean;
    }

    // this is a new Twig extension function our users can use in their templates
    public static function bean($module, $id) {
        // Security reminder: treat parameters as untrusted user-provided content:
        //$module = $GLOBALS['db']->quoted($module);
        //$id = $GLOBALS['db']->quoted($id);

        // handle case where we were given a full bean instead of its id:
        if (property_exists($id, 'id')) {
            $id = $id->id;
        }
        try {
            $bean = BeanFactory::getBean(ucfirst($module), $id, array('encode' => false));
        }
        catch (Exception $e) {
            throw new Twig\Error\Error('Error retrieving bean by id "' . $id . '.');
        }
        if (!is_object($bean) || $bean->id !== $id) {
            throw new Twig\Error\Error('Error retrieving bean by id "' . $id . '.');
        }
        return $bean;
    }

    // this is a new Twig extension function our users can use in their templates
    public function attachFunction($fileName, $displayName = '') {
        // Security reminder: treat parameters as untrusted user-provided content:
        $fileName = str_replace('..', '.', $fileName);

        if (!file_exists($fileName)) {
            throw new Twig\Error\Error('attachFunction: Error attaching file to email "' . $fileName . '.');
        }
        $url = $fileName;

        // avoid repeats which are common due to evaluating both description and description_html:
        if (!in_array($fileName, array_column($this->files2Attach, 0))) {
            $this->files2Attach[] = [$fileName, $displayName, $url];
        }
    }

    // this is a new Twig extension filter our users can use in their templates
    public function attachFilter($fileName, $displayName = '') {
        // Security reminder: treat parameters as untrusted user-provided content:
        $fileName = str_replace('..', '.', $fileName);

        if (!file_exists($fileName)) {
            throw new Twig\Error\Error('attachFilter: Error attaching file to email "' . $fileName . '.');
        }
        $url = $fileName;

        // avoid repeats which are common due to evaluating both description and description_html:
        if (!in_array($fileName, array_column($this->files2Attach, 0))) {
            $this->files2Attach[] = [$fileName, $displayName, $url];
        }
    }

    // this is a new Twig extension filter our users can use in their templates
    // transforms a Twig template received as a string into its rendered HTML
    public function render($template) {
        // Security reminder: treat parameters as untrusted user-provided content:

        $twigTemplate = $this->twig->createTemplate($template);
        //$this->validateTemplate($this->twig, $template);
        $output = $this->twig->render($twigTemplate, $this->assignments);
        return $output;
    }

    // this is a new Twig extension filter our users can use in their templates
    public function topdf($html, $fileName = '')
    {
        global $sugar_config;
        // Security reminder: treat parameters as untrusted user-provided content:
        // TODO: prevent risky extensions, prevent ".." and other directory tricks
        try {
            require_once('modules/AOS_PDF_Templates/PDF_Lib/mpdf.php');

            $pdf_single = new mPDF('en'); //, $format, '', '', $template->margin_left, $template->margin_right,
                //$template->margin_top, $template->margin_bottom, $template->margin_header, $template->margin_footer);
            $fp = fopen($sugar_config['upload_dir'] . 'nfile.pdf', 'wb');
            fclose($fp);

            $pdf_single->SetAutoFont();
            //$pdf_single->SetHTMLHeader($header);
            //$pdf_single->SetHTMLFooter($footer);
            $pdf_single->WriteHTML($html);
            if ($fileName === '') {
                $fileName = $sugar_config['upload_dir'] . 'topdf.pdf';
            }
            $pdf_single->Output($fileName, 'F');
        } catch (mPDF_exception $e) {
            echo $e;
        }
        return $fileName;
    }

    // this is a new Twig extension function our users can use in their templates
    public function recent($module = '') {
        // Security reminder: treat parameters as untrusted user-provided content:
        $module = htmlspecialchars($module);

        try {
            //global $current_user;
            $filteredRecents = $_SESSION['breadCrumbs']->getBreadCrumbList($module);
            $index = 0;
            foreach ($filteredRecents as $item) {
                $objFromID = BeanFactory::getBean($item['module_name'], $item['item_id']);
                $objFromID->item_summary = $item['item_summary'];
                $ret[$index++] = $objFromID;
            }
        }
        catch (Exception $e) {
            throw new Twig\Error\Error('Error getting recents for module "' . $module . '.');
        }
        return $ret;
    }

    // Make sure to keep this as a fast check - it will get called once for every field in every bean save
    // Avoid regexps if possible...
    public static function shouldTriggerReplace($value1, $value2 = null, $value3 = null) {
        return (is_string($value1) ? substr($value1, 0, 2) === '{{' : false);
    }

    public static function buildRichContextFromBean($bean, $field = null, $module = null) {
        $context = Array();

        $context[] = [ $bean->object_name ,  $bean ];
        if ($bean instanceof Person) {
            $context[] = [ 'person' ,  $bean ];
        }
        $context[] = [ 'field' => $field];
        $context[] = [ 'module', $bean->object_name ];
        $context[] = Array($bean); // directly-accessible individual fields
        if (isset($bean->fetched_row) and is_array($bean->fetched_row)) {
            $context[] = ['was', $bean->fetched_row[$field]];  // quick shortcut into the current field, as it was in the DB
            $context[] = ['were', $bean->fetched_row];         // full access to the bean as it was in the DB
            // see also currentEdit variable which is set both in inline and regular Edits,
            // containing what was typed in the field, to allow for "find-and-replace"-type templates
        } else {
            if (!isset($bean->assigned_user_id)) {
                $context[] = [ 'assigned_user_id', $_POST['assigned_user_id']];
            }
            if (!isset($bean->assigned_user_name)) {
                $context[] = [ 'assigned_user_name', $_POST['assigned_user_name']];
            }
        }

        return $context;
    }

    private function setup($main) {
        $files = [
            'custom_css'    => getcwd() . '/custom/pgr/PowerReplacer/custom_styles.css',
            'custom_header' => getcwd() . '/custom/pgr/PowerReplacer/custom_header.html',
            'custom_footer' => getcwd() . '/custom/pgr/PowerReplacer/custom_footer.html'
        ];

        $loaderArray = [];
        foreach ($files as $key => $file) {
            if (file_exists($file)) {
                $loaderArray[$key] = file_get_contents($file);
            }
        }
        $loaderArray['main'] = $main;

        $arrayLoader = new \Twig\Loader\ArrayLoader($loaderArray);
        $fileLoader = new \Twig\Loader\FilesystemLoader(getcwd() . '/custom/pgr/PowerReplacer');
        $chainLoader = new \Twig\Loader\ChainLoader( [$arrayLoader, $fileLoader] );

        $this->twig = new \Twig\Environment($chainLoader);
        $this->configureTwig();

        $this->assignments['mod_strings'] = $GLOBALS['mod_strings'];
        $this->assignments['sugar_config'] = $GLOBALS['sugar_config'];
        $this->assignments['app_strings'] = $GLOBALS['app_strings'];
    }

    public function replace(string $original, array $context) {

        $this->setup($original);

        $original = $this->twigifyOldVars($original);
        $this->assignments = [];

        /*
        $varsFound = [];
        preg_match_all(self::TWIG_VAR_PATTERN, $original, $varsFound); // , PREG_OFFSET_CAPTURE);
        $varsFound = $varsFound[1]; // keep only what we need, the bare variable names
        foreach ($varsFound as $varToAssign) {
            if ($varToAssign === '') continue;

            $modulePrefix = $context[0][1]->object_name;
            if ($modulePrefix === 'User') {
                $modulePrefix = 'contact_user_';
            } else {
                $modulePrefix = strtolower($modulePrefix) . '_';
            }

            $fieldName = strtok(str_replace($modulePrefix, '', $varToAssign), '|');
            if (property_exists($context[0][1], $fieldName)) {
                $assignments[strtok($varToAssign, '|')] = $context[0][1]->$fieldName;
            }
        }
        */
        // TODO check if buildRichContextFromBean function doesn't dispense this
        foreach ($context as $item) {
            if (isset($item[1])) {
                $this->assignments[$item[0]] = $item[1];
            } elseif (isset($item[0]) && (is_array($item[0]) || is_object($item[0]))) {
                foreach ($item[0] as $key=>$value) {
                    $this->assignments[$key] = $value; // add each member separately into the $assignments array
                }
            }
        }

        $this->validateTemplate($this->twig, $original);
        $output = $this->twig->render('main', $this->assignments);

        return $output;
    }

    // Static version for convenient one-line replaces:
    public static function quickReplace($value, array $context, $undoCleanUp = false) {
        $replacer = new SuiteReplacer();
        if ($undoCleanUp === true) {
            $value = $replacer->undoCleanUp($value);
        }
        return $replacer->replace($value, $context);
    }
}

