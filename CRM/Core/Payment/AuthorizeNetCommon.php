<?php
/**
 * https://civicrm.org/licensing
 */

use CRM_AuthNetEcheck_ExtensionUtil as E;
use net\authorize\api\contract\v1 as AnetAPI;
use net\authorize\api\controller as AnetController;
use net\authorize\api\constants\ANetEnvironment as AnetEnvironment;

abstract class CRM_Core_Payment_AuthorizeNetCommon extends CRM_Core_Payment {

  use CRM_Core_Payment_AuthorizeNetTrait;

  const RESPONSECODE_APPROVED = 1;
  const RESPONSECODE_DECLINED = 2;
  const RESPONSECODE_ERROR = 3;
  const RESPONSECODE_REVIEW = 4;

  /**
   * @fixme: Confirm that this is the correct "timezone" - we copied this from the original core Authorize.net processor.
   * @var string
   */
  const TIMEZONE = 'America/Denver';

  /**
   * Constructor
   *
   * @param string $mode the mode of operation: live or test
   *
   * @return void
   */
  function __construct($mode, &$paymentProcessor) {
    $this->_paymentProcessor = $paymentProcessor;
    $this->setParam('apiLoginID', self::getApiLoginId($paymentProcessor));
    $this->setParam('transactionKey', self::getTransactionKey($paymentProcessor));
  }

  /**
   * @param array $paymentProcessor
   *
   * @return string
   */
  public static function getApiLoginId($paymentProcessor) {
    return trim(CRM_Utils_Array::value('user_name', $paymentProcessor));
  }

  /**
   * @param array $paymentProcessor
   *
   * @return string
   */
  public static function getTransactionKey($paymentProcessor) {
    return trim(CRM_Utils_Array::value('password', $paymentProcessor));
  }

  /**
   * @param array $paymentProcessor
   *
   * @return string
   */
  public static function getSignature($paymentProcessor) {
    return trim(CRM_Utils_Array::value('signature', $paymentProcessor));
  }

  /**
   * This function checks to see if we have the right config values.
   *
   * @return string
   *   the error message if any
   */
  public function checkConfig() {
    $error = [];
    if (!empty($error)) {
      return implode('<p>', $error);
    }
    else {
      return NULL;
    }
  }

  /**
   * Should the first payment date be configurable when setting up back office recurring payments.
   * In the case of Authorize.net this is an option
   * @return bool
   */
  protected function supportsFutureRecurStartDate() {
    return TRUE;
  }

  /**
   * Can recurring contributions be set against pledges.
   *
   * In practice all processors that use the baseIPN function to finish transactions or
   * call the completetransaction api support this by looking up previous contributions in the
   * series and, if there is a prior contribution against a pledge, and the pledge is not complete,
   * adding the new payment to the pledge.
   *
   * However, only enabling for processors it has been tested against.
   *
   * @return bool
   */
  protected function supportsRecurContributionsForPledges() {
    return TRUE;
  }

  /**
   * We can use the processor on the backend
   * @return bool
   */
  public function supportsBackOffice() {
    return TRUE;
  }

  /**
   * We can edit recurring contributions
   * @return bool
   */
  public function supportsEditRecurringContribution() {
    return TRUE;
  }

