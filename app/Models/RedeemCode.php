<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class RedeemCode extends Model
{
    protected $fillable = [
        'session_id', 'kode', 'pin', 'status', 'message'
    ];

    public function session()
    {
        return $this->belongsTo(RedeemSession::class);
    }
}
