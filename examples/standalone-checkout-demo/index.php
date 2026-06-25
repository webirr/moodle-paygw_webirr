<?php

declare(strict_types=1);

defined('MOODLE_INTERNAL') || define('MOODLE_INTERNAL', true);

require_once(__DIR__ . '/../../plugin/webirr/classes/local/webirr_client.php');

use paygw_webirr\local\webirr_client;

$path = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH) ?: '/';
$pluginroot = dirname(__DIR__, 2) . '/plugin/webirr';
$requestedfile = realpath($pluginroot . $path);

if (
    in_array($_SERVER['REQUEST_METHOD'], ['GET', 'HEAD'], true) &&
    $requestedfile !== false &&
    strpos($requestedfile, $pluginroot . DIRECTORY_SEPARATOR) === 0 &&
    is_file($requestedfile)
) {
    $contenttype = function_exists('mime_content_type') ? mime_content_type($requestedfile) : false;
    header('Content-Type: ' . ($contenttype ?: 'application/octet-stream'));
    header('Content-Length: ' . (string)filesize($requestedfile));
    if ($_SERVER['REQUEST_METHOD'] === 'HEAD') {
        return;
    }
    readfile($requestedfile);
    return;
}

if (strpos($path, '/api/') === 0) {
    handle_api($path);
    return;
}

render_page();

function handle_api(string $path): void {
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
        json_response(['success' => false, 'error' => 'POST required'], 405);
    }

    try {
        if ($path === '/api/create-bill') {
            create_bill();
            return;
        }

        if ($path === '/api/payment-status') {
            payment_status();
            return;
        }

        json_response(['success' => false, 'error' => 'Unknown endpoint'], 404);
    } catch (Throwable $throwable) {
        json_response(['success' => false, 'error' => $throwable->getMessage()], 500);
    }
}

function create_bill(): void {
    $payload = read_json_payload();
    $course = find_demo_course((string)($payload['courseId'] ?? ''));
    if (!$course) {
        json_response(['success' => false, 'error' => 'Choose a valid Moodle course.'], 400);
    }

    $amount = format_amount((string)$course['amount']);
    $customername = trim((string)($payload['customerName'] ?? ''));
    if ($customername === '') {
        json_response(['success' => false, 'error' => 'Customer name is required.'], 400);
    }
    $customerphone = trim((string)($payload['customerPhone'] ?? ''));
    $description = (string)$course['title'] . ' - moodle course enrollment';
    $merchantreference = normalize_merchant_reference((string)($payload['merchantReference'] ?? default_merchant_reference()));
    $billreference = build_demo_bill_reference($merchantreference);
    $detailshash = details_hash($customername, $amount, $description);

    $bill = new stdClass();
    $bill->amount = $amount;
    $bill->customerCode = 'MOODLE-DEMO';
    $bill->customerName = $customername !== '' ? $customername : 'Elias';
    $bill->customerPhone = $customerphone;
    $bill->time = date('Y-m-d H:i');
    $bill->description = $description !== '' ? $description : 'moodle course enrollment';
    $bill->billReference = $billreference;

    $client = create_webirr_client();
    $db = demo_db();
    $existing = find_demo_payment($db, $billreference);

    if ($existing && !empty($existing['payment_code'])) {
        $payment = reuse_demo_payment($db, $existing, $bill, $merchantreference, $detailshash, $client);
        json_response(payment_code_response($payment, $payment['operation'] ?? 'reused', $client));
    }

    $recovered = $client->get_bill_by_reference($billreference);
    if (empty($recovered->error)) {
        $paymentcode = extract_bill_payment_code($recovered);
        if ($paymentcode === '') {
            json_response(['success' => false, 'error' => 'Invalid bill lookup response from WeBirr'], 502);
        }

        $status = extract_bill_status($recovered);
        $operation = 'recovered';
        if ($status !== 2 && bill_details_changed($recovered, $bill)) {
            $updated = $client->update_bill($bill);
            if (!empty($updated->error)) {
                json_response([
                    'success' => false,
                    'error' => $updated->error,
                    'errorCode' => $updated->errorCode ?? null,
                ], 502);
            }
            $operation = 'updated';
        }

        $payment = save_demo_payment(
            $db,
            null,
            $merchantreference,
            $billreference,
            $paymentcode,
            $amount,
            $bill->customerName,
            $description,
            $detailshash,
            $status
        );
        $payment['operation'] = $operation;
        json_response(payment_code_response($payment, $operation, $client));
    } else if (is_transport_error($recovered->error)) {
        json_response([
            'success' => false,
            'error' => $recovered->error,
            'errorCode' => $recovered->errorCode ?? null,
        ], 502);
    }

    $result = $client->create_bill($bill);

    if (!empty($result->error)) {
        json_response([
            'success' => false,
            'error' => $result->error,
            'errorCode' => $result->errorCode ?? null,
        ], 502);
    }

    $paymentcode = (string)$result->res;
    $payment = save_demo_payment(
        $db,
        null,
        $merchantreference,
        $billreference,
        $paymentcode,
        $amount,
        $bill->customerName,
        $description,
        $detailshash,
        0
    );
    $payment['operation'] = 'created';
    json_response(payment_code_response($payment, 'created', $client));
}

function payment_status(): void {
    $payload = read_json_payload();
    $paymentid = (int)($payload['paymentId'] ?? 0);

    if ($paymentid <= 0) {
        json_response(['success' => false, 'error' => 'paymentId is required'], 400);
    }

    $db = demo_db();
    $stmt = $db->prepare('SELECT * FROM demo_payments WHERE id = :id');
    $stmt->execute([':id' => $paymentid]);
    $payment = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$payment) {
        json_response(['success' => false, 'error' => 'Payment record not found'], 404);
    }

    $client = create_webirr_client();
    $result = $client->get_payment_status((string)$payment['payment_code']);

    if (!empty($result->error)) {
        json_response([
            'success' => false,
            'error' => $result->error,
            'errorCode' => $result->errorCode ?? null,
        ], 502);
    }

    $status = 0;
    if (isset($result->res) && is_object($result->res) && isset($result->res->status)) {
        $status = (int)$result->res->status;
    }
    $paymentreference = extract_payment_reference($result);
    $paymentissuer = extract_payment_issuer($result);

    $stmt = $db->prepare(
        'UPDATE demo_payments
            SET status = :status, raw_status = :raw_status, updated_at = :updated_at
          WHERE id = :id'
    );
    $stmt->execute([
        ':id' => $paymentid,
        ':status' => $status,
        ':raw_status' => json_encode($result, JSON_UNESCAPED_SLASHES),
        ':updated_at' => gmdate('c'),
    ]);

    json_response([
        'success' => true,
        'status' => $status,
        'complete' => $status === 2,
        'paymentId' => $paymentid,
        'paymentCode' => $payment['payment_code'],
        'billReference' => $payment['bill_reference'],
        'paymentReference' => $paymentreference,
        'paymentIssuer' => $paymentissuer,
    ]);
}

