<?php
/**
 * https://civicrm.org/licensing
 */

use CRM_AuthNetEcheck_ExtensionUtil as E;
use net\authorize\api\contract\v1 as AnetAPI;
use net\authorize\api\controller as AnetController;
use net\authorize\api\constants\ANetEnvironment as AnetEnvironment;

class CRM_Core_Payment_AuthNetEcheck extends CRM_Core_Payment_AuthorizeNetCommon {

  use CRM_Core_Payment_AuthorizeNetTrait;

  /**
   * Constructor
   *
   * @param string $mode the mode of operation: live or test
   *
   * @return void
   */
  function __construct($mode, &$paymentProcessor) {
    $this->_processorName = $this->getPaymentTypeLabel();
    parent::__construct($mode, $paymentProcessor);
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
    return E::ts('Authorize.net (eCheck.Net)');
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
      // US account number (max 17 digits)
      'bank_account_number' => [
        'htmlType' => 'text',
        'name' => 'bank_account_number',
        'title' => E::ts('Account Number'),
        'description' => E::ts('Usually between 8 and 12 digits - identifies your individual account'),
        'attributes' => [
          'size' => 20,
          'maxlength' => 17,
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
      //e.g. SWIFT-BIC can have maxlength of 11 digits eg. 211287748
      'bank_identification_number' => [
        'htmlType' => 'text',
        'name' => 'bank_identification_number',
        'title' => E::ts('Routing Number'),
        'description' => E::ts('A 9-digit code (ABA number) that is used to identify where your bank account was opened (eg. 211287748)'),
        'attributes' => [
          'size' => 20,
          'maxlength' => 9,
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
   * Get the bank account for AuthNet
   *
   * @return \net\authorize\api\contract\v1\BankAccountType
   */
  private function getBankAccount() {
    // Create the payment data for a Bank Account
    $bankAccount = new AnetAPI\BankAccountType();
    $bankAccount->setAccountType(strtoupper($this->getParam('bank_account_type')));
    // see eCheck documentation for proper echeck type to use for each situation
    $bankAccount->setEcheckType('WEB');
    $bankAccount->setRoutingNumber($this->getParam('bank_identification_number'));
    $bankAccount->setAccountNumber($this->getParam('bank_account_number'));
    $bankAccount->setNameOnAccount($this->getParam('account_holder'));
    $bankAccount->setBankName($this->getParam('bank_name'));
    $bankAccount->setAccountType('checking');
    return $bankAccount;
  }

  /**
   * Get the payment details for the subscription
   *
   * @return AnetAPI\PaymentType
   */
  protected function getPaymentDetails() {
    $bankAccount = $this->getBankAccount();
    // Add the payment data to a paymentType object
    $paymentDetails = new AnetAPI\PaymentType();
    $paymentDetails->setBankAccount($bankAccount);
    return $paymentDetails;
  }

}
