<?php
/**
 * https://civicrm.org/licensing
 */

trait CRM_AuthorizeNet_WebhookTrait {
  /**********************
   * MJW_Webhook_Trait: 20190707
   *********************/

  /**
   * @var array Payment processor
   */
  private $_paymentProcessor;

  /**
   * Get the path of the webhook depending on the UF (eg Drupal, Joomla, Wordpress)
   *
   * @param string $paymentProcessorId
   *
   * @return string
   */
  public static function getWebhookPath($paymentProcessorId) {
    $UFWebhookPath = CRM_Utils_System::url('civicrm/payment/ipn/' . $paymentProcessorId, NULL, TRUE, NULL, FALSE, TRUE);
    return $UFWebhookPath;
  }

}