function reuse_demo_payment(
    PDO $db,
    array $existing,
    stdClass $bill,
    string $merchantreference,
    string $detailshash,
    webirr_client $client
): array {
    $operation = 'reused';
    $status = (int)$existing['status'];
    $existinghash = (string)($existing['details_hash'] ?? '');

    if ($status !== 2 && $existinghash !== $detailshash) {
        $statusresult = $client->get_payment_status((string)$existing['payment_code']);
        if (!empty($statusresult->error)) {
            throw new RuntimeException((string)$statusresult->error);
        }

        $status = extract_payment_status($statusresult);
        if ($status === 2) {
            update_demo_payment_status($db, (int)$existing['id'], $status, $statusresult);
            $payment = find_demo_payment($db, (string)$existing['bill_reference']);
            if (!$payment) {
                throw new RuntimeException('Payment record not found after status update.');
            }
            $payment['operation'] = 'reused';
            return $payment;
        }

        $bill->billReference = (string)$existing['bill_reference'];
        $updated = $client->update_bill($bill);
        if (!empty($updated->error)) {
            throw new RuntimeException((string)$updated->error);
        }

        $operation = 'updated';
        $existing = save_demo_payment(
            $db,
            $existing,
            $merchantreference,
            (string)$existing['bill_reference'],
            (string)$existing['payment_code'],
            (string)$bill->amount,
            (string)$bill->customerName,
            (string)$bill->description,
            $detailshash,
            $status
        );
    }

    $existing['operation'] = $operation;
    return $existing;
}

function payment_code_response(array $payment, string $operation, webirr_client $client): array {
    return [
        'success' => true,
        'paymentId' => (int)$payment['id'],
        'paymentCode' => (string)$payment['payment_code'],
        'billReference' => (string)$payment['bill_reference'],
        'merchantReference' => (string)$payment['merchant_reference'],
        'amount' => (string)$payment['amount'],
        'customerName' => (string)$payment['customer_name'],
        'description' => (string)$payment['description'],
        'courseTitle' => item_title_from_description((string)$payment['description']),
        'status' => (int)$payment['status'],
        'operation' => $operation,
        'supportedBanks' => supported_banks_response($client),
    ];
}

function supported_banks_response(webirr_client $client): array {
    $response = $client->get_supported_banks();
    if (!empty($response->error) || !isset($response->res) || !is_array($response->res)) {
        return [];
    }

    $banks = [];
    foreach ($response->res as $bank) {
        if (!is_object($bank)) {
            continue;
        }

        $bankid = trim((string)($bank->bankID ?? $bank->bankid ?? ''));
        $name = trim((string)($bank->name ?? ''));
        if ($bankid === '' || $name === '') {
            continue;
        }

        $banks[] = [
            'bankID' => $bankid,
            'name' => $name,
        ];
    }

    return $banks;
}

function supported_banks_preview(): array {
    try {
        return supported_banks_response(create_webirr_client());
    } catch (Throwable $throwable) {
        return [];
    }
}

function render_payment_instruction_items(array $banks): void {
    if (empty($banks)) {
        ?>
                    <div class="payment-instruction-item payment-instruction-fallback">Use a supported WeBirr banking or wallet app.</div>
        <?php
        return;
    }

    foreach ($banks as $bank) {
        $name = trim((string)($bank['name'] ?? ''));
        if ($name === '') {
            continue;
        }
        ?>
                    <div class="payment-instruction-item"><span class="payment-instruction-channel"><?php echo htmlspecialchars($name, ENT_QUOTES, 'UTF-8'); ?></span><span class="payment-instruction-arrow">-&gt;</span><span class="payment-instruction-target">WeBirr</span><span class="payment-instruction-arrow">-&gt;</span><span class="payment-instruction-target">Payment Code</span></div>
        <?php
    }
}

function find_demo_payment(PDO $db, string $billreference): ?array {
    $stmt = $db->prepare('SELECT * FROM demo_payments WHERE bill_reference = :bill_reference');
    $stmt->execute([':bill_reference' => $billreference]);
    $record = $stmt->fetch(PDO::FETCH_ASSOC);

    return is_array($record) ? $record : null;
}

function save_demo_payment(
    PDO $db,
    ?array $existing,
    string $merchantreference,
    string $billreference,
    string $paymentcode,
    string $amount,
    string $customername,
    string $description,
    string $detailshash,
    int $status
): array {
    $now = gmdate('c');

    if ($existing) {
        $stmt = $db->prepare(
            'UPDATE demo_payments
                SET merchant_reference = :merchant_reference,
                    payment_code = :payment_code,
                    amount = :amount,
                    customer_name = :customer_name,
                    description = :description,
                    details_hash = :details_hash,
                    status = :status,
                    updated_at = :updated_at
              WHERE id = :id'
        );
        $stmt->execute([
            ':id' => (int)$existing['id'],
            ':merchant_reference' => $merchantreference,
            ':payment_code' => $paymentcode,
            ':amount' => $amount,
            ':customer_name' => $customername,
            ':description' => $description,
            ':details_hash' => $detailshash,
            ':status' => $status,
            ':updated_at' => $now,
        ]);
    } else {
        $stmt = $db->prepare(
            'INSERT INTO demo_payments
                (merchant_reference, bill_reference, payment_code, amount, customer_name, description, details_hash, status, created_at, updated_at)
             VALUES
                (:merchant_reference, :bill_reference, :payment_code, :amount, :customer_name, :description, :details_hash, :status, :created_at, :updated_at)'
        );
        $stmt->execute([
            ':merchant_reference' => $merchantreference,
            ':bill_reference' => $billreference,
            ':payment_code' => $paymentcode,
            ':amount' => $amount,
            ':customer_name' => $customername,
            ':description' => $description,
            ':details_hash' => $detailshash,
            ':status' => $status,
            ':created_at' => $now,
            ':updated_at' => $now,
        ]);
    }

    $payment = find_demo_payment($db, $billreference);
    if (!$payment) {
        throw new RuntimeException('Payment record was not saved.');
    }

    return $payment;
}

function update_demo_payment_status(PDO $db, int $paymentid, int $status, object $rawstatus): void {
    $stmt = $db->prepare(
        'UPDATE demo_payments
            SET status = :status, raw_status = :raw_status, updated_at = :updated_at
          WHERE id = :id'
    );
    $stmt->execute([
        ':id' => $paymentid,
        ':status' => $status,
        ':raw_status' => json_encode($rawstatus, JSON_UNESCAPED_SLASHES),
        ':updated_at' => gmdate('c'),
    ]);
}

function normalize_merchant_reference(string $merchantreference): string {
    $merchantreference = trim($merchantreference);

    return $merchantreference !== '' ? $merchantreference : default_merchant_reference();
}

function default_merchant_reference(): string {
    return 'ord_' . substr(str_replace('-', '', demo_uuid()), 0, 8);
}

