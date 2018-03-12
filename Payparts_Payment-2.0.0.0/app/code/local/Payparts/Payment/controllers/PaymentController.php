<?php

class Payparts_Payment_PaymentController extends Mage_Core_Controller_Front_Action
{

    /**
     * Redirect to PayParts
     */
    public function redirectAction()
    {

        $session = Mage::getSingleton('checkout/session');
        $session->setPaypartsQuoteId($session->getQuoteId());

        $this->getResponse()->setBody($this->getLayout()->createBlock('payparts/redirect')->toHtml());
        $session->pay_parts_paym_state = false;
        $session->unsQuoteId();
        $session->unsRedirectUrl();
    }

    /**
     * When a customer cancel payment from PayParts.
     */
    public function failAction()
    {
        $session = Mage::getSingleton('checkout/session');
        $session->setQuoteId($session->getPaypartsQuoteId());
        if ($session->getLastRealOrderId()) {
            $order = Mage::getModel('sales/order')->loadByIncrementId($session->getLastRealOrderId());
            if ($order->getId()) {
                $order->cancel()->save();
            }

        }

        $quote = Mage::getModel('sales/quote')->load($session->getPaypartsQuoteId());
        if ($quote->getId()) {
            $quote->setActive(true);
            $quote->save();
        }
        $session->addError(Mage::helper('payparts')->__('Payment failed. Pleas try again later.'));
        if($session->pay_parts_paym_state){
            $session->addError(Mage::helper('payparts')->__('Payment is held successfully.'));
        }

        $this->_redirect('checkout/cart');
    }

    /**
     * Customer return processing
     */
    public function returnAction()
    {
        try {
            $session = Mage::getSingleton('checkout/session');
            $postData = Mage::helper('core')->jsonDecode(Mage::app()->getRequest()->getRawBody());
            $order = Mage::getModel('sales/order')->loadByIncrementId($session->getLastRealOrderId());
            if($postData['paymentState'] == 'SUCCESS'){
                $session->pay_parts_paym_state = true;
                Mage::getSingleton('checkout/session')->getQuote()->setIsActive(false)->save();
                $order->setStatus('payparts_paid');
                $order->setState('payparts_paid');
                $order->save();
            }
            else if($postData['paymentState'] == 'CANCELED'){
                $order->cancel()->save();
            }
            $this->_redirect('checkout/onepage/success');
            return;
        } catch (Mage_Core_Exception $e) {
            $this->_getCheckout()->addError($e->getMessage());
        } catch(Exception $e) {
            Mage::logException($e);
        }
        $this->_redirect('checkout/cart');
    }

    /**
     * Background notifications
     */
    public function notifyAction()
    {
        $session = Mage::getSingleton('checkout/session');
        $request = Mage::app()->getRequest()->getPost();
        $session->pattern = Mage::app()->getRequest()->getRawBody();
        parse_str(Mage::app()->getRequest()->getRawBody(), $parts_params);

        if($parts_params['parts']){
            $session->parts = $parts_params['parts'];
            $session->system = $parts_params['system'];
        }
        $paymentMethod = Mage::getModel('payparts/redirect');
        if (!$paymentMethod->validateRequest($request)) {
            return;
        }

        $order = Mage::getModel('sales/order')->loadByIncrementId($request['OrderId']);
        $order->setState(Mage_Sales_Model_Order::STATE_PROCESSING);
        $order->setStatus('processing');
        $order->setIsNotified(false);
        $order->save();
        echo 'OK ' . $request['OrderId'];
    }
}