  /**
   * Submit a payment using Advanced Integration Method.
   *
   * Sets appropriate parameters and calls Smart Debit API to create a payment
   *
   * Payment processors should set payment_status_id.
   *
   * @param array $params
   *   Assoc array of input parameters for this transaction.
   *
   * @param string $component
   *
   * @return array
   *   Result array
   *
   * @throws \CiviCRM_API3_Exception
   */
  public function doPayment(&$params, $component = 'contribute') {
    $params = $this->beginDoPayment($params);

    if (!empty($params['is_recur']) && !empty($params['contributionRecurID'])) {
      $this->doRecurPayment();
      return $params;
    }

    // Authorize.Net will not refuse duplicates, so we should check if the user already submitted this transaction
    if ($this->checkDupe($this->getInvoiceNumber(), CRM_Utils_Array::value('contributionID', $params))) {
      $this->handleError(
        9004,
        E::ts('It appears that this transaction is a duplicate.  Have you already submitted the form once?  If so there may have been a connection problem.  Check your email for a receipt from Authorize.net.  If you do not receive a receipt within 2 hours you can try your transaction again.  If you continue to have problems please contact the site administrator.'),
        $params['error_url']
      );
    }

    $merchantAuthentication = $this->getMerchantAuthentication();
    $order = $this->getOrder();

    $customerData = $this->getCustomerDataType();
    $customerAddress = $this->getCustomerAddress();

    //create a bank debit transaction
    $transactionRequestType = new AnetAPI\TransactionRequestType();
    $transactionRequestType->setTransactionType("authCaptureTransaction");
    $transactionRequestType->setAmount($this->getParam('amount'));
    $transactionRequestType->setCurrencyCode($this->getCurrency($params));
    $transactionRequestType->setPayment($this->getPaymentDetails());
    $transactionRequestType->setOrder($order);
    $transactionRequestType->setBillTo($customerAddress);
    $transactionRequestType->setCustomer($customerData);
    $request = new AnetAPI\CreateTransactionRequest();
    $request->setMerchantAuthentication($merchantAuthentication);
    $request->setRefId($this->getInvoiceNumber());
    $request->setTransactionRequest($transactionRequestType);
    $controller = new AnetController\CreateTransactionController($request);
    /** @var \net\authorize\api\contract\v1\CreateTransactionResponse $response */
    $response = $controller->executeWithApiResponse($this->getIsTestMode() ? AnetEnvironment::SANDBOX : AnetEnvironment::PRODUCTION);

    if (!$response || !$response->getMessages()) {
      $this->handleError(NULL, 'No response returned', $params['error_url']);
    }

    $tresponse = $response->getTransactionResponse();

    if ($response->getMessages()->getResultCode() == "Ok") {
      if (!$tresponse) {
        $this->handleError(NULL, 'No transaction response returned', $params['error_url']);
      }

      $this->setPaymentProcessorInvoiceID($tresponse->getTransId());

      switch ($tresponse->getResponseCode()) {
        case self::RESPONSECODE_APPROVED:
          $params['contribution_status_id'] = CRM_Core_PseudoConstant::getKey('CRM_Contribute_BAO_Contribution', 'contribution_status_id', 'Completed');
          break;

        case self::RESPONSECODE_DECLINED:
        case self::RESPONSECODE_ERROR:
        if ($tresponse->getErrors()) {
          $this->handleError($tresponse->getErrors()[0]->getErrorCode(), $tresponse->getErrors()[0]->getErrorText(), $params['error_url']);
        }
        else {
          $this->handleError(NULL, 'Transaction Failed', $params['error_url']);
        }
        $params['contribution_status_id'] = CRM_Core_PseudoConstant::getKey('CRM_Contribute_BAO_Contribution', 'contribution_status_id', 'Failed');
          break;

        case self::RESPONSECODE_REVIEW:
          // Keep it in pending state
          break;

      }
    }
    else {
      // resultCode !== 'Ok'
      $errorCode = NULL;
      if ($tresponse && $tresponse->getErrors()) {
        foreach ($tresponse->getErrors() as $tError) {
          $errorCode = $tError->getErrorCode();
          switch ($errorCode) {
            case '39':
              $errorMessages[] = $errorCode . ': ' . $tError->getErrorText() . ' (' . $this->getCurrency($params) . ')';
              break;

            default:
              $errorMessages[] = $errorCode . ': ' . $tError->getErrorText();
          }
        }
        $errorMessage = implode(', ', $errorMessages);
      }
      elseif ($response->getMessages()) {
        foreach ($response->getMessages()->getMessage() as $rError) {
          $errorMessages[] = $rError->getCode() . ': ' . $rError->getText();
        }
        $errorMessage = implode(', ', $errorMessages);
      }
      else {
        $errorCode = NULL;
        $errorMessage = NULL;
      }
      $this->handleError($errorCode, $errorMessage, $params['error_url']);
    }

    return $this->endDoPayment($params);
  }

