<?php
/*--------------------------------------------------------------------+
 | CiviCRM version 4.7                                                |
+--------------------------------------------------------------------+
 | Copyright CiviCRM LLC (c) 2004-2017                                |
+--------------------------------------------------------------------+
 | This file is a part of CiviCRM.                                    |
 |                                                                    |
 | CiviCRM is free software; you can copy, modify, and distribute it  |
 | under the terms of the GNU Affero General Public License           |
 | Version 3, 19 November 2007 and the CiviCRM Licensing Exception.   |
 |                                                                    |
 | CiviCRM is distributed in the hope that it will be useful, but     |
 | WITHOUT ANY WARRANTY; without even the implied warranty of         |
 | MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.               |
 | See the GNU Affero General Public License for more details.        |
 |                                                                    |
 | You should have received a copy of the GNU Affero General Public   |
 | License and the CiviCRM Licensing Exception along                  |
 | with this program; if not, contact CiviCRM LLC                     |
 | at info[AT]civicrm[DOT]org. If you have questions about the        |
 | GNU Affero General Public License or the licensing of CiviCRM,     |
 | see the CiviCRM license FAQ at http://civicrm.org/licensing        |
 +-------------------------------------------------------------------*/

/**
 * The record will be automatically inserted, updated, or deleted from the
 * database as appropriate. For more details, see "hook_civicrm_managed" at:
 * http://wiki.civicrm.org/confluence/display/CRMDOC/Hook+Reference
 */
return [
  0 => [
    'name' => 'Authorize.Net eCheck.Net',
    'entity' => 'payment_processor_type',
    'params' => [
      'version' => 3,
      'name' => 'AuthorizeNeteCheck',
      'title' => 'Authorize.net eCheck.Net',
      'class_name' => 'Payment_AuthNetEcheck',
      'user_name_label' => 'API Login',
      'password_label' => 'Payment Key',
      'signature_label' => 'MD5 Hash',
      'url_site_default'=> 'https://secure.authorize.net/gateway/transact.dll',
      'url_recur_default' => 'https://api.authorize.net/xml/v1/request.api',
      'url_site_test_default' => 'https://test.authorize.net/gateway/transact.dll',
      'url_recur_test_default' => 'https://apitest.authorize.net/xml/v1/request.api',
      'billing_mode' => 1,
      'payment_type' => 1,
      'is_recur' => 1,
    ],
  ],
];
