<?php

/**
 * The base class for easyComm.
 */
class easyComm {
    /* @var modX $modx */
    public $modx;
    /* @var pdoTools $pdoTools */
    public $pdoTools;

    /**
     * @param modX $modx
     * @param array $config
     */
    function __construct(modX &$modx, array $config = array()) {
        $this->modx =& $modx;

        $corePath = $this->modx->getOption('ec_core_path', $config, $this->modx->getOption('core_path') . 'components/easycomm/');
        $assetsUrl = $this->modx->getOption('ec_assets_url', $config, $this->modx->getOption('assets_url') . 'components/easycomm/');
        $connectorUrl = $assetsUrl . 'connector.php';
        $actionUrl = $this->modx->getOption('ec_action_url', $config, $assetsUrl.'action.php');

        $this->config = array_merge(array(
            'assetsUrl' => $assetsUrl,
            'cssUrl' => $assetsUrl . 'css/',
            'jsUrl' => $assetsUrl . 'js/',
            'imagesUrl' => $assetsUrl . 'images/',
            'connectorUrl' => $connectorUrl,
            'actionUrl' => $actionUrl,

            'corePath' => $corePath,
            'modelPath' => $corePath . 'model/',
            'chunksPath' => $corePath . 'elements/chunks/',
            'templatesPath' => $corePath . 'elements/templates/',
            'chunkSuffix' => '.chunk.tpl',
            'snippetsPath' => $corePath . 'elements/snippets/',
            'processorsPath' => $corePath . 'processors/',

            'json_response' => true,
            'nestedChunkPrefix' => 'ec_',
        ), $config);

        $this->modx->addPackage('easycomm', $this->config['modelPath']);
        $this->modx->lexicon->load('easycomm:default');
    }