  /**
   * Get the merchant authentication for AuthNet
   *
   * @return \net\authorize\api\contract\v1\MerchantAuthenticationType
   */
  protected function getMerchantAuthentication() {
    // Create a merchantAuthenticationType object with authentication details
    $merchantAuthentication = new AnetAPI\MerchantAuthenticationType();
    $merchantAuthentication->setName($this->getParam('apiLoginID'));
    $merchantAuthentication->setTransactionKey($this->getParam('transactionKey'));
    return $merchantAuthentication;
  }

  /**
   * Get the customer address for AuthNet
   *
   * @return \net\authorize\api\contract\v1\CustomerAddressType
   */
  protected function getCustomerAddress() {
    // Set the customer's Bill To address
    $customerAddress = new AnetAPI\CustomerAddressType();
    !empty($this->getParam('billing_first_name')) ? $customerAddress->setFirstName($this->getParam('billing_first_name')) : NULL;
    !empty($this->getParam('billing_last_name')) ? $customerAddress->setLastName($this->getParam('billing_last_name')) : NULL;
    $customerAddress->setAddress($this->getParam('street_address'));
    $customerAddress->setCity($this->getParam('city'));
    $customerAddress->setState($this->getParam('state_province'));
    $customerAddress->setZip($this->getParam('postal_code'));
    $customerAddress->setCountry($this->getParam('country'));
    return $customerAddress;
  }

  /**
   * Get the customer data for AuthNet
   *
   * @return \net\authorize\api\contract\v1\CustomerDataType
   */
  protected function getCustomerDataType() {
    // Set the customer's identifying information
    $customerData = new AnetAPI\CustomerDataType();
    $customerData->setType('individual');
    $customerData->setId($this->getContactId($this->_params));
    $customerData->setEmail($this->getBillingEmail($this->_params, $this->getContactId($this->_params)));
    return $customerData;
  }

  /**
   * Get the customer data for AuthNet
   *
   * @return \net\authorize\api\contract\v1\CustomerType
   */
  protected function getCustomerType() {
    // Set the customer's identifying information
    $customerData = new AnetAPI\CustomerType();
    $customerData->setType('individual');
    $customerData->setId($this->getContactId($this->_params));
    $customerData->setEmail($this->getBillingEmail($this->_params, $this->getContactId($this->_params)));
    return $customerData;
  }

  /**
   * Get the order for AuthNet
   *
   * @return \net\authorize\api\contract\v1\OrderType
   */
  protected function getOrder() {
    // Order info
    $order = new AnetAPI\OrderType();
    $order->setInvoiceNumber($this->getInvoiceNumber());
    $order->setDescription($this->getParam('description'));
    return $order;
  }

  /**
   * Get the recurring payment interval for AuthNet
   *
   * @return \net\authorize\api\contract\v1\PaymentScheduleType\IntervalAType
   */
  protected function getRecurInterval() {
    $intervalLength = $this->getParam('frequency_interval');
    $intervalUnit = $this->getParam('frequency_unit');
    if ($intervalUnit == 'week') {
      $intervalLength *= 7;
      $intervalUnit = 'days';
    }
    elseif ($intervalUnit == 'year') {
      $intervalLength *= 12;
      $intervalUnit = 'months';
    }
    elseif ($intervalUnit == 'day') {
      $intervalUnit = 'days';
    }
    elseif ($intervalUnit == 'month') {
      $intervalUnit = 'months';
    }

    // interval cannot be less than 7 days or more than 1 year
    if ($intervalUnit == 'days') {
      if ($intervalLength < 7) {
        $this->handleError(NULL, 'Payment interval must be at least one week', $this->getParam('error_url'));
      }
      elseif ($intervalLength > 365) {
        $this->handleError(NULL, 'Payment interval may not be longer than one year', $this->getParam('error_url'));
      }
    }
    elseif ($intervalUnit == 'months') {
      if ($intervalLength < 1) {
        $this->handleError(NULL, 'Payment interval must be at least one week', $this->getParam('error_url'));
      }
      elseif ($intervalLength > 12) {
        $this->handleError(NULL, 'Payment interval may not be longer than one year', $this->getParam('error_url'));
      }
    }

    $interval = new AnetAPI\PaymentScheduleType\IntervalAType();
    $interval->setLength($intervalLength);
    $interval->setUnit($intervalUnit);
    return $interval;
  }

