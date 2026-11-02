<?php

namespace App\Models;

use DateTimeInterface;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class GateAllocation extends Model
{
    protected $fillable = ['flight_id', 'gate_id', 'starts_at', 'ends_at'];

    protected $casts = [
        'starts_at' => 'datetime',
        'ends_at' => 'datetime',
    ];

    public function flight(): BelongsTo
    {
        return $this->belongsTo(Flight::class);
    }

    public function gate(): BelongsTo
    {
        return $this->belongsTo(Gate::class);
    }

    public function scopeOverlapping(Builder $query, DateTimeInterface $start, DateTimeInterface $end): void
    {
        $query->where('starts_at', '<', $end)->where('ends_at', '>', $start);
    }
}
