<?php

namespace App\Models;

use App\Support\GateCodes;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Airport extends Model
{
    protected $fillable = ['icao_code', 'name', 'city', 'latitude', 'longitude'];

    protected $casts = [
        'latitude' => 'float',
        'longitude' => 'float',
    ];

    public function gates(): HasMany
    {
        return $this->hasMany(Gate::class);
    }

    public function flights(): HasMany
    {
        return $this->hasMany(Flight::class);
    }

    public function hasCoordinates(): bool
    {
        return $this->latitude !== null && $this->longitude !== null;
    }

    /**
     * "Frankfurt Airport (EDDF)", used wherever an airport is shown to people.
     */
    public function getLabelAttribute(): string
    {
        return "{$this->name} ({$this->icao_code})";
    }

    public static function findByCode(?string $code): ?self
    {
        return $code ? static::where('icao_code', strtoupper($code))->first() : null;
    }

    /**
     * The airport pages and commands use when none is given: the configured one, else the first by code.
     */
    public static function default(): ?self
    {
        return static::findByCode(config('gates.airport_icao'))
            ?? static::orderBy('icao_code')->first();
    }

    /**
     * Gives the airport its gates (A1..A10, B1..B10 by default) if it has none yet.
     */
    public function ensureGates(?int $count = null): void
    {
        if ($this->gates()->exists()) {
            return;
        }

        $this->gates()->createMany(GateCodes::generate($count ?? config('gates.count')));
    }
}
