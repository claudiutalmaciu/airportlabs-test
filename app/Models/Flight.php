<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

class Flight extends Model
{
    public const ARRIVAL = 'arrival';

    public const DEPARTURE = 'departure';

    public const STATUS_PENDING = 'pending';

    public const STATUS_ALLOCATED = 'allocated';

    public const STATUS_UNALLOCATED = 'unallocated';

    /**
     * How each gate status is shown to people. The stored values stay allocated / unallocated / pending.
     */
    public const STATUS_LABELS = [
        self::STATUS_ALLOCATED => 'Gate allocated',
        self::STATUS_UNALLOCATED => 'No gate available',
        self::STATUS_PENDING => 'Awaiting gate',
    ];

    protected $fillable = [
        'airport_id', 'direction', 'icao24', 'callsign', 'airline_code', 'airline_name', 'departure_airport', 'arrival_airport',
        'first_seen', 'last_seen', 'observed_at', 'gate_status',
    ];

    protected $casts = [
        'first_seen' => 'datetime',
        'last_seen' => 'datetime',
        'observed_at' => 'datetime',
    ];

    protected static function booted(): void
    {
        static::saving(function (Flight $flight) {
            $flight->airline_code ??= static::airlineCodeFrom($flight->callsign);
            $flight->airline_name ??= static::airlineName($flight->airline_code);
        });
    }

    /**
     * The ICAO airline designator in an airline callsign: three letters then the flight number (DLH4AB → DLH).
     * Private and military callsigns (e.g. registrations like DABCD) give null.
     */
    public static function airlineCodeFrom(?string $callsign): ?string
    {
        return $callsign !== null && preg_match('/^([A-Z]{3})\d/', $callsign, $matches) ? $matches[1] : null;
    }

    /**
     * Commercial name for an airline code, from config/airlines.php.
     */
    public static function airlineName(?string $code): ?string
    {
        return $code !== null ? config("airlines.{$code}") : null;
    }

    public function getGateStatusLabelAttribute(): string
    {
        return self::STATUS_LABELS[$this->gate_status] ?? ucfirst((string) $this->gate_status);
    }

    public function airport(): BelongsTo
    {
        return $this->belongsTo(Airport::class);
    }

    public function allocation(): HasOne
    {
        return $this->hasOne(GateAllocation::class);
    }

    public function exceptions(): HasMany
    {
        return $this->hasMany(AllocationException::class);
    }
}
