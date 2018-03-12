<?php

class Payparts_Payment_Block_Redirect extends Mage_Core_Block_Template {

  /**
   * Set template with message
   */
  protected function _construct() {
    $this->setTemplate('payparts/redirect.phtml');
    parent::_construct();
  }

  /**
   * Return redirect form
   *
   * @return Varien_Data_Form
   */
  public function getForm() {


    $paymentMethod = Mage::getModel('payparts/redirect');

    $html = '<form method="POST" action="' . $paymentMethod->getPaypartsPlaceUrl() . '" id="payparts_redirect" name="payparts_redirect">';
    foreach ($paymentMethod->getRedirectFormFields() as $key => $value) {
      $html .= '<input type="hidden" name="' . $key . '" value="' . $value . '">';
    }
    $html .=  '<input type="submit" value="' . $this->__("Click here, if not redirected for 30 seconds.") . '">';
    $html .= '</form>';

    return $html;
  }

}