function demo_uuid(): string {
    try {
        $bytes = random_bytes(16);
    } catch (Exception $exception) {
        $bytes = md5(uniqid('', true), true);
    }

    $hex = bin2hex($bytes);

    return sprintf(
        '%s-%s-%s-%s-%s',
        substr($hex, 0, 8),
        substr($hex, 8, 4),
        substr($hex, 12, 4),
        substr($hex, 16, 4),
        substr($hex, 20, 12)
    );
}

function demo_courses(): array {
    return [
        ['id' => 'course-001', 'title' => 'WeBirr Online Checkout Test Course', 'amount' => '530.00'],
        ['id' => 'course-002', 'title' => 'Payments 101', 'amount' => '610.00'],
        ['id' => 'course-003', 'title' => 'Merchant Operations', 'amount' => '680.00'],
        ['id' => 'course-004', 'title' => 'Digital Commerce Basics', 'amount' => '720.00'],
        ['id' => 'course-005', 'title' => 'Customer Support Essentials', 'amount' => '560.00'],
        ['id' => 'course-006', 'title' => 'Payment Reconciliation', 'amount' => '750.00'],
        ['id' => 'course-007', 'title' => 'Billing And Collections', 'amount' => '590.00'],
        ['id' => 'course-008', 'title' => 'Merchant Reporting', 'amount' => '640.00'],
        ['id' => 'course-009', 'title' => 'Payment Risk Basics', 'amount' => '700.00'],
        ['id' => 'course-010', 'title' => 'Gateway Integration Workshop', 'amount' => '780.00'],
    ];
}

function find_demo_course(string $courseid): ?array {
    foreach (demo_courses() as $course) {
        if ($course['id'] === $courseid) {
            return $course;
        }
    }

    return null;
}

function item_title_from_description(string $description): string {
    $parts = explode(' - ', $description, 2);
    return trim($parts[0]) !== '' ? trim($parts[0]) : 'Moodle Course';
}

function build_demo_bill_reference(string $merchantreference): string {
    $slug = strtolower((string)preg_replace('/[^A-Za-z0-9]+/', '_', $merchantreference));
    $slug = trim($slug, '_');
    if ($slug === '') {
        $slug = 'payable_' . date('Ymd');
    }

    return 'moodle_demo_' . $slug;
}

function details_hash(string $customername, string $amount, string $description): string {
    return hash('sha256', implode("\n", [$customername, $amount, $description]));
}

function extract_bill_payment_code(object $result): string {
    if (isset($result->res) && !is_object($result->res) && !is_array($result->res)) {
        return trim((string)$result->res);
    }

    foreach (['wbcCode', 'paymentCode', 'wbc_code', 'paymentcode'] as $key) {
        $value = extract_bill_value($result, $key);
        if ($value !== '') {
            return $value;
        }
    }

    return '';
}

function extract_bill_status(object $result): int {
    foreach (['paymentStatus', 'status'] as $key) {
        $value = extract_bill_value($result, $key);
        if ($value !== '') {
            return (int)$value;
        }
    }

    return 0;
}

function extract_payment_status(object $result): int {
    if (isset($result->res) && is_object($result->res) && isset($result->res->status)) {
        return (int)$result->res->status;
    }

    return extract_bill_status($result);
}

function bill_details_changed(object $result, stdClass $bill): bool {
    $amount = extract_bill_value($result, 'amount');
    if ($amount !== '' && format_amount($amount) !== (string)$bill->amount) {
        return true;
    }

    $customername = extract_bill_value($result, 'customerName');
    if ($customername !== '' && $customername !== (string)$bill->customerName) {
        return true;
    }

    $description = extract_bill_value($result, 'description');
    if ($description !== '' && $description !== (string)$bill->description) {
        return true;
    }

    return false;
}

function extract_bill_value(object $result, string $key): string {
    if (isset($result->res) && is_object($result->res) && isset($result->res->$key)) {
        return trim((string)$result->res->$key);
    }

    if (isset($result->$key)) {
        return trim((string)$result->$key);
    }

    return '';
}

function is_transport_error(string $error): bool {
    return preg_match('/^(http error|invalid response|Moodle curl class|Unable to encode)/i', $error) === 1;
}

function extract_payment_reference(object $result): string {
    return extract_payment_value($result, 'paymentReference');
}

function extract_payment_issuer(object $result): string {
    $issuer = '';
    foreach (['issuerName', 'paymentIssuer', 'bankName', 'bankID'] as $key) {
        $issuer = extract_payment_value($result, $key);
        if ($issuer !== '') {
            break;
        }
    }

    if ($issuer === '') {
        return '';
    }

    $words = preg_split('/[\s_-]+/', trim($issuer)) ?: [];
    $formatted = array_map(
        static fn(string $word): string => strlen($word) <= 3 ? strtoupper($word) : ucfirst(strtolower($word)),
        $words
    );

    return implode(' ', $formatted);
}

function extract_payment_value(object $result, string $key): string {
    if (!isset($result->res) || !is_object($result->res)) {
        return '';
    }

    if (isset($result->res->$key)) {
        return (string)$result->res->$key;
    }

    if (
        isset($result->res->data) &&
        is_object($result->res->data) &&
        isset($result->res->data->$key)
    ) {
        return (string)$result->res->data->$key;
    }

    return '';
}

function create_webirr_client(): webirr_client {
    $merchantid = getenv('WEBIRR_TEST_ENV_MERCHANT_ID') ?: '';
    $apikey = getenv('WEBIRR_TEST_ENV_API_KEY') ?: '';

    if ($merchantid === '' || $apikey === '') {
        throw new RuntimeException('Set WEBIRR_TEST_ENV_MERCHANT_ID and WEBIRR_TEST_ENV_API_KEY before starting the demo.');
    }

    return new webirr_client($merchantid, $apikey, true, demo_transport());
}

function demo_transport(): callable {
    return static function(string $method, string $url, ?array $payload, array $headers): array {
        $body = $payload === null ? null : json_encode($payload, JSON_UNESCAPED_SLASHES);
        if ($payload !== null && $body === false) {
            return [
                'status' => 0,
                'body' => '',
                'error' => 'Unable to encode request payload',
            ];
        }

        if (function_exists('curl_init')) {
            $ch = curl_init($url);
            curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
            curl_setopt($ch, CURLOPT_CUSTOMREQUEST, $method);
            curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);
            if ($body !== null) {
                curl_setopt($ch, CURLOPT_POSTFIELDS, $body);
            }

            $responsebody = curl_exec($ch);
            $error = curl_error($ch);
            $status = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);

            return [
                'status' => $status,
                'body' => $responsebody === false ? '' : (string)$responsebody,
                'error' => $error,
            ];
        }

        $options = [
            'http' => [
                'method' => $method,
                'header' => implode("\r\n", $headers),
                'ignore_errors' => true,
            ],
        ];
        if ($body !== null) {
            $options['http']['content'] = $body;
        }

        $responsebody = file_get_contents($url, false, stream_context_create($options));
        $status = 0;
        foreach ($http_response_header ?? [] as $header) {
            if (preg_match('/^HTTP\/\S+\s+(\d+)/', $header, $matches)) {
                $status = (int)$matches[1];
                break;
            }
        }

        return [
            'status' => $status,
            'body' => $responsebody === false ? '' : (string)$responsebody,
            'error' => $responsebody === false ? 'HTTP request failed' : '',
        ];
    };
}

