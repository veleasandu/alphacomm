<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class TransactionResource extends JsonResource
{
    /**
     * Transform the resource into an array.
     *
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'order_id' => $this->order_id,
            'payment_provider' => $this->payment_provider,
            'status' => $this->status,
            'response_data' => $this->when($this->response_data, function() {
                // Remove sensitive data if present
                $data = $this->response_data;
                unset($data['card_number']);
                unset($data['cvv']);
                return $data;
            }),
            'order' => new OrderResource($this->whenLoaded('order')),
            'created_at' => $this->created_at->format('Y-m-d H:i:s'),
            'updated_at' => $this->updated_at->format('Y-m-d H:i:s')
        ];
    }
}
