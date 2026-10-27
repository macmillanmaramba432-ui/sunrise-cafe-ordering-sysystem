
<?php
session_start();

require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../config/mpesa.php';

header('Content-Type: application/json; charset=utf-8');

function respond(int $status, array $data): void
{
    http_response_code($status);
    echo json_encode($data);
    exit;
}

// Require a logged-in customer.
if (empty($_SESSION['customer_id'])) {
    respond(401, [
        'success' => false,
        'message' => 'Please log in before making a payment.'
    ]);
}

// Only accept POST requests.
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    respond(405, [
        'success' => false,
        'message' => 'Use POST to request a payment.'
    ]);
}

// Validate the order ID.
$orderId = filter_input(INPUT_POST, 'order_id', FILTER_VALIDATE_INT);

// Normalize Kenyan phone numbers.
$phoneInput = $_POST['phone'] ?? '';
$phone = preg_replace('/[\s\-()]/', '', (string) $phoneInput);

if (str_starts_with($phone, '+254')) {
    $phone = substr($phone, 1);
} elseif (preg_match('/^(07|01)\d{8}$/', $phone)) {
    $phone = '254' . substr($phone, 1);
}

if (
    !$orderId ||
    !preg_match('/^254[17]\d{8}$/', $phone)
) {
    respond(400, [
        'success' => false,
        'message' => 'Enter a valid Kenyan mobile number and order ID.'
    ]);
}

// Load the order belonging to this customer.
$stmt = $pdo->prepare(
    "SELECT id, order_number, total, payment_status,
            payment_method, mpesa_checkout_request_id
     FROM orders
     WHERE id = ? AND customer_id = ?
     LIMIT 1"
);

$stmt->execute([
    $orderId,
    (int) $_SESSION['customer_id']
]);

$order = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$order) {
    respond(404, [
        'success' => false,
        'message' => 'Order not found.'
    ]);
}

// Accept the payment-method spelling used by checkout.php.
$method = strtolower(trim((string) $order['payment_method']));

if (!in_array($method, ['mpesa', 'm-pesa'], true)) {
    respond(400, [
        'success' => false,
        'message' => 'This order is not set up for M-Pesa payment.'
    ]);
}

if (strtolower(trim((string) $order['payment_status'])) === 'paid') {
    respond(409, [
        'success' => false,
        'message' => 'This order is already paid.'
    ]);
}

// Prevent another prompt while a previous request is awaiting its result.
if (!empty($order['mpesa_checkout_request_id'])) {
    respond(409, [
        'success' => false,
        'message' => 'A payment request already exists for this order. Check its status before trying again.'
    ]);
}

$amount = (int) ceil((float) $order['total']);

if ($amount < 1) {
    respond(400, [
        'success' => false,
        'message' => 'The order amount is invalid.'
    ]);
}

// Check configuration.
if (
    !defined('MPESA_ENV') ||
    !defined('MPESA_CONSUMER_KEY') ||
    !defined('MPESA_CONSUMER_SECRET') ||
    !defined('MPESA_SHORTCODE') ||
    !defined('MPESA_PASSKEY') ||
    !defined('MPESA_CALLBACK_URL') ||
    MPESA_CONSUMER_KEY === '' ||
    MPESA_CONSUMER_SECRET === '' ||
    MPESA_PASSKEY === '' ||
    str_contains(MPESA_CALLBACK_URL, 'YOUR-PUBLIC-HTTPS-URL') ||
    !filter_var(MPESA_CALLBACK_URL, FILTER_VALIDATE_URL) ||
    parse_url(MPESA_CALLBACK_URL, PHP_URL_SCHEME) !== 'https'
) {
    respond(500, [
        'success' => false,
        'message' => 'M-Pesa configuration is incomplete. Check the credentials and public HTTPS callback URL.'
    ]);
}

if (!function_exists('curl_init')) {
    respond(500, [
        'success' => false,
        'message' => 'PHP cURL is not enabled. Enable the cURL extension in your PHP configuration.'
    ]);
}

if (!in_array(MPESA_ENV, ['sandbox', 'production'], true)) {
    respond(500, [
        'success' => false,
        'message' => 'Invalid M-Pesa environment setting.'
    ]);
}

$baseUrl = MPESA_ENV === 'sandbox'
    ? 'https://sandbox.safaricom.co.ke'
    : 'https://api.safaricom.co.ke';

// Get the OAuth access token.
$curl = curl_init(
    $baseUrl . '/oauth/v1/generate?grant_type=client_credentials'
);

curl_setopt_array($curl, [
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_USERPWD => MPESA_CONSUMER_KEY . ':' . MPESA_CONSUMER_SECRET,
    CURLOPT_HTTPAUTH => CURLAUTH_BASIC,
    CURLOPT_CONNECTTIMEOUT => 10,
    CURLOPT_TIMEOUT => 30
]);