  /**
   * Get the payment schedule for AuthNet
   *
   * @param $interval
   *
   * @return \net\authorize\api\contract\v1\PaymentScheduleType
   */
  protected function getRecurSchedule($interval = NULL) {
    $firstPaymentDate = $this->getParam('receive_date');
    if (!empty($firstPaymentDate)) {
      //allow for post dated payment if set in form
      $startDate = date_create($firstPaymentDate);
    }
    else {
      $startDate = date_create();
    }
    /* Format start date in Mountain Time to avoid Authorize.net error E00017
     * we do this only if the day we are setting our start time to is LESS than the current
     * day in mountaintime (ie. the server time of the A-net server). A.net won't accept a date
     * earlier than the current date on it's server so if we are in PST we might need to use mountain
     * time to bring our date forward. But if we are submitting something future dated we want
     * the date we entered to be respected
     */
    $minDate = date_create('now', new DateTimeZone(self::TIMEZONE));
    if (strtotime($startDate->format('Y-m-d')) < strtotime($minDate->format('Y-m-d'))) {
      $startDate->setTimezone(new DateTimeZone(self::TIMEZONE));
    }

    $installments = $this->getParam('installments');

    // for open ended subscription totalOccurrences has to be 9999
    $installments = empty($installments) ? 9999 : $installments;

    $paymentSchedule = new AnetAPI\PaymentScheduleType();
    if ($interval) {
      $paymentSchedule->setInterval($interval);
    }
    $paymentSchedule->setStartDate($startDate);
    $paymentSchedule->setTotalOccurrences($installments);
    return $paymentSchedule;
  }

  /**
   * Get the trxn_id from the recurring contribution
   *
   * @param array $params
   *
   * @return string
   * @throws \CiviCRM_API3_Exception
   */
  protected function getSubscriptionId($params) {
    $recurId = $this->getRecurringContributionId($params);
    return (string) civicrm_api3('ContributionRecur', 'getvalue', ['id' => $recurId, 'return' => 'trxn_id']);
  }

  /**
   * Submit an Automated Recurring Billing subscription.
   *
   * @return bool
   * @throws \CiviCRM_API3_Exception
   */
  protected function doRecurPayment() {
    $params = $this->_params;

    $merchantAuthentication = $this->getMerchantAuthentication();
    // Subscription Type Info
    $subscription = new AnetAPI\ARBSubscriptionType();
    $subscription->setName($this->getPaymentDescription($params));
    $interval = $this->getRecurInterval();
    $paymentSchedule = $this->getRecurSchedule($interval);

    $subscription->setPaymentSchedule($paymentSchedule);
    $subscription->setAmount($this->getAmount($this->_params));
    $subscription->setPayment($this->getPaymentDetails());

    $order = $this->getOrder();
    $subscription->setOrder($order);

    $customerAddress = $this->getCustomerAddress();
    $customerData = $this->getCustomerType();
    $subscription->setBillTo($customerAddress);
    $subscription->setCustomer($customerData);

    $request = new AnetAPI\ARBCreateSubscriptionRequest();
    $request->setmerchantAuthentication($merchantAuthentication);
    $request->setRefId($this->getInvoiceNumber());
    $request->setSubscription($subscription);
    $controller = new AnetController\ARBCreateSubscriptionController($request);
    /** @var \net\authorize\api\contract\v1\ARBCreateSubscriptionResponse $response */
    $response = $controller->executeWithApiResponse($this->getIsTestMode() ? AnetEnvironment::SANDBOX : AnetEnvironment::PRODUCTION);

    if (!$response || !$response->getMessages()) {
      $this->handleError(NULL, 'No response returned', $params['error_url']);
    }

    if ($response->getMessages()->getResultCode() == "Ok") {
      $recurParams = [
        'id' => $params['contributionRecurID'],
        'trxn_id' => $response->getSubscriptionId(),
        // FIXME processor_id is deprecated as it is not guaranteed to be unique, but currently (CiviCRM 5.9)
        //  it is required by cancelSubscription (where it is called subscription_id)
        'processor_id' => $response->getSubscriptionId(),
        'auto_renew' => 1,
        'contribution_status_id' => CRM_Core_PseudoConstant::getKey('CRM_Contribute_BAO_Contribution', 'contribution_status_id', 'In Progress'),
      ];
      if (!empty($params['installments'])) {
        if (empty($params['start_date'])) {
          $params['start_date'] = date('YmdHis');
        }
      }

      // Update the recurring payment
      civicrm_api3('ContributionRecur', 'create', $recurParams);

      return TRUE;
    }
    else {
      if ($response->getMessages()) {
        foreach ($response->getMessages()->getMessage() as $rError) {
          $errorMessages[] = $rError->getCode() . ': ' . $rError->getText();
        }
        $errorMessage = implode(', ', $errorMessages);
      }
      else {
        $errorCode = NULL;
        $errorMessage = NULL;
      }
      $this->handleError($errorCode, $errorMessage, $params['error_url']);
    }
    return FALSE;
  }

