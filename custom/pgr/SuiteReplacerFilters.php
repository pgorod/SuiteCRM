<?php

use SuiteCRM\PDF\PDFWrapper;

class SuiteReplacerFilters
{
    /**
     * @var SuiteReplacer
     */
    private static $suiteReplacer;

    private function __construct(SuiteReplacer $suiteReplacer) {
         $this->suiteReplacer = $suiteReplacer;
    }

    public static function related($focus, $relatedModule)
    {
        // Security reminder: treat parameters as untrusted user-provided content:
        $relatedModule = strtolower(htmlspecialchars($relatedModule));
        // decided against allowing a whereClause for get_linked_beans, because quoting breaks the query, and not quoting is crazy-insecure...
        // $whereClause = DBManagerFactory::getInstance()->quote($changed) ?: '';

        if (!($focus instanceof SugarBean)) {
            throw new Twig\Error\Error('"related" filter called on non-Bean object. Maybe missing context for related record of type "' . $relatedModule . '"');
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
            $prefixedModules = ['am_projecttemplates', 'am_tasktemplates', 'aobh_businesshours', 'aod_index', 'aod_indexevent', 'aok_knowledgebase',
                'aok_knowledge_base_categories', 'aop_case_events', 'aop_case_updates', 'aor_charts', 'aor_conditions', 'aor_fields',
                'aor_reports', 'aor_scheduled_reports', 'aos_contracts', 'aos_invoices', 'aos_line_item_groups', 'aos_pdf_templates',
                'aos_products', 'aos_quotes', 'aos_products_quotes', 'aos_product_categories', 'aow_actions', 'aow_conditions',
                'aow_processed', 'aow_workflow', 'fp_events', 'fp_event_locations', 'jjwg_areas', 'jjwg_maps', 'jjwg_markers'];
            // adds variations for simplified module names:
            foreach ($prefixedModules as $prefixedModule) {
                if ((strpos($prefixedModule, $relatedModule) !== false) &&
                    (!in_array($prefixedModule, $variations))) {
                    $variations[] = $prefixedModule;
                }
            }
            // adds variations for custom relationships with names like leads_aos_quotes_1:
            foreach ($focus->field_name_map as $key => $field) {
                if ((strpos($focus->table_name . '_' . $key, $relatedModule) !== false) &&
                    (!in_array($key, $variations))) {
                    $variations[] = $key;
                }
            }
            // tries each variation until one works:
            foreach ($variations as $varkey => $variation) {
                $linkedBeans = $focus->get_linked_beans($variation); // , '', '', 0, -1, 0, $whereClause);
                if ($varkey >= 1) {
                    // traverse relationship tables in one simplified step (e.g. quotes > products_quotes > products)
                    foreach ($linkedBeans as $key => $linked) {
                        $linkedLinkedBeans = $linked->get_linked_beans($variations[$varkey - 1]); // , '', '', 0, -1, 0, $whereClause);
                        if (count($linkedLinkedBeans) === 1) {
                            $linkedBeans[$key] = $linkedLinkedBeans[0];
                        }
                    }
                }
                if ($relatedModule === 'email_addresses') {
                    foreach ($linkedBeans as $key => $linked) {
                        $linkedBeans[$key] = $linkedBeans[$key]->email_address;
                    }
                }
                if (count($linkedBeans) > 0) {
                    break;
                }
            }
        } catch (Exception $e) {
            throw new Twig\Error\Error('Error retrieving related records of type "' . $relatedModule . '" from context.');
        }
        if (!count($linkedBeans)) {
            throw new Twig\Error\Error('No related records of type "' . $relatedModule . '" found.');
        }
        return $linkedBeans;
    }

    // Gets a photo field (either core or custom field) from bean and inserts it into template
    // TODO:pgr: use PHP 8.0 attributes to specify Twig attributes of the function? e.g. #[isSafeHtml]
    #[isSafeHtml]
    public static function photo($focus) {
        global $sugar_config;
        $width = 80;

        if (!($focus instanceof SugarBean)) {
            throw new Twig\Error\Error('Missing bean context when getting photo.');
        }
        try {
            $savedFile = self::copyToPublicDir($focus->id . '_photo', 'emailImages');

            $site = $sugar_config['site_url'];
            $site = rtrim($site, '/') . '/';

            //$ret = $site . 'index.php?entryPoint=emailImage&id=' . $focus->id . '_photo';
            $ret = '<img src="' . $site . $savedFile . '" alt="{ image }" width="' . $width . '">';

            return '' . $ret . '';
// <img src="http://localhost:8080/index.php?entryPoint=emailImage&id=e5014bcf-8569-cc3e-eefe-5be1d240e904_photo&type=Leads" alt="missing image" width="150">
        }
        catch (Exception $e) {
            throw new Twig\Error\Error('Error when getting photo of record of type "' . $focus->module_name . '"');
        }
    }

