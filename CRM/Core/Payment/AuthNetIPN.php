<?php
/**
 * https://civicrm.org/licensing
 */

use CRM_AuthNetEcheck_ExtensionUtil as E;
use \JohnConde\Authnet\AuthnetWebhook as AuthnetWebhook;
use \JohnConde\Authnet\AuthnetApiFactory as AuthnetApiFactory;
use \JohnConde\Authnet\AuthnetWebhooksResponse as AuthnetWebhooksResponse;

class CRM_Core_Payment_AuthNetIPN extends CRM_Core_Payment_BaseIPN {

  use CRM_Core_Payment_AuthorizeNetTrait;
  use CRM_Core_Payment_AuthNetIPNTrait;

  /**
   * Authorize.net webhook transaction ID
   *
   * @var string
   */
  private $trxnId;

  /**
   * Get the transaction ID
   * @return string
   */
  private function getTransactionId() {
    return $this->trxnId;
  }

  /**
   * CRM_Core_Payment_AuthNetIPN constructor.
   *
   * @param string $ipnData
   * @param array $headers
   *
   * @throws \JohnConde\Authnet\AuthnetInvalidCredentialsException
   * @throws \JohnConde\Authnet\AuthnetInvalidJsonException
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
   * @return bool
   * @throws \CiviCRM_API3_Exception
   * @throws \ErrorException
   * @throws \JohnConde\Authnet\AuthnetInvalidCredentialsException
   * @throws \JohnConde\Authnet\AuthnetInvalidServerException
   */
  public function main() {
    // Here you can get more information about the transaction
    $request = AuthnetApiFactory::getJsonApiHandler(
      CRM_Core_Payment_AuthorizeNetCommon::getApiLoginId($this->_paymentProcessor),
      CRM_Core_Payment_AuthorizeNetCommon::getTransactionKey($this->_paymentProcessor),
      $this->getIsTestMode() ? AuthnetApiFactory::USE_DEVELOPMENT_SERVER : AuthnetApiFactory::USE_PRODUCTION_SERVER
    );
    /** @var AuthnetWebhooksResponse $response */
    $response = $request->getTransactionDetailsRequest(['transId' => $this->getTransactionId()]);

    if ($response->messages->resultCode !== 'Ok') {
      $this->exception('Bad response from getTransactionDetailsRequest in IPN handler');
    }

    // Set parameters required for IPN functions
    if ($this->getParamFromResponse($response, 'is_recur')) {
      $this->contribution_recur_id = $this->getRecurringContributionIDFromSubscriptionID($this->getParamFromResponse($response, 'subscription_id'));
    }
    $this->event_type = $response->transaction->transactionType;

    // Process the event
    switch ($response->transaction->transactionType) {
      case 'net.authorize.payment.authcapture.created':
        // Notifies you that an authorization and capture transaction was created.
        if ($this->getParamFromResponse($response, 'is_recur')) {
          $params = [
            'contribution_id' => $this->getContributionIDFromInvoiceID($this->getParamFromResponse($response, 'invoice_id')),
            'contribution_recur_id' => $this->contribution_recur_id,
            'payment_processor_transaction_id' => $this->getParamFromResponse($response, 'transaction_id'),
          ];
          $this->recordCompleted($params);
        }
        else {
          $params = [
            'contribution_id' => $this->getContributionIDFromInvoiceID($this->getParamFromResponse($response, 'invoice_id')),
          ];
        }
        $this->recordCompleted($params);
        break;

      case 'net.authorize.payment.refund.created':
        // Notifies you that a successfully settled transaction was refunded.
        $params = [
          'contribution_id' => $this->getContributionIDFromInvoiceID($this->getParamFromResponse($response, 'invoice_id')),
          'total_amount' => $this->getParamFromResponse($response, 'refund_amount'),
        ];
        $this->recordRefund($params);
        break;

      case 'net.authorize.payment.void.created':
        // Notifies you that an unsettled transaction was voided.
        $params = [
          'contribution_id' => $this->getContributionIDFromInvoiceID($this->getParamFromResponse($response, 'invoice_id')),
        ];
        $this->recordCancelled($params);
        break;

      case 'net.authorize.payment.fraud.held':
        // Notifies you that a transaction was held as suspicious.
        $params = [
          'contribution_id' => $this->getContributionIDFromInvoiceID($this->getParamFromResponse($response, 'invoice_id')),
        ];
        $this->recordPending($params);
        break;

      case 'net.authorize.payment.fraud.approved':
        // Notifies you that a previously held transaction was approved.
        $params = [
          'contribution_id' => $this->getContributionIDFromInvoiceID($this->getParamFromResponse($response, 'invoice_id')),
        ];
        $this->recordCompleted($params);
        break;

      case 'net.authorize.payment.fraud.declined':
        // Notifies you that a previously held transaction was declined.
        $params = [
          'contribution_id' => $this->getContributionIDFromInvoiceID($this->getParamFromResponse($response, 'invoice_id')),
        ];
        $this->recordFailed($params);
        break;

        // Now the "subscription" (recurring) ones
      // case 'net.authorize.customer.subscription.created':
        // Notifies you that a subscription was created.
      // case 'net.authorize.customer.subscription.updated':
        // Notifies you that a subscription was updated.
      // case 'net.authorize.customer.subscription.suspended':
        // Notifies you that a subscription was suspended.
      case 'net.authorize.customer.subscription.terminated':
        // Notifies you that a subscription was terminated.
      case 'net.authorize.customer.subscription.cancelled':
        // Notifies you that a subscription was cancelled.
        $this->recordSubscriptionCancelled();
        break;

      // case 'net.authorize.customer.subscription.expiring':
        // Notifies you when a subscription has only one recurrence left to be charged.
    }

    return TRUE;
  }

