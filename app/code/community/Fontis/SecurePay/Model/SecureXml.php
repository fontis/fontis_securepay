<?php
/**
 * Fontis SecurePay Extension
 *
 * NOTICE OF LICENSE
 *
 * This source file is subject to the Open Software License (OSL 3.0)
 * that is available through the world-wide-web at this URL:
 * http://opensource.org/licenses/osl-3.0.php
 * If you are unable to obtain it through the world-wide-web, please send
 * an email to license@magentocommerce.com so you can be sent a copy.
 *
 * @category   Fontis
 * @package    Fontis_SecurePay
 * @author     Chris Norton
 * @copyright  Copyright (c) 2013 Fontis Pty. Ltd. (http://www.fontis.com.au)
 * @license    http://opensource.org/licenses/osl-3.0.php  Open Software License (OSL 3.0)
 */
 
class Fontis_SecurePay_Model_SecureXml extends Mage_Payment_Model_Method_Cc
{

    protected $_code  = 'secureXml';

    protected $_isGateway               = true;
    protected $_canAuthorize            = false;
    protected $_canCapture              = true;
    protected $_canCapturePartial       = false;
    protected $_canRefund               = false;
    protected $_canVoid                 = false;
    protected $_canUseInternal          = true;
    protected $_canUseCheckout          = true;
    protected $_canUseForMultishipping  = true;
    protected $_canSaveCc               = false;
    
    // Credit Card URLs
    const CC_URL_LIVE = 'https://www.securepay.com.au/xmlapi/payment';
    const CC_URL_TEST = 'https://www.securepay.com.au/test/payment';
    
    // Direct Entry URLs
    const DE_URL_LIVE = 'https://www.securepay.com.au/xmlapi/directentry';
    const DE_URL_TEST = 'https://www.securepay.com.au/test/directentry';
    
    
    const STATUS_APPROVED = 'Approved';

	const PAYMENT_ACTION_AUTH_CAPTURE = 'authorize_capture';
	const PAYMENT_ACTION_AUTH = 'authorize';

	/**
	 * Returns the URL to send requests to. This changes depending on whether
	 * the extension is set to testing mode or not.
	 */
	public function getGatewayUrl()
	{
		if(Mage::getStoreConfig('payment/secureXml/test'))
		{
			return self::CC_URL_TEST;
		}
		else
		{
			return self::CC_URL_LIVE;
		}
	}
	
	public function getDebug()
	{
		return Mage::getStoreConfig('payment/secureXml/debug');
	}
	
	public function getLogPath()
	{
		return Mage::getBaseDir() . '/var/log/secureXml.log';
	}
	
	public function getUsername()
	{
		return Mage::getStoreConfig('payment/secureXml/username');
	}
	
	public function getPassword()
	{
		return Mage::getStoreConfig('payment/secureXml/password');
	}

	/**
	 *
	 */
	public function validate()
    {
    	if($this->getDebug())
		{
	    	$writer = new Zend_Log_Writer_Stream($this->getLogPath());
			$logger = new Zend_Log($writer);
			$logger->info("entering validate()");
		}
		
        parent::validate();
        $paymentInfo = $this->getInfoInstance();
        if ($paymentInfo instanceof Mage_Sales_Model_Order_Payment) {
            $currency_code = $paymentInfo->getOrder()->getBaseCurrencyCode();
        } else {
            $currency_code = $paymentInfo->getQuote()->getBaseCurrencyCode();
        }
        return $this;
    }

	public function authorize(Varien_Object $payment, $amount)
	{
		if($this->getDebug())
		{
			$writer = new Zend_Log_Writer_Stream($this->getLogPath());
			$logger = new Zend_Log($writer);
			$logger->info("entering authorize()");
		}
	}
	
	/**
	 *
	 */
	public function capture(Varien_Object $payment, $amount)
	{
		if($this->getDebug())
		{
			$writer = new Zend_Log_Writer_Stream($this->getLogPath());
			$logger = new Zend_Log($writer);
			$logger->info("entering capture()");
		}
	
		$this->setAmount($amount)
			->setPayment($payment);

		$result = $this->_call($payment);
		
		if($this->getDebug()) { $logger->info(var_export($result, TRUE)); }

		if($result === false)
		{
			$e = $this->getError();
			if (isset($e['message'])) {
				$message = Mage::helper('securePay')->__('There has been an error processing your payment.') . $e['message'];
			} else {
				$message = Mage::helper('securePay')->__('There has been an error processing your payment. Please try later or contact us for help.');
			}
			Mage::throwException($message);
		}
		else
		{
			// Check if there is a gateway error
			if ($result['Status']['statusCode'] == "000")
			{
				// Check if there is an error processing the credit card
				if($result['Payment']['TxnList']['Txn']['approved'] == "Yes")
				{
					$payment->setStatus(self::STATUS_APPROVED)
						->setLastTransId($this->getTransactionId());
				}
				else
				{
					Mage::throwException("Bank error code " . $result['Payment']['TxnList']['Txn']['responseCode'] . ": " . $result['Payment']['TxnList']['Txn']['responseText']);
				}

			}
			else
			{
				Mage::throwException("Gateway error code " . $result['Status']['statusCode'] . ": " . $result['Status']['statusDescription']);
			}
		}
		return $this;
	}

