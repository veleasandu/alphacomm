<?php

namespace App\Http\Controllers;

use App\Http\Resources\TransactionResource;
use App\Models\Order;
use App\Models\Transaction;
use App\Services\Payment\PaymentServiceInterface;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use OpenApi\Attributes as OA;

#[OA\Tag(
    name: "Transactions",
    description: "API Endpoints for transaction management"
)]
class TransactionController extends Controller
{
    #[OA\Get(
        path: "/transactions/{id}",
        summary: "Get transaction details",
        security: [["bearerAuth" => []]],
        tags: ["Transactions"],
        parameters: [
            new OA\Parameter(
                name: "id",
                in: "path",
                required: true,
                schema: new OA\Schema(type: "integer")
            )
        ],
        responses: [
            new OA\Response(
                response: 200,
                description: "Transaction details",
                content: new OA\JsonContent(ref: "#/components/schemas/Transaction")
            ),
            new OA\Response(
                response: 404,
                description: "Transaction not found",
                content: new OA\JsonContent(
                    properties: [
                        new OA\Property(
                            property: "message",
                            type: "string",
                            example: "Transaction not found"
                        )
                    ]
                )
            ),
            new OA\Response(response: 401, description: "Unauthenticated")
        ]
    )]
    public function show($id): JsonResponse
    {
        $transaction = Transaction::find($id);
        
        if (!$transaction) {
            return response()->json([
                'message' => 'Transaction not found'
            ], 404);
        }

        $transaction->load('order');
        return response()->json(new TransactionResource($transaction));
    }

    #[OA\Post(
        path: "/webhooks/payment",
        summary: "Handle payment provider webhook notifications",
        requestBody: new OA\RequestBody(
            required: true,
            content: new OA\JsonContent(
                required: ["event", "transaction_id"],
                properties: [
                    new OA\Property(
                        property: "event",
                        type: "string",
                        enum: ["payment.succeeded", "payment.failed"]
                    ),
                    new OA\Property(property: "transaction_id", type: "string"),
                    new OA\Property(property: "reason", type: "string")
                ]
            )
        ),
        tags: ["Transactions"],
        responses: [
            new OA\Response(
                response: 200,
                description: "Webhook processed successfully",
                content: new OA\JsonContent(
                    properties: [
                        new OA\Property(
                            property: "message",
                            type: "string",
                            example: "Webhook processed successfully"
                        )
                    ]
                )
            ),
            new OA\Response(
                response: 500,
                description: "Webhook processing failed",
                content: new OA\JsonContent(
                    properties: [
                        new OA\Property(
                            property: "message",
                            type: "string",
                            example: "Webhook processing failed"
                        ),
                        new OA\Property(property: "error", type: "string")
                    ]
                )
            )
        ]
    )]
    public function handleWebhook(Request $request, PaymentServiceInterface $paymentService): JsonResponse
    {
        try {
            // Validate webhook signature
            $payload = $request->all();
            $webhookResponse = $paymentService->handleWebhook($payload);

            // Extract transaction ID from payload
            $transactionId = $payload['transaction_id'] ?? null;
            if (!$transactionId) {
                throw new \Exception('Transaction ID not found in webhook payload');
            }

            // Find and update transaction
            DB::beginTransaction();

            $transaction = Transaction::where('response_data->transaction_id', $transactionId)->first();
            if (!$transaction) {
                throw new \Exception('Transaction not found: ' . $transactionId);
            }

            $order = $transaction->order;

            // Update transaction and order status based on webhook event
            switch ($payload['type'] ?? '') {
                case 'payment_intent.succeeded':
                    $transaction->update([
                        'status' => Transaction::STATUS_SUCCESS,
                        'response_data' => array_merge($transaction->response_data ?? [], [
                            'webhook_response' => $webhookResponse
                        ])
                    ]);
                    $order->update(['status' => Order::STATUS_PAID]);
                    break;

                case 'payment.failed':
                    $transaction->update([
                        'status' => Transaction::STATUS_FAILED,
                        'response_data' => array_merge($transaction->response_data ?? [], [
                            'webhook_response' => $webhookResponse,
                            'failure_reason' => $payload['reason'] ?? 'Unknown failure reason'
                        ])
                    ]);
                    $order->update(['status' => Order::STATUS_FAILED]);
                    break;

                default:
                    Log::info('Unhandled webhook event', ['payload' => $payload]);
                    break;
            }

            DB::commit();

            return response()->json([
                'message' => 'Webhook processed successfully'
            ]);

        } catch (\Exception $e) {
            DB::rollBack();

            Log::error('Webhook processing failed', [
                'error' => $e->getMessage(),
                'payload' => $request->all()
            ]);

            return response()->json([
                'message' => 'Webhook processing failed',
                'error' => $e->getMessage()
            ], 500);
        }
    }
}
