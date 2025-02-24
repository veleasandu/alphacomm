<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use OpenApi\Attributes as OA;

#[OA\Schema(
    schema: "Transaction",
    required: ["id", "order_id", "payment_provider", "status", "response_data"],
    properties: [
        new OA\Property(property: "id", type: "integer", example: 1),
        new OA\Property(property: "order_id", type: "integer", example: 1),
        new OA\Property(property: "payment_provider", type: "string", example: "mock_provider"),
        new OA\Property(property: "status", type: "string", enum: ["pending", "success", "failed"], example: "success"),
        new OA\Property(
            property: "response_data",
            properties: [
                new OA\Property(property: "payment_method", type: "string", example: "card"),
                new OA\Property(property: "transaction_id", type: "string", example: "pi_123456789"),
                new OA\Property(property: "amount", type: "number", format: "float", example: 99.99),
                new OA\Property(property: "currency", type: "string", example: "EUR"),
                new OA\Property(property: "status", type: "string", example: "succeeded"),
                new OA\Property(
                    property: "error",
                    properties: [
                        new OA\Property(property: "code", type: "string", example: "card_declined"),
                        new OA\Property(property: "message", type: "string", example: "Card was declined")
                    ],
                    type: "object"
                ),
                new OA\Property(
                    property: "webhook_response",
                    properties: [
                        new OA\Property(property: "received", type: "boolean", example: true),
                        new OA\Property(property: "processed", type: "boolean", example: true),
                        new OA\Property(property: "type", type: "string", example: "payment.succeeded")
                    ],
                    type: "object"
                )
            ],
            type: "object"
        ),
        new OA\Property(property: "created_at", type: "string", format: "date-time"),
        new OA\Property(property: "updated_at", type: "string", format: "date-time")
    ]
)]
class Transaction extends Model
{
    use HasFactory;

    public const STATUS_PENDING = 'pending';
    public const STATUS_SUCCESS = 'success';
    public const STATUS_FAILED = 'failed';

    protected $fillable = [
        'order_id',
        'payment_provider',
        'status',
        'response_data'
    ];

    protected $casts = [
        'response_data' => 'array'
    ];

    public function order(): BelongsTo
    {
        return $this->belongsTo(Order::class);
    }
}