  /**
   * Retrieve parameters from IPN response
   *
   * @param AuthnetWebhooksResponse $response
   * @param string $param
   *
   * @return mixed
   */
  protected function getParamFromResponse($response, $param) {
    switch ($param) {
      case 'transaction_id':
        return $response->transaction->transId;

      case 'invoice_id':
        return $response->transaction->order->invoiceNumber;

      case 'refund_amount':
        // @todo: Check that this is the correct parameter?
        return $response->transaction->refundAmount;

      case 'is_recur':
        return $response->transaction->recurringBilling;

      case 'subscription_id':
        return $response->transaction->subscription->id;

      case 'subscription_payment_number':
        return $response->transaction->subscription->payNum;

    }
  }

  /**
   * Get the contribution ID from the paymentprocessor invoiceID.
   * For AuthorizeNet we save the 20character invoice ID into the contribution trxn_id
   *
   * @param string $invoiceID
   *
   * @return int
   * @throws \CiviCRM_API3_Exception
   */
  protected function getContributionIDFromInvoiceID($invoiceID) {
    $contribution = civicrm_api3('Contribution', 'get', [
      'trxn_id' => ['LIKE' => "{$invoiceID}%"],
      'options' => ['limit' => 1, 'sort' => "id DESC"],
    ]);
    if (empty($contribution['id'])) {
      $this->exception("Could not find matching contribution for invoice ID: {$invoiceID}");
    }

    return $contribution['id'];
  }

  /**
   * @param $subscriptionID
   *
   * @return int
   * @throws \CiviCRM_API3_Exception
   */
  protected function getRecurringContributionIDFromSubscriptionID($subscriptionID) {
    $contributionRecur = civicrm_api3('ContributionRecur', 'get', [
      'trxn_id' => $subscriptionID,
    ]);
    if (empty($contributionRecur['id'])) {
      $this->exception("Could not find matching contribution for invoice ID: {$subscriptionID}");
    }

    return $contributionRecur['id'];
  }

}
