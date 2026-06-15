<?php

namespace App\Http\Resources\Agent;

use App\Models\AgentPayment;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin AgentPayment
 */
class AgentPaymentResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'agentName' => $this->agent?->name,
            'periodStart' => $this->period_start->format('d/m/Y'),
            'periodEnd' => $this->period_end->format('d/m/Y'),
            'totalInvoices' => $this->total_invoices,
            'totalRebate' => (float) $this->total_rebate,
            'paidBy' => $this->paidBy?->name,
            'paidAt' => $this->paid_at?->format('d/m/Y H:i'),
            'notes' => $this->notes,
        ];
    }
}