	/**
	 *
	 */
	protected function _call(Varien_Object $payment)
	{
		if($this->getDebug())
		{
			$writer = new Zend_Log_Writer_Stream($this->getLogPath());
			$logger = new Zend_Log($writer);
			$logger->info("entering _call()");
		}
		
		// Generate any needed values
		
		// Create expiry dat in format "MM/YY"
		$date_expiry = str_pad($payment->getCcExpMonth(), 2, '0', STR_PAD_LEFT) . '/' . 
						substr($payment->getCcExpYear(), 2, 2);
		
		
		$transaction_id = $payment->getOrder()->getIncrementId();
		
		$timestamp = time();
		$timezone = trim(sprintf("%+04d", (int)date("Z") / 60));
		$messageTimestamp = date('YdmHis', $timestamp) . // YYYYDDMMHHNNSS
							'000' .  				// milliseconds
							'000' . 				// microseconds
							$timezone;
		
		// Most currency have two minor units (e.g. cents) and thus need to be
		// multiplied by 100 to get the correct number to send.
		$amount = $this->getAmount() * 100;
		
		// Several currencies do not have minor units and thus should not be
		// multiplied.
		if($payment->getOrder()->getBaseCurrencyCode() == 'JPY' ||
		   $payment->getOrder()->getBaseCurrencyCode() == 'ITL' ||
		   $payment->getOrder()->getBaseCurrencyCode() == 'GRD')
		{
			$amount = $amount / 100;
		}
		
		if($this->getDebug())
		{
			$logger->info( var_export($payment->getOrder()->getData(), TRUE) );
		}
		
		// Build the XML request
		$doc = new SimpleXMLElement('<SecurePayMessage></SecurePayMessage>');
		
		$messageInfo = $doc->addChild('MessageInfo');
		$messageInfo->addChild('messageID', substr(md5($transaction_id . $timestamp), 0, 30));
		$messageInfo->addChild('messageTimestamp', htmlentities($messageTimestamp));
		$messageInfo->addChild('timeoutValue', htmlentities('60'));
		$messageInfo->addChild('apiVersion', htmlentities('xml-4.2'));
		
		$merchantInfo = $doc->addChild('MerchantInfo');
		$merchantInfo->addChild('merchantID', htmlentities( $this->getUsername() ));
		$merchantInfo->addChild('password', htmlentities( $this->getPassword() ));
		
		$doc->addChild('RequestType', 'Payment');
		
		// Currently SecurePay only allows one transaction per request
		$txnList = $doc->addChild('Payment')->addChild('TxnList');
		$txnList->addAttribute('count', '1');
		
		//htmlentities($transaction_id)
		$txn = $txnList->addChild('Txn');
		$txn->addAttribute('ID', '1');	// Currently must be set to "1"
		$txn->addChild('txnType', '0'); // Set to "0" for payment
		$txn->addChild('txnSource', '23'); // Set to "23" for SecureXML
		$txn->addChild('amount', htmlentities($amount));
		$txn->addChild('currency', htmlentities($payment->getOrder()->getBaseCurrencyCode()));
		$txn->addChild('purchaseOrderNo', htmlentities($transaction_id));
		
		$cc = $txn->addChild('CreditCardInfo');
		$cc->addChild('cardNumber', htmlentities($payment->getCcNumber()));
		$cc->addChild('cvv', htmlentities($payment->getCcCid()));
		$cc->addChild('expiryDate', htmlentities($date_expiry));

		$xml = $doc->asXML();
		
		// DEBUG
		if($this->getDebug()) { $logger->info($xml); }
		
		// Send the data via HTTP POST and get the response
		$http = new Varien_Http_Adapter_Curl();
		$http->setConfig(array('timeout' => 30));
		
		$http->write(Zend_Http_Client::POST, $this->getGatewayUrl(), '1.1', array(), $xml);
		
		$response = $http->read();
		
		if ($http->getErrno()) {
			$http->close();
			$this->setError(array(
				'message' => $http->getError()
			));
			return false;
		}
		
		// DEBUG
		if($this->getDebug()) { 
			//$logger->info($response); 
		}
        
        $http->close();

		// Strip out header tags
        $response = preg_split('/^\r?$/m', $response, 2);
        $response = trim($response[1]);
		
		// Parse the XML object
		$xmlObj = simplexml_load_string($response);

		// Build an associative array with returned values		
		$result = array();
		$result['Status'] = array();
		$result['Payment'] = array('TxnList' => array('Txn' => array()));

		$xpath = $xmlObj->xpath('/SecurePayMessage/Status/statusCode');
		$result['Status']['statusCode'] = ($xpath !== FALSE && isset($xpath[0])) ? (string)$xpath[0] : '';
		
		$xpath = $xmlObj->xpath('/SecurePayMessage/Status/statusDescription');
		$result['Status']['statusDescription'] = ($xpath !== FALSE && isset($xpath[0])) ? (string)$xpath[0] : '';
		
		$xpath = $xmlObj->xpath('/SecurePayMessage/Payment/TxnList/Txn/approved');
		$result['Payment']['TxnList']['Txn']['approved'] = ($xpath !== FALSE && isset($xpath[0])) ? (string)$xpath[0] : '';
		
		$xpath = $xmlObj->xpath('/SecurePayMessage/Payment/TxnList/Txn/responseCode');
		$result['Payment']['TxnList']['Txn']['responseCode'] = ($xpath !== FALSE && isset($xpath[0])) ? (string)$xpath[0] : '';
		
		$xpath = $xmlObj->xpath('/SecurePayMessage/Payment/TxnList/Txn/responseText');
		$result['Payment']['TxnList']['Txn']['responseText'] = ($xpath !== FALSE && isset($xpath[0])) ? (string)$xpath[0] : '';		
				
		return $result;
	}
}
