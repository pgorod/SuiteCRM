<?php

require_once 'custom/pgr/SuiteReplacer.php';
require_once 'modules/AOW_Actions/actions/actionSendEmail.php';

class actionSendPowerReplacerEmail extends actionSendEmail
{
    public function run_action(SugarBean $bean, $params = array(), $in_save = false)
    {

        // ****************************************************************
        // ******************* Pgr Version ********************************
        // ****************************************************************
        // ****************************************************************

        if (!empty($bean->in_relationship_update)) { // if this is set, and true, then we are inside a bean save triggered by a relationship delete...
            return true;
        }

        include_once __DIR__ . '/../../EmailTemplates/EmailTemplate.php';

        $this->clearLastEmailsStatus();

        $emailTemp = BeanFactory::newBean('EmailTemplates');
        $emailTemp->retrieve($params['email_template']);

        if ($emailTemp->id == '') {
            return false;
        }

        $emails = $this->getEmailsFromParams($bean, $params);

        if (!isset($emails['to']) || empty($emails['to'])) {
            return false;
        }

        $attachments = $this->getAttachments($emailTemp);

        $ret = true;
        if (isset($params['individual_email']) && $params['individual_email']) {
            // a separate email per recipient:
            foreach ($emails['to'] as $email_to) {
                $emailTemp = BeanFactory::newBean('EmailTemplates');
                $emailTemp->retrieve($params['email_template']);
                $template_override = isset($emails['template_override'][$email_to]) ? $emails['template_override'][$email_to] : array();
//                $this->parse_template($bean, $emailTemp, $template_override);
                if (!$this->sendEmail(array($email_to), $emailTemp->subject, $emailTemp->body_html, $emailTemp->body, $bean, $emails['cc'], $emails['bcc'], $attachments)) {
                    $ret = false;
                    $this->lastEmailsFailed++;
                } else {
                    $this->lastEmailsSuccess++;
                }
            }
        } else {
            // a single email with all recipients in the "to:" field:
//            $this->parse_template($bean, $emailTemp);
            if ($emailTemp->text_only == '1') {
                $email_body_html = $emailTemp->body;
            } else {
                $email_body_html = $emailTemp->body_html;
            }

            // avoid undefined index warnings for cc and bcc:
            $emails['cc'] = $emails['cc'] ?? array();
            $emails['bcc'] = $emails['bcc'] ?? array();

            if (!$this->sendEmail($emails['to'], $emailTemp->subject, $email_body_html, $emailTemp->body, $bean, $emails['cc'], $emails['bcc'], $attachments)) {
                $ret = false;
                $this->lastEmailsFailed++;
            } else {
                $this->lastEmailsSuccess++;
            }
        }
        return $ret;
    }


