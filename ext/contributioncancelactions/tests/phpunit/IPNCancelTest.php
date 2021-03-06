<?php

use CRM_Contributioncancelactions_ExtensionUtil as E;
use Civi\Test\HeadlessInterface;
use Civi\Test\HookInterface;
use Civi\Test\TransactionalInterface;
use Civi\Api4\Contact;
use Civi\Api4\MembershipType;

/**
 * FIXME - Add test description.
 *
 * Tips:
 *  - With HookInterface, you may implement CiviCRM hooks directly in the test class.
 *    Simply create corresponding functions (e.g. "hook_civicrm_post(...)" or similar).
 *  - With TransactionalInterface, any data changes made by setUp() or test****() functions will
 *    rollback automatically -- as long as you don't manipulate schema or truncate tables.
 *    If this test needs to manipulate schema or truncate tables, then either:
 *       a. Do all that using setupHeadless() and Civi\Test.
 *       b. Disable TransactionalInterface, and handle all setup/teardown yourself.
 *
 * @group headless
 */
class IPNCancelTest extends \PHPUnit\Framework\TestCase implements HeadlessInterface, HookInterface, TransactionalInterface {

  use \Civi\Test\Api3TestTrait;

  /**
   * Created ids.
   *
   * @var array
   */
  protected $ids = [];

  /**
   * The setupHeadless function runs at the start of each test case, right before
   * the headless environment reboots.
   *
   * It should perform any necessary steps required for putting the database
   * in a consistent baseline -- such as loading schema and extensions.
   *
   * The utility `\Civi\Test::headless()` provides a number of helper functions
   * for managing this setup, and it includes optimizations to avoid redundant
   * setup work.
   *
   * @see \Civi\Test
   */
  public function setUpHeadless() {
    // Civi\Test has many helpers, like install(), uninstall(), sql(), and sqlFile().
    // See: https://docs.civicrm.org/dev/en/latest/testing/phpunit/#civitest
    return \Civi\Test::headless()
      ->installMe(__DIR__)
      ->apply();
  }

  /**
   * Test that a cancel from paypal pro results in an order being cancelled.
   *
   * @throws \API_Exception
   * @throws \CRM_Core_Exception
   * @throws \Civi\API\Exception\UnauthorizedException
   */
  public function testPaypalProCancel() {
    $this->createMembershipOrder();
    $membership = $this->callAPISuccessGetSingle('Membership', []);
    $membership['owner_membership_id'] = $membership['id'];
    $membership['contact_id'] = Contact::create()->setValues(['first_name' => 'Bugs', 'last_name' => 'Bunny'])->execute()->first()['id'];
    unset($membership['id']);
    $this->callAPISuccess('Membership', 'create', $membership);

    $ipn = new CRM_Core_Payment_PayPalProIPN([
      'rp_invoice_id' => http_build_query([
        'b' => $this->ids['Contribution'][0],
        'm' => 'contribute',
        'i' => 'zyx',
        'c' => $this->ids['contact'][0],
      ]),
      'mc_gross' => 200,
      'payment_status' => 'Refunded',
      'processor_id' => $this->createPaymentProcessor(),
    ]);
    $ipn->main();
    $this->callAPISuccessGetSingle('Contribution', ['contribution_status_id' => 'Cancelled']);
    $this->callAPISuccessGetCount('Membership', ['status_id' => 'Cancelled'], 2);
  }

  /**
   * Create an order with more than one membership.
   *
   * @throws \API_Exception
   * @throws \CRM_Core_Exception
   * @throws \Civi\API\Exception\UnauthorizedException
   */
  protected function createMembershipOrder() {
    $this->ids['contact'][0] = Civi\Api4\Contact::create()->setValues(['first_name' => 'Brer', 'last_name' => 'Rabbit'])->execute()->first()['id'];
    $this->createMembershipType();
    $priceFieldID = $this->callAPISuccessGetValue('price_field', [
      'return' => 'id',
      'label' => 'Membership Amount',
      'options' => ['limit' => 1, 'sort' => 'id DESC'],
    ]);
    $generalPriceFieldValueID = $this->callAPISuccessGetValue('price_field_value', [
      'return' => 'id',
      'label' => 'General',
      'options' => ['limit' => 1, 'sort' => 'id DESC'],
    ]);

    $orderID = $this->callAPISuccess('Order', 'create', [
      'financial_type_id' => 'Member Dues',
      'contact_id' => $this->ids['contact'][0],
      'is_test' => 0,
      'payment_instrument_id' => 'Credit card',
      'receive_date' => '2019-07-25 07:34:23',
      'invoice_id' => 'zyx',
      'line_items' => [
        [
          'params' => [
            'contact_id' => $this->ids['contact'][0],
            'source' => 'Payment',
            'membership_type_id' => 'General',
            // This is interim needed while we improve the BAO - if the test passes without it it can go!
            'skipStatusCal' => TRUE,
          ],
          'line_item' => [
            [
              'label' => 'General',
              'qty' => 1,
              'unit_price' => 200,
              'line_total' => 200,
              'financial_type_id' => 1,
              'entity_table' => 'civicrm_membership',
              'price_field_id' => $priceFieldID,
              'price_field_value_id' => $generalPriceFieldValueID,
            ],
          ],
        ],
      ],
    ])['id'];
    $this->ids['Contribution'][0] = $orderID;
  }

  /**
   * Create the general membership type.
   *
   * @throws \API_Exception
   * @throws \Civi\API\Exception\UnauthorizedException
   */
  protected function createMembershipType(): void {
    MembershipType::create()->setValues([
      'name' => 'General',
      'duration_unit' => 'year',
      'duration_interval' => 1,
      'period_type' => 'rolling',
      'member_of_contact_id' => 1,
      'domain_id' => 1,
      'financial_type_id' => 2,
      'is_active' => 1,
      'sequential' => 1,
      'visibility' => 'Public',
    ])->execute();
  }

  /**
   * Create a payment processor.
   *
   * @param array $params
   *
   * @return int
   * @throws \CRM_Core_Exception
   */
  public function createPaymentProcessor($params = []) {
    $params = array_merge([
      'name' => 'demo',
      'domain_id' => CRM_Core_Config::domainID(),
      'payment_processor_type_id' => 'PayPal',
      'is_active' => 1,
      'is_default' => 0,
      'is_test' => 1,
      'user_name' => 'sunil._1183377782_biz_api1.webaccess.co.in',
      'password' => '1183377788',
      'signature' => 'APixCoQ-Zsaj-u3IH7mD5Do-7HUqA9loGnLSzsZga9Zr-aNmaJa3WGPH',
      'url_site' => 'https://www.sandbox.paypal.com/',
      'url_api' => 'https://api-3t.sandbox.paypal.com/',
      'url_button' => 'https://www.paypal.com/en_US/i/btn/btn_xpressCheckout.gif',
      'class_name' => 'Payment_PayPalImpl',
      'billing_mode' => 3,
      'financial_type_id' => 1,
      'financial_account_id' => 12,
      // Credit card = 1 so can pass 'by accident'.
      'payment_instrument_id' => 'Debit Card',
    ], $params);
    if (!is_numeric($params['payment_processor_type_id'])) {
      // really the api should handle this through getoptions but it's not exactly api call so lets just sort it
      //here
      $params['payment_processor_type_id'] = $this->callAPISuccess('payment_processor_type', 'getvalue', [
        'name' => $params['payment_processor_type_id'],
        'return' => 'id',
      ], 'integer');
    }
    $result = $this->callAPISuccess('payment_processor', 'create', $params);
    return (int) $result['id'];
  }

}
