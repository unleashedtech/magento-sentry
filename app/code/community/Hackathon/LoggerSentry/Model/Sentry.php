<?php

set_include_path(get_include_path() . PATH_SEPARATOR . realpath(Mage::getBaseDir() .DS.'lib'.DS.'sentry'.DS.'sentry'.DS.'lib'));

class Hackathon_LoggerSentry_Model_Sentry extends Zend_Log_Writer_Abstract
{
    /**
     * @var array
     */
    protected $_options = array();

    /**
     * sentry client
     *
     * @var Raven_Client
     */
    protected $_sentryClient;

    protected $_priorityToLevelMapping
        = array(
            0 => 'fatal',
            1 => 'fatal',
            2 => 'fatal',
            3 => 'error',
            4 => 'warning',
            5 => 'info',
            6 => 'info',
            7 => 'debug'
        );

    /**
     *
     *
     * ignore filename - it is Zend_Log_Writer_Abstract dependency
     *
     * @param string $filename
     */
    public function __construct($filename)
    {
        /* @var $helper FireGento_Logger_Helper_Data */
        $helper = Mage::helper('firegento_logger');
        $options = array(
            'logger' => $helper->getLoggerConfig('sentry/logger_name'),
            'environment' => $helper->getLoggerConfig('sentry/environment') ?: null,
        );
        try {
            $this->_sentryClient = new Raven_Client($helper->getLoggerConfig('sentry/apikey'), $options);
        } catch (Exception $e) {
            // Ignore errors so that it doesn't crush the website when/if Sentry goes down.
        }

    }

    /**
     * Places event line into array of lines to be used as message body.
     *
     * @param FireGento_Logger_Model_Event $event Event data
     *
     * @throws Zend_Log_Exception
     * @return void
     */
    protected function _write($eventObj)
    {
        try {
            /* @var $helper FireGento_Logger_Helper_Data */
            $helper = Mage::helper('firegento_logger');
            $helper->addEventMetadata($eventObj);

            $event = $eventObj->getEventDataArray();

            $additional = array(
                'file' => $event['file'],
                'line' => $event['line'],
            );

            foreach (array('REQUEST_METHOD', 'REQUEST_URI', 'REMOTE_IP', 'HTTP_USER_AGENT') as $key) {
                if (!empty($event[$key])) {
                    $additional[$key] = $event[$key];
                }
            }

            if (!empty($eventObj->getStoreCode())) {
                $additional['store_code'] = $eventObj->getStoreCode();
            }

            $this->_assumePriorityByMessage($event);

            // if we still can't figure it out, assume it's an error
            $priority = !empty($event['priority']) ? $event['priority'] : 3;

            if (!$this->_isHighEnoughPriorityToReport($priority)) {
                return; // Don't log anything warning or less severe.
            }

            $this->_addUserContext($eventObj);

            $stack = $this->_getStackTrace($event);

            $this->_sentryClient->captureMessage(
                $event['message'], array(), $this->_priorityToLevelMapping[$priority], $stack, $additional
            );

        } catch (Exception $e) {
            throw new Zend_Log_Exception($e->getMessage(), $e->getCode());
        }
    }

    /**
     * @param  int  $priority 
     * @return boolean           True if we should be reporting this, false otherwise.
     */
    protected function _isHighEnoughPriorityToReport($priority)
    {
        return $priority <= (int)Mage::helper('firegento_logger')->getLoggerConfig('sentry/priority');
    }

    /**
     * Try to attach a priority # based on the error message string (since sometimes it is not specified)
     * @param FireGento_Logger_Model_Event &$event Event data
     * @return \Hackathon_LoggerSentry_Model_Sentry
     */
    protected function _assumePriorityByMessage(&$event)
    {
        if (stripos($event['message'], "warn") === 0) {
            $event['priority'] = 4;
        }
        if (stripos($event['message'], "notice") === 0) {
            $event['priority'] = 5;
        }

        return $this;
    }

    protected function _addUserContext($eventObj)
    {
        if (empty($_SESSION)) {
            return;
        }

        // Include admin information if available
        $data = array_filter(array(
            'admin_user_id' => $eventObj->getAdminUserId(),
            'admin_user_name' => $eventObj->getAdminUserName(),
        ));

        // Include customer id and group
        $customerSession = Mage::getSingleton('customer/session');
        $data['customer_group_id'] = $customerSession->getCustomerGroupId();
        if ($customerSession->isLoggedIn()) {
            $data['customer_id'] = $customerSession->getCustomer()->getId();
        } else {
            $data['customer_id'] = 'guest';
        }

        // Include shopping cart id
        $checkoutSession = Mage::getSingleton('checkout/session');
        if ($checkoutSession->hasQuote()) {
            $data['quote_id'] = $checkoutSession->getQuoteId();
        }

        if (!empty($data)) {
            $this->_sentryClient->user_context($data);
        }
    }

    /**
     * @param array $event
     *
     * @return array
     */
    protected function _getStackTrace($event)
    {
        $stack = debug_backtrace();
        // Remove the call to this _getStackTrace() function
        array_shift($stack);

        if (isset($event['file']) && isset($event['line'])) {
            // "Unwind" the stack until we find where this was actually triggered from
            foreach ($stack as $i => $item) {
                if ($item['line'] === $event['line'] && strpos($item['file'], $event['file']) !== false) {
                    return array_slice($stack, $i);
                }
            }
        }

        return $stack;
    }

    /**
     * Satisfy newer Zend Framework
     *
     * @static
     *
     * @param $config
     *
     * @return void
     */
    static public function factory($config)
    {
    }
}
