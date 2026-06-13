<?php

declare(strict_types=1);

require_once(__DIR__ . '/../vendor/autoload.php');

use WeBirr\Bill;
use WeBirr\WeBirrClient;

$path = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH) ?: '/';
$pluginroot = dirname(__DIR__);
$requestedfile = realpath($pluginroot . $path);

if (
    in_array($_SERVER['REQUEST_METHOD'], ['GET', 'HEAD'], true) &&
    $requestedfile !== false &&
    strpos($requestedfile, $pluginroot . DIRECTORY_SEPARATOR) === 0 &&
    is_file($requestedfile)
) {
    return false;
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
    $amount = format_amount((string)($payload['amount'] ?? '530.00'));
    $customername = trim((string)($payload['customerName'] ?? 'Elias'));
    $customerphone = trim((string)($payload['customerPhone'] ?? ''));
    $description = trim((string)($payload['description'] ?? 'moodle course enrollment'));
    $billreference = 'moodle_demo_' . date('YmdHis') . '_' . bin2hex(random_bytes(3));

    $bill = new Bill();
    $bill->amount = $amount;
    $bill->customerCode = 'MOODLE-DEMO';
    $bill->customerName = $customername !== '' ? $customername : 'Elias';
    $bill->customerPhone = $customerphone;
    $bill->time = date('Y-m-d H:i');
    $bill->description = $description !== '' ? $description : 'moodle course enrollment';
    $bill->billReference = $billreference;

    $client = webirr_client();
    $result = $client->createBill($bill);

    if (!empty($result->error)) {
        json_response([
            'success' => false,
            'error' => $result->error,
            'errorCode' => $result->errorCode ?? null,
        ], 502);
    }

    $paymentcode = (string)$result->res;
    $db = demo_db();
    $stmt = $db->prepare(
        'INSERT INTO demo_payments
            (bill_reference, payment_code, amount, customer_name, status, created_at, updated_at)
         VALUES
            (:bill_reference, :payment_code, :amount, :customer_name, 0, :created_at, :updated_at)'
    );
    $now = gmdate('c');
    $stmt->execute([
        ':bill_reference' => $billreference,
        ':payment_code' => $paymentcode,
        ':amount' => $amount,
        ':customer_name' => $bill->customerName,
        ':created_at' => $now,
        ':updated_at' => $now,
    ]);

    json_response([
        'success' => true,
        'paymentId' => (int)$db->lastInsertId(),
        'paymentCode' => $paymentcode,
        'billReference' => $billreference,
        'merchantReference' => 'pnr/2026/12/72627836',
        'amount' => $amount,
        'status' => 0,
    ]);
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

    $client = webirr_client();
    $result = $client->getPaymentStatus((string)$payment['payment_code']);

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

function webirr_client(): WeBirrClient {
    $merchantid = getenv('WEBIRR_TEST_ENV_MERCHANT_ID') ?: '';
    $apikey = getenv('WEBIRR_TEST_ENV_API_KEY') ?: '';

    if ($merchantid === '' || $apikey === '') {
        throw new RuntimeException('Set WEBIRR_TEST_ENV_MERCHANT_ID and WEBIRR_TEST_ENV_API_KEY before starting the demo.');
    }

    return new WeBirrClient($merchantid, $apikey, true);
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
            bill_reference TEXT NOT NULL UNIQUE,
            payment_code TEXT NOT NULL,
            amount TEXT NOT NULL,
            customer_name TEXT NOT NULL,
            status INTEGER NOT NULL DEFAULT 0,
            raw_status TEXT,
            created_at TEXT NOT NULL,
            updated_at TEXT NOT NULL
        )'
    );

    return $db;
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
        .journey-layout {
            display: grid;
            grid-template-columns: minmax(0, 1fr) 26px minmax(0, 1fr) 26px minmax(0, 1fr);
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
            background: white;
            color: var(--primary);
            border-color: #9cc3e7;
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
        .payment-instruction-link {
            margin-top: 10px;
            font-size: 14px;
        }
        .payment-instruction-link a {
            color: var(--primary);
            font-weight: 600;
            text-decoration: none;
        }
        .payment-instruction-link a:hover {
            text-decoration: underline;
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
        .confirmation-receipt {
            display: none;
            text-align: center;
        }
        .confirmation-mark {
            display: inline-flex;
            align-items: center;
            justify-content: center;
            width: 54px;
            height: 54px;
            margin-bottom: 12px;
            border-radius: 50%;
            background: var(--success-bg);
            border: 2px solid var(--success-border);
            color: var(--success-border);
            font-size: 30px;
            font-weight: 800;
            line-height: 1;
        }
        .confirmation-title {
            margin: 0 0 16px;
            font-size: 18px;
            font-weight: 800;
            letter-spacing: 0;
        }
        .confirmation-receipt .record {
            text-align: left;
        }
        .journey-panel {
            min-height: 360px;
        }
        .journey-panel .payment-code {
            display: block;
        }
        .journey-confirmed {
            padding-top: 18px;
            text-align: center;
        }
        .journey-confirmed .record {
            text-align: left;
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
                <div class="panel-title">Checkout</div>
                <div class="field">
                    <label>Customer</label>
                    <input value="Elias" readonly>
                </div>
                <div class="field">
                    <label>Amount</label>
                    <input value="530.00" readonly>
                </div>
                <div class="field">
                    <label>Description</label>
                    <input value="moodle course enrollment" readonly>
                </div>
                <div class="button-row">
                    <button class="primary" type="button">Checkout</button>
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
                <div class="payment-instruction-link">
                    <a href="https://webirr.net/instructions/all.html" target="_blank" rel="noopener">Payment Instruction</a>
                </div>
                <dl class="record">
                    <dt>Merchant reference</dt>
                    <dd>pnr/2026/12/72627836</dd>
                    <dt>Payment Status</dt>
                    <dd>pending</dd>
                </dl>
            </section>
            <div class="journey-arrow" aria-hidden="true">&rarr;</div>
            <section class="panel journey-panel journey-confirmed">
                <div class="confirmation-mark" aria-hidden="true">&#10003;</div>
                <div class="confirmation-title">Payment Confirmed</div>
                <dl class="record">
                    <dt>Payment Reference</dt>
                    <dd>TX70e78862148f4c249606</dd>
                    <dt>Paid Via</dt>
                    <dd>CBE Mobile</dd>
                </dl>
            </section>
        </div>
        <?php } else { ?>
        <div class="layout">
            <section class="panel">
                <div class="panel-title">Checkout</div>
                <div class="field">
                    <label for="customerName">Customer</label>
                    <input id="customerName" value="Elias" autocomplete="name">
                </div>
                <div class="field">
                    <label for="amount">Amount</label>
                    <input id="amount" value="530.00" inputmode="decimal">
                </div>
                <div class="field">
                    <label for="description">Description</label>
                    <input id="description" value="moodle course enrollment">
                </div>
                <div class="button-row">
                    <button class="primary" id="createBill">Checkout</button>
                </div>
            </section>

            <section class="panel">
                <div id="pendingReceipt">
                    <div class="payment-code-title" id="paymentCodeTitle">WeBirr Payment Code</div>
                    <div class="payment-code" id="paymentCode"></div>
                    <div class="status" id="statusBox">
                        <span class="status-spinner" id="statusSpinner" aria-hidden="true"></span>
                        <span id="statusText">Create a bill to start the checkout flow.</span>
                    </div>
                    <div class="payment-instruction-link">
                        <a href="https://webirr.net/instructions/all.html" target="_blank" rel="noopener">Payment Instruction</a>
                    </div>
                    <div class="meta" id="statusMeta"></div>
                    <div class="button-row" id="statusActions" style="display: none;">
                        <button class="primary" id="refreshStatus">Refresh</button>
                    </div>
                    <dl class="record" id="record" style="display: none;">
                        <dt>Merchant reference</dt>
                        <dd id="merchantReference"></dd>
                        <dt>Payment Status</dt>
                        <dd id="paymentStatus">pending</dd>
                    </dl>
                </div>
                <div class="confirmation-receipt" id="confirmationReceipt">
                    <div class="confirmation-mark" aria-hidden="true">&#10003;</div>
                    <div class="confirmation-title">Payment Confirmed</div>
                    <dl class="record">
                        <dt>Payment Reference</dt>
                        <dd id="confirmationPaymentReference"></dd>
                        <dt>Paid Via</dt>
                        <dd id="confirmationPaymentIssuer"></dd>
                    </dl>
                </div>
            </section>
        </div>
        <?php } ?>
    </main>
    <?php if ($preview !== 'journey') { ?>
    <script>
        const state = {
            paymentId: null,
            timer: null
        };
        const delayMs = 5000;
        const createButton = document.getElementById('createBill');
        const refreshButton = document.getElementById('refreshStatus');
        const actions = document.getElementById('statusActions');
        const statusBox = document.getElementById('statusBox');
        const statusSpinner = document.getElementById('statusSpinner');
        const statusText = document.getElementById('statusText');
        const statusMeta = document.getElementById('statusMeta');
        const paymentCodeTitle = document.getElementById('paymentCodeTitle');
        const paymentCode = document.getElementById('paymentCode');
        const record = document.getElementById('record');
        const pendingReceipt = document.getElementById('pendingReceipt');
        const confirmationReceipt = document.getElementById('confirmationReceipt');
        const confirmationPaymentReference = document.getElementById('confirmationPaymentReference');
        const confirmationPaymentIssuer = document.getElementById('confirmationPaymentIssuer');

        createButton.addEventListener('click', createBill);
        refreshButton.addEventListener('click', () => checkStatus(false));

        if (new URLSearchParams(window.location.search).get('preview') === 'confirmed') {
            showConfirmedReceipt({
                paymentReference: 'TX70e78862148f4c249606',
                paymentIssuer: 'CBE Mobile'
            });
        }

        async function createBill() {
            setBusy(true);
            showPendingReceipt();
            setStatus('Creating payment code...', 'info', true);
            actions.style.display = 'none';
            statusMeta.textContent = '';

            const response = await postJson('/api/create-bill', {
                customerName: document.getElementById('customerName').value,
                amount: document.getElementById('amount').value,
                description: document.getElementById('description').value
            });

            if (!response.success) {
                setBusy(false);
                setStatus(response.error || 'Unable to create bill.', 'danger', false);
                return;
            }

            state.paymentId = response.paymentId;
            paymentCodeTitle.style.display = 'block';
            paymentCode.textContent = response.paymentCode;
            paymentCode.style.display = 'block';
            record.style.display = 'grid';
            document.getElementById('merchantReference').textContent = response.merchantReference;
            document.getElementById('paymentStatus').textContent = 'pending';
            showPendingReceipt();
            setBusy(false);
            waitAndCheck();
        }

        function waitAndCheck() {
            window.clearTimeout(state.timer);
            actions.style.display = 'none';
            setActionBusy(true);
            setStatus('Waiting for payment confirmation...', 'info', true);
            statusMeta.textContent = '';
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
        }

        function setActionBusy(disabled) {
            refreshButton.disabled = disabled;
        }

        function setStatus(message, type, spinning) {
            statusBox.className = 'status ' + (type || 'info');
            statusText.textContent = message;
            statusSpinner.style.display = spinning ? 'inline-block' : 'none';
        }

        function showPendingReceipt() {
            pendingReceipt.style.display = 'block';
            confirmationReceipt.style.display = 'none';
            confirmationPaymentReference.textContent = '';
            confirmationPaymentIssuer.textContent = '';
        }

        function showConfirmedReceipt(response) {
            window.clearTimeout(state.timer);
            pendingReceipt.style.display = 'none';
            confirmationReceipt.style.display = 'block';
            confirmationPaymentReference.textContent = response.paymentReference || '';
            confirmationPaymentIssuer.textContent = response.paymentIssuer || '';
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
