<?php

use CRM_AuthNetEcheck_ExtensionUtil as E;

class CRM_Core_Payment_AuthNetEcheck extends CRM_Core_Payment_AuthorizeNet {

  use CRM_Core_Payment_AuthNetEcheckTrait;

  /**
   * Constructor
   *
   * @param string $mode the mode of operation: live or test
   *
   * @return void
   */
  function __construct($mode, &$paymentProcessor) {
    parent::__construct($mode, $paymentProcessor);
    $this->_setParam('paymentType', 'ECHECK');
    $this->_processorName = E::ts('Authorize.net eCheck.net');
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
    return FALSE;
  }

  /**
   * Override CRM_Core_Payment function
   *
   * @return string
   */
  public function getPaymentTypeName() {
    return 'direct_debit';
  }

  /**
   * Override CRM_Core_Payment function
   *
   * @return string
   */
  public function getPaymentTypeLabel() {
    return 'Authorize.net eCheck.Net';
  }

  /**
   * Override CRM_Core_Payment function
   *
   * @return array
   */
  public function getPaymentFormFields() {
    return [
      'account_holder',
      'bank_account_number',
      'bank_identification_number',
      'bank_name',
    ];
  }

  /**
   * Return an array of all the details about the fields potentially required for payment fields.
   *
   * Only those determined by getPaymentFormFields will actually be assigned to the form
   *
   * @return array
   *   field metadata
   */
  public function getPaymentFormFieldsMetadata() {
    return [
      'account_holder' => [
        'htmlType' => 'text',
        'name' => 'account_holder',
        'title' => E::ts('Name on Account'),
        'attributes' => [
          'size' => 20,
          'maxlength' => 34,
          'autocomplete' => 'on',
        ],
        'is_required' => TRUE,
      ],
      //e.g. IBAN can have maxlength of 34 digits
      'bank_account_number' => [
        'htmlType' => 'text',
        'name' => 'bank_account_number',
        'title' => E::ts('Account Number'),
        'attributes' => [
          'size' => 20,
          'maxlength' => 34,
          'autocomplete' => 'off',
        ],
        'rules' => [
          [
            'rule_message' => E::ts('Please enter a valid Bank Identification Number (value must not contain punctuation characters).'),
            'rule_name' => 'nopunctuation',
            'rule_parameters' => NULL,
          ],
        ],
        'is_required' => TRUE,
      ],
      //e.g. SWIFT-BIC can have maxlength of 11 digits
      'bank_identification_number' => [
        'htmlType' => 'text',
        'name' => 'bank_identification_number',
        'title' => E::ts('Routing Number'),
        'attributes' => [
          'size' => 20,
          'maxlength' => 11,
          'autocomplete' => 'off',
        ],
        'is_required' => TRUE,
        'rules' => [
          [
            'rule_message' => E::ts('Please enter a valid Bank Identification Number (value must not contain punctuation characters).'),
            'rule_name' => 'nopunctuation',
            'rule_parameters' => NULL,
          ],
        ],
      ],
      'bank_name' => [
        'htmlType' => 'text',
        'name' => 'bank_name',
        'title' => E::ts('Bank Name'),
        'attributes' => [
          'size' => 20,
          'maxlength' => 64,
          'autocomplete' => 'off',
        ],
        'is_required' => TRUE,
      ],
    ];
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
   * @throws \Civi\Payment\Exception\PaymentProcessorException
   */
  public function doPayment(&$params, $component = 'contribute') {
    if (!defined('CURLOPT_SSLCERT')) {
      Throw new \Civi\Payment\Exception\PaymentProcessorException('Authorize.Net requires curl with SSL support');
    }

    // Set default contribution status
    $params['contribution_status_id'] = CRM_Core_PseudoConstant::getKey('CRM_Contribute_BAO_Contribution', 'contribution_status_id', 'Pending');

    $params['error_url'] = self::getErrorUrl($params);

    /*
     * recurpayment function does not compile an array & then process it -
     * - the tpl does the transformation so adding call to hook here
     * & giving it a change to act on the params array
     */
    $newParams = $params;
    if (!empty($params['is_recur']) && !empty($params['contributionRecurID'])) {
      CRM_Utils_Hook::alterPaymentProcessorParams($this, $params, $newParams);
    }
    foreach ($newParams as $field => $value) {
      $this->_setParam($field, $value);
    }

    if (!empty($params['is_recur']) && !empty($params['contributionRecurID'])) {
      $this->doRecurPayment();
      return $params;
    }

    $postFields = array();
    $authorizeNetFields = $this->_getAuthorizeNetFields();

    // Set up our call for hook_civicrm_paymentProcessor,
    // since we now have our parameters as assigned for the AIM back end.
    CRM_Utils_Hook::alterPaymentProcessorParams($this, $params, $authorizeNetFields);

    foreach ($authorizeNetFields as $field => $value) {
      // CRM-7419, since double quote is used as enclosure while doing csv parsing
      $value = ($field == 'x_description') ? str_replace('"', "'", $value) : $value;
      $postFields[] = $field . '=' . urlencode($value);
    }

    // Authorize.Net will not refuse duplicates, so we should check if the user already submitted this transaction
    if ($this->checkDupe($authorizeNetFields['x_invoice_num'], CRM_Utils_Array::value('contributionID', $params))) {
      self::handleError(
        9004,
        E::ts('It appears that this transaction is a duplicate.  Have you already submitted the form once?  If so there may have been a connection problem.  Check your email for a receipt from Authorize.net.  If you do not receive a receipt within 2 hours you can try your transaction again.  If you continue to have problems please contact the site administrator.'),
        $params['error_url']
      );
    }

    $curlSession = curl_init($this->_paymentProcessor['url_site']);

    if (!$curlSession) {
      self::handleError(9002, E::ts('Could not initiate connection to payment gateway'), $params['error_url']);
    }

    $options = [
      CURLOPT_POST => TRUE,
      CURLOPT_RETURNTRANSFER => TRUE,
      CURLOPT_POSTFIELDS => implode('&', $postFields),
      CURLOPT_SSL_VERIFYHOST => Civi::settings()->get('verifySSL') ? 2 : 0,
      CURLOPT_SSL_VERIFYPEER => Civi::settings()->get('verifySSL'),
    ];
    curl_setopt_array($curlSession, $options);

    $response = curl_exec($curlSession);

    if (!$response) {
      self::handleError(curl_errno($curlSession), curl_error($curlSession), $params['error_url']);
    }

    curl_close($curlSession);

    $response_fields = $this->explode_csv($response);
    // FIXME: MD5 responses are deprecated in favour of SHA-512 (https://support.authorize.net/s/article/What-is-the-MD5-Hash-Security-feature-and-how-does-it-work)
    /**if (!$this->checkMD5($response_fields[37], $response_fields[6], $response_fields[9])) {
      self::handleError(9003, 'MD5 Verification failed', $params['error_url']);
    }*/

    // check for application errors
    // TODO: AVS, CVV2, CAVV, and other verification results
    switch ($response_fields[0]) {
      case self::AUTH_REVIEW:
        // We've set default to "Pending" at the start of doPayment
        break;

      case self::AUTH_ERROR:
      case self::AUTH_DECLINED:
        $errormsg = $response_fields[2] . ' ' . $response_fields[3];
        self::handleError($response_fields[1], $errormsg, $params['error_url']);

      default:
        // Success

        // test mode always returns trxn_id = 0
        // also live mode in CiviCRM with test mode set in
        // Authorize.Net return $response_fields[6] = 0
        // hence treat that also as test mode transaction
        // fix for CRM-2566
        if (($this->_mode == 'test') || $response_fields[6] == 0) {
          $query = "SELECT MAX(trxn_id) FROM civicrm_contribution WHERE trxn_id RLIKE 'test[0-9]+'";
          $p = array();
          $trxn_id = strval(CRM_Core_DAO::singleValueQuery($query, $p));
          $trxn_id = str_replace('test', '', $trxn_id);
          $trxn_id = intval($trxn_id) + 1;
          $contributionParams['trxn_id'] = sprintf('test%08d', $trxn_id);
        }
        else {
          $contributionParams['trxn_id'] = $response_fields[6];
        }
        $contributionParams['gross_amount'] = $response_fields[9];
        break;
    }

    // TODO: include authorization code?
    if ($this->getContributionId($params)) {
      $contributionParams['id'] = $this->getContributionId($params);
      civicrm_api3('Contribution', 'create', $contributionParams);
      unset($contributionParams['id']);
    }
    $params = array_merge($params, $contributionParams);

    // We need to set this to ensure that contributions are set to the correct status
    if (!empty($params['contribution_status_id'])) {
      $params['payment_status_id'] = $params['contribution_status_id'];
    }
    return $params;
  }

  /**
   * Submit an Automated Recurring Billing subscription.
   *
   * @return bool
   */
  public function doRecurPayment() {
    $template = CRM_Core_Smarty::singleton();

    $intervalLength = $this->_getParam('frequency_interval');
    $intervalUnit = $this->_getParam('frequency_unit');
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
        self::handleError(9001, 'Payment interval must be at least one week', $this->_getParam('error_url'));
      }
      elseif ($intervalLength > 365) {
        self::handleError(9001, 'Payment interval may not be longer than one year', $this->_getParam('error_url'));
      }
    }
    elseif ($intervalUnit == 'months') {
      if ($intervalLength < 1) {
        self::handleError(9001, 'Payment interval must be at least one week', $this->_getParam('error_url'));
      }
      elseif ($intervalLength > 12) {
        self::handleError(9001, 'Payment interval may not be longer than one year', $this->_getParam('error_url'));
      }
    }

