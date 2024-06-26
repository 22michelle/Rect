<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Transactions extends Model
{
    use HasFactory;

    protected $fillable = ['sender_id', 'receiver_id', 'amount', 'fee_rate', 'is_distributed'];

    public function sender(): BelongsTo
    {
        return $this->belongsTo(Account::class, 'sender_id');
    }

    public function receiver(): BelongsTo
    {
        return $this->belongsTo(Account::class, 'receiver_id');
    }
}
