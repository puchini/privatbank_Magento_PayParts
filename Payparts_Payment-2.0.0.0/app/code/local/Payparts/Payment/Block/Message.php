<?php
/**
 * Payparts notification "form"
 */
class Payparts_Payment_Block_Message extends Mage_Payment_Block_Form
{
    /**
     * Set template with message
     */
    protected function _construct()
    {
        parent::_construct();
        $this->setTemplate('payparts/message.phtml');
    }
}