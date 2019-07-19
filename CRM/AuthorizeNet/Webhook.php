<?php
/**
 * https://civicrm.org/licensing
 */

use CRM_AuthNetEcheck_ExtensionUtil as E;
use \JohnConde\Authnet\AuthnetWebhook as AuthnetWebhook;
use \JohnConde\Authnet\AuthnetApiFactory as AuthnetApiFactory;

class CRM_AuthorizeNet_Webhook {

  use CRM_AuthorizeNet_WebhookTrait;
  use CRM_Core_Payment_AuthorizeNetTrait;

  /**
   * CRM_AuthorizeNet_Webhook constructor.
   *
   * @param array $paymentProcessor
   */
  function __construct($paymentProcessor) {
    $this->_paymentProcessor = $paymentProcessor;
  }

  /**
   * Get a request handler for authnet webhooks
   *
   * @return \JohnConde\Authnet\AuthnetWebhooksRequest
   * @throws \ErrorException
   * @throws \JohnConde\Authnet\AuthnetInvalidCredentialsException
   * @throws \JohnConde\Authnet\AuthnetInvalidServerException
   */
  public function getRequest() {
    return AuthnetApiFactory::getWebhooksHandler(
      CRM_Core_Payment_AuthorizeNetCommon::getApiLoginId($this->_paymentProcessor),
      CRM_Core_Payment_AuthorizeNetCommon::getTransactionKey($this->_paymentProcessor),
      $this->getIsTestMode() ? AuthnetApiFactory::USE_DEVELOPMENT_SERVER : AuthnetApiFactory::USE_PRODUCTION_SERVER);
  }

  /**
   * Get a list of configured webhooks
   *
   * @return \JohnConde\Authnet\AuthnetWebhooksResponse
   * @throws \ErrorException
   * @throws \JohnConde\Authnet\AuthnetCurlException
   * @throws \JohnConde\Authnet\AuthnetInvalidCredentialsException
   * @throws \JohnConde\Authnet\AuthnetInvalidJsonException
   * @throws \JohnConde\Authnet\AuthnetInvalidServerException
   */
  public function getWebhooks() {
    $request = $this->getRequest();
    return $request->getWebhooks();
  }

  /**
   * Create a new webhook
   *
   * @throws \ErrorException
   * @throws \JohnConde\Authnet\AuthnetCurlException
   * @throws \JohnConde\Authnet\AuthnetInvalidCredentialsException
   * @throws \JohnConde\Authnet\AuthnetInvalidJsonException
   * @throws \JohnConde\Authnet\AuthnetInvalidServerException
   */
  public function createWebhook() {
    $request = $this->getRequest();
    $request->createWebhooks(self::getDefaultEnabledEvents(), self::getWebhookPath($this->_paymentProcessor['id']), 'active');
  }

  /**
   * Check and update existing webhook
   *
   * @param array $webhook
   */
  /**
   * @param \JohnConde\Authnet\AuthnetWebhooksResponse $webhook
   *
   * @throws \ErrorException
   * @throws \JohnConde\Authnet\AuthnetCurlException
   * @throws \JohnConde\Authnet\AuthnetInvalidCredentialsException
   * @throws \JohnConde\Authnet\AuthnetInvalidJsonException
   * @throws \JohnConde\Authnet\AuthnetInvalidServerException
   */
  public function checkAndUpdateWebhook($webhook) {
    $update = FALSE;
    if ($webhook->getStatus() !== 'active') {
      $update = TRUE;
    }
    if (array_diff(self::getDefaultEnabledEvents(), $webhook->getEventTypes())) {
      $update = TRUE;
    }
    if ($update) {
      $request = $this->getRequest();
      $request->updateWebhook([$webhook->getWebhooksId()], self::getWebhookPath($this->_paymentProcessor['id']), self::getDefaultEnabledEvents(),'active');
    }
  }

