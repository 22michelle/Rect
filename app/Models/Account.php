<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Account extends Model
{
    use HasFactory;


    protected $fillable = [
        'name', 'balance', 'link_obligation', 'link_income', 'value', 'public_rate', 'auxiliary', 'trigger', 'trxCount'
    ];

    public function sentTransactions(): HasMany
    {
        return $this->hasMany(Transactions::class, 'sender_id');
    }

    public function receivedTransactions(): HasMany
    {
        return $this->hasMany(Transactions::class, 'receiver_id');
    }
}
