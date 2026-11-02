<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A problem met while fetching, allocating or validating (not a PHP exception).
 */
class AllocationException extends Model
{
    public const NO_GATE_AVAILABLE = 'no_gate_available';

    public const FETCH_FAILED = 'fetch_failed';

    public const OVERLAP = 'overlap';

    public const RESTRICTION_CONFLICT = 'restriction_conflict';

    public const REALLOCATED = 'reallocated';

    /**
     * How each type is shown to people.
     */
    public const TYPE_LABELS = [
        self::NO_GATE_AVAILABLE => 'No gate available',
        self::FETCH_FAILED => 'Fetch failed',
        self::RESTRICTION_CONFLICT => 'Moved off a closed gate',
        self::OVERLAP => 'Overlap fixed',
        self::REALLOCATED => 'Reallocated',
    ];

    protected $fillable = ['airport_id', 'flight_id', 'gate_id', 'type', 'message', 'context'];

    protected $casts = [
        'context' => 'array',
    ];

    public function getTypeLabelAttribute(): string
    {
        return self::TYPE_LABELS[$this->type] ?? ucfirst(str_replace('_', ' ', $this->type));
    }

    public function airport(): BelongsTo
    {
        return $this->belongsTo(Airport::class);
    }

    public function flight(): BelongsTo
    {
        return $this->belongsTo(Flight::class);
    }

    public function gate(): BelongsTo
    {
        return $this->belongsTo(Gate::class);
    }
}