function demo_db(): PDO {
    $datadir = __DIR__ . '/data';
    if (!is_dir($datadir)) {
        mkdir($datadir, 0770, true);
    }

    $db = new PDO('sqlite:' . $datadir . '/webirr-demo.sqlite');
    $db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    $db->exec(
        'CREATE TABLE IF NOT EXISTS demo_payments (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            merchant_reference TEXT NOT NULL DEFAULT "",
            bill_reference TEXT NOT NULL UNIQUE,
            payment_code TEXT NOT NULL,
            amount TEXT NOT NULL,
            customer_name TEXT NOT NULL,
            description TEXT NOT NULL DEFAULT "",
            details_hash TEXT NOT NULL DEFAULT "",
            status INTEGER NOT NULL DEFAULT 0,
            raw_status TEXT,
            created_at TEXT NOT NULL,
            updated_at TEXT NOT NULL
        )'
    );
    ensure_demo_column($db, 'merchant_reference', 'TEXT NOT NULL DEFAULT ""');
    ensure_demo_column($db, 'description', 'TEXT NOT NULL DEFAULT ""');
    ensure_demo_column($db, 'details_hash', 'TEXT NOT NULL DEFAULT ""');

    return $db;
}

function ensure_demo_column(PDO $db, string $column, string $definition): void {
    $columns = $db->query('PRAGMA table_info(demo_payments)')->fetchAll(PDO::FETCH_ASSOC);
    foreach ($columns as $existing) {
        if (($existing['name'] ?? '') === $column) {
            return;
        }
    }

    $db->exec('ALTER TABLE demo_payments ADD COLUMN ' . $column . ' ' . $definition);
}

function read_json_payload(): array {
    $raw = file_get_contents('php://input');
    if ($raw === false || trim($raw) === '') {
        return [];
    }

    $payload = json_decode($raw, true);
    if (!is_array($payload)) {
        json_response(['success' => false, 'error' => 'Invalid JSON request'], 400);
    }

    return $payload;
}

function format_amount(string $value): string {
    $amount = (float)$value;
    if ($amount <= 0) {
        $amount = 1.00;
    }

    return number_format($amount, 2, '.', '');
}

function json_response(array $payload, int $status = 200): void {
    http_response_code($status);
    header('Content-Type: application/json');
    echo json_encode($payload, JSON_UNESCAPED_SLASHES);
    exit;
}