  /**
   * @param string $message
   * @param array $params
   *
   * @return bool
   * @throws \CiviCRM_API3_Exception
   */
  protected function updateSubscriptionBillingInfo(&$message = '', $params = []) {
    $params = $this->setParams($params);

    $merchantAuthentication = $this->getMerchantAuthentication();

    $subscription = new AnetAPI\ARBSubscriptionType();
    $subscription->setPayment($this->getPaymentDetails());

    $customerAddress = $this->getCustomerAddress();
    //$customerData = $this->getCustomerType();
    $subscription->setBillTo($customerAddress);
    //$subscription->setCustomer($customerData);

    $request = new AnetAPI\ARBUpdateSubscriptionRequest();
    $request->setMerchantAuthentication($merchantAuthentication);
    $request->setRefId($this->getInvoiceNumber());
    $request->setSubscriptionId($this->getSubscriptionId($params));
    $request->setSubscription($subscription);
    $controller = new AnetController\ARBUpdateSubscriptionController($request);
    /** @var \net\authorize\api\contract\v1\ARBUpdateSubscriptionResponse $response */
    $response = $controller->executeWithApiResponse($this->getIsTestMode() ? AnetEnvironment::SANDBOX : AnetEnvironment::PRODUCTION);

    if (!$response || !$response->getMessages()) {
      $this->handleError(NULL, 'No response returned', $params['error_url']);
    }

    if ($response->getMessages()->getResultCode() != "Ok") {
      if ($response->getMessages()) {
        foreach ($response->getMessages()->getMessage() as $rError) {
          $errorMessages[] = $rError->getCode() . ': ' . $rError->getText();
        }
        $errorMessage = implode(', ', $errorMessages);
      }
      else {
        $errorCode = NULL;
        $errorMessage = NULL;
      }
      $this->handleError($errorCode, $errorMessage, $params['error_url']);
    }

    return TRUE;
  }

