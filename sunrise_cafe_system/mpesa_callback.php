
<?php
/*
|--------------------------------------------------------------------------
| Sunrise Cafe - M-Pesa STK Push Callback
|--------------------------------------------------------------------------
| Receives the payment result from Safaricom.
| Never mark an order as paid from the initial STK Push response.
*/

require_once __DIR__ . '/../config/database.php';

header('Content-Type: application/json; charset=utf-8');

function callbackResponse(): void
{
    // Acknowledge receipt to Safaricom without exposing internal details.
    http_response_code(200);

    echo json_encode([
        'ResultCode' => 0,
        'ResultDesc' => 'Accepted'
    ]);

    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['message' => 'POST required']);
    exit;
}

$rawBody = file_get_contents('php://input');
$data = json_decode($rawBody ?: '', true);

$callback = $data['Body']['stkCallback'] ?? null;

if (!is_array($callback)) {
    error_log('M-Pesa callback received an invalid payload.');
    callbackResponse();
}

$checkoutRequestId = $callback['CheckoutRequestID'] ?? '';
$resultCode = $callback['ResultCode'] ?? null;

if (
    !is_string($checkoutRequestId) ||
    $checkoutRequestId === '' ||
    !is_numeric($resultCode)
) {
    error_log('M-Pesa callback is missing required fields.');
    callbackResponse();
}

try {
    $pdo->beginTransaction();

    // Lock the matching order while processing the callback.
    $stmt = $pdo->prepare(
        "SELECT id, total, payment_status, mpesa_receipt_number
         FROM orders
         WHERE mpesa_checkout_request_id = ?
         LIMIT 1
         FOR UPDATE"
    );

    $stmt->execute([$checkoutRequestId]);
    $order = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$order) {
        $pdo->rollBack();

        error_log(
            'M-Pesa callback has no matching order. Request ID: ' .
            $checkoutRequestId
        );

        callbackResponse();
    }

    // Duplicate callbacks must not process the payment twice.
    if (
        strtolower((string) $order['payment_status']) === 'paid' ||
        !empty($order['mpesa_receipt_number'])
    ) {
        $pdo->commit();
        callbackResponse();
    }

    // A non-zero result means the payment was not successful.
    if ((int) $resultCode !== 0) {
        $pdo->commit();

        error_log(
            'M-Pesa payment unsuccessful for order ID ' .
            (int) $order['id'] .
            ': ' . (string) ($callback['ResultDesc'] ?? 'Unknown result')
        );

        callbackResponse();
    }

    // Successful callbacks must contain transaction metadata.
    $items = $callback['CallbackMetadata']['Item'] ?? [];

    if (!is_array($items)) {
        $pdo->rollBack();
        error_log('M-Pesa success callback has no metadata.');
        callbackResponse();
    }

    $metadata = [];

    foreach ($items as $item) {
        if (
            is_array($item) &&
            isset($item['Name']) &&
            array_key_exists('Value', $item)
        ) {
            $metadata[$item['Name']] = $item['Value'];
        }
    }

    $receipt = $metadata['MpesaReceiptNumber'] ?? '';
    $paidAmount = $metadata['Amount'] ?? null;

    if (
        !is_string($receipt) ||
        $receipt === '' ||
        !is_numeric($paidAmount)
    ) {
        $pdo->rollBack();
        error_log('M-Pesa success callback has incomplete payment details.');
        callbackResponse();
    }

    // Verify the amount against the server-calculated order total.
    // STK Push amounts are whole shillings in this integration.
    $expectedAmount = (int) ceil((float) $order['total']);

    if ((int) round((float) $paidAmount) !== $expectedAmount) {
        $pdo->rollBack();

        error_log(
            'M-Pesa amount mismatch for order ID ' .
            (int) $order['id']
        );

        callbackResponse();
    }

    // Store the receipt and mark the order paid atomically.
    $update = $pdo->prepare(
        "UPDATE orders
         SET payment_status = 'Paid',
             mpesa_receipt_number = ?
         WHERE id = ?
           AND payment_status = 'Pending'
           AND mpesa_receipt_number IS NULL"
    );

    $update->execute([
        $receipt,
        (int) $order['id']
    ]);

    if ($update->rowCount() !== 1) {
        $pdo->rollBack();

        error_log(
            'M-Pesa payment status could not be updated for order ID ' .
            (int) $order['id']
        );

        callbackResponse();
    }

    $pdo->commit();

} catch (Throwable $e) {
    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }

    error_log('M-Pesa callback processing failed: ' . $e->getMessage());
}

callbackResponse();