    public function sendEmail($emailTo, $emailSubject, $emailBody, $altemailBody, SugarBean $relatedBean = null, $emailCc = array(), $emailBcc = array(), $attachments = array())
    {

        // ****************************************************************
        // ******************* Pgr Version ********************************
        // ****************************************************************
        // ****************************************************************

        require_once('modules/Emails/Email.php');
        require_once('include/SugarPHPMailer.php');

        $emailObj = BeanFactory::newBean('Emails');
        $defaults = $emailObj->getSystemDefaultEmail();
        $mail = new SugarPHPMailer();
        $mail->setMailerForSystem();
        $mail->From = $defaults['email'];
        isValidEmailAddress($mail->From);
        $mail->FromName = $defaults['name'];
        $mail->ClearAllRecipients();
        $mail->ClearReplyTos();
        $mail->Subject = from_html($emailSubject);
        $mail->Body = $emailBody;
        $mail->AltBody = $altemailBody;

        if (empty($emailTo)) {
            return false;
        }
        foreach ($emailTo as $to) {
            $mail->AddAddress($to);
        }
        if (!empty($emailCc)) {
            foreach ($emailCc as $email) {
                $mail->AddCC($email);
            }
        }
        if (!empty($emailBcc)) {
            foreach ($emailBcc as $email) {
                $mail->AddBCC($email);
            }
        }

        // ------------------------------------------------

        /*
                $bogusContext = Array();
                $bogusContext[]= [lcfirst($relatedBean->object_name), $relatedBean];
                if (in_array(lcfirst($relatedBean->object_name), ['contact', 'lead', 'target', 'user', ])) {
                    $bogusContext[] = ['person', $relatedBean];
                }
                $bogusContext[]= ['site_url',        $sugar_config['site_url']];
                $bogusContext[]= ['thisEmail',       $mail];

                $parts  = ['Subject', 'Body', 'AltBody'];

                        $bCancel = false;
                        foreach ($parts as $key) {
                            $value = $mail->$key;
                            $value = ($key === 'Body') ?  htmlspecialchars_decode($value, ENT_QUOTES | ENT_HTML401) : $value;
                            try {
                                $mail->$key = $replacer->replace($value);
                            }
                            catch (\Exception $twigException) {
                                LoggerManager::getLogger()->error('SendPowerReplacerEmail at record id'. $relatedBean->id . ', ' . $relatedBean->name . '. Twig Template error: ' . $twigException->getMessage());
                                $bCancel = true;
                            }
                        }
        */

        global $sugar_config;
        $replacer = SuiteReplacer::getInstance()
            ->addContext($relatedBean)
            ->addContext(['site_url', $sugar_config['site_url']])
            ->addContext(['thisEmail', $mail]);

        $bCancel = false;
        try {
            $mail->Subject = $replacer->replace($mail->Subject, true);
            $mail->Body    = $replacer->replace(htmlspecialchars_decode($mail->Body, ENT_QUOTES | ENT_HTML401), true);  /*html*/
            $mail->AltBody = $replacer->replace($mail->AltBody); /*plaintext*/
        } catch (\Exception $twigException) {
            LoggerManager::getLogger()->error('SendPowerReplacerEmail at record id' . $relatedBean->id . ', ' . $relatedBean->name . '. Twig Template error: ' . $twigException->getMessage());
            $bCancel = true;
        }

        // TIP: throw exception inside template to provoke the cancellation conditionally, if you want. See our custom 'cancel' Twig function
        if ($bCancel) {
            return false;
        }

        // add any attachments specified inside the template, clearing others (statically defined inside WF module action) first?:
        $attachments = [];
        foreach ($replacer->pickedObjects as $pickedObject) {
            if ($pickedObject[0] == 'Note') {
                $attachments[] = $pickedObject[2];
                //$mail->addAttachment($file[0], $file[1]);
            }
        }
        // ------------------------------------------------


        $mail->handleAttachments($attachments);
        $mail->prepForOutbound();

        //now create email
        if ($mail->Send()) {
            $emailObj->to_addrs = implode(',', $emailTo);
            $emailObj->cc_addrs = implode(',', $emailCc);
            $emailObj->bcc_addrs = implode(',', $emailBcc);
            $emailObj->type = 'out';
            $emailObj->deleted = '0';
            $emailObj->name = $mail->Subject;
            $emailObj->description = $mail->AltBody;
            $emailObj->description_html = $mail->Body;
            $emailObj->from_addr = $mail->From;
            isValidEmailAddress($emailObj->from_addr);
            if ($relatedBean instanceof SugarBean && !empty($relatedBean->id)) {
                $emailObj->parent_type = $relatedBean->module_dir;
                $emailObj->parent_id = $relatedBean->id;
            }
            $emailObj->date_sent_received = TimeDate::getInstance()->nowDb();
            $emailObj->modified_user_id = '1';
            $emailObj->created_by = '1';
            $emailObj->status = 'sent';
            $emailObj->save();

            // Fix for issue 1561 - Email Attachments Sent By Workflow Do Not Show In Related Activity.
            foreach ($attachments as $attachment) {
                $note = BeanFactory::newBean('Notes');
                $note->id = create_guid();
                $note->date_entered = $attachment->date_entered;
                $note->date_modified = $attachment->date_modified;
                $note->modified_user_id = $attachment->modified_user_id;
                $note->assigned_user_id = $attachment->assigned_user_id;
                $note->new_with_id = true;
                $note->parent_id = $emailObj->id;
                $note->parent_type = $attachment->parent_type;
                $note->name = $attachment->name;
                $note->filename = $attachment->filename;
                $note->file_mime_type = $attachment->file_mime_type;
                $fileLocation = "upload://{$attachment->id}";
                $dest = "upload://{$note->id}";
                if (!copy($fileLocation, $dest)) {
                    LoggerManager::getLogger()->debug("actionSendEmail from Workflow: could not copy attachment file to $fileLocation => $dest");
                }
                $note->save();
            }
            return true;
        }
        return false;
    }
}

