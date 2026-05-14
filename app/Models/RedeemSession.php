<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class RedeemSession extends Model
{
    protected $fillable = [
        'unipin_username', 'status', 'type', 'total', 'success', 'failed'
    ];

    public function codes()
    {
        return $this->hasMany(RedeemCode::class, 'session_id');
    }
}
