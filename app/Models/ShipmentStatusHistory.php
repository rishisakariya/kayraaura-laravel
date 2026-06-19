<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Collection;

class ShipmentStatusHistory extends Model
{
    public const DIRECTION_FORWARD = 'forward';

    public const DIRECTION_REVERSE = 'reverse';

    public const SOURCE_WEBHOOK = 'webhook';

    public const SOURCE_SYNC = 'sync';

    public const SOURCE_ADMIN = 'admin';

    public const SOURCE_SYSTEM = 'system';

    public $timestamps = false;

    protected $fillable = [
        'shipment_id',
        'direction',
        'old_status',
        'new_status',
        'raw_status',
        'source',
        'created_at',
    ];

    protected $casts = [
        'created_at' => 'datetime',
    ];

    public function shipment(): BelongsTo
    {
        return $this->belongsTo(OrderShipment::class, 'shipment_id');
    }

    public function toApiArray(): array
    {
        return [
            'direction' => $this->direction,
            'old_status' => $this->old_status,
            'new_status' => $this->new_status,
            'raw_status' => $this->raw_status,
            'source' => $this->source,
            'created_at' => $this->created_at?->format('Y-m-d H:i:s'),
        ];
    }

    public static function formatForApi(Collection $histories): array
    {
        return $histories
            ->sortBy('created_at')
            ->values()
            ->map(fn (self $history) => $history->toApiArray())
            ->all();
    }
}