function render_page(): void {
    $preview = (string)($_GET['preview'] ?? '');
    $defaultmerchantreference = default_merchant_reference();
    $courses = demo_courses();
    $previewbanks = in_array($preview, ['journey', 'confirmed'], true) ? supported_banks_preview() : [];
    $previewissuer = trim((string)($previewbanks[0]['name'] ?? '')) ?: 'Supported WeBirr App';
    ?>
<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>WeBirr Online Checkout</title>
    <style>
        :root {
            color-scheme: light;
            --bg: #f6f7f9;
            --ink: #1f2933;
            --muted: #64748b;
            --line: #d7dee8;
            --panel: #ffffff;
            --primary: #145c9e;
            --primary-dark: #0f4779;
            --warning-bg: #fff7df;
            --warning-border: #d99a10;
            --success-bg: #e9f8ef;
            --success-border: #2d8a4d;
            --danger-bg: #fdecec;
            --danger-border: #c24141;
            --info-bg: #eaf4ff;
            --info-border: #3a7ab8;
        }
        * {
            box-sizing: border-box;
        }
        body {
            margin: 0;
            background: var(--bg);
            color: var(--ink);
            font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", sans-serif;
            line-height: 1.5;
        }
        .shell {
            max-width: 880px;
            margin: 0 auto;
            padding: 32px 20px;
        }
        .shell-wide {
            max-width: 1240px;
        }
        .topbar {
            display: flex;
            align-items: center;
            justify-content: space-between;
            gap: 16px;
            margin-bottom: 20px;
        }
        .brand {
            display: grid;
            grid-template-columns: 52px minmax(0, 1fr);
            gap: 12px;
            align-items: center;
        }
        .brand-logo {
            width: 52px;
            height: 52px;
            object-fit: contain;
        }
        .brand h1 {
            margin: 0;
            font-size: 24px;
            font-weight: 700;
            letter-spacing: 0;
        }
        .layout {
            display: grid;
            grid-template-columns: repeat(2, minmax(0, 1fr));
            gap: 18px;
        }
        .checkout-stage {
            max-width: 680px;
            margin: 0 auto;
        }
        .stage-panel[hidden] {
            display: none;
        }
        .journey-layout {
            display: grid;
            grid-template-columns: minmax(0, 0.85fr) 24px minmax(0, 0.85fr) 24px minmax(280px, 1.25fr) 24px minmax(0, 1.05fr);
            gap: 8px;
            align-items: stretch;
        }
        .journey-arrow {
            display: flex;
            align-items: center;
            justify-content: center;
            color: #94a3b8;
            font-size: 24px;
            font-weight: 700;
        }
        .panel {
            background: var(--panel);
            border: 1px solid var(--line);
            border-radius: 8px;
            padding: 20px;
            min-width: 0;
        }
        .panel-title {
            margin: 0 0 16px;
            font-size: 16px;
            font-weight: 700;
            letter-spacing: 0;
        }
        label {
            display: block;
            margin-bottom: 6px;
            font-size: 13px;
            font-weight: 600;
            color: #334155;
        }
        input {
            width: 100%;
            min-height: 40px;
            border: 1px solid #cbd5e1;
            border-radius: 6px;
            padding: 8px 10px;
            font: inherit;
        }
        .field {
            margin-bottom: 14px;
        }
        .field-hint {
            margin-top: 5px;
            color: var(--muted);
            font-size: 12px;
        }
        .summary {
            display: grid;
            grid-template-columns: 120px minmax(0, 1fr);
            gap: 10px 14px;
            margin: 0;
            padding: 0;
        }
        .summary dt {
            color: var(--muted);
            font-size: 14px;
            font-weight: 600;
        }
        .summary dd {
            margin: 0;
            color: var(--ink);
            font-weight: 700;
            overflow-wrap: anywhere;
        }
        .button-row {
            display: flex;
            flex-wrap: wrap;
            gap: 8px;
            margin-top: 16px;
        }
        button {
            border: 1px solid transparent;
            border-radius: 6px;
            padding: 9px 14px;
            min-height: 40px;
            font: inherit;
            font-weight: 600;
            cursor: pointer;
        }
        button:disabled {
            opacity: 0.55;
            cursor: wait;
        }
        .primary {
            background: var(--primary);
            color: white;
        }
        .primary:hover:not(:disabled) {
            background: var(--primary-dark);
        }
        .secondary {
            display: inline-flex;
            align-items: center;
            justify-content: center;
            min-height: 40px;
            border-radius: 6px;
            padding: 9px 14px;
            background: white;
            color: var(--ink);
            border: 1px solid var(--line);
            font: inherit;
            font-weight: 600;
            text-decoration: none;
        }
        a.primary {
            display: inline-flex;
            align-items: center;
            justify-content: center;
            min-height: 40px;
            border-radius: 6px;
            padding: 9px 14px;
            background: var(--primary);
            color: white;
            font: inherit;
            font-weight: 600;
            text-decoration: none;
        }
        a.primary:hover {
            background: var(--primary-dark);
        }
        .course-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(220px, 1fr));
            gap: 14px;
            margin-top: 18px;
        }
        .course-card {
            display: grid;
            gap: 14px;
            align-content: space-between;
            min-height: 210px;
            border: 1px solid var(--line);
            border-radius: 8px;
            padding: 16px;
            background: white;
        }
        .course-card h2 {
            margin: 0 0 8px;
            font-size: 18px;
        }
        .course-card p {
            margin: 0 0 10px;
            color: var(--muted);
        }
        .payment-code {
            display: none;
            margin: 6px 0 12px;
            border: 1px solid var(--line);
            border-radius: 8px;
            background: #f8fafc;
            padding: 16px;
            text-align: center;
            font-size: 32px;
            font-weight: 800;
            letter-spacing: 1px;
            overflow-wrap: anywhere;
        }
        .payment-code-title {
            margin: 0 0 8px;
            text-align: center;
            font-size: 14px;
            font-weight: 700;
            color: #334155;
        }
        .status {
            border: 1px solid var(--info-border);
            border-radius: 8px;
            background: var(--info-bg);
            padding: 12px;
            font-weight: 600;
            display: flex;
            align-items: center;
            gap: 8px;
        }
        .status.warning {
            background: var(--warning-bg);
            border-color: var(--warning-border);
        }
        .status.success {
            background: var(--success-bg);
            border-color: var(--success-border);
        }
        .status.danger {
            background: var(--danger-bg);
            border-color: var(--danger-border);
        }
        .status-spinner {
            display: none;
            width: 18px;
            height: 18px;
            flex: 0 0 18px;
            border: 3px solid rgba(20, 92, 158, 0.25);
            border-top-color: var(--primary);
            border-radius: 50%;
            animation: status-spinner-rotate 0.8s linear infinite;
        }
        @keyframes status-spinner-rotate {
            to {
                transform: rotate(360deg);
            }
        }
        .meta {
            margin-top: 10px;
            color: var(--muted);
            font-size: 14px;
        }
        .payment-instruction-list {
            margin: 14px 0;
            padding: 12px 14px;
            border: 1px solid #d8e6f3;
            border-radius: var(--radius);
            background: #f7fbff;
            font-size: 14px;
        }
        .payment-instruction-title {
            margin-bottom: 8px;
            color: var(--primary);
            font-weight: 700;
        }
        .payment-instruction-item {
            display: flex;
            align-items: center;
            gap: 8px;
            flex-wrap: wrap;
            padding: 2px 0;
        }
        .payment-instruction-channel {
            min-width: 90px;
            font-weight: 600;
        }
        .payment-instruction-arrow {
            color: var(--muted);
        }
        .payment-instruction-target {
            font-weight: 600;
        }
        .record {
            display: grid;
            grid-template-columns: 130px minmax(0, 1fr);
            gap: 8px;
            margin-top: 14px;
            font-size: 14px;
        }
        .record dt {
            color: var(--muted);
        }
        .record dd {
            margin: 0;
            font-weight: 600;
            overflow-wrap: anywhere;
        }
        .webirr-success-card {
            max-width: 560px;
            margin: 24px 0;
            padding: 24px;
            border: 1px solid #d7eadc;
            border-radius: 8px;
            background: #f2fbf5;
        }
        .webirr-success-check {
            display: inline-flex;
            align-items: center;
            justify-content: center;
            width: 42px;
            height: 42px;
            margin-bottom: 12px;
            border-radius: 50%;
            background: #198754;
            color: #fff;
            font-size: 24px;
            font-weight: 700;
        }
        .webirr-success-card h3 {
            margin: 0 0 18px;
        }
        .webirr-success-row {
            display: flex;
            justify-content: space-between;
            gap: 16px;
            padding: 10px 0;
            border-top: 1px solid #d7eadc;
        }
        .webirr-success-label {
            color: #555;
            font-weight: 600;
        }
        .webirr-success-value {
            font-weight: 700;
            text-align: right;
            overflow-wrap: anywhere;
        }
        .webirr-success-page-title {
            margin: 0 0 12px;
            font-size: 24px;
            font-weight: 700;
            letter-spacing: 0;
        }
        .webirr-success-continue {
            display: flex;
            justify-content: center;
            margin-top: 20px;
        }
        .journey-panel {
            min-height: 360px;
        }
        .journey-panel .summary {
            grid-template-columns: 1fr;
            gap: 4px;
        }
        .journey-panel .summary dt {
            font-size: 13px;
        }
        .journey-panel .summary dd {
            margin-bottom: 8px;
            font-size: 14px;
        }
        .journey-panel .payment-code {
            display: block;
        }
        .journey-panel .payment-instruction-list {
            padding: 10px;
            font-size: 12px;
        }
        .journey-panel .payment-instruction-item {
            display: block;
        }
        .journey-panel .payment-instruction-channel {
            min-width: 0;
        }
        .journey-panel .payment-instruction-arrow {
            margin: 0 4px;
        }
        .journey-panel .record {
            grid-template-columns: 1fr;
            gap: 3px;
            font-size: 13px;
        }
        .journey-panel .record dd {
            margin-bottom: 8px;
        }
        .journey-confirmed {
            min-height: 360px;
        }
        .journey-confirmed .webirr-success-card {
            max-width: none;
            margin: 0;
            padding: 22px;
        }
        .journey-confirmed .webirr-success-page-title {
            font-size: 22px;
            line-height: 1.2;
        }
        .journey-confirmed .webirr-success-value {
            font-size: 13px;
        }
        @media (max-width: 980px) {
            .layout,
            .journey-layout {
                grid-template-columns: 1fr;
            }
            .journey-arrow {
                min-height: 24px;
                transform: rotate(90deg);
            }
            .topbar {
                align-items: flex-start;
                flex-direction: column;
            }
        }
    </style>
