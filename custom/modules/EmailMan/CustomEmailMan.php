<?php

if (!defined('sugarEntry') || !sugarEntry) {
    die('Not A Valid Entry Point');
}

require_once 'modules/EmailMan/EmailMan.php';
require_once 'custom/pgr/SuiteReplacer.php';
require_once('modules/AOS_PDF_Templates/PDF_Lib/mpdf.php');

class CustomEmailMan extends EmailMan {
    protected $previewFileStarted = false;


    public function appendEmailToPreview(array $templateData = null)
    {
        global $app_strings;
        $content = '';
        $fileName = 'public/campaignPreview.html';

        if (!$this->previewFileStarted) {
            if (file_exists($fileName)) {
                unlink($fileName);
            }
            // Page header:
            $content .= '<html><head><meta http-equiv="Content-Type" content="text/html; charset=utf-8">' . PHP_EOL;
            $content .= '<style>
                            body        { background-color: lightsteelblue; } 
                            ul          { margin: 0; }
                            div.content { border-radius: 7px; background: #FFF; padding: 10px; margin: 5px; }
                            div.box     { display: inline; }
                            div.label   { float:left; background-color: lightsteelblue; }
                            div.subject { float:left; width: 80%; margin-bottom: 15px; }
                            div.attach  { float:left; width: 80%; margin-bottom: 15px; background-color: lightsteelblue; font-weight: bold; }
                            .faded      { color: lightslategrey; font-weight: lighter; }
                            div.error   { color: darkred; text-align: right; padding: 15px; clear: both; }
                         </style>';
            $content .= '</head><body>' . PHP_EOL;
            $this->previewFileStarted = true;
        }
        if (!isset($templateData)) {
            $content .= '</body></html>';
        } else {
            // Output current email:
            $attachments = '';
            foreach ($templateData['attach'] as $file) {
                $attachments .= '<li>'. (isset($file[1]) ?
                    $file[1] . '<span class="faded"> ('. $file[0] . ')</span></li>' :
                    $file[0] . '</li>');
            }
            $content .= '<div class="box">';
            $content .= '  <div class="content label"><b>' . $app_strings['LBL_EMAIL_SUBJECT'] .'</b></div>';
            $content .= '  <div class="content subject">'    . $templateData['subject'] . '</div>';
            $content .= '</div><div style="clear:both"></div>';

            $content .= '<div class="content">' . (isset($templateData['body_html'] ) ? $templateData['body_html'] : '') . '</div>';
            $content .= isset($templateData['error']) ? '<div class="error">' . $templateData['error'] . '</div>' : '';
            if ($attachments !== '') {
                $content .= '<div class="box">';
                $content .= '  <div class="content label"><b>' . 'Attachments:' . '</b></div>';
                $content .= '  <div class="content attach"><ul>' . $attachments . '</ul></div>';
                $content .= '</div><div style="clear:both"></div>';
            }

            //$content .= $attachments !== '' ? '<div class="attach"><b>' . $attachments .'</b></div>' : '';
            $content .= '<hr>';
        }
        /*
        $mpdf = new mPDF();
        $mpdf->showImageErrors = true;
        $mpdf->WriteHTML($content);
        $mpdf->Output('public/' . uniqid(rand(), true) . '.pdf', 'F');
        */
        file_put_contents($fileName, $content . PHP_EOL, FILE_APPEND | LOCK_EX);
    }

    /**
     * @global array $beanList ;
     * @global array $beanFiles ;
     * @global Configurator|array $sugar_config ;
     * @global array $mod_strings ;
     * @global Localization $locale ;
     * @param SugarPHPMailer $mail
     * @param int $save_emails
     * @param bool $testmode
     * @return bool
     */
    public function sendEmail(SugarPHPMailer $mail, $save_emails = 1, $testmode = false, $previewmode = false) {
        $this->test = $testmode;

        global $beanList;
        global $beanFiles;
        global $sugar_config;
        global $mod_strings;
        global $locale;
        $OBCharset = $locale->getPrecedentPreference('default_email_charset');
        $mod_strings = return_module_language($sugar_config['default_language'], 'EmailMan');

        //get tracking entities locations.
        if (!isset($this->tracking_url)) {
            $admin = BeanFactory::newBean('Administration');
            $admin->retrieveSettings('massemailer'); //retrieve all admin settings.
            if (isset($admin->settings['massemailer_tracking_entities_location_type']) and $admin->settings['massemailer_tracking_entities_location_type'] == '2' and isset($admin->settings['massemailer_tracking_entities_location'])) {
                $this->tracking_url = $admin->settings['massemailer_tracking_entities_location'];
            } else {
                $this->tracking_url = $sugar_config['site_url'];
            }
        }

        //make sure tracking url ends with '/' character
        $strLen = strlen($this->tracking_url);
        if ($this->tracking_url[$strLen - 1] != '/') {
            $this->tracking_url .= '/';
        }

        if (!isset($beanList[$this->related_type])) {
            return false;
        }
        $class = $beanList[$this->related_type];
        if (!class_exists($class)) {
            require_once($beanFiles[$class]);
        }

        $this->setTargetId(create_guid());

        $focus = new $class();
        $focus->retrieve($this->related_id);
        $focus->emailAddress->handleLegacyRetrieve($focus);

        //check to see if bean has a primary email address
        if (!$this->is_primary_email_address($focus)) {
            //no primary email address designated, do not send out email, create campaign log
            //of type send error to denote that this user was not emailed
            $this->set_as_sent($focus->email1, true, null, null, 'send error');
            //create fatal logging for easy review of cause.
            $GLOBALS['log']->fatal('Email Address provided is not Primary Address for email with id ' . $focus->email1 . "' Emailman id=$this->id");
            return true;
        }

        if (!$this->valid_email_address($focus->email1)) {
            $this->set_as_sent($focus->email1, true, null, null, 'invalid email');
            $GLOBALS['log']->fatal('Encountered invalid email address: ' . $focus->email1 . " Emailman id=$this->id");
            return true;
        }

        if ($this->shouldBlockEmail($focus)) {
            $GLOBALS['log']->warn('Email Address was sent due to not being confirm opt in' . $focus->email1);

            // block sending campaign email
            $this->set_as_sent($focus->email1, true, null, null, 'blocked');
            return true;
        }

        if (
            (
                !isset($focus->email_opt_out)
                || (
                    $focus->email_opt_out !== 'on'
                    && $focus->email_opt_out !== 1
                    && $focus->email_opt_out !== '1'
                )
            )
            && (
                !isset($focus->invalid_email)
                || ($focus->invalid_email !== 'on' && $focus->invalid_email !== 1 && $focus->invalid_email !== '1')
            )) {
            // If email address is not opted out or the email is valid
            $lower_email_address = strtolower($focus->email1);
            //test against restricted domains
            $at_pos = strrpos($lower_email_address, '@');
            if ($at_pos !== false) {
                foreach ($this->restricted_domains as $domain => $value) {
                    $pos = strrpos($lower_email_address, $domain);
                    if ($pos !== false && $pos > $at_pos) {
                        //found
                        $this->set_as_sent($lower_email_address, true, null, null, 'blocked');
                        return true;
                    }
                }
            }

            if (isset($this->restricted_addresses[$lower_email_address])) {
                $this->set_as_sent($lower_email_address, true, null, null, 'blocked');

                return true;
            }

            //test for duplicate email address by marketing id.
            $dup_query = "select id from campaign_log where more_information='" . $this->db->quote($focus->email1) . "' and marketing_id='" . $this->marketing_id . "'";
            $dup = $this->db->query($dup_query);
            $dup_row = $this->db->fetchByAssoc($dup);
            if (!empty($dup_row)) {
                //we have seen this email address before
                $this->set_as_sent($focus->email1, true, null, null, 'blocked');
                return true;
            }

            //fetch email marketing.
            if (empty($this->current_emailmarketing) or ! isset($this->current_emailmarketing)) {
                $this->current_emailmarketing = BeanFactory::newBean('EmailMarketing');
            }
            if (empty($this->current_emailmarketing->id) or $this->current_emailmarketing->id !== $this->marketing_id) {
                $this->current_emailmarketing->retrieve($this->marketing_id);

                $this->newmessage = true;
            }
            //fetch email template associate with the marketing message.
            if (empty($this->current_emailtemplate) or $this->current_emailtemplate->id !== $this->current_emailmarketing->template_id) {
                $this->current_emailtemplate = BeanFactory::newBean('EmailTemplates');

                if (isset($this->resend_type) && $this->resend_type == 'Reminder') {
                    $this->current_emailtemplate->retrieve($sugar_config['survey_reminder_template']);
                } else {
                    $this->current_emailtemplate->retrieve($this->current_emailmarketing->template_id);
                }

                //escape email template contents.
                $this->current_emailtemplate->subject = from_html($this->current_emailtemplate->subject);
                $this->current_emailtemplate->body_html = from_html($this->current_emailtemplate->body_html);
                $this->current_emailtemplate->body = from_html($this->current_emailtemplate->body);

                $q = "SELECT * FROM notes WHERE parent_id = '" . $this->current_emailtemplate->id . "' AND deleted = 0";
                $r = $this->db->query($q);

                // cn: bug 4684 - initialize the notes array, else old data is still around for the next round
                $this->notes_array = array();
                if (!class_exists('Note')) {
                    require_once('modules/Notes/Note.php');
                }
                while ($a = $this->db->fetchByAssoc($r)) {
                    $noteTemplate = BeanFactory::newBean('Notes');
                    $noteTemplate->retrieve($a['id']);
                    $this->notes_array[] = $noteTemplate;
                }
            }

            // fetch mailbox details..
            if (empty($this->current_mailbox)) {
                $this->current_mailbox = BeanFactory::newBean('InboundEmail');
            }
            if (empty($this->current_mailbox->id) or $this->current_mailbox->id !== $this->current_emailmarketing->inbound_email_id) {
                $this->current_mailbox->retrieve($this->current_emailmarketing->inbound_email_id);
                //extract the email address.
                $this->mailbox_from_addr = $this->current_mailbox->get_stored_options('from_addr', 'nobody@example.com', null);
                isValidEmailAddress($this->mailbox_from_addr);
            }

            // fetch campaign details..
            if (empty($this->current_campaign)) {
                $this->current_campaign = BeanFactory::newBean('Campaigns');
            }
            if (empty($this->current_campaign->id) or $this->current_campaign->id !== $this->current_emailmarketing->campaign_id) {
                $this->current_campaign->retrieve($this->current_emailmarketing->campaign_id);

                //load defined tracked_urls
                $this->current_campaign->load_relationship('tracked_urls');
                $query_array = $this->current_campaign->tracked_urls->getQuery(true);
                $query_array['select'] = "SELECT tracker_name, tracker_key, id, is_optout ";
                $result = $this->current_campaign->db->query(implode(' ', $query_array));

                $this->has_optout_links = false;
                $this->tracker_urls = array();
                while (($row = $this->current_campaign->db->fetchByAssoc($result)) != null) {
                    $this->tracker_urls['{' . $row['tracker_name'] . '}'] = $row;
                    //has the user defined opt-out links for the campaign.
                    if ($row['is_optout'] == 1) {
                        $this->has_optout_links = true;
                    }
                }
            }

            $mail->ClearAllRecipients();
            $mail->ClearReplyTos();
            $mail->Sender = $this->current_emailmarketing->from_addr ? $this->current_emailmarketing->from_addr : $this->mailbox_from_addr;
            isValidEmailAddress($mail->Sender);
            $mail->From = $this->current_emailmarketing->from_addr ? $this->current_emailmarketing->from_addr : $this->mailbox_from_addr;
            isValidEmailAddress($mail->From);
            $mail->FromName = $locale->translateCharsetMIME(trim($this->current_emailmarketing->from_name), 'UTF-8', $OBCharset);

            $mail->ClearCustomHeaders();
            $mail->AddCustomHeader('X-CampTrackID:' . $this->getTargetId());
            //CL - Bug 25256 Check if we have a reply_to_name/reply_to_addr value from the email marketing table.  If so use email marketing entry; otherwise current mailbox (inbound email) entry
            $replyToName = empty($this->current_emailmarketing->reply_to_name) ? $this->current_mailbox->get_stored_options('reply_to_name', $mail->FromName, null) : $this->current_emailmarketing->reply_to_name;
            $replyToAddr = empty($this->current_emailmarketing->reply_to_addr) ? $this->current_mailbox->get_stored_options('reply_to_addr', $mail->From, null) : $this->current_emailmarketing->reply_to_addr;

            if (!empty($replyToAddr)) {
                $mail->AddReplyTo($replyToAddr, $locale->translateCharsetMIME(trim($replyToName), 'UTF-8', $OBCharset));
            }



            $replacer = new SuiteReplacer();
            $context = Array();
            $context[]= [lcfirst($focus->object_name), $focus];
            if (in_array(lcfirst($focus->object_name), ['contact', 'lead', 'target', 'user', ])) {
                $context[] = ['person', $focus];
            }
            $context[]= ['site_url',        $sugar_config['site_url']];
            $context[]= ['tracker_url',     $this->tracker_urls];
            $context[]= ['campaign',        $this->current_campaign];
            $context[]= ['email_marketing', $this->current_emailmarketing];
            $context[]= ['replyToAddr',     $replyToAddr];
            $context[]= ['replyToName',     $replyToName];


            $parts  = ['body', 'subject', 'body_html'];
            $error = null;
            foreach ($parts as $key) {
                $value = $this->current_emailtemplate->$key;
//                    $value = (strstr($key, 'html') !== false) ?  $replacer->undoCleanUp($value) : $value;
                $value = (strstr($key, 'html') !== false) ?  htmlspecialchars_decode($value, ENT_QUOTES | ENT_HTML401) : $value;
                try {
                    $template_data[$key] = $replacer->replace($value, $context);
                }
                catch (\Exception $twigException) {
                    $template_data['error'] = 'Twig Template error: ' . $twigException->getMessage();
                    //$errorvalue = $value;
                }
            }

            //parse and replace bean variables.
            $macro_nv = array();

            //require_once __DIR__ . '/../EmailTemplates/EmailTemplateParser.php';
            require_once 'modules/EmailTemplates/EmailTemplateParser.php';

            $NOTtemplate_data = (new EmailTemplateParser(
                $this->current_emailtemplate,
                $this->current_campaign,
                $focus,
                $sugar_config['site_url'],
                $this->getTargetId()
            ))->parseVariables();

            //add email address to this list.
            $macro_nv['sugar_to_email_address'] = $focus->email1;
            $macro_nv['email_template_id'] = $this->current_emailmarketing->template_id;

            //parse and replace urls.
            //this is new style of adding tracked urls to a campaign.
            $tracker_url_template = $this->tracking_url . 'index.php?entryPoint=campaign_trackerv2&track=%s' . '&identifier=' . $this->getTargetId();
            $removeme_url_template = $this->tracking_url . 'index.php?entryPoint=removeme&identifier=' . $this->getTargetId();
            $template_data = $this->current_emailtemplate->parse_tracker_urls($template_data, $tracker_url_template, $this->tracker_urls, $removeme_url_template);
            $mail->AddAddress($focus->email1, $locale->translateCharsetMIME(trim($focus->name), 'UTF-8', $OBCharset));

            //refetch strings in case they have been changed by creation of email templates or other beans.
            $mod_strings = return_module_language($sugar_config['default_language'], 'EmailMan');

            if ($this->test && !$previewmode) {
                $mail->Subject = $mod_strings['LBL_PREPEND_TEST'] . $template_data['subject'];
            } else {
                $mail->Subject = $template_data['subject'];
            }

            //check if this template is meant to be used as "text only"
            $text_only = false;
            if (isset($this->current_emailtemplate->text_only) && $this->current_emailtemplate->text_only) {
                $text_only = true;
            }
            //if this template is textonly, then just send text body.  Do not add tracker, opt out,
            //or perform other processing as it will not show up in text only email
            if ($text_only) {
                $this->description_html = '';
                $mail->IsHTML(false);
                $mail->Body = $template_data['body'];
            } else {
                $mail->Body = wordwrap($template_data['body_html'], 900);
                //BEGIN:this code will trigger for only campaigns pending before upgrade to 4.2.0.
                //will be removed for the next release.
                if (!isset($btracker)) {
                    $btracker = false;
                }
                if ($btracker) {
                    $mail->Body .= "<br /><br /><a href='" . $tracker_url . "'>" . $tracker_text . "</a><br /><br />";
                } else {
                    if (!empty($tracker_url)) {
                        $mail->Body = str_replace('TRACKER_URL_START', "<a href='" . $tracker_url . "'>", $mail->Body);
                        $mail->Body = str_replace('TRACKER_URL_END', "</a>", $mail->Body);
                        $mail->AltBody = str_replace('TRACKER_URL_START', "<a href='" . $tracker_url . "'>", $mail->AltBody);
                        $mail->AltBody = str_replace('TRACKER_URL_END', "</a>", $mail->AltBody);
                    }
                }
                //END
                //do not add the default remove me link if the campaign has a tracker url of the opt-out link
                if ($this->has_optout_links == false) {
                    $mail->Body .= "<br /><span style='font-size:0.8em'>{$mod_strings['TXT_REMOVE_ME']} <a href='" . $this->tracking_url . "index.php?entryPoint=removeme&identifier={$this->getTargetId()}'>{$mod_strings['TXT_REMOVE_ME_CLICK']}</a></span>";
                }
                // cn: bug 11979 - adding single quote to conform with HTML email RFC
                $mail->Body .= "<br /><img alt='' height='1' width='1' src='{$this->tracking_url}index.php?entryPoint=image&identifier={$this->getTargetId()}' />";

                $mail->AltBody = $template_data['body'];
                if ($btracker) {
                    $mail->AltBody .= "\n" . $tracker_url;
                }
                if ($this->has_optout_links == false) {
                    $mail->AltBody .= "\n\n\n{$mod_strings['TXT_REMOVE_ME_ALT']} " . $this->tracking_url . "index.php?entryPoint=removeme&identifier={$this->getTargetId()}";
                }
            }

            // cn: bug 4684, handle attachments in email templates.
            $mail->handleAttachments($this->notes_array);
            $tmp_Subject = $mail->Subject;
            $mail->prepForOutbound();

            $template_data['attach'] = $replacer->files2Attach;
            if ($previewmode) {
                $this->appendEmailToPreview($template_data);
                $success = true;
            } else {
                $success = $mail->Send();

                //Do not save the encoded subject.
                $mail->Subject = $tmp_Subject;
                if ($success) {
                    $email_id = null;
                    if ($save_emails == 1) {
                        $email_id = $this->create_indiv_email($focus, $mail);
                    } else {
                        //find/create reference email record. all campaign targets receiving this message will be linked with this message.
                        $decodedFromName = mb_decode_mimeheader($this->current_emailmarketing->from_name);
                        $fromAddressName = "{$decodedFromName} <{$this->mailbox_from_addr}>";

                        $email_id = $this->create_ref_email(
                            $this->marketing_id,
                            $this->current_emailtemplate->subject,
                            $this->current_emailtemplate->body,
                            $this->current_emailtemplate->body_html,
                            $this->current_campaign->name,
                            $this->mailbox_from_addr,
                            $this->user_id,
                            $this->notes_array,
                            $macro_nv,
                            $this->newmessage,
                            $fromAddressName
                        );
                        $this->newmessage = false;
                    }
                }
            if ($success) {
                $this->set_as_sent($focus->email1, true, $email_id, 'Emails', 'targeted');
            } else {
                if (!empty($layout_def['parent_id'])) {
                    if (isset($layout_def['fields'][strtoupper($layout_def['parent_id'])])) {
                        $parent .= "&parent_id=" . $layout_def['fields'][strtoupper($layout_def['parent_id'])];
                    }
                }
                if (!empty($layout_def['parent_module'])) {
                    if (isset($layout_def['fields'][strtoupper($layout_def['parent_module'])])) {
                        $parent .= "&parent_module=" . $layout_def['fields'][strtoupper($layout_def['parent_module'])];
                    }
                }
                //log send error. save for next attempt after 24hrs. no campaign log entry will be created.
                $this->set_as_sent($focus->email1, false, null, null, 'send error');
            }
            }
        } else {
            $success = false;

            if (isset($focus->email_opt_out) && ($focus->email_opt_out === 'on' || $focus->email_opt_out == '1' || $focus->email_opt_out == 1)) {
                $this->set_as_sent($focus->email1, true, null, null, 'blocked');
            } else {
                if (isset($focus->invalid_email) && ($focus->invalid_email == 1 || $focus->invalid_email == '1')) {
                    $this->set_as_sent($focus->email1, true, null, null, 'invalid email');
                } else {
                    $this->set_as_sent($focus->email1, true, null, null, 'send error');
                }
            }
        }

        return $success;
    }

    // THE FUNCTION BELOW IS 100% LIKE THE PARENT VERSION, it's just here because they made it "private"
    // instead of "protected". See https://github.com/salesagility/SuiteCRM/pull/8841
    private function shouldBlockEmail(SugarBean $bean)
    {
        global $sugar_config;

        $optInLevel = isset($sugar_config['email_enable_confirm_opt_in']) ? $sugar_config['email_enable_confirm_opt_in'] : '';

        // Find email address
        $email_address = trim($bean->email1);

        if (empty($email_address)) {
            return false;
        }

        $query = 'SELECT * '
            . 'FROM email_addr_bean_rel '
            . 'JOIN email_addresses on email_addr_bean_rel.email_address_id = email_addresses.id '
            . 'WHERE email_addr_bean_rel.bean_id = \'' . $bean->id . '\' '
            . 'AND email_addr_bean_rel.deleted=0 '
            . 'AND email_addr_bean_rel.primary_address=1 '
            . 'AND email_addresses.email_address LIKE \'' . $bean->db->quote($email_address) . '\'';

        $result = $bean->db->query($query);
        $row = $bean->db->fetchByAssoc($result);

        if (!empty($row)) {
            if ((int)$row['opt_out'] === 1) {
                return true;
            }

            if ((int)$row['invalid_email'] === 1) {
                return true;
            }

            if (
                $optInLevel === SugarEmailAddress::COI_STAT_DISABLED
                && (int)$row['opt_out'] === 0
            ) {
                return false;
            }

            if (
                $optInLevel === SugarEmailAddress::COI_STAT_OPT_IN
                && false === ($row['confirm_opt_in'] === SugarEmailAddress::COI_STAT_OPT_IN
                    || $row['confirm_opt_in'] === SugarEmailAddress::COI_STAT_CONFIRMED_OPT_IN)
            ) {
                return true;
            }

            if (
                $optInLevel == SugarEmailAddress::COI_STAT_CONFIRMED_OPT_IN
                && $row['confirm_opt_in'] !== SugarEmailAddress::COI_STAT_CONFIRMED_OPT_IN
            ) {
                return true;
            }
        }

        return false;
    }

}