    // transforms a Twig template received as a string into its rendered HTML
    public static function render($template) {
        // Security reminder: treat parameters as untrusted user-provided content:

        $twigTemplate = self::$suiteReplacer->twig->createTemplate($template);
        //$this->validateTemplate($this->twig, $template);
        $output = self::$suiteReplacer->twig->render($twigTemplate, self::$suiteReplacer->assignments);
        return $output;
    }

    public static function topdf($template, $fileName = '')
    {
        global $sugar_config;
        // Security reminder: treat parameters as untrusted user-provided content:
        // simple yet safe: replace every sequence of NOT "a-zA-Z0-9_-" with a dash; add an extension yourself.
        $fileName = preg_replace('/[^a-zA-Z0-9_-]+/', '-', strtolower($fileName)).'.pdf';

        try {
            $pdf = PDFWrapper::getPDFEngine();
            //$pdf->addCSS(file_get_contents('custom/pgr/PowerReplacer/custom_styles.css');

            if ($template instanceof AOS_PDF_Templates) {
                $pdf->configurePDF([
                    'mode' => 'en',
                    'page_size' => $template->page_size,
                    'font' => 'DejaVuSansCondensed',
                    'margin_left' => $template->margin_left,
                    'margin_right' => $template->margin_right,
                    'margin_top' => $template->margin_top,
                    'margin_bottom' => $template->margin_bottom,
                    'margin_header' => $template->margin_header,
                    'margin_footer' => $template->margin_footer,
                    'orientation' => $template->orientation
                ]);
                $pdf->writeHeader($template->pdfheader);
                $pdf->writeFooter($template->pdffooter);
                $pdf->writeHTML(self::$suiteReplacer->twig->render($template->description));
            } else {
                $pdf->writeHTML($template);
                //$pdf->
            }

            if ($fileName === '') {
                $fileName = $sugar_config['upload_dir'] . 'topdf.pdf';
            }
            $pdf->outputPDF($fileName, 'F');
        } catch (Exception $e) {
            echo $e;
        }
        return $fileName;
    }

    public static function attachFilter($fileNamesOrNoteObjects, $displayName = '') {
        // Security reminder: treat parameters as untrusted user-provided content

        // we can handle both individual items, or arrays of items, so if needed, create an array of one:
        if (!is_array($fileNamesOrNoteObjects)) {
            $fileNamesOrNoteObjects = Array($fileNamesOrNoteObjects);
        }
        foreach ($fileNamesOrNoteObjects as $fileNameOrNoteObj) {
            // are we attaching a full Note objects or a simple string filename?
            if ($fileNameOrNoteObj instanceof Note) {

                // avoid repeats which are common due to evaluating both description and description_html:
                if (!in_array($fileNameOrNoteObj, array_column(self::$suiteReplacer->pickedObjects, 1))) {
                    // pass the object itself to the outside calling function, in this case it's probably sendEmail from within a Workflow action
                    self::$suiteReplacer->pickedObjects[] = ['Note', $fileNameOrNoteObj->id, $fileNameOrNoteObj];
                }
            } else {
                $fileNameOrNoteObj = str_replace('..', '.', $fileNameOrNoteObj);

                if (!file_exists($fileNameOrNoteObj)) {
                    throw new Twig\Error\Error('attachFilter: Error attaching file to email "' . $fileNameOrNoteObj . '.');
                }
                $url = $fileNameOrNoteObj;

                // avoid repeats which are common due to evaluating both description and description_html:
                if (!in_array($fileNameOrNoteObj, array_column(self::$suiteReplacer->files2Attach, 0))) {
                    self::$suiteReplacer->files2Attach[] = [$fileNameOrNoteObj, $displayName, $url];
                }
            }
        }
    }

    protected static function copyToPublicDir($sourceId, $subDir = '', $destName = '')
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



}