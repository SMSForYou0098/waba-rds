<?php

namespace App\Models\Billing;

use App\Models\User;
use Illuminate\Database\Eloquent\Model;

class BalancesArchive extends Model
{
    protected $table = 'balances_archive';

    protected $fillable = [
        'original_id',
        'user_id',
        'total_credits',
        'alert_credit',
        'new_credit',
        'payment_type',
        'account_manager_id',
        'manual_deduction',
        'auto_deduction',
        'report_id',
        'remarks',
        'duplicate_count',
        'archived_at',
    ];

    protected $casts = [
        'archived_at' => 'datetime',
    ];

    public function user()
    {
        return $this->belongsTo(User::class);
    }

    public function accountManager()
    {
        return $this->belongsTo(User::class, 'account_manager_id');
    }
}
