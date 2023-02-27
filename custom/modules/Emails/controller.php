<?php

require_once 'modules/Emails/EmailsController.php';
require_once 'modules/EmailTemplates/EmailTemplateParser.php';
require_once 'custom/pgr/SuiteReplacer.php';

class CustomEmailsController extends EmailsController {

    // this is currently unfinished and unused:
    public function action_AjaxReplacer() {
        $toReplace = $_REQUEST['toReplace'];

        $this->view = 'ajax';
        echo json_encode($toReplace . ' from Ajax replacer');
    }

    // this custom version is the same code as the original, but swapping out template variable replacement to use the new SuiteReplacer
    // Also allows a use from two different buttons: send and preview"
    public function action_send()
    {
        global $current_user;
        global $app_strings;

        $request = $_REQUEST;

        // the old "description" field is used when is_only_plain_text = true
        if (isset($GLOBALS['RAW_REQUEST']['description_html'] ) && isset($GLOBALS['RAW_REQUEST']['description'] ) && $GLOBALS['RAW_REQUEST']['description'] === '') {
            $GLOBALS['RAW_REQUEST']['description'] = $GLOBALS['RAW_REQUEST']['description_html'] ;
        }

        $this->bean = $this->bean->populateBeanFromRequest($this->bean, $request);
        $inboundEmailAccount = BeanFactory::newBean('InboundEmail');
        $inboundEmailAccount->retrieve($_REQUEST['inbound_email_id']);

        if ($this->userIsAllowedToSendEmail($current_user, $inboundEmailAccount, $this->bean)) {
            $this->bean->save();

            $this->bean->handleMultipleFileAttachments();

            $campaign = BeanFactory::getBean('Campaigns'); // used to get survey_id... TODO check what's needed for Surveys

            $focusName = $request['parent_type'];
            $focus = BeanFactory::getBean($focusName, $request['parent_id']);  // TODO check module name exists

            $replacer = SuiteReplacer::getInstance()
                ->addContext($focus)
                ->addContext($campaign)
                ->addContext(['to', $this->bean->to_addrs_arr])
                ->addContext(['cc', $this->bean->cc_addrs_arr])
                ->addContext(['bcc', $this->bean->bcc_addrs_arr]);

            $parts  = ['description', 'name', 'description_html'];
            $error = null;
            foreach ($parts as $key) {
                if (isset($GLOBALS['RAW_REQUEST'][$key])) {
                    $value = $GLOBALS['RAW_REQUEST'][$key];
                    $value = (strstr($key, 'html') !== false) ?  htmlspecialchars_decode($value, ENT_QUOTES | ENT_HTML401) : $value;
                    try {
                        $this->bean->$key = $replacer->replace($value, true);
                    }
                    catch (\Exception $twigException) {
                        $error = 'Twig Template error: ' . $twigException->getMessage();
                        $errorValue = $value;
                    }
                }
            }
            $replacer->clearStatics();

            if (!is_null($error)) {
                $GLOBALS['log']->error($error);
                $split = explode(" ", $twigException->getMessage());
                $line = intval($split[count($split)-1]);
                $lineText = explode("\n", $errorValue)[$line-1];
                $this->view = 'ajax';
                $response['errors'] = [
                    'type'   => get_class($this->bean),
                    'id'     => $this->bean->id,
                    'error'  => $error,
                    'title'  => $error . ": <br/><br/><textarea readonly type=text style='width:100%'>$lineText</textarea><br/><br/>Mail not sent.",
                    'lineno' => $line,
                    'line'   => $lineText
                ];
                echo json_encode($response);
                return;
            }

            // handle Previews and exit early:
            if (isset($request['preview']) && ( $request['preview'] === 'preview')) {
                foreach ($parts as $key) {
                    $response["preview-$key"] = html_entity_decode($this->bean->$key);
                }
                $response["preview-attach"] = $replacer->files2Attach;
                $response["preview-attachAsHTML"] = '<ul>';
                foreach ($replacer->files2Attach as $file) {
                    $response["preview-attachAsHTML"] .= '<li>'. (isset($file[1]) ?
                            $file[1] . '<span class=faded> ('. $file[0] . ')</span></li>' :
                            $file[0] . '</li>');
                }
                $response["preview-attachAsHTML"] .= '</ul>';
                if ($response["preview-attachAsHTML"] === '<ul></ul>') {
                    $response["preview-attachAsHTML"] = '';
                }
                $this->view = 'ajax';
                echo json_encode($response);
                return;
            }

            //TODO discard $this->bean if it doesn't implement EmailInterface

            $mail =  new SugarPHPMailer();
            // add any attachments specified inside the template:
            foreach ($replacer->files2Attach as $file) {
                $mail->addAttachment($file[0], $file[1]);
            }
            $this->bean->saved_attachments[] = new Note();
            if ($this->bean->send($mail)) { // can receive a previously made SugarPHPMailer object
                $this->bean->status = 'sent';
                $this->bean->save();
            } else {
                // Don't save status if the email is a draft.
                // We need to ensure that drafts will still show
                // in the list view
                if ($this->bean->status !== 'draft') {
                    $this->bean->save();
                }
                $this->bean->status = 'send_error';
            }

            $this->view = 'sendemail';
        } else {
            $GLOBALS['log']->security(
                'User ' . $current_user->name .
                ' attempted to send an email using incorrect email account settings in' .
                ' which they do not have access to.'
            );

            $this->view = 'ajax';
            $response['errors'] = [
                'type' => get_class($this->bean),
                'id' => $this->bean->id,
                'title' => $app_strings['LBL_EMAIL_ERROR_SENDING']
            ];
            echo json_encode($response);
        }
    }
}
