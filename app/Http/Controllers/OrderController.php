<?php

namespace App\Http\Controllers;

use App\Http\Resources\OrderResource;
use App\Jobs\ProcessPayment;
use App\Models\Order;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use OpenApi\Attributes as OA;

#[OA\Tag(
    name: "Orders",
    description: "API Endpoints for order management"
)]
class OrderController extends Controller
{
    #[OA\Get(
        path: "/orders",
        summary: "List all orders",
        security: [["bearerAuth" => []]],
        tags: ["Orders"],
        parameters: [
            new OA\Parameter(
                name: "status",
                description: "Filter by order status",
                in: "query",
                required: false,
                schema: new OA\Schema(type: "string", enum: ["pending", "paid", "failed"])
            )
        ],
        responses: [
            new OA\Response(
                response: 200,
                description: "List of orders",
                content: new OA\JsonContent(
                    properties: [
                        new OA\Property(
                            property: "data",
                            type: "array",
                            items: new OA\Items(ref: "#/components/schemas/Order")
                        )
                    ]
                )
            ),
            new OA\Response(response: 401, description: "Unauthenticated")
        ]
    )]
    public function index(Request $request): JsonResponse
    {
        $orders = Order::with('transactions')
            ->when($request->status, fn($query, $status) => $query->where('status', $status))
            ->latest()
            ->paginate();

        return response()->json([
            'data' => OrderResource::collection($orders)
        ]);
    }

    #[OA\Post(
        path: "/orders",
        summary: "Create a new order",
        security: [["bearerAuth" => []]],
        requestBody: new OA\RequestBody(
            required: true,
            content: new OA\JsonContent(
                required: ["amount"],
                properties: [
                    new OA\Property(property: "amount", type: "number", format: "float")
                ]
            )
        ),
        tags: ["Orders"],
        responses: [
            new OA\Response(
                response: 201,
                description: "Order created successfully",
                content: new OA\JsonContent(ref: "#/components/schemas/Order")
            ),
            new OA\Response(response: 422, description: "Validation error"),
            new OA\Response(response: 401, description: "Unauthenticated")
        ]
    )]
    public function store(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'amount' => 'required|numeric|min:0.01',
        ]);

        $order = Order::create([
            'user_id' => auth()->id(),
            'amount' => $validated['amount'],
            'status' => Order::STATUS_PENDING
        ]);

        return response()->json([
            'data' => new OrderResource($order)
        ], 201);
    }

    #[OA\Get(
        path: "/orders/{id}",
        summary: "Get order details",
        security: [["bearerAuth" => []]],
        tags: ["Orders"],
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
                description: "Order details",
                content: new OA\JsonContent(ref: "#/components/schemas/Order")
            ),
            new OA\Response(response: 404, description: "Order not found"),
            new OA\Response(response: 401, description: "Unauthenticated")
        ]
    )]
    public function show(Order $order): JsonResponse
    {
        $order->load('transactions');
        return response()->json([
            'data' => new OrderResource($order)
        ]);
    }

    #[OA\Post(
        path: "/orders/{id}/pay",
        summary: "Process payment for an order",
        security: [["bearerAuth" => []]],
        requestBody: new OA\RequestBody(
            required: true,
            content: new OA\JsonContent(
                required: ["payment_method", "payment_details"],
                properties: [
                    new OA\Property(
                        property: "payment_method",
                        type: "string",
                        enum: ["card", "bank_transfer"]
                    ),
                    new OA\Property(
                        property: "payment_details",
                        properties: [
                            new OA\Property(property: "number", type: "string"),
                            new OA\Property(property: "expiry", type: "string"),
                            new OA\Property(property: "cvv", type: "string")
                        ],
                        type: "object"
                    )
                ]
            )
        ),
        tags: ["Orders"],
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
                description: "Payment processed successfully",
                content: new OA\JsonContent(
                    properties: [
                        new OA\Property(property: "message", type: "string"),
                        new OA\Property(
                            property: "data",
                            properties: [
                                new OA\Property(property: "order", ref: "#/components/schemas/Order"),
                                new OA\Property(property: "transaction_id", type: "string")
                            ],
                            type: "object"
                        )
                    ]
                )
            ),
            new OA\Response(response: 400, description: "Payment failed"),
            new OA\Response(response: 422, description: "Validation error"),
            new OA\Response(response: 429, description: "Too many requests"),
            new OA\Response(response: 401, description: "Unauthenticated")
        ]
    )]
    public function processPayment(Request $request, Order $order): JsonResponse
    {
        if ($order->status !== Order::STATUS_PENDING) {
            return response()->json([
                'message' => 'Order cannot be processed',
                'reason' => "Current status: {$order->status}"
            ], 422);
        }

        $validated = $request->validate([
            'payment_method' => 'required|string|in:card,bank_transfer',
            'payment_details' => 'required|array',
            'payment_details.number' => 'required_if:payment_method,card|string',
            'payment_details.expiry' => 'required_if:payment_method,card|string',
            'payment_details.cvv' => 'required_if:payment_method,card|string',
        ]);

        // Dispatch payment processing job
        ProcessPayment::dispatch($order, $validated);

        return response()->json([
            'message' => 'Payment processing initiated',
            'data' => [
                'order' => new OrderResource($order),
                'status' => 'processing'
            ]
        ], 202);
    }
}
