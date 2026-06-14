<?php

namespace paygw_webirr\local;

defined('MOODLE_INTERNAL') || die();

/**
 * Contract tests for the internal WeBirr client.
 *
 * @package    paygw_webirr
 * @copyright  2026 WeBirr
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
final class webirr_client_test extends \advanced_testcase {
    /**
     * The create bill request should use the canonical endpoint and populate
     * the merchant identity from the configured client.
     */
    public function test_create_bill_uses_client_merchant_id(): void {
        $requests = [];
        $client = new webirr_client(
            'merchant-from-client',
            'api-key',
            true,
            function(string $method, string $url, ?array $payload, array $headers) use (&$requests): array {
                $requests[] = [
                    'method' => $method,
                    'url' => $url,
                    'payload' => $payload,
                    'headers' => $headers,
                ];

                return [
                    'status' => 200,
                    'body' => '{"error":null,"res":"123 456 789"}',
                    'error' => '',
                ];
            }
        );

        $bill = (object)[
            'amount' => '530.00',
            'customerCode' => 'MOODLE-DEMO',
            'customerName' => 'Elias',
            'customerPhone' => '',
            'time' => '2026-06-13 10:30',
            'description' => 'moodle course enrollment',
            'billReference' => 'moodle/test/1',
            'merchantID' => 'merchant-on-bill',
            'extras' => [],
        ];

        $response = $client->create_bill($bill);

        $this->assertNull($response->error);
        $this->assertSame('123 456 789', $response->res);
        $this->assertCount(1, $requests);
        $this->assertSame('POST', $requests[0]['method']);
        $this->assertStringStartsWith('https://api.webirr.net/einvoice/api/bill?', $requests[0]['url']);
        $this->assertStringContainsString('api_key=api-key', $requests[0]['url']);
        $this->assertStringContainsString('merchant_id=merchant-from-client', $requests[0]['url']);
        $this->assertSame('merchant-from-client', $requests[0]['payload']['merchantID']);
        $this->assertSame('530.00', $requests[0]['payload']['amount']);
        $this->assertSame('Elias', $requests[0]['payload']['customerName']);
        $this->assertSame('', $requests[0]['payload']['customerPhone']);
        $this->assertInstanceOf(\stdClass::class, $requests[0]['payload']['extras']);
    }

    /**
     * Empty merchant IDs must not be sent as query parameters or overwrite a
     * bill value.
     */
    public function test_empty_client_merchant_id_is_omitted(): void {
        $requests = [];
        $client = new webirr_client(
            '',
            'api-key',
            false,
            function(string $method, string $url, ?array $payload, array $headers) use (&$requests): array {
                $requests[] = [
                    'method' => $method,
                    'url' => $url,
                    'payload' => $payload,
                    'headers' => $headers,
                ];

                return [
                    'status' => 200,
                    'body' => '{"error":null,"res":"123 456 789"}',
                    'error' => '',
                ];
            }
        );

        $bill = (object)[
            'amount' => '530.00',
            'customerCode' => 'MOODLE-DEMO',
            'customerName' => 'Elias',
            'time' => '2026-06-13 10:30',
            'description' => 'moodle course enrollment',
            'billReference' => 'moodle/test/2',
            'merchantID' => 'merchant-on-bill',
        ];

        $client->create_bill($bill);

        $this->assertCount(1, $requests);
        $this->assertStringStartsWith('https://api.webirr.net:8080/einvoice/api/bill?', $requests[0]['url']);
        $this->assertStringContainsString('api_key=api-key', $requests[0]['url']);
        $this->assertStringNotContainsString('merchant_id=', $requests[0]['url']);
        $this->assertSame('merchant-on-bill', $requests[0]['payload']['merchantID']);
        $this->assertSame('', $requests[0]['payload']['customerPhone']);
    }

    /**
     * Payment status should use the canonical single-status endpoint.
     */
    public function test_get_payment_status_uses_canonical_endpoint(): void {
        $requests = [];
        $client = new webirr_client(
            'merchant-from-client',
            'api-key',
            true,
            function(string $method, string $url, ?array $payload, array $headers) use (&$requests): array {
                $requests[] = [
                    'method' => $method,
                    'url' => $url,
                    'payload' => $payload,
                    'headers' => $headers,
                ];

                return [
                    'status' => 200,
                    'body' => '{"error":null,"res":{"status":0}}',
                    'error' => '',
                ];
            }
        );

        $response = $client->get_payment_status('123 456 789');

        $this->assertNull($response->error);
        $this->assertSame(0, $response->res->status);
        $this->assertCount(1, $requests);
        $this->assertSame('GET', $requests[0]['method']);
        $this->assertNull($requests[0]['payload']);
        $this->assertStringStartsWith('https://api.webirr.net/einvoice/api/paymentStatus?', $requests[0]['url']);
        $this->assertStringContainsString('api_key=api-key', $requests[0]['url']);
        $this->assertStringContainsString('merchant_id=merchant-from-client', $requests[0]['url']);
        $this->assertStringContainsString('wbc_code=123%20456%20789', $requests[0]['url']);
    }

    /**
     * HTTP and JSON errors should be normalized to the gateway response shape.
     */
    public function test_transport_errors_are_normalized(): void {
        $client = new webirr_client(
            'merchant-from-client',
            'api-key',
            true,
            function(): array {
                return [
                    'status' => 500,
                    'body' => 'server error',
                    'error' => '',
                ];
            }
        );

        $response = $client->get_payment_status('123 456 789');

        $this->assertSame('http error 500', $response->error);
        $this->assertNull($response->res);
    }
}
