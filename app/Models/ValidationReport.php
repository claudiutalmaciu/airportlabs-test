<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class ValidationReport extends Model
{
    protected $fillable = ['stats'];

    protected $casts = [
        'stats' => 'array',
    ];
}