  /**
   * Checks whether the payment processors have a correctly configured
   * webhook (we may want to check the test processors too, at some point, but
   * for now, avoid having false alerts that will annoy people).
   *
   * @see hook_civicrm_check()
   *
   * @param array $messages
   *
   * @return array
   * @throws \CiviCRM_API3_Exception
   * @throws \JohnConde\Authnet\AuthnetInvalidJsonException
   */
  public static function check($messages) {
    $checkMessage = [
      'name' => 'authnet_webhook',
      'label' => 'AuthorizeNet',
    ];
    $result = civicrm_api3('PaymentProcessor', 'get', [
      'class_name' => ['IN' => ['Payment_AuthNetCreditcard', 'Payment_AuthNeteCheck']],
      'is_active' => 1,
    ]);

    foreach ($result['values'] as $paymentProcessor) {
      $webhook_path = self::getWebhookPath($paymentProcessor['id']);

      try {
        $webhookHandler = new CRM_AuthorizeNet_Webhook($paymentProcessor);
        /** @var JohnConde\Authnet\AuthnetWebhooksResponse $webhooks */
        $webhooks = $webhookHandler->getWebhooks();
      }
      catch (Exception $e) {
        $error = $e->getMessage();
        $messages[] = new CRM_Utils_Check_Message(
          "{$checkMessage['name']}_webhook",
          E::ts('The %1 (%2) Payment Processor has an error: %3', [
            1 => $paymentProcessor['name'],
            2 => $paymentProcessor['id'],
            3 => $error,
          ]),
          "{$checkMessage['label']} - " . E::ts('API Key'),
          \Psr\Log\LogLevel::ERROR,
          'fa-money'
        );

        continue;
      }

      $foundWebhook = FALSE;
      foreach ($webhooks->getWebhooks() as $webhook) {
        try {
          if ($webhook->getURL() == $webhook_path) {
            $foundWebhook = TRUE;
            // Check and update webhook
            $webhookHandler->checkAndUpdateWebhook($webhook);
          }
        }
        catch (Exception $e) {
          $messages[] = new CRM_Utils_Check_Message(
            "{$checkMessage['name']}_webhook",
            E::ts('Could not update webhook. You can review from your account dashboard.<br/>The webhook URL is: %3', [
              1 => $paymentProcessor['name'],
              2 => $paymentProcessor['id'],
              3 => urldecode($webhook_path),
            ]) . ".<br/>Error from {$checkMessage['label']}: <em>" . $e->getMessage() . '</em>',
            "{$checkMessage['label']} " . E::ts('Webhook: %1 (%2)', [
                1 => $paymentProcessor['name'],
                2 => $paymentProcessor['id'],
              ]
            ),
            \Psr\Log\LogLevel::WARNING,
            'fa-money'
          );
        }
      }

      if (!$foundWebhook) {
        try {
          $webhookHandler->createWebhook();
        }
        catch (Exception $e) {
          $messages[] = new CRM_Utils_Check_Message(
            "{$checkMessage['name']}_webhook",
            E::ts('Could not create webhook. You can review from your account dashboard.<br/>The webhook URL is: %3', [
              1 => $paymentProcessor['name'],
              2 => $paymentProcessor['id'],
              3 => urldecode($webhook_path),
            ]) . ".<br/>Error from {$checkMessage['label']}: <em>" . $e->getMessage() . '</em>',
            "{$checkMessage['label']} " . E::ts('Webhook: %1 (%2)', [
                1 => $paymentProcessor['name'],
                2 => $paymentProcessor['id'],
              ]
            ),
            \Psr\Log\LogLevel::WARNING,
            'fa-money'
          );
        }
      }
    }
  }

  /**
   * List of webhooks we currently handle
   * @return array
   */
  public static function getDefaultEnabledEvents() {
    // See https://developer.authorize.net/api/reference/features/webhooks.html#Event_Types_and_Payloads
    return [
      'net.authorize.payment.authcapture.created', // Notifies you that an authorization and capture transaction was created.
      'net.authorize.payment.refund.created', // Notifies you that a successfully settled transaction was refunded.
      'net.authorize.payment.void.created', // Notifies you that an unsettled transaction was voided.

      //'net.authorize.customer.subscription.created', // Notifies you that a subscription was created.
      //'net.authorize.customer.subscription.updated', // Notifies you that a subscription was updated.
      //'net.authorize.customer.subscription.suspended',// Notifies you that a subscription was suspended.
      'net.authorize.customer.subscription.terminated',// Notifies you that a subscription was terminated.
      'net.authorize.customer.subscription.cancelled', // Notifies you that a subscription was cancelled.
      //'net.authorize.customer.subscription.expiring', // Notifies you when a subscription has only one recurrence left to be charged.

      'net.authorize.payment.fraud.held', // Notifies you that a transaction was held as suspicious.
      'net.authorize.payment.fraud.approved', // Notifies you that a previously held transaction was approved.
      'net.authorize.payment.fraud.declined', // Notifies you that a previously held transaction was declined.
    ];
  }

}
