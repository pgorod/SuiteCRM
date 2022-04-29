<?php

if (!defined('sugarEntry') || !sugarEntry) {
    die('Not A Valid Entry Point');
}

require_once 'modules/EmailTemplates/EmailTemplate.php';

class CustomEmailTemplate extends EmailTemplate
{
    public function cleanBean() {
        SugarBean::cleanBean();
        $this->body_html = $GLOBALS['RAW_REQUEST']['body_html'];
    }




    public function fill_in_additional_detail_fields()
    {
        if (empty($this->body) && !empty($this->body_html)) {
            global $sugar_config;

            $bodyCleanup = $this->body_html;

            $bodyCleanup = html_entity_decode($bodyCleanup, ENT_COMPAT, $sugar_config['default_charset']);

            // Template contents should contains at least one
            // white space character at after the variable names
            // to recognise it when parsing and replacing variables
            $bodyCleanup = preg_replace('/(\$\w+\b)([^\s\/&"\'])/', '$1 $2', $bodyCleanup);

            $bodyCleanup = Html2Text\Html2Text::convert($bodyCleanup, true);

            $this->body = $bodyCleanup;
        }
        $this->created_by_name = get_assigned_user_name($this->created_by);
        $this->modified_by_name = get_assigned_user_name($this->modified_user_id);
        $this->assigned_user_name = get_assigned_user_name($this->assigned_user_id);
        $this->fill_in_additional_parent_fields();
    }


    public function retrieve($id = -1, $encode = true, $deleted = true)
    {
        $ret = parent::retrieve($id, false /* $encode */, $deleted);
        $this->repairMozaikClears();
        $this->imageLinkReplaced = false;
        $this->repairEntryPointImages();
        if ($this->imageLinkReplaced) {
            $this->save();
        }
        $this->addDomainToRelativeImagesSrc();
        return $ret;
    }

    // THE FUNCTION BELOW IS 100% LIKE THE PARENT VERSION, it's just here because they made it "private"
    // instead of "protected". See similar case in https://github.com/salesagility/SuiteCRM/pull/8841
    private function repairMozaikClears()
    {
        // repair tinymce auto correction in mozaik clears
        $this->body_html = str_replace('&lt;div class=&quot;mozaik-clear&quot;&gt;&nbsp;&lt;br&gt;&lt;/div&gt;', '&lt;div class=&quot;mozaik-clear&quot;&gt;&lt;/div&gt;', $this->body_html);
    }



    // THE FUNCTION BELOW IS 100% LIKE THE PARENT VERSION, it's just here because they made it "private"
    // instead of "protected".
    private function repairEntryPointImages()
    {
        global $sugar_config, $log;

        // repair the images url at entry points, change to a public direct link for remote email clients..

        $html = from_html($this->body_html);
        $siteUrl = $sugar_config['site_url'];
        $regex = '#<img[^>]*[\s]+src=[\s]*["\'](' . preg_quote($siteUrl) . '\/index\.php\?entryPoint=download&type=Notes&id=([a-f0-9]{8}\-[a-f0-9]{4}\-[a-f0-9]{4}\-[a-f0-9]{4}\-[a-f0-9]{12})&filename=.+?)["\']#si';

        if (preg_match($regex, $html, $match)) {

            $splits = explode('.', $match[1]);

            $fileExtension = end($splits);

            $toFile = $match[2] . '.' . $fileExtension;
            if (is_string($toFile) && !has_valid_image_extension('repair-entrypoint-images-fileext', $toFile)){
                $log->error("repairEntryPointImages | file with invalid extension '$toFile'");
                return;
            }

            $this->makePublicImage($match[2], $fileExtension);
            $newSrc = $sugar_config['site_url'] . '/public/' . $match[2] . '.' . $fileExtension;
            $this->body_html = to_html(str_replace($match[1], $newSrc, $html));
            $this->imageLinkReplaced = true;
            $this->repairEntryPointImages();
        }
    }

    // THE FUNCTION BELOW IS 100% LIKE THE PARENT VERSION, it's just here because they made it "private"
    // instead of "protected".
    private function makePublicImage($id, $ext = 'jpg')
    {
        $toFile = 'public/' . $id . '.' . $ext;
        if (file_exists($toFile)) {
            return;
        }
        $fromFile = 'upload://' . $id;
        if (!file_exists($fromFile)) {
            throw new Exception('file not found');
        }
        if (!file_exists('public')) {
            sugar_mkdir('public', 0777);
        }
        $fdata = file_get_contents($fromFile);
        if (!file_put_contents($toFile, $fdata)) {
            throw new Exception('file write error');
        }
    }

}
