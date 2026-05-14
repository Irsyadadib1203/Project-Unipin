<?php

namespace App\Models;
 
use Illuminate\Database\Eloquent\Model;
 
class UnipinJob extends Model
{
    protected $fillable = [
        'session_key',
        'kode',
        'status',
        'type',
        'log',
        'amount',
        'no_transaksi',
    ];
 
    // Scope: filter by session
    public function scopeForSession($query, string $key)
    {
        return $query->where('session_key', $key);
    }
}