    $template->assign('intervalLength', $intervalLength);
    $template->assign('intervalUnit', $intervalUnit);

    $template->assign('apiLogin', $this->_getParam('apiLogin'));
    $template->assign('paymentKey', $this->_getParam('paymentKey'));
    $template->assign('refId', substr($this->_getParam('invoiceID'), 0, 20));

    //for recurring, carry first contribution id
    $template->assign('invoiceNumber', $this->_getParam('contributionID'));
    $firstPaymentDate = $this->_getParam('receive_date');
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

    $template->assign('startDate', $startDate->format('Y-m-d'));

    $installments = $this->_getParam('installments');

    // for open ended subscription totalOccurrences has to be 9999
    $installments = empty($installments) ? 9999 : $installments;
    $template->assign('totalOccurrences', $installments);

    $template->assign('amount', $this->_getParam('amount'));

    $template->assign('paymentType', $this->_getParam('paymentType'));
    $template->assign('accountType', $this->_getParam('bank_account_type'));
    $template->assign('routingNumber', $this->_getParam('bank_identification_number'));
    $template->assign('accountNumber', $this->_getParam('bank_account_number'));
    $template->assign('nameOnAccount', $this->_getParam('account_holder'));
    $template->assign('echeckType', 'WEB');
    $template->assign('bankName', $this->_getParam('bank_name'));

