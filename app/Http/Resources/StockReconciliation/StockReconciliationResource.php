<?php

namespace App\Http\Resources\StockReconciliation;

use App\Models\StockReconciliation;
use App\Models\StockReconciliationLine;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin StockReconciliation
 */
class StockReconciliationResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'branchName' => $this->whenLoaded('branch', fn () => $this->branch->name),
            'initiatedByName' => $this->initiatedBy?->name,
            'isCompleted' => $this->isCompleted(),
            'statusLabel' => $this->isCompleted() ? 'مكتمل' : 'قيد الجرد',
            'notes' => $this->notes,
            'linesCount' => $this->whenCounted('lines'),
            'variantLinesCount' => $this->whenLoaded(
                'lines',
                fn () => $this->lines->filter(fn (StockReconciliationLine $line) => $line->variance !== 0)->count(),
            ),
            'totalVariance' => $this->whenLoaded(
                'lines',
                fn () => (int) $this->lines->sum('variance'),
            ),
            'createdAt' => $this->created_at?->format('d/m/Y H:i'),
            'completedAt' => $this->completed_at?->format('d/m/Y H:i'),
            'lines' => StockReconciliationLineResource::collection($this->whenLoaded('lines')),
        ];
    }
}