    /**
     * Initializes component into different contexts.
     *
     * @param string $ctx The context to load. Defaults to web.
     * @param array $scriptProperties
     *
     * @return boolean
     */
    public function initialize($ctx = 'web', $scriptProperties = array()) {
        $this->config = array_merge($this->config, $scriptProperties);
        if (!$this->pdoTools) {$this->loadPdoTools();}
        $this->pdoTools->setConfig($this->config);
        $this->config['ctx'] = $ctx;
        if (!empty($this->initialized[$ctx])) {
            return true;
        }
        switch ($ctx) {
            case 'mgr': break;
            default:
                if (!defined('MODX_API_MODE') || !MODX_API_MODE) {
                    $config = $this->makePlaceholders($this->config);
                    if ($css = $this->modx->getOption('ec_frontend_css')) {
                        $this->modx->regClientCSS(str_replace($config['pl'], $config['vl'], $css));
                    }
                    $config_js = preg_replace(array('/^\n/', '/\t{6}/'), '', '
						easyCommConfig = {
							ctx: "'.$ctx.'"
							,jsUrl: "'.$this->config['jsUrl'].'web/"
							,cssUrl: "'.$this->config['cssUrl'].'web/"
							,actionUrl: "'.$this->config['actionUrl'].'"
						};
					');
                    $this->modx->regClientStartupScript("<script type=\"text/javascript\">\n".$config_js."\n</script>", true);
                    if ($js = trim($this->modx->getOption('ec_frontend_js'))) {
                        if (!empty($js) && preg_match('/\.js/i', $js)) {
                            $this->modx->regClientScript(str_replace($config['pl'], $config['vl'], $js));
                        }
                    }
                }
                $this->initialized[$ctx] = true;
                break;
        }
        return true;
    }

    /**
     * Method for transform array to placeholders
     *
     * @var array $array With keys and values
     * @var string $prefix Prefix for array keys
     *
     * @return array $array Two nested arrays with placeholders and values
     */
    public function makePlaceholders(array $array = array(), $prefix = '') {
        if (!$this->pdoTools) {
            $this->loadPdoTools();
        }
        return $this->pdoTools->makePlaceholders($array, $prefix);
    }

    /**
     * Loads an instance of pdoTools
     *
     * @return boolean
     */
    public function loadPdoTools() {
        if (!is_object($this->pdoTools) || !($this->pdoTools instanceof pdoTools)) {
            /** @var pdoFetch $pdoFetch */
            $fqn = $this->modx->getOption('pdoFetch.class', null, 'pdotools.pdofetch', true);
            if ($pdoClass = $this->modx->loadClass($fqn, '', false, true)) {
                $this->pdoTools = new $pdoClass($this->modx, $this->config);
            }
            elseif ($pdoClass = $this->modx->loadClass($fqn, MODX_CORE_PATH . 'components/pdotools/model/', false, true)) {
                $this->pdoTools = new $pdoClass($this->modx, $this->config);
            }
            else {
                $this->modx->log(modX::LOG_LEVEL_ERROR, 'Could not load pdoFetch from "MODX_CORE_PATH/components/pdotools/model/".');
            }
        }
        return !empty($this->pdoTools) && $this->pdoTools instanceof pdoTools;
    }

    /**
     * Create ecMessage through processor
     *
     * @param array $data $_POST
     *
     * @return array
     */
    public function createMessage($data = array()){
        if(!empty($this->config['antispamField'])) {
            if(!empty($data[$this->config['antispamField']])) {
                return $this->error("ec_fe_spam_detected");
            }
        }

        $requiredFields = array_map('trim', explode(',', $this->config['requiredFields']));
        $requiredFields = array_unique(array_merge($requiredFields, array('thread')));
        $allowedFields = array_map('trim', explode(',', $this->config['allowedFields']));
        $allowedFields = array_unique(array_merge($allowedFields, $requiredFields));

        $fields = array();
        foreach ($allowedFields as $field) {
            if (array_key_exists($field, $data)) {
                $fields[$field] = $this->sanitizeString($data[$field]);
            }
        }

        $fields['requiredFields'] = $requiredFields;

        if (!empty($fields['thread']) && $thread = $this->modx->getObject('ecThread', array('name' => $fields['thread']))) {
            $fields['thread'] = $thread->get('id');
        }

        $response = $this->runProcessor('web/message/create', $fields);

        /* @var modProcessorResponse $response */
        if ($response->isError()) {
            return $this->error($response->getMessage(), $response->getFieldErrors());
        }
        else{
            /* @var ecMessage $message */
            if($message = $this->modx->getObject('ecMessage', $response->response['object']['id'])) {
                $this->sendNewMessageNotification($message->toArray());
            }
        }

        if(!empty($this->config['tplSuccess'])) {
            return $this->success('ec_fe_send_success', $this->getChunk($this->config['tplSuccess'], $response->response['object']));
        }
        return $this->success('ec_fe_send_success', $response->response['object']);
    }

    /**
     * Email notifications about new message
     *
     * @param array $message
     *
     * @return void
     */
    function sendNewMessageNotification($message = array()) {
        /* @var ecThread $thread */
        $thread = $this->modx->getObject('ecThread', $message['thread']);
        if($thread) {
            /* @var modResource $resource */
            $resource = $this->modx->getObject('modResource', $thread->get('resource'));
            if($resource) {
                $messageData = array_merge(
                    $message,
                    $thread->toArray('thread_'),
                    $resource->toArray('resource_')
                );

                // Send a message to the user.
                if($this->modx->getOption('ec_mail_notify_user', null, true) && !empty($this->config['tplNewEmailUser']) && $this->isValidEmail($message['user_email'])) {
                    $this->modx->log(modX::LOG_LEVEL_DEBUG,'easyComm: Send a message to the user');
                    $to = $message['user_email'];
                    $subject = empty($this->config['newEmailSubjUser']) ? $this->modx->getOption('ec_mail_new_subject_user', null, '') : $this->config['newEmailSubjUser'];
                    $subject = $this->getStringAsChunk($subject);
                    $body = $this->getChunk($this->config['tplNewEmailUser'], $messageData);

                    $this->sendEmail($to, $subject, $body);
                }
                // Send a message to the manager.
                if($this->modx->getOption('ec_mail_notify_manager', null, true) && !empty($this->config['tplNewEmailManager'])) {
                    $this->modx->log(modX::LOG_LEVEL_DEBUG,'easyComm: Send a message to the manager');
                    $to = $this->modx->getOption('ec_mail_manager', null, '');
                    if(empty($to)) {
                        $to = $this->modx->getOption('emailsender');
                    }
                    $subject = empty($this->config['newEmailSubjManager']) ? $this->modx->getOption('ec_mail_new_subject_manager', null, '') : $this->config['newEmailSubjManager'];
                    $subject = $this->getStringAsChunk($subject);
                    $body = $this->getChunk($this->config['tplNewEmailManager'], $messageData);

                    $this->sendEmail($to, $subject, $body);
                }
            }
        }
    }

    /**
     * Email notifications about publish message
     *
     * @param array $message
     *
     * @return bool
     */
    public function sendUpdateMessageNotification($message = array()){
        if(empty($message)) {
            return false;
        }

        /* @var ecThread $thread */
        if(!$thread = $this->modx->getObject('ecThread', $message['thread'])){
            return false;
        }
        /* @var modResource $resource*/
        if(!$resource = $thread->getOne('Resource')) {
            return false;
        }
        $properties = $thread->get('properties');
        // Send a message to the user.
        if(!empty($properties['tplUpdateEmailUser']) && $this->isValidEmail($message['user_email'])) {

            $this->modx->log(modX::LOG_LEVEL_DEBUG,'easyComm: Send a message to the user');
            $to = $message['user_email'];
            $subject = empty($properties['updateEmailSubjUser']) ? $this->modx->getOption('ec_mail_update_subject_user', null, '') : $properties['updateEmailSubjUser'];
            $subject = $this->getStringAsChunk($subject);
            $tmp = array(
                'no_reply_and_published' => empty($message['reply_text']) && !empty($message['published']),
                'reply_and_published' => !empty($message['reply_text']) && !empty($message['published']),
                'reply_and_not_published' => !empty($message['reply_text']) && empty($message['published']),
            );
            $body = $this->getChunk($properties['tplUpdateEmailUser'], array_merge(
                $tmp,
                $message,
                $thread->toArray('thread_'),
                $resource->toArray('resource_')
            ));

            $this->sendEmail($to, $subject, $body);
        }
        return true;
    }

    private function getStringAsChunk($string, $props = array()) {
        /* @var modChunk $chunk */
        $chunk = $this->modx->newObject('modChunk');
        $chunk->setCacheable(false);

        return $chunk->process($props, $string);
    }

    private function isValidEmail($email) {
        if(empty($email)) {
            return false;
        }
        if (filter_var($email, FILTER_VALIDATE_EMAIL)) {
            return true;
        }
        return false;
    }
    /**
     * Send email
     *
     * @param string $to
     * @param string $subject
     * @param string $body
     *
     * @return void
     */
    private function sendEmail($to, $subject, $body){
        if(empty($to)) {
            $this->modx->log(modX::LOG_LEVEL_WARN,'easyComm can`t send email, because recipient is blank');
        }
        $mailTo = array_map('trim', explode(',', $to));

        $mail = $this->modx->getService('mail', 'mail.modPHPMailer');
        $mail->setHTML(true);

        $mail->set(modMail::MAIL_SUBJECT, $subject);
        $mail->set(modMail::MAIL_BODY, $body);

        $mail->set(modMail::MAIL_SENDER, $this->modx->getOption('ec_mail_from', null, $this->modx->getOption('emailsender'), true));
        $mail->set(modMail::MAIL_FROM, $this->modx->getOption('ec_mail_from', null, $this->modx->getOption('emailsender'), true));
        $mail->set(modMail::MAIL_FROM_NAME, $this->modx->getOption('ec_mail_from_name', null, $this->modx->getOption('site_name'), true));

        foreach($mailTo as $mto){
            $mail->address('to', $mto);
        }

        if (!$mail->send()) {
            $this->modx->log(modX::LOG_LEVEL_ERROR,'An error occurred while trying to send the email: '.$mail->mailer->ErrorInfo);
        }
        $mail->reset();
    }



    /**
     * Process and return the output from a Chunk by name.
     *
     * @param string $name The name of the chunk.
     * @param array $properties An associative array of properties to process the Chunk with, treated as placeholders within the scope of the Element.
     * @param boolean $fastMode If false, all MODX tags in chunk will be processed.
     *
     * @return string The processed output of the Chunk.
     */
    public function getChunk($name, array $properties = array(), $fastMode = false) {
        if (!$this->modx->parser) {
            $this->modx->getParser();
        }
        if (!$this->pdoTools) {
            $this->loadPdoTools();
        }
        return $this->pdoTools->getChunk($name, $properties, $fastMode);
    }

    /**
     * Shorthand for the call of processor
     *
     * @access public
     * @param string $action Path to processor
     * @param array $data Data to be transmitted to the processor
     * @return mixed The result of the processor
     */
    public function runProcessor($action = '', $data = array()) {
        if (empty($action)) {return false;}
        return $this->modx->runProcessor($action, $data, array('processors_path' => $this->config['processorsPath']));
    }

    /**
     * Sanitize MODX tags
     *
     * @param string $string Any string with MODX tags
     *
     * @return string String with html entities
     */
    public function sanitizeString($string = '') {
        $string = htmlentities(trim($string), ENT_QUOTES, "UTF-8");
        $string = preg_replace('/^@.*\b/', '', $string);
        $arr1 = array('[',']','`');
        $arr2 = array('&#091;','&#093;','&#096;');
        return str_replace($arr1, $arr2, $string);
    }


    /**
     * This method returns an error of the cart
     *
     * @param string $message A lexicon key for error message
     * @param array $data.Additional data, for example cart status
     * @param array $placeholders Array with placeholders for lexicon entry
     *
     * @return array|string $response
     */
    public function error($message = '', $data = array(), $placeholders = array()) {
        $response = array(
            'success' => false
        ,'message' => $this->modx->lexicon($message, $placeholders)
        ,'data' => $data
        );
        return $this->config['json_response']
            ? $this->modx->toJSON($response)
            : $response;
    }

    /* This method returns an success of the action
	 *
	 * @param string $message A lexicon key for success message
	 * @param array $data.Additional data, for example cart status
	 * @param array $placeholders Array with placeholders for lexicon entry
	 *
	 * @return array|string $response
	 * */
    public function success($message = '', $data = array(), $placeholders = array()) {
        $response = array(
            'success' => true
        ,'message' => $this->modx->lexicon($message, $placeholders)
        ,'data' => $data
        );
        return $this->config['json_response']
            ? $this->modx->toJSON($response)
            : $response;
    }
}