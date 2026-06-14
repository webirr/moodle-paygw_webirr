<?php

namespace paygw_webirr\local;

defined('MOODLE_INTERNAL') || die();

/**
 * Small WeBirr API client for the Moodle payment gateway plugin.
 *
 * This intentionally covers only the Moodle checkout surface instead of
 * depending on the standalone PHP SDK at runtime.
 *
 * @package    paygw_webirr
 * @copyright  2026 WeBirr
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class webirr_client {
    /** @var string WeBirr TestEnv base URL. */
    private const TEST_BASE_URL = 'https://api.webirr.net';

    /** @var string WeBirr production base URL. */
    private const PROD_BASE_URL = 'https://api.webirr.net:8080';

    /** @var string Merchant ID configured in the Moodle payment account. */
    private string $merchantid;

    /** @var string API key configured in the Moodle payment account. */
    private string $apikey;

    /** @var string Base URL chosen from the TestEnv setting. */
    private string $baseurl;

    /** @var callable|null Optional test/demo transport. */
    private $transport;

    /**
     * @param string $merchantid WeBirr merchant ID.
     * @param string $apikey WeBirr API key.
     * @param bool $testmode Whether to use WeBirr TestEnv.
     * @param callable|null $transport Optional transport used by tests/demo code.
     * @param string|null $baseurl Optional base URL override used by tests only.
     */
    public function __construct(
        string $merchantid,
        string $apikey,
        bool $testmode,
        ?callable $transport = null,
        ?string $baseurl = null
    ) {
        $this->merchantid = trim($merchantid);
        $this->apikey = trim($apikey);
        $this->baseurl = rtrim($baseurl ?: ($testmode ? self::TEST_BASE_URL : self::PROD_BASE_URL), '/');
        $this->transport = $transport;
    }

    /**
     * Create a WeBirr bill and return the gateway API response object.
     *
     * @param \stdClass $bill Moodle checkout bill data.
     * @return \stdClass Gateway response with error/res fields.
     */
    public function create_bill(\stdClass $bill): \stdClass {
        return $this->request('POST', 'einvoice/api/bill', [], $this->bill_payload($bill));
    }

    /**
     * Fetch single payment status for a WeBirr payment code.
     *
     * @param string $paymentcode WeBirr payment code / WBC code.
     * @return \stdClass Gateway response with error/res fields.
     */
    public function get_payment_status(string $paymentcode): \stdClass {
        return $this->request('GET', 'einvoice/api/paymentStatus', [
            'wbc_code' => $paymentcode,
        ]);
    }

    /**
     * Build a gateway request and decode the response.
     *
     * @param string $method HTTP method.
     * @param string $path Gateway path relative to the base URL.
     * @param array $params Extra query parameters.
     * @param array|null $payload Optional JSON payload.
     * @return \stdClass Gateway response object.
     */
    private function request(string $method, string $path, array $params = [], ?array $payload = null): \stdClass {
        $url = $this->build_url($path, $params);
        $headers = ['Accept: application/json'];
        if ($payload !== null) {
            $headers[] = 'Content-Type: application/json';
        }

        $response = $this->transport
            ? call_user_func($this->transport, $method, $url, $payload, $headers)
            : $this->send_with_moodle_curl($method, $url, $payload, $headers);

        return $this->decode_response($response);
    }

    /**
     * Build a WeBirr gateway URL with the common merchant query parameters.
     *
     * @param string $path Gateway path relative to the base URL.
     * @param array $params Extra query parameters.
     * @return string Fully qualified URL.
     */
    private function build_url(string $path, array $params): string {
        $query = ['api_key' => $this->apikey];
        if ($this->merchantid !== '') {
            $query['merchant_id'] = $this->merchantid;
        }

        $query = array_merge($query, $params);

        return $this->baseurl . '/' . ltrim($path, '/') . '?' . http_build_query($query, '', '&', PHP_QUERY_RFC3986);
    }

    /**
     * Convert Moodle bill data to the gateway-compatible bill payload.
     *
     * @param \stdClass $bill Moodle checkout bill data.
     * @return array Gateway JSON payload.
     */
    private function bill_payload(\stdClass $bill): array {
        $merchantid = (string)($bill->merchantID ?? '');
        if ($this->merchantid !== '') {
            $merchantid = $this->merchantid;
        }

        $extras = $bill->extras ?? [];
        if (is_array($extras) && empty($extras)) {
            $extras = new \stdClass();
        }

        return [
            'amount' => (string)($bill->amount ?? ''),
            'customerCode' => (string)($bill->customerCode ?? ''),
            'customerName' => (string)($bill->customerName ?? ''),
            'customerPhone' => (string)($bill->customerPhone ?? ''),
            'time' => (string)($bill->time ?? ''),
            'description' => (string)($bill->description ?? ''),
            'billReference' => (string)($bill->billReference ?? ''),
            'merchantID' => $merchantid,
            'extras' => $extras,
        ];
    }

    /**
     * Send the HTTP request using Moodle's curl wrapper.
     *
     * @param string $method HTTP method.
     * @param string $url Fully qualified URL.
     * @param array|null $payload Optional JSON payload.
     * @param array $headers Request headers.
     * @return array Response shape consumed by decode_response().
     */
    private function send_with_moodle_curl(string $method, string $url, ?array $payload, array $headers): array {
        global $CFG;

        if (!class_exists('\\curl') && isset($CFG->libdir)) {
            require_once($CFG->libdir . '/filelib.php');
        }

        if (!class_exists('\\curl')) {
            return [
                'status' => 0,
                'body' => '',
                'error' => 'Moodle curl class is not available',
            ];
        }

        $curl = new \curl();
        $curl->setHeader($headers);

        $body = $payload === null ? '' : json_encode($payload, JSON_UNESCAPED_SLASHES);
        if ($body === false) {
            return [
                'status' => 0,
                'body' => '',
                'error' => 'Unable to encode WeBirr request payload',
            ];
        }

        if ($method === 'POST') {
            $responsebody = $curl->post($url, $body);
        } else {
            $responsebody = $curl->get($url);
        }

        $info = $curl->get_info();

        return [
            'status' => (int)($info['http_code'] ?? 0),
            'body' => (string)$responsebody,
            'error' => $curl->error ?? '',
        ];
    }

    /**
     * Decode a transport response into the API response object shape.
     *
     * @param mixed $response Response body string or array with status/body/error.
     * @return \stdClass Gateway response object.
     */
    private function decode_response($response): \stdClass {
        $status = 200;
        $body = $response;
        $transporterror = '';

        if (is_array($response)) {
            $status = (int)($response['status'] ?? 200);
            $body = $response['body'] ?? '';
            $transporterror = (string)($response['error'] ?? '');
        }

        if ($transporterror !== '') {
            return $this->error_response($transporterror);
        }

        if ($status < 200 || $status >= 300) {
            return $this->error_response('http error ' . $status);
        }

        $decoded = json_decode((string)$body);
        if (json_last_error() !== JSON_ERROR_NONE || !is_object($decoded)) {
            return $this->error_response('invalid response from WeBirr');
        }

        return $decoded;
    }

    /**
     * Create a gateway-style error response.
     *
     * @param string $message Error message.
     * @return \stdClass Error response.
     */
    private function error_response(string $message): \stdClass {
        return (object)[
            'error' => $message,
            'res' => null,
        ];
    }
}