$tokenResponse = curl_exec($curl);
$tokenStatus = curl_getinfo($curl, CURLINFO_HTTP_CODE);
$tokenError = curl_error($curl);
curl_close($curl);

$tokenData = json_decode($tokenResponse ?: '', true);

if (
    $tokenError ||
    $tokenStatus !== 200 ||
    empty($tokenData['access_token'])
) {
    error_log(
        'M-Pesa OAuth failed. HTTP ' . $tokenStatus .
        '; error: ' . $tokenError
    );

    respond(502, [
        'success' => false,
        'message' => 'Could not authenticate with M-Pesa. Check your sandbox credentials.'
    ]);
}

// Generate the STK Push password.
$timestamp = date('YmdHis');

$password = base64_encode(
    MPESA_SHORTCODE . MPESA_PASSKEY . $timestamp
);

// Prepare the payment request.
$payload = [
    'BusinessShortCode' => MPESA_SHORTCODE,
    'Password' => $password,
    'Timestamp' => $timestamp,
    'TransactionType' => 'CustomerPayBillOnline',
    'Amount' => $amount,
    'PartyA' => $phone,
    'PartyB' => MPESA_SHORTCODE,
    'PhoneNumber' => $phone,
    'CallBackURL' => MPESA_CALLBACK_URL,
    'AccountReference' => substr(
        (string) $order['order_number'],
        0,
        12
    ),
    'TransactionDesc' => 'Sunrise Cafe order payment'
];

$curl = curl_init(
    $baseUrl . '/mpesa/stkpush/v1/processrequest'
);

curl_setopt_array($curl, [
    CURLOPT_POST => true,
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_HTTPHEADER => [
        'Authorization: Bearer ' . $tokenData['access_token'],
        'Content-Type: application/json'
    ],
    CURLOPT_POSTFIELDS => json_encode($payload),
    CURLOPT_CONNECTTIMEOUT => 10,
    CURLOPT_TIMEOUT => 30
]);

$response = curl_exec($curl);
$status = curl_getinfo($curl, CURLINFO_HTTP_CODE);
$error = curl_error($curl);
curl_close($curl);

if ($error || $status < 200 || $status >= 300) {
    error_log(
        'M-Pesa STK request failed. HTTP ' . $status .
        '; error: ' . $error
    );

    respond(502, [
        'success' => false,
        'message' => 'We could not confirm the M-Pesa request response. Check the order before retrying.'
    ]);
}

$result = json_decode($response ?: '', true);

if (
    !is_array($result) ||
    ($result['ResponseCode'] ?? null) !== '0' ||
    empty($result['CheckoutRequestID'])
) {
    error_log(
        'M-Pesa STK request rejected: ' . ($response ?: 'Empty response')
    );

    respond(502, [
        'success' => false,
        'message' => 'Safaricom did not accept the payment request. Check the configuration and try again if no request was started.'
    ]);
}

// Save the request ID so the callback can locate this order.
// Keep the order unpaid until a verified payment result is processed.
try {
    $saveRequest = $pdo->prepare(
        "UPDATE orders
         SET mpesa_checkout_request_id = ?
         WHERE id = ?
           AND customer_id = ?
           AND payment_status = 'Pending'
           AND LOWER(payment_method) IN ('mpesa', 'm-pesa')
           AND mpesa_checkout_request_id IS NULL"
    );

    $saveRequest->execute([
        $result['CheckoutRequestID'],
        (int) $order['id'],
        (int) $_SESSION['customer_id']
    ]);

    if ($saveRequest->rowCount() !== 1) {
        error_log(
            'M-Pesa request ID could not be saved for order ' .
            (int) $order['id'] .
            '. CheckoutRequestID: ' .
            $result['CheckoutRequestID']
        );

        respond(500, [
            'success' => false,
            'message' => 'Safaricom may have started a payment request, but its reference could not be saved. Do not retry yet; check the order first.'
        ]);
    }
} catch (Throwable $e) {
    error_log(
        'Saving M-Pesa request ID failed: ' . $e->getMessage()
    );

    respond(500, [
        'success' => false,
        'message' => 'Safaricom may have started a payment request, but saving its reference failed. Check the order before retrying.'
    ]);
}

// This confirms the request was accepted, not that payment succeeded.
respond(200, [
    'success' => true,
    'message' => 'The payment request was accepted. Check your phone for the M-Pesa prompt.',
    'order_id' => (int) $order['id'],
    'checkout_request_id' => $result['CheckoutRequestID'],
    'customer_message' => $result['CustomerMessage']
        ?? 'Check your phone for the M-Pesa prompt.'
]);