    // name rather than description is used in the tpl - see http://www.authorize.net/support/ARB_guide.pdf
    $template->assign('name', $this->_getParam('description', TRUE));

    $template->assign('email', $this->_getParam('email'));
    $template->assign('contactID', $this->_getParam('contactID'));
    $template->assign('billingFirstName', $this->_getParam('billing_first_name'));
    $template->assign('billingLastName', $this->_getParam('billing_last_name'));
    $template->assign('billingAddress', $this->_getParam('street_address', TRUE));
    $template->assign('billingCity', $this->_getParam('city', TRUE));
    $template->assign('billingState', $this->_getParam('state_province'));
    $template->assign('billingZip', $this->_getParam('postal_code', TRUE));
    $template->assign('billingCountry', $this->_getParam('country'));

    $arbXML = $template->fetch('CRM/Contribute/Form/Contribution/AuthorizeNetARB.tpl');
    // submit to authorize.net

    $curlSession = curl_init($this->_paymentProcessor['url_recur']);
    if (!$curlSession) {
      self::handleError(9002, 'Could not initiate connection to payment gateway', $this->_getParam('error_url'));
    }

    $options = [
      CURLOPT_HEADER => TRUE, // return headers
      CURLOPT_HTTPHEADER => ["Content-Type: text/xml"],
      CURLOPT_POST => TRUE,
      CURLOPT_RETURNTRANSFER => TRUE,
      CURLOPT_POSTFIELDS => $arbXML,
      CURLOPT_SSL_VERIFYHOST => Civi::settings()->get('verifySSL') ? 2 : 0,
      CURLOPT_SSL_VERIFYPEER => Civi::settings()->get('verifySSL'),
    ];
    curl_setopt_array($curlSession, $options);

