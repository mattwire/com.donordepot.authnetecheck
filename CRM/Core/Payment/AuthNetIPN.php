<?php
/*
 * @file
 * Handle Twocheckout Webhooks for recurring payments.
 */

use CRM_AuthNetEcheck_ExtensionUtil as E;
use \JohnConde\Authnet\AuthnetWebhook as AuthnetWebhook;

class CRM_Core_Payment_AuthNetIPN extends CRM_Core_Payment_BaseIPN {

  use CRM_Core_Payment_AuthNetIPNTrait;
  use CRM_Core_Payment_AuthorizeNetTrait;

  /**
   * Authorize.net webhook transaction ID
   *
   * @var string
   */
  private $trxnId;

  private function getTransactionId() {
    return $this->trxnId;
  }

  /**
   * CRM_Core_Payment_TwocheckoutIPN constructor.
   *
   * @param $ipnData
   * @param bool $verify
   *
   * @throws \CRM_Core_Exception
   */
  public function __construct($ipnData, $headers) {
    $this->_params = $ipnData;
    $this->getPaymentProcessor();

    $webhook = new AuthnetWebhook(CRM_Core_Payment_AuthorizeNetCommon::getSignature($this->_paymentProcessor), $ipnData, $headers);
    if ($webhook->isValid()) {
      // Get the transaction ID
      $this->trxnId = $webhook->payload->id;
    }
    parent::__construct();
  }

  /**
   * @throws \CRM_Core_Exception
   * @throws \CiviCRM_API3_Exception
   */
  public function main() {
    // Here you can get more information about the transaction
    $request  = AuthnetApiFactory::getJsonApiHandler(CRM_Core_Payment_AuthorizeNetCommon::getApiLoginId($this->_paymentProcessor), CRM_Core_Payment_AuthorizeNetCommon::getTransactionKey($this->_paymentProcessor));
    $response = $request->getTransactionDetailsRequest(['transId' => $this->getTransactionId()]);

    /* You can put these response values in the database or whatever your business logic dictates.
    $response-&gt;transaction-&gt;transactionType
    $response-&gt;transaction-&gt;transactionStatus
    $response-&gt;transaction-&gt;authCode
    $response-&gt;transaction-&gt;AVSResponse
    */


    $verify = Twocheckout_Notification::check($this->_params, $this->getSecretWord());
    if ($verify['response_code'] !== 'Success') {
      $this->handleError($verify['response_code'], $verify['response_message']);
      return FALSE;
    }

    // We need a contribution ID - from the transactionID (invoice ID)
    try {
      // Same approach as api repeattransaction.
      $contribution = civicrm_api3('contribution', 'getsingle', [
        'return' => ['id', 'contribution_status_id', 'total_amount', 'trxn_id'],
        'contribution_test' => $this->getIsTestMode(),
        'options' => ['limit' => 1, 'sort' => 'id DESC'],
        'trxn_id' => $this->getParam('invoice_id'),
      ]);
      $contributionId = $contribution['id'];
    }
    catch (Exception $e) {
      $this->exception('Cannot find any contributions with invoice ID: ' . $this->getParam('invoice_id') . '. ' . $e->getMessage());
    }

    // See https://www.2checkout.com/documentation/notifications
    switch ($this->getParam('message_type')) {
      case 'FRAUD_STATUS_CHANGED':
        switch ($this->getParam('fraud_status')) {
          case 'pass':
            // Do something when sale passes fraud review.
            // The last one was not completed, so complete it.
            civicrm_api3('Contribution', 'completetransaction', array(
              'id' => $contributionId,
              'payment_processor_id' => $this->_paymentProcessor['id'],
              'is_email_receipt' => $this->getSendEmailReceipt(),
            ));
            break;
          case 'fail':
            // Do something when sale fails fraud review.
            $this->failtransaction([
              'id' => $contributionId,
              'payment_processor_id' => $this->_paymentProcessor['id']
            ]);
            break;
          case 'wait':
            // Do something when sale requires additional fraud review.
            // Do nothing, we'll remain in Pending.
            break;
        }
        break;

      case 'REFUND_ISSUED':
        // To be implemented
        break;
    }

    // Unhandled event type.
    return TRUE;
  }

  public function exception($message) {
    $errorMessage = $this->getPaymentProcessorLabel() . ' Exception: Event: ' . $this->event_type . ' Error: ' . $message;
    Civi::log()->debug($errorMessage);
    http_response_code(400);
    exit(1);
  }

}
