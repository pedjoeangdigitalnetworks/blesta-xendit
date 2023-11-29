<?php
/**
 * Xendit Gateway
 *
 * Xendit API reference: https://developers.xendit.co/api-reference/
 *
 * @package blesta
 * @subpackage blesta.components.gateways.xendit
 * @copyright Copyright (c) 2023, Phillips Data, Inc.
 * @license http://www.blesta.com/license/ The Blesta License Agreement
 * @link http://www.pedjoeangdigital.net/ Pedjoeang Digital Network
 */
require_once dirname(__FILE__) . DS . 'libs' . DS . 'xendit.php';

use Xendit\XenditClient;

class Xendit extends NonmerchantGateway
{
    /**
     * @var array An array of meta data for this gateway
     */
    private $meta;

    /**
     * Construct a new merchant gateway
     */
    public function __construct()
    {
        // Load configuration required by this gateway
        $this->loadConfig(dirname(__FILE__) . DS . 'config.json');

        // Load components required by this gateway
        Loader::loadComponents($this, ['Input']);

        // Load the helpers required for this gateway
        Loader::loadHelpers($this, ['Html']);

        // Load the language required by this gateway
        Language::loadLang('xendit', null, dirname(__FILE__) . DS . 'language' . DS);
    }

    /**
     * Sets the meta data for this particular gateway
     *
     * @param array $meta An array of meta data to set for this gateway
     */
    public function setMeta(array $meta = null)
    {
        $this->meta = $meta;
    }

    /**
     * Create and return the view content required to modify the settings of this gateway
     *
     * @param array $meta An array of meta (settings) data belonging to this gateway
     * @return string HTML content containing the fields to update the meta data for this gateway
     */
    public function getSettings(array $meta = null)
    {
        // Load the view into this object, so helpers can be automatically add to the view
        $this->view = new View('settings', 'default');
        $this->view->setDefaultView('components' . DS . 'gateways' . DS . 'nonmerchant' . DS . 'xendit' . DS);

        // Load the helpers required for this view
        Loader::loadHelpers($this, ['Form', 'Html']);

        $this->view->set('meta', $meta);

        return $this->view->fetch();
    }

    /**
     * Validates the given meta (settings) data to be updated for this gateway
     *
     * @param array $meta An array of meta (settings) data to be updated for this gateway
     * @return array The meta data to be updated in the database for this gateway, or reset into the form on failure
     */
    public function editSettings(array $meta)
    {
        $rules = [
            'api_key' => [
                'valid' => [
                    'rule' => function ($api_key) use ($meta) {
                        try {
                            $client = new XenditClient($api_key);

                            $balance = $client->get_balance('CASH');
                            return isset($balance->balance);
                        } catch (Throwable $e) {
                            return false;
                        }
                    },
                    'message' => Language::_('Xendit.!error.api_key.valid', true)
                ],
                'empty' => [
                    'rule' => 'isEmpty',
                    'negate' => true,
                    'message' => Language::_('Xendit.!error.api_key.empty', true)
                ]
            ]
        ];
        $this->Input->setRules($rules);

        // Validate the given meta data to ensure it meets the requirements
        $this->Input->validates($meta);

        // Return the meta data, no changes required regardless of success or failure for this gateway
        return $meta;
    }

    /**
     * Returns an array of all fields to encrypt when storing in the database
     *
     * @return array An array of the field names to encrypt when storing in the database
     */
    public function encryptableFields()
    {
        return ['api_key'];
    }

    /**
     * Sets the currency code to be used for all subsequent payments
     *
     * @param string $currency The ISO 4217 currency code to be used for subsequent payments
     */
    public function setCurrency($currency)
    {
        $this->currency = $currency;
    }