    $response = curl_exec($curlSession);

    if (!$response) {
      self::handleError(curl_errno($curlSession), curl_error($curlSession), $this->_getParam('error_url'));
    }

    curl_close($curlSession);
    $responseFields = $this->_ParseArbReturn($response);

    if ($responseFields['resultCode'] == 'Error') {
      self::handleError($responseFields['code'], $responseFields['text'], $this->_getParam('error_url'));
    }

    // update recur processor_id with subscriptionId
    CRM_Core_DAO::setFieldValue('CRM_Contribute_DAO_ContributionRecur', $this->_getParam('contributionRecurID'),
      'processor_id', $responseFields['subscriptionId']
    );
    //only impact of assigning this here is is can be used to cancel the subscription in an automated test
    // if it isn't cancelled a duplicate transaction error occurs
    if (!empty($responseFields['subscriptionId'])) {
      $this->_setParam('subscriptionId', $responseFields['subscriptionId']);
    }
    return TRUE;
  }

  /**
   * @param string $message
   * @param array $params
   *
   * @return bool|object
   */
  public function updateSubscriptionBillingInfo(&$message = '', $params = array()) {

    $params['error_url'] = self::getErrorUrl($params);

    $template = CRM_Core_Smarty::singleton();
    $template->assign('subscriptionType', 'updateBilling');

    $template->assign('apiLogin', $this->_getParam('apiLogin'));
    $template->assign('paymentKey', $this->_getParam('paymentKey'));
    $template->assign('subscriptionId', $params['subscriptionId']);

    $template->assign('paymentType', $this->_getParam('paymentType'));
    $template->assign('accountType', $params['bank_account_type']);
    $template->assign('routingNumber', $params['bank_identification_number']);
    $template->assign('accountNumber', $params['bank_account_number']);
    $template->assign('nameOnAccount', $params['account_holder']);
    $template->assign('echeckType', 'WEB');
    $template->assign('bankName', $params['bank_name']);

    $template->assign('billingFirstName', $params['first_name']);
    $template->assign('billingLastName', $params['last_name']);
    $template->assign('billingAddress', $params['street_address']);
    $template->assign('billingCity', $params['city']);
    $template->assign('billingState', $params['state_province']);
    $template->assign('billingZip', $params['postal_code']);
    $template->assign('billingCountry', $params['country']);

    $arbXML = $template->fetch('CRM/Contribute/Form/Contribution/AuthorizeNetARB.tpl');

    // submit to authorize.net
    $submit = curl_init($this->_paymentProcessor['url_recur']);
    if (!$submit) {
      self::handleError(9002, 'Could not initiate connection to payment gateway', $params['error_url']);
    }

    curl_setopt($submit, CURLOPT_RETURNTRANSFER, 1);
    curl_setopt($submit, CURLOPT_HTTPHEADER, array("Content-Type: text/xml"));
    curl_setopt($submit, CURLOPT_HEADER, 1);
    curl_setopt($submit, CURLOPT_POSTFIELDS, $arbXML);
    curl_setopt($submit, CURLOPT_POST, 1);
    curl_setopt($submit, CURLOPT_SSL_VERIFYPEER, Civi::settings()->get('verifySSL'));

    $response = curl_exec($submit);

    if (!$response) {
      self::handleError(curl_errno($submit), curl_error($submit), $params['error_url']);
    }

    curl_close($submit);

    $responseFields = $this->_ParseArbReturn($response);
    $message = "{$responseFields['code']}: {$responseFields['text']}";

    if ($responseFields['resultCode'] == 'Error') {
      self::handleError($responseFields['code'], $responseFields['text'], $params['error_url']);
    }
    return TRUE;
  }


  function _getAuthorizeNetFields() {
    if (empty($amount)) {
      //CRM-9894 would this ever be the case??
      $amount = $this->_getParam('amount');
    }
    $fields = array();
    $fields['x_login'] = $this->_getParam('apiLogin');
    $fields['x_tran_key'] = $this->_getParam('paymentKey');
    $fields['x_email_customer'] = $this->_getParam('email');
    $fields['x_first_name'] = $this->_getParam('billing_first_name');
    $fields['x_last_name'] = $this->_getParam('billing_last_name');
    $fields['x_address'] = $this->_getParam('street_address');
    $fields['x_city'] = $this->_getParam('city');
    $fields['x_state'] = $this->_getParam('state_province');
    $fields['x_zip'] = $this->_getParam('postal_code');
    $fields['x_country'] = $this->_getParam('country');
    $fields['x_customer_ip'] = $this->_getParam('ip_address');
    $fields['x_email'] = $this->_getParam('email');
    $fields['x_invoice_num'] = substr($this->_getParam('invoiceID'), 0, 20);
    $fields['x_amount'] = $amount;
    $fields['x_currency_code'] = $this->_getParam('currencyID');
    $fields['x_description'] = $this->_getParam('description');
    $fields['x_cust_id'] = $this->_getParam('contactID');
    $fields['x_method'] = $this->_getParam('paymentType');
    $fields['x_bank_aba_code'] = $this->_getParam('bank_identification_number');
    $fields['x_bank_acct_num'] = $this->_getParam('bank_account_number');
    $fields['x_bank_acct_type'] = strtoupper($this->_getParam('bank_account_type'));
    $fields['x_bank_name'] = $this->_getParam('bank_name');
    $fields['x_bank_acct_name'] = $this->_getParam('account_holder');
    $fields['x_echeck_type'] = 'WEB';
    $fields['x_email_customer'] = $this->_getParam('email');

    $fields['x_relay_response'] = 'FALSE';

    // request response in CSV format
    $fields['x_delim_data'] = 'TRUE';
    $fields['x_delim_char'] = ',';
    $fields['x_encap_char'] = '"';

    if ($this->_mode != 'live') {
      $fields['x_test_request'] = 'TRUE';
    }
    return $fields;
  }

  /**
   * Handle an error and notify the user
   *
   * @param string $errorCode
   * @param string $errorMessage
   * @param string $bounceURL
   *
   * @return string $errorMessage
   *     (or statusbounce if URL is specified)
   */
  public function handleError($errorCode = NULL, $errorMessage = NULL, $bounceURL = NULL) {
    $errorCode = empty($errorCode) ? 9001 : $errorCode;
    $errorMessage = empty($errorMessage) ? 'Unknown System Error.' : $errorMessage;
    $message = 'Code: ' . $errorCode . ' Message: ' . $errorMessage;

    Civi::log()->debug('AuthNetEcheck Payment Error: ' . $message);

    if ($bounceURL) {
      CRM_Core_Error::statusBounce($message, $bounceURL, E::ts('Error: %1', $this->getPaymentTypeLabel()));
    }
    return $errorMessage;
  }

}
