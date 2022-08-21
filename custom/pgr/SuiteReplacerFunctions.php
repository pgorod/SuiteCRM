<?php


class SuiteReplacerFunctions
{
    /**
     * @var SuiteReplacer
     */
    private static $suiteReplacer;

    private function __construct(SuiteReplacer $suiteReplacer) {
        $this->suiteReplacer = $suiteReplacer;
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

    // CAN BE DELETED, THE FILTER VERSION IS ENOUGH
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

    // this is a new Twig extension function our users can use in their templates
    public function cancel($msg = '', $condition = true) {
        // Security reminder: treat parameters as untrusted user-provided content: although for logging we will opt for no sanitizing here.
        if ($condition) {
            throw new Twig\Error\Error("Process cancelled with msg: $msg");
        }
    }


}