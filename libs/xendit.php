<?php
namespace Xendit;


class XenditClient {
    private $api_key;
    private $api_url = 'https://api.xendit.co';

    public function __construct($api_key) {
        $this->api_key = $api_key;
    }

    private function _request($path, $data = array(), $method = 'GET') {
        $curl = curl_init();
        curl_setopt($curl, CURLOPT_URL, $this->api_url . $path);
        curl_setopt($curl, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($curl, CURLOPT_USERPWD, $this->api_key . ':');
        curl_setopt($curl, CURLOPT_HTTPAUTH, CURLAUTH_BASIC);
        curl_setopt($curl, CURLOPT_CUSTOMREQUEST, $method);
        curl_setopt($curl, CURLOPT_POSTFIELDS, json_encode($data));
        curl_setopt($curl, CURLOPT_HTTPHEADER, array(
            'Content-Type: application/json',
            'Accept: application/json'
        ));
        $response = curl_exec($curl);
        curl_close($curl);
        return json_decode($response, false);
    }

    public function create_invoice($data) {
        return $this->_request('/v2/invoices', $data, 'POST');
    }

    public function get_invoice($invoice_id) {
        return $this->_request('/v2/invoices/?external_id=' . $invoice_id);
    }

    public function get_invoice_list($params = array()) {
        return $this->_request('/v2/invoices?' . http_build_query($params));
    }

    public function get_balance($type = 'CASH', $currency = 'IDR') {
        return $this->_request('/balance?account_type=' . $type . '&currency=' . $currency);
    }
}