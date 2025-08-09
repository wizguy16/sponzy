<?php

namespace App\Http\Controllers;

use App\Models\User;
use App\Models\Deposits;
use Illuminate\Http\Request;
use App\Models\PaymentGateways;
use Illuminate\Support\Facades\Http;

class AtlosWebhookController extends Controller
{
    use Traits\Functions;

    public function webhook(Request $request)
    {
        $payment = PaymentGateways::whereName('Atlos')->firstOrFail();
        $signature = $request->header('signature');
        $payload = $request->getContent();
        $apiSecret = $payment->key_secret;

        // Verify the HMAC-SHA256 signature
        $calculatedHash = base64_encode(hash_hmac('sha256', $payload, $apiSecret, true));

        if ($signature !== $calculatedHash) {
            info('ATLOS Webhook: Signature mismatch. Received ' . $signature . ' Expected ' . $calculatedHash);
            return response('Invalid signature', 401);
        }

        $response = json_decode($payload, true);

        info('ATLOS Webhook received:', $response);

        // Process the webhook based on the status
        if ($response['Status'] == 100) {
            if (!$this->confirmPaymentByHash($response['BlockchainHash'])) {
                info('Invalid blockchain hash (from ATLOS Webhook)');
                return response('Invalid blockchain hash', 403);
            }

            $dataDecode = base64_decode($response['OrderId']);
            parse_str($dataDecode, $data);

            if (Deposits::where('txn_id', $response['OrderId'])->doesntExist()) {
                $this->deposit(
                    $data['user'],
                    $orderId,
                    $data['amount'],
                    'Atlos',
                    $data['taxes'] ?? null
                );

                // Add Funds to User
                User::find($data['user'])->increment('wallet', $data['amount']);
            }

            info("Payment confirmed for the order {$response['OrderId']}");
        } else {
            info("Payment status not confirmed for order {$response['OrderId']}: Status {$response['Status']}");
        }

        return response('OK', 200);
    }

    protected function confirmPaymentByHash(string $blockchainHash)
    {
        $payment = PaymentGateways::whereName('Atlos')->firstOrFail();
        $merchantId = $payment->key;

        $response = Http::post('https://atlos.io/api/Transaction/FindByHash', [
            'MerchantId' => $merchantId,
            'BlockchainHash' => $blockchainHash,
        ]);

        if ($response->failed()) {
            info('Error querying ATLOS (From confirmPaymentByHash): ' . $response->body());
            return false;
        }

        $result = $response->json();

        if (!$result['IsFound']) {
            info("Transaction not found (From confirmPaymentByHash): $blockchainHash");
            return false;
        }

        $transaction = $result['Transaction'];

        if ($transaction['Status'] == 100) {
            info("Payment confirmed for the order (From confirmPaymentByHash) {$transaction['OrderId']}");
            return true;
        } else {
            info("Payment status not confirmed for order (From confirmPaymentByHash) {$transaction['OrderId']}: Status {$transaction['Status']}");
            return false;
        }
    }
}
