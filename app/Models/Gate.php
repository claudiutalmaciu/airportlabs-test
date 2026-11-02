<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Gate extends Model
{
    protected $fillable = ['airport_id', 'code', 'terminal'];

    public function airport(): BelongsTo
    {
        return $this->belongsTo(Airport::class);
    }

    public function allocations(): HasMany
    {
        return $this->hasMany(GateAllocation::class);
    }

    public function restrictions(): HasMany
    {
        return $this->hasMany(GateRestriction::class);
    }
}