    /**
     * Returns all HTML markup required to render an authorization and capture payment form
     *
     * @param array $contact_info An array of contact info including:
     *  - id The contact ID
     *  - client_id The ID of the client this contact belongs to
     *  - user_id The user ID this contact belongs to (if any)
     *  - contact_type The type of contact
     *  - contact_type_id The ID of the contact type
     *  - first_name The first name on the contact
     *  - last_name The last name on the contact
     *  - title The title of the contact
     *  - company The company name of the contact
     *  - address1 The address 1 line of the contact
     *  - address2 The address 2 line of the contact
     *  - city The city of the contact
     *  - state An array of state info including:
     *      - code The 2 or 3-character state code
     *      - name The local name of the country
     *  - country An array of country info including:
     *      - alpha2 The 2-character country code
     *      - alpha3 The 3-cahracter country code
     *      - name The english name of the country
     *      - alt_name The local name of the country
     *  - zip The zip/postal code of the contact
     * @param float $amount The amount to charge this contact
     * @param array $invoice_amounts An array of invoices, each containing:
     *  - id The ID of the invoice being processed
     *  - amount The amount being processed for this invoice (which is included in $amount)
     * @param array $options An array of options including:
     *  - description The Description of the charge
     *  - return_url The URL to redirect users to after a successful payment
     *  - recur An array of recurring info including:
     *      - amount The amount to recur
     *      - term The term to recur
     *      - period The recurring period (day, week, month, year, onetime) used in conjunction
     *          with term in order to determine the next recurring payment
     * @return string HTML markup required to render an authorization and capture payment form
     */
    public function buildProcess(array $contact_info, $amount, array $invoice_amounts = null, array $options = null)
    {
        // Force 2-decimal places only
        $amount = round($amount, 2);
        if (isset($options['recur']['amount'])) {
            $options['recur']['amount'] = round($options['recur']['amount'], 2);
        }

        // Initialize API
        $client = new XenditClient($this->meta['api_key']);

        Loader::loadModels($this, ['Contacts']);
        $contact_numbers = $this->Contacts->getNumbers($contact_info['id'], 'phone');
        // Set invoice parameters
        $address = [
            'city' => ($contact_info['city'] ?? null),
            'country' => ($contact_info['country']['name'] ?? null),
            'postal_code' => ($contact_info['zip'] ?? null),
            'state' => ($contact_info['state']['name'] ?? null),
            'street' => $this->Html->concat(
                ' ',
                ($contact_info['address1'] ?? null),
                ($contact_info['address2'] ?? null)
            )
        ];
        $fees = (3/100) * $amount;
        $params = [
            'external_id' => base64_encode($this->serializeInvoices($invoice_amounts)),
            'amount' => $amount + $fees,
            'description' => $options['description'] ?? 'Payment',
            'invoice_duration' => 86400,
            'customer' => [
                'given_names' => ($contact_info['first_name'] ?? null),
                'surname' => ($contact_info['last_name'] ?? null),
                'mobile_number' =>preg_replace('/[^0-9]/', '', $contact_numbers[0]->number),
                'addresses' => [$address]
            ],
            'client_type' => 'INTEGRATION',
            'platform_callback_url' => Configure::get('Blesta.gw_callback_url')
                . Configure::get('Blesta.company_id') . '/xendit/?client_id='
                . ($contact_info['client_id'] ?? null),
            'success_redirect_url' => ($options['return_url']  . "&invoice_id=" . ($invoice_amounts[0]->id ?? null) ?? null),
            'failure_redirect_url' => ($options['return_url'] . "&invoice_id=" . ($invoice_amounts[0]->id ?? null) ?? null),
            'currency' => 'IDR',
            'fees' => [
                [
                    'type' => 'ADMIN',
                    'value' => $fees
                ]
            ]
            // 3% from total amount
        ];
        // Create invoice
        try {
            $invoice = $client->create_invoice($params);
        } catch (Exception $e) {
            $this->Input->setErrors([$e->getMessage()]);
        }
        // Set view
        $this->view = $this->makeView('process', 'default', str_replace(ROOTWEBDIR, '', dirname(__FILE__) . DS));
        $this->view->set('post_to', $invoice->invoice_url ?? null);

        // Load the helpers required for this view
        Loader::loadHelpers($this, ['Form', 'Html']);

        return $this->view->fetch();
    }