  /**
   * Change the subscription amount using the Smart Debit API
   *
   * @param string $message
   * @param array $params
   *
   * @return bool
   * @throws \Exception
   */
  protected function changeSubscriptionAmount(&$message = '', $params = []) {
    $existingParams = civicrm_api3('ContributionRecur', 'getsingle', ['id' => $this->getRecurringContributionId($params)]);
    $params = array_merge($existingParams, $params);
    $params = $this->setParams($params);

    $merchantAuthentication = $this->getMerchantAuthentication();

    $subscription = new AnetAPI\ARBSubscriptionType();
    $paymentSchedule = $this->getRecurSchedule();
    $subscription->setPaymentSchedule($paymentSchedule);
    $subscription->setAmount($this->getAmount($this->_params));
    $request = new AnetAPI\ARBUpdateSubscriptionRequest();
    $request->setMerchantAuthentication($merchantAuthentication);
    $request->setRefId($this->getInvoiceNumber());
    $request->setSubscriptionId($this->getSubscriptionId($params));
    $request->setSubscription($subscription);
    $controller = new AnetController\ARBUpdateSubscriptionController($request);
    /** @var \net\authorize\api\contract\v1\ARBUpdateSubscriptionResponse $response */
    $response = $controller->executeWithApiResponse($this->getIsTestMode() ? AnetEnvironment::SANDBOX : AnetEnvironment::PRODUCTION);

    if (!$response || !$response->getMessages()) {
      $this->handleError(NULL, 'No response returned', $params['error_url']);
    }

    if ($response->getMessages()->getResultCode() != "Ok") {
      if ($response->getMessages()) {
        foreach ($response->getMessages()->getMessage() as $rError) {
          $errorMessages[] = $rError->getCode() . ': ' . $rError->getText();
        }
        $errorMessage = implode(', ', $errorMessages);
      }
      else {
        $errorCode = NULL;
        $errorMessage = NULL;
      }
      $this->handleError($errorCode, $errorMessage, $params['error_url']);
    }

    return TRUE;
  }

  /**
   * @param string $message
   * @param array $params
   *
   * @return bool
   * @throws \CiviCRM_API3_Exception
   */
  protected function cancelSubscription(&$message = '', $params = []) {
    $params = $this->setParams($params);

    $contributionRecurId = $this->getRecurringContributionId($params);
    try {
      $contributionRecur = civicrm_api3('ContributionRecur', 'getsingle', [
        'id' => $contributionRecurId,
      ]);
    }
    catch (Exception $e) {
      return FALSE;
    }
    if (empty($contributionRecur['trxn_id'])) {
      CRM_Core_Session::setStatus(E::ts('The recurring contribution cannot be cancelled (No reference (trxn_id) found).'), 'Smart Debit', 'error');
      return FALSE;
    }

    $merchantAuthentication = $this->getMerchantAuthentication();

    $request = new AnetAPI\ARBCancelSubscriptionRequest();
    $request->setMerchantAuthentication($merchantAuthentication);
    $request->setRefId($this->getInvoiceNumber());
    $request->setSubscriptionId($this->getSubscriptionId($params));
    $controller = new AnetController\ARBCancelSubscriptionController($request);
    /** @var \net\authorize\api\contract\v1\ARBCancelSubscriptionResponse $response */
    $response = $controller->executeWithApiResponse($this->getIsTestMode() ? AnetEnvironment::SANDBOX : AnetEnvironment::PRODUCTION);

    if (!$response || !$response->getMessages()) {
      $this->handleError(NULL, 'No response returned', $params['error_url']);
    }

    if ($response->getMessages()->getResultCode() != "Ok") {
      if ($response->getMessages()) {
        foreach ($response->getMessages()->getMessage() as $rError) {
          $errorMessages[] = $rError->getCode() . ': ' . $rError->getText();
        }
        $errorMessage = implode(', ', $errorMessages);
      }
      else {
        $errorCode = NULL;
        $errorMessage = NULL;
      }
      $this->handleError($errorCode, $errorMessage, $params['error_url']);
    }

    return TRUE;
  }

  /**
   * Return the invoice number formatted in the "standard" way
   * @fixme This is how it has always been done with authnet and is not necessarily the best way
   *
   * @return string
   */
  protected function getInvoiceNumber() {
    return substr($this->getParam('invoiceID'), 0, 20);


  }

  /**
   * Set the payment details for the subscription
   *
   * @return AnetAPI\PaymentType
   */
  abstract protected function getPaymentDetails();

  /**
   * Process incoming payment notification (IPN).
   *
   * @throws \CRM_Core_Exception
   * @throws \CiviCRM_API3_Exception
   */
  public static function handlePaymentNotification() {
    $headers = getallheaders();
    $payload = file_get_contents("php://input");
    $ipnClass = new CRM_Core_Payment_AuthNetIPN($payload, $headers);
    if ($ipnClass->main()) {
      http_response_code(200);
    }
  }

}