</head>
<body>
    <main class="shell<?php echo $preview === 'journey' ? ' shell-wide' : ''; ?>">
        <div class="topbar">
            <div class="brand">
                <img class="brand-logo" src="/pix/webirr-cute-logo.png" alt="WeBirr">
                <div>
                    <h1>WeBirr Online Checkout</h1>
                </div>
            </div>
        </div>
        <?php if ($preview === 'journey') { ?>
        <div class="journey-layout">
            <section class="panel journey-panel">
                <div class="panel-title">Course Catalog</div>
                <dl class="summary">
                    <dt>Customer</dt>
                    <dd>Elias</dd>
                    <dt>Course</dt>
                    <dd>WeBirr Online Checkout Test Course</dd>
                    <dt>Amount</dt>
                    <dd>530.00 ETB</dd>
                    <dt>Description</dt>
                    <dd>moodle course enrollment</dd>
                    <dt>Reference</dt>
                    <dd><?php echo htmlspecialchars($defaultmerchantreference, ENT_QUOTES, 'UTF-8'); ?></dd>
                </dl>
                <div class="button-row">
                    <button class="primary" type="button">Continue</button>
                </div>
            </section>
            <div class="journey-arrow" aria-hidden="true">&rarr;</div>
            <section class="panel journey-panel">
                <div class="panel-title">Checkout</div>
                <dl class="summary">
                    <dt>Customer</dt>
                    <dd>Elias</dd>
                    <dt>Course</dt>
                    <dd>WeBirr Online Checkout Test Course</dd>
                    <dt>Amount</dt>
                    <dd>530.00 ETB</dd>
                    <dt>Description</dt>
                    <dd>moodle course enrollment</dd>
                    <dt>Reference</dt>
                    <dd><?php echo htmlspecialchars($defaultmerchantreference, ENT_QUOTES, 'UTF-8'); ?></dd>
                </dl>
                <div class="button-row">
                    <button class="primary" type="button">Checkout</button>
                    <a class="secondary" href="#">Cancel</a>
                </div>
            </section>
            <div class="journey-arrow" aria-hidden="true">&rarr;</div>
            <section class="panel journey-panel">
                <div class="payment-code-title">WeBirr Payment Code</div>
                <div class="payment-code">175 431 619</div>
                <div class="status warning">
                    <span class="status-spinner" aria-hidden="true" style="display: inline-block;"></span>
                    <span>Payment not received yet.</span>
                </div>
                <div class="payment-instruction-list">
                    <div class="payment-instruction-title">Payment Instruction</div>
                    <?php render_payment_instruction_items($previewbanks); ?>
                </div>
                <dl class="record">
                    <dt>Merchant reference</dt>
                    <dd><?php echo htmlspecialchars($defaultmerchantreference, ENT_QUOTES, 'UTF-8'); ?></dd>
                    <dt>Payment Status</dt>
                    <dd>pending</dd>
                </dl>
            </section>
            <div class="journey-arrow" aria-hidden="true">&rarr;</div>
            <section class="panel journey-panel journey-confirmed">
                <h2 class="webirr-success-page-title">Your payment was successful.</h2>
                <div class="webirr-success-card">
                    <div class="webirr-success-check" aria-hidden="true">&#10003;</div>
                    <h3>Payment Confirmed</h3>
                    <div class="webirr-success-row">
                        <span class="webirr-success-label">Customer</span>
                        <span class="webirr-success-value">Elias</span>
                    </div>
                    <div class="webirr-success-row">
                        <span class="webirr-success-label">Amount</span>
                        <span class="webirr-success-value">530.00 ETB</span>
                    </div>
                    <div class="webirr-success-row">
                        <span class="webirr-success-label">Payment Reference</span>
                        <span class="webirr-success-value">TX70e78862148f4c249606</span>
                    </div>
                    <div class="webirr-success-row">
                        <span class="webirr-success-label">Paid Via</span>
                        <span class="webirr-success-value"><?php echo htmlspecialchars($previewissuer, ENT_QUOTES, 'UTF-8'); ?></span>
                    </div>
                </div>
                <div class="webirr-success-continue">
                    <button class="primary" type="button">Continue</button>
                </div>
            </section>
        </div>
        <?php } else { ?>
        <div class="checkout-stage">
            <section class="panel stage-panel" id="entryPanel">
                <div class="panel-title">Course Catalog</div>
                <div class="field">
                    <label for="customerName">Customer</label>
                    <input id="customerName" value="Elias" autocomplete="name">
                </div>
                <div class="course-grid">
                    <?php foreach ($courses as $course): ?>
                        <article class="course-card">
                            <div>
                                <h2><?php echo htmlspecialchars((string)$course['title'], ENT_QUOTES, 'UTF-8'); ?></h2>
                                <p>moodle course enrollment</p>
                                <strong><?php echo htmlspecialchars((string)$course['amount'], ENT_QUOTES, 'UTF-8'); ?> ETB</strong>
                            </div>
                            <button
                                class="primary"
                                type="button"
                                data-action="enroll"
                                data-course-id="<?php echo htmlspecialchars((string)$course['id'], ENT_QUOTES, 'UTF-8'); ?>"
                                data-course-title="<?php echo htmlspecialchars((string)$course['title'], ENT_QUOTES, 'UTF-8'); ?>"
                                data-course-amount="<?php echo htmlspecialchars((string)$course['amount'], ENT_QUOTES, 'UTF-8'); ?>"
                            >Enroll</button>
                        </article>
                    <?php endforeach; ?>
                </div>
            </section>

            <section class="panel stage-panel" id="reviewPanel" hidden>
                <div class="panel-title">Checkout</div>
                <dl class="summary">
                    <dt>Customer</dt>
                    <dd id="reviewCustomer"></dd>
                    <dt>Course</dt>
                    <dd id="reviewCourse"></dd>
                    <dt>Amount</dt>
                    <dd id="reviewAmount"></dd>
                    <dt>Description</dt>
                    <dd id="reviewDescription"></dd>
                    <dt>Reference</dt>
                    <dd id="reviewMerchantReference"></dd>
                </dl>
                <div class="button-row">
                    <button class="primary" id="createBill" type="button">Enroll</button>
                    <button class="secondary" id="backToEntry" type="button">Back</button>
                    <button class="secondary" id="cancelCheckout" type="button">Cancel</button>
                </div>
            </section>

            <section class="panel stage-panel" id="paymentPanel" hidden>
                <div id="pendingReceipt">
                    <div class="payment-code-title" id="paymentCodeTitle">WeBirr Payment Code</div>
                    <div class="payment-code" id="paymentCode"></div>
                    <div class="status" id="statusBox">
                        <span class="status-spinner" id="statusSpinner" aria-hidden="true"></span>
                        <span id="statusText">Creating payment code...</span>
                    </div>
                    <div class="payment-instruction-list">
                        <div class="payment-instruction-title">Payment Instruction</div>
                        <div id="paymentInstructionItems"></div>
                    </div>
                    <div class="meta" id="statusMeta"></div>
                    <div class="button-row" id="statusActions" style="display: none;">
                        <button class="primary" id="refreshStatus">Refresh</button>
                    </div>
                    <dl class="record" id="record" style="display: none;">
                        <dt>Merchant reference</dt>
                        <dd id="merchantReference"></dd>
                        <dt>Course</dt>
                        <dd id="paymentCourse"></dd>
                        <dt>Payment Status</dt>
                        <dd id="paymentStatus">pending</dd>
                    </dl>
                </div>
            </section>

            <section class="panel stage-panel confirmation-receipt" id="confirmationReceipt" hidden>
                <h2 class="webirr-success-page-title">Your payment was successful.</h2>
                <div class="webirr-success-card">
                    <div class="webirr-success-check" aria-hidden="true">&#10003;</div>
                    <h3>Payment Confirmed</h3>
                    <div class="webirr-success-row">
                        <span class="webirr-success-label">Customer</span>
                        <span class="webirr-success-value" id="confirmationCustomer"></span>
                    </div>
                    <div class="webirr-success-row">
                        <span class="webirr-success-label">Amount</span>
                        <span class="webirr-success-value" id="confirmationAmount"></span>
                    </div>
                    <div class="webirr-success-row">
                        <span class="webirr-success-label">Payment Reference</span>
                        <span class="webirr-success-value" id="confirmationPaymentReference"></span>
                    </div>
                    <div class="webirr-success-row">
                        <span class="webirr-success-label">Paid Via</span>
                        <span class="webirr-success-value" id="confirmationPaymentIssuer"></span>
                    </div>
                </div>
                <div class="webirr-success-continue">
                    <a class="primary" href="#" id="downloadReceipt" download="webirr-course-enrollment-receipt.txt">Download receipt</a>
                    <button class="primary" id="startOver" type="button">Continue</button>
                </div>
            </section>
        </div>
        <?php } ?>
    </main>
    <?php if ($preview !== 'journey') { ?>
    <script>
        const state = {
            paymentId: null,
            timer: null,
            checkout: null,
            selectedCourse: null,
            merchantReference: '',
            paymentCode: ''
        };
        const delayMs = 5000;
        const entryPanel = document.getElementById('entryPanel');
        const reviewPanel = document.getElementById('reviewPanel');
        const paymentPanel = document.getElementById('paymentPanel');
        const createButton = document.getElementById('createBill');
        const backButton = document.getElementById('backToEntry');
        const cancelButton = document.getElementById('cancelCheckout');
        const startOverButton = document.getElementById('startOver');
        const refreshButton = document.getElementById('refreshStatus');
        const actions = document.getElementById('statusActions');
        const statusBox = document.getElementById('statusBox');
        const statusSpinner = document.getElementById('statusSpinner');
        const statusText = document.getElementById('statusText');
        const statusMeta = document.getElementById('statusMeta');
        const paymentCodeTitle = document.getElementById('paymentCodeTitle');
        const paymentCode = document.getElementById('paymentCode');
        const paymentInstructionItems = document.getElementById('paymentInstructionItems');
        const record = document.getElementById('record');
        const pendingReceipt = document.getElementById('pendingReceipt');
        const confirmationReceipt = document.getElementById('confirmationReceipt');
        const confirmationPaymentReference = document.getElementById('confirmationPaymentReference');
        const confirmationPaymentIssuer = document.getElementById('confirmationPaymentIssuer');
        const confirmationCustomer = document.getElementById('confirmationCustomer');
        const confirmationAmount = document.getElementById('confirmationAmount');
        const downloadReceipt = document.getElementById('downloadReceipt');

        createButton.addEventListener('click', createBill);
        backButton.addEventListener('click', showEntry);
        cancelButton.addEventListener('click', showEntry);
        startOverButton.addEventListener('click', showEntry);
        refreshButton.addEventListener('click', () => checkStatus(false));
        document.querySelectorAll('[data-action="enroll"]').forEach((button) => {
            button.addEventListener('click', () => showReview(button));
        });

        if (new URLSearchParams(window.location.search).get('preview') === 'confirmed') {
            showConfirmedReceipt({
                paymentReference: 'TX70e78862148f4c249606',
                paymentIssuer: <?php echo json_encode($previewissuer, JSON_UNESCAPED_SLASHES); ?>
            });
        }

        function showReview(button) {
            const customerName = document.getElementById('customerName').value.trim();
            if (!customerName) {
                alert('Customer name is required.');
                document.getElementById('customerName').focus();
                return;
            }
            state.selectedCourse = {
                id: button.getAttribute('data-course-id') || '',
                title: button.getAttribute('data-course-title') || '',
                amount: button.getAttribute('data-course-amount') || ''
            };
            state.merchantReference = newMerchantReference();
            state.checkout = collectCheckoutInput();
            document.getElementById('reviewCustomer').textContent = state.checkout.customerName;
            document.getElementById('reviewCourse').textContent = state.checkout.courseTitle;
            document.getElementById('reviewAmount').textContent = state.checkout.amount + ' ETB';
            document.getElementById('reviewDescription').textContent = state.checkout.description;
            document.getElementById('reviewMerchantReference').textContent = state.checkout.merchantReference;
            showScreen('review');
        }

        function showEntry() {
            window.clearTimeout(state.timer);
            state.paymentId = null;
            state.checkout = null;
            state.selectedCourse = null;
            state.merchantReference = '';
            state.paymentCode = '';
            paymentCode.textContent = '';
            paymentCode.style.display = 'none';
            paymentInstructionItems.innerHTML = '';
            record.style.display = 'none';
            actions.style.display = 'none';
            statusMeta.textContent = '';
            setBusy(false);
            setActionBusy(false);
            showScreen('entry');
        }

        function collectCheckoutInput() {
            const customerName = document.getElementById('customerName').value.trim();
            const amount = state.selectedCourse ? state.selectedCourse.amount : '530.00';
            const courseTitle = state.selectedCourse ? state.selectedCourse.title : 'Moodle Course';
            const description = courseTitle + ' - moodle course enrollment';
            const merchantReference = state.merchantReference || newMerchantReference();
            const courseId = state.selectedCourse ? state.selectedCourse.id : '';

            return {customerName, amount, description, merchantReference, courseTitle, courseId};
        }

        async function createBill() {
            setBusy(true);
            showPendingReceipt();
            setStatus('Creating payment code...', 'info', true);
            actions.style.display = 'none';
            statusMeta.textContent = '';

            const response = await postJson('/api/create-bill', state.checkout || collectCheckoutInput());

            if (!response.success) {
                setBusy(false);
                setStatus(response.error || 'Unable to create bill.', 'danger', false);
                return;
            }

            state.paymentId = response.paymentId;
            state.paymentCode = response.paymentCode;
            state.checkout = Object.assign({}, state.checkout || {}, {
                customerName: response.customerName || (state.checkout ? state.checkout.customerName : ''),
                amount: response.amount || (state.checkout ? state.checkout.amount : ''),
                description: response.description || (state.checkout ? state.checkout.description : ''),
                courseTitle: response.courseTitle || (state.checkout ? state.checkout.courseTitle : ''),
                merchantReference: response.merchantReference || (state.checkout ? state.checkout.merchantReference : '')
            });
            paymentCodeTitle.style.display = 'block';
            paymentCode.textContent = response.paymentCode;
            paymentCode.style.display = 'block';
            renderPaymentInstructions(response.supportedBanks || []);
            record.style.display = 'grid';
            document.getElementById('merchantReference').textContent = response.merchantReference;
            document.getElementById('paymentCourse').textContent = state.checkout.courseTitle;
            document.getElementById('paymentStatus').textContent = 'pending';
            showPendingReceipt();
            setBusy(false);
            waitAndCheck(response.operation || 'created');
        }

        function waitAndCheck(operation) {
            window.clearTimeout(state.timer);
            actions.style.display = 'none';
            setActionBusy(true);
            setStatus('Waiting for payment confirmation...', 'info', true);
            statusMeta.textContent = operation ? 'Stable reference action: ' + operation : '';
            state.timer = window.setTimeout(() => checkStatus(true), delayMs);
        }

        async function checkStatus(automatic) {
            if (!state.paymentId) {
                return;
            }

            window.clearTimeout(state.timer);
            setActionBusy(true);
            setStatus(automatic ? 'Checking payment status...' : 'Refreshing payment status...', 'info', true);
            statusMeta.textContent = '';

            const response = await postJson('/api/payment-status', {
                paymentId: state.paymentId
            });

            if (!response.success) {
                setStatus(response.error || 'Unable to check payment status.', 'danger', false);
                actions.style.display = 'flex';
                setActionBusy(false);
                return;
            }

            document.getElementById('paymentStatus').textContent = statusLabel(response.status);

            if (response.complete) {
                showConfirmedReceipt(response);
                actions.style.display = 'none';
                return;
            }

            setStatus('Payment not received yet.', 'warning', true);
            statusMeta.textContent = '';
            showPendingReceipt();
            actions.style.display = 'flex';
            setActionBusy(true);
            state.timer = window.setTimeout(() => checkStatus(true), delayMs);
        }

        async function postJson(url, payload) {
            const response = await fetch(url, {
                method: 'POST',
                headers: {'Content-Type': 'application/json'},
                body: JSON.stringify(payload)
            });
            return response.json();
        }

        function setBusy(disabled) {
            createButton.disabled = disabled;
            backButton.disabled = disabled;
            cancelButton.disabled = disabled;
        }

        function setActionBusy(disabled) {
            refreshButton.disabled = disabled;
        }

        function setStatus(message, type, spinning) {
            statusBox.className = 'status ' + (type || 'info');
            statusText.textContent = message;
            statusSpinner.style.display = spinning ? 'inline-block' : 'none';
        }

        function renderPaymentInstructions(banks) {
            paymentInstructionItems.innerHTML = '';
            if (!Array.isArray(banks) || banks.length === 0) {
                const fallback = document.createElement('div');
                fallback.className = 'payment-instruction-item payment-instruction-fallback';
                fallback.textContent = 'Use a supported WeBirr banking or wallet app.';
                paymentInstructionItems.appendChild(fallback);
                return;
            }

            banks.forEach((bank) => {
                const name = bank && (bank.name || bank.bankName || bank.bankID || bank.bankid);
                if (!name) {
                    return;
                }

                const item = document.createElement('div');
                item.className = 'payment-instruction-item';
                item.appendChild(instructionSpan('payment-instruction-channel', name));
                item.appendChild(instructionSpan('payment-instruction-arrow', '->'));
                item.appendChild(instructionSpan('payment-instruction-target', 'WeBirr'));
                item.appendChild(instructionSpan('payment-instruction-arrow', '->'));
                item.appendChild(instructionSpan('payment-instruction-target', 'Payment Code'));
                paymentInstructionItems.appendChild(item);
            });

            if (paymentInstructionItems.children.length === 0) {
                const fallback = document.createElement('div');
                fallback.className = 'payment-instruction-item payment-instruction-fallback';
                fallback.textContent = 'Use a supported WeBirr banking or wallet app.';
                paymentInstructionItems.appendChild(fallback);
            }
        }

        function instructionSpan(className, text) {
            const span = document.createElement('span');
            span.className = className;
            span.textContent = text;
            return span;
        }

        function showPendingReceipt() {
            showScreen('payment');
            pendingReceipt.style.display = 'block';
            confirmationPaymentReference.textContent = '';
            confirmationPaymentIssuer.textContent = '';
            confirmationCustomer.textContent = '';
            confirmationAmount.textContent = '';
        }

        function showConfirmedReceipt(response) {
            window.clearTimeout(state.timer);
            showScreen('confirmation');
            confirmationPaymentReference.textContent = response.paymentReference || '';
            confirmationPaymentIssuer.textContent = response.paymentIssuer || '';
            confirmationCustomer.textContent = state.checkout ? state.checkout.customerName : 'Elias';
            confirmationAmount.textContent = state.checkout ? state.checkout.amount + ' ETB' : '530.00 ETB';
            configureReceipt(response);
        }

        function configureReceipt(response) {
            const checkout = state.checkout || {};
            const lines = [
                'WeBirr Online Checkout Demo',
                '----------------------------',
                'Course Enrollment Receipt',
                '',
                'Customer Name: ' + (checkout.customerName || ''),
                'Course Title: ' + (checkout.courseTitle || ''),
                'Amount: ' + (checkout.amount || '') + ' ETB',
                'Merchant Reference: ' + (checkout.merchantReference || ''),
                'WeBirr Payment Code: ' + (state.paymentCode || ''),
                'Payment Reference: ' + (response.paymentReference || ''),
                'Paid Via: ' + (response.paymentIssuer || ''),
                'Enrollment Status: Enrolled',
                ''
            ];
            const filenameReference = checkout.merchantReference || 'webirr-course';
            downloadReceipt.href = 'data:text/plain;charset=utf-8,' + encodeURIComponent(lines.join('\n'));
            downloadReceipt.download = filenameReference + '-enrollment-receipt.txt';
        }

        function showScreen(screen) {
            entryPanel.hidden = screen !== 'entry';
            reviewPanel.hidden = screen !== 'review';
            paymentPanel.hidden = screen !== 'payment';
            confirmationReceipt.hidden = screen !== 'confirmation';
        }

        function newMerchantReference() {
            const random = Math.floor(Math.random() * 0xffffffff).toString(16).padStart(8, '0');
            return 'ord_' + random;
        }

        function statusLabel(status) {
            if (status === 2) {
                return 'paid';
            }
            if (status === 1) {
                return 'in progress';
            }
            if (status === 3) {
                return 'reversed';
            }
            return 'pending';
        }
    </script>
    <?php } ?>
</body>
</html>
<?php
}