    /**
     * Validates the incoming POST/GET response from the gateway to ensure it is
     * legitimate and can be trusted.
     *
     * @param array $get The GET data for this request
     * @param array $post The POST data for this request
     * @return array An array of transaction data, sets any errors using Input if the data fails to validate
     *  - client_id The ID of the client that attempted the payment
     *  - amount The amount of the payment
     *  - currency The currency of the payment
     *  - invoices An array of invoices and the amount the payment should be applied to (if any) including:
     *      - id The ID of the invoice to apply to
     *      - amount The amount to apply to the invoice
     *  - status The status of the transaction (approved, declined, void, pending, reconciled, refunded, returned)
     *  - reference_id The reference ID for gateway-only use with this transaction (optional)
     *  - transaction_id The ID returned by the gateway to identify this transaction
     *  - parent_transaction_id The ID returned by the gateway to identify this
     *      transaction's original transaction (in the case of refunds)
     */
    public function validate(array $get, array $post)
    {
        // Initialize API
        $php_input = file_get_contents('php://input');
        $json = json_decode($php_input);
        $invoice_id  = $get['invoice_id'];
        $is_from_webhook = false;
        if (isset($json->external_id)) {
            $invoice_id = $json->external_id;
            $is_from_webhook = true;
        }
        $client_id = $get['client_id'];
        $client = new XenditClient($this->meta['api_key']);
        $success_redirect_url = $json->success_redirect_url;
        $failure_redirect_url = $json->failure_redirect_url;

        // get client id from success redirect url
        $client_id = explode('client_id=', $success_redirect_url);
        $client_id = explode('&', $client_id[1]);
        $client_id = $client_id[0];

        // Get transaction
        if (!$is_from_webhook) {
            try {
                $transaction = $client->get_invoice($invoice_id);
            } catch (Exception $e) {
                $this->Input->setErrors([$e->getMessage()]);
            }

            if (count($transaction) >= 1) {
                $transaction = $transaction[0];
            } else {
                file_put_contents('/var/tmp/failed_xendit.txt', json_encode($transaction) . "\n\n", FILE_APPEND);
                return [];
            }

        } else {
            $transaction = $json;
        }

        // load client info from invoice number
        // $this->loadModel('Clients');

        // Set status
        $status = 'error';
        $success = false;
        if (isset($transaction->status)) {
            $success = true;
            $trxStatus = strtolower($transaction->status);
            switch ($trxStatus) {
                case 'settled':
                    $status = 'approved';
                    break;
                case 'paid':
                    $status = 'approved';
                    break;
                case 'pending':
                    $status = 'pending';
                    break;
                default:
                    $status = 'declined';
                    break;
            }
        }

        if (!$success) {
            return;
        }

        $fees = (3/100) * $transaction->paid_amount;
        $paid_amount = $transaction->paid_amount - $fees;
        return [
            'client_id' => ($client_id ?? null),
            'amount' => ($paid_amount ?? null),
            'currency' => 'IDR',
            'invoices' => $this->unserializeInvoices(base64_decode($transaction->external_id)),
            'status' => $status,
            'reference_id' => ($transaction->id ?? null),
            'transaction_id' => ($transaction->payment_id ?? null),
            'parent_transaction_id' => null
        ];
    }

