<?php

use Twig\TwigFilter;
use Twig\TwigFunction;

require_once 'SuiteReplacerFilters.php';
require_once 'SuiteReplacerFunctions.php';

class SuiteReplacer {
    const DOLLAR_VAR_PATTERN = '/\$([a-zA-Z_\x7f-\xff]+_[a-zA-Z0-9_\x7f-\xff]*)/';
    //const TWIG_VAR_PATTERN = '/\{\{(?!%)\s*((?:(?!\.)[^\s])*)\s*(?<!%)\}\}|\{%\s*(?:\s(?!endfor)(\w+))+\s*%\}/i';

    public $twig;
    public $assignments;
    public $files2Attach = [];
    public $pickedObjects = [];

    private static $_instance = null;
    private static $auto_new  = null;
    private static $auto_edit = null;
    protected static $context = [];
    protected static $conditions = [];

    private function __construct() {
        $this->addBasicLoaders();
        $this->addTwigExtensions();
    }

    public static function getInstance() {
        if (self::$_instance === null) {
            self::$_instance = new self;
        }
        return self::$_instance;
    }

    private function buildSandboxPolicy() {
        [$tags, $filters, $methods, $properties, $functions] = [null, null, null, null, null];
        require_once 'SuiteReplacerConfigs.php';
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

    public function validateTemplate($twigObject, $template, $name = 'template2Validate') {
        //$template = '{{' . $template; // test breaking the template
        try {
            $twigObject->parse($twigObject->tokenize(new \Twig\Source($template, $name)));
        } catch (\Twig\Error\SyntaxError $e) {
            // $template contains one or more syntax errors
            $GLOBALS['log']->warning('Twig template failed syntax validation: ' . $e->getMessage());
        }
    }

    private function addTwigExtensions() {
        //$twig->getExtension(\Twig\Extension\CoreExtension::class)->setTimezone('Europe/Lisbon');
        $sandbox = new \Twig\Extension\SandboxExtension($this->buildSandboxPolicy(), true); // true makes ALL templates go through sandbox
        $this->twig->addExtension($sandbox);

        $this->twig->addExtension(new Twig\Extra\CssInliner\CssInlinerExtension());
        $this->twig->addExtension(new Twig\Extra\Intl\IntlExtension());

        // TODO: pgr: change this into a reflection-based generic loop going through all methods and adding them
        // without need for named references. Use separate array for function options (is_safe etc)

        // add custom Twig filters and functions from class static methods
        $this->twig->addFilter(new TwigFilter('related', 'SuiteReplacerFilters::related'));
        $this->twig->addFilter(new TwigFilter('photo',   'SuiteReplacerFilters::photo',
                                                           array('is_safe' => array('html'))));
        $this->twig->addFilter(new TwigFilter('render',  'SuiteReplacerFilters::render'));
        $this->twig->addFilter(new TwigFilter('topdf',   'SuiteReplacerFilters::topdf'));
        $this->twig->addFilter(new TwigFilter('attach',  'SuiteReplacerFilters::attachFilter'));

        $this->twig->addFunction(new TwigFunction('owner',  'SuiteReplacerFunctions::owner'));
        $this->twig->addFunction(new TwigFunction('bean',   'SuiteReplacerFunctions::bean'));
        $this->twig->addFunction(new TwigFunction('attach', 'SuiteReplacerFunctions::attachFilter'));
        $this->twig->addFunction(new TwigFunction('recent', 'SuiteReplacerFunctions::recent'));
        $this->twig->addFunction(new TwigFunction('cancel', 'SuiteReplacerFunctions::cancel'));
    }

    // Make sure to keep this as a fast check - it will get called once for every field in every bean save
    // Avoid regexps if possible...
    public static function shouldTriggerReplace($value1, $value2 = null, $value3 = null) {
        return (is_string($value1) ? substr($value1, 0, 2) === '{{' : false);
    }

    public function addCondition($condition, $argument = null) {
        self::$conditions[] = [ $condition, $argument ];

        return $this;
    }

    public function addContext($input, $focusField = null) {
        // Ways in which this can be called:
        // addContext($bean)
        // addContext(['customName', $bean])
        // addContext(['moduleName', $beanId])
        // addContext([$bean1, $bean2, $bean3])
        // addContext(['customName1', $bean1], ['customName2', $bean2], ['customName3', $bean3], )
        // Note that there is special treatment to the first added bean, should be "main" record being handled

        $name = null;
        if (is_array($input) && (count($input)==2)) {
            $name  = $input[0];
            $input = $input[1];
        }
        if (is_string($input)) {
            global $beanList;
            $uName = ucfirst($name);
            if (!empty($beanList[$uName]) || in_array($uName, $beanList)) {
                $input = BeanFactory::getBean($uName . 's', $input) ?: BeanFactory::getBean($uName, $input);
            }
        }
        if ($input instanceof SugarBean) {
            $name = $name ?? lcfirst($input->object_name);
            return $this->addBeanContext($input, $name, $focusField);
        } elseif (is_string($name)) {
            self::$context[] = [ $name, $input ];
            return $this;
        }
        throw new Twig\Error\Error('addContext can\'t understand provided argument. Use a bean or an array like [name, bean] or [name, object].');
    }

    public function addBeanContext($bean, $name, $focusField = null) {

        $firstContext = count(self::$context) === 0;

        self::$context[] = [ $name ,  $bean ];
        if ($bean instanceof Person) {
            self::$context[] = ['person', $bean];
        }

        if ($firstContext ) {
            if (isset($focusField)) {
                self::$context[] = ['field', $focusField];
            }
            self::$context[] = ['module', lcfirst($bean->object_name)];

            if (isset($bean->fetched_row) && is_array($bean->fetched_row)) {
                // EDITing previously existing records:

                // see also currentEdit variable which is set both in inline and regular Edits,
                // containing what was typed in the field, to allow for "find-and-replace"-type templates
                if (isset($focusField)) { // from workflows, for example, we won't have any $focusField
                    self::$auto_edit = null;
                    if (isset($bean->field_defs[$focusField]) && isset($bean->field_defs[$focusField]['auto_edit'])) {
                        self::$auto_edit = $bean->field_defs[$focusField]['auto_edit'];
                    }
                    self::$context[] = [ 'was', $bean->fetched_row[$focusField] ?? null ];  // quick shortcut into the current field, as it was in the DB
                }
                self::$context[] = [ 'fetched', $bean->fetched_row ];              // full access to the bean as it was in the DB
            } else {
                // NEW record being created:
                if (!isset($bean->assigned_user_id) && isset($_POST['assigned_user_id'])) {
                    self::$context[] = ['assigned_user_id', $_POST['assigned_user_id']];
                }
                if (!isset($bean->assigned_user_name) && isset($_POST['assigned_user_name'])) {
                    self::$context[] = ['assigned_user_name', $_POST['assigned_user_name']];
                }
                if (isset($bean->field_defs[$focusField]) && isset($bean->field_defs[$focusField]['auto_new'])) {
                    self::$auto_new = $bean->field_defs[$focusField]['auto_new'];
                }
            }
        }
        return $this;
    }

    private function addBasicLoaders() {
        $loaderArray = [];
//        $loaderArray['main'] = $main;

        $files = [
            'custom_css'    => getcwd() . '/custom/pgr/PowerReplacer/custom_styles.css',
            'custom_header' => getcwd() . '/custom/pgr/PowerReplacer/custom_header.html',
            'custom_footer' => getcwd() . '/custom/pgr/PowerReplacer/custom_footer.html'
        ];

        foreach ($files as $key => $file) {
            if (file_exists($file)) {
                $loaderArray[$key] = file_get_contents($file);
            }
        }
        $arrayLoader = new \Twig\Loader\ArrayLoader($loaderArray);

        // base-path for user's "source" and "include" commands inside Twig templates:
        $fileLoader  = new \Twig\Loader\FilesystemLoader(getcwd() . '/custom/pgr/PowerReplacer');

        $chainLoader = new \Twig\Loader\ChainLoader( [$arrayLoader, $fileLoader] );
        $this->twig = new \Twig\Environment($chainLoader);
        //$this->addTwigExtensions();

    }

    protected function evaluateConditions() {
        // Accepts array of conditions, each one can be specified as
        // - a direct boolean (so you can have a condition be calculated directly on the argument list)
        // - a callable pair of function name and argument [ 'shouldTriggerReplace', $bean->$field ]
        // - that function can be global scope, or "self", or "this"
        // All conditions must be true to allow "replace" to happen
        $result = true;
        foreach (self::$conditions as $condition) {
            if (is_bool($condition[0])) {
                $result = $result && $condition[0];
            }
            elseif (is_callable($condition[0])) {
                $result = $result && call_user_func($condition[0], $condition[1]);
            }
            elseif (is_callable(['this', $condition[0]])) { // User calling class methods
                $result = $result && call_user_func(['this', $condition[0]], $condition[1]);
            }
            elseif (is_callable(['self', $condition[0]])) { // SuiteReplacer methods
                $result = $result && call_user_func(['self', $condition[0]], $condition[1]);
            }
            // ignores other condition types
        }
        return $result;
    }

    public function replace(string $original, $holdContext = false) {
        $output = $original;
        if (!empty(self::$auto_new) || !empty(self::$auto_edit) || $this->evaluateConditions()) {
            $this->assignments = [];
            //$original = ($undoCleanUp === true) ? self::undoCleanUp($original) : $original;
            $original = $this->twigifyOldVars($original);

            if (!empty(self::$auto_new) || !empty(self::$auto_edit)) {
                $this->assignments['typedHere'] = $original;
                $original = self::$auto_new ?? self::$auto_edit;
            }
            $loaders = $this->twig->getLoader()->getLoaders(); // get to the ArrayLoader inside the ChainLoader
            $loaders[0]->setTemplate('main', $original);
            //if ($this->twig->getLoader()->exists('main')) {
            //    $this->twig->getLoader()[0]->setTemplate('main', $original);
            //}
            //else {
            //    $this->twig->getLoader()->addLoader(new \Twig\Loader\ArrayLoader(['main' => "$original"]));
            //}

            $this->assignments['mod_strings'] = $GLOBALS['mod_strings'];
            $this->assignments['app_strings'] = $GLOBALS['app_strings'];
            //$this->assignments['sugar_config'] = $GLOBALS['sugar_config']; // SECURITY don't add this indiscriminately: it includes dbconfig with DB admin user and password...

            foreach (self::$context as $item) {
                // Copy context into assignments array, dropping unset items:
                if (isset($item[1])) {
                    $this->assignments[$item[0]] = $item[1];
                    if ($item[1] instanceof SugarBean) { // (is_array($item[0]) || is_object($item[0]))) {
                        // Copy first object into assignments array, adding each member separately for direct access
                        foreach ($item[1] as $key => $value) {
                            $this->assignments[$key] = $value;
                        }
                    }
                }
            }

            $this->validateTemplate($this->twig, $original);
            $output = $this->twig->render('main', $this->assignments);
        }
        if (!$holdContext) {
            self::clearStatics();
        }
        return $output;
    }

    public static function clearStatics() {
        self::$auto_new   = null;
        self::$auto_edit  = null;
        self::$conditions = [];
        self::$context    = [];
    }

}

