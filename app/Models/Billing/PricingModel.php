<?php

namespace App\Models\Billing;

use App\Models\User;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class PricingModel extends Model
{
    use HasFactory;

    protected $fillable = [
        'user_id',
        'price_alert',
        'marketing_price',
        'utility_price',
    ];
    public function user()
    {
        return $this->belongsTo(User::class);
    }
}