    /**
     * Returns data regarding a success transaction. This method is invoked when
     * a client returns from the non-merchant gateway's web site back to Blesta.
     *
     * @param array $get The GET data for this request
     * @param array $post The POST data for this request
     * @return array An array of transaction data, may set errors using Input if the data appears invalid
     *  - client_id The ID of the client that attempted the payment
     *  - amount The amount of the payment
     *  - currency The currency of the payment
     *  - invoices An array of invoices and the amount the payment should be applied to (if any) including:
     *      - id The ID of the invoice to apply to
     *      - amount The amount to apply to the invoice
     *  - status The status of the transaction (approved, declined, void, pending, reconciled, refunded, returned)
     *  - transaction_id The ID returned by the gateway to identify this transaction
     *  - parent_transaction_id The ID returned by the gateway to identify this transaction's original transaction
     */
    public function success(array $get, array $post)
    {
        // Initialize API
        $client = new XenditClient($this->meta['api_key']);
        // Get transaction
        try {
            $transaction = $client->get_invoice($get['invoice_id']);
        } catch (Exception $e) {
            $this->Input->setErrors([$e->getMessage()]);
        }

        if (count($transaction) >= 1) {
            $transaction = $transaction[0];
        }

        file_put_contents('/var/tmp/success_xendit.txt', json_encode($transaction) . "\n\n", FILE_APPEND);

        // Set status
        $status = 'error';
        if (isset($transaction->status)) {
            $trxStatus = strtolower($transaction->status);
            switch ($trxStatus) {
                case 'settled':
                    $status = 'approved';
                    break;
                case 'paid':
                    $status = 'approved';
                    break;
                case 'pending':
                    $status = 'pending';
                    break;
                default:
                    $status = 'declined';
                    break;
            }
        }

        $params = [
            'client_id' => ($get['client_id'] ?? null),
            'amount' => ($transaction->amount ?? null),
            'currency' => 'IDR',
            'invoices' => null,
            'status' => $status,
            'transaction_id' => ($transaction->id ?? null),
            'parent_transaction_id' => null
        ];

        return $params;
    }

    /**
     * Refund a payment
     *
     * @param string $reference_id The reference ID for the previously submitted transaction
     * @param string $transaction_id The transaction ID for the previously submitted transaction
     * @param float $amount The amount to refund this transaction
     * @param string $notes Notes about the refund that may be sent to the client by the gateway
     * @return array An array of transaction data including:
     *  - status The status of the transaction (approved, declined, void, pending, reconciled, refunded, returned)
     *  - reference_id The reference ID for gateway-only use with this transaction (optional)
     *  - transaction_id The ID returned by the remote gateway to identify this transaction
     *  - message The message to be displayed in the interface in addition to the standard
     *      message for this transaction status (optional)
     */
    public function refund($reference_id, $transaction_id, $amount, $notes = null)
    {
        $this->Input->setErrors($this->getCommonError('unsupported'));
    }

    /**
     * Void a payment or authorization.
     *
     * @param string $reference_id The reference ID for the previously submitted transaction
     * @param string $transaction_id The transaction ID for the previously submitted transaction
     * @param string $notes Notes about the void that may be sent to the client by the gateway
     * @return array An array of transaction data including:
     *  - status The status of the transaction (approved, declined, void, pending, reconciled, refunded, returned)
     *  - reference_id The reference ID for gateway-only use with this transaction (optional)
     *  - transaction_id The ID returned by the remote gateway to identify this transaction
     *  - message The message to be displayed in the interface in addition to the standard
     *      message for this transaction status (optional)
     */
    public function void($reference_id, $transaction_id, $notes = null)
    {
        $this->Input->setErrors($this->getCommonError('unsupported'));
    }

    /**
     * Serializes an array of invoice info into a string
     *
     * @param array A numerically indexed array invoices info including:
     *  - id The ID of the invoice
     *  - amount The amount relating to the invoice
     * @return string A serialized string of invoice info in the format of key1=value1|key2=value2
     */
    private function serializeInvoices(array $invoices)
    {
        $str = '';
        foreach ($invoices as $i => $invoice) {
            $str .= ($i > 0 ? '|' : '') . $invoice['id'] . '=' . $invoice['amount'];
        }
        return $str;
    }

    /**
     * Unserializes a string of invoice info into an array
     *
     * @param string A serialized string of invoice info in the format of key1=value1|key2=value2
     * @return array A numerically indexed array invoices info including:
     *  - id The ID of the invoice
     *  - amount The amount relating to the invoice
     */
    private function unserializeInvoices($str)
    {
        $invoices = [];
        $temp = explode('|', $str);
        foreach ($temp as $pair) {
            $pairs = explode('=', $pair, 2);
            if (count($pairs) != 2) {
                continue;
            }
            $invoices[] = ['id' => $pairs[0], 'amount' => $pairs[1]];
        }
        return $invoices;
    }
}
