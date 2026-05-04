<?php

namespace App\Models\Billing;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Balance extends Model
{
    use HasFactory;

    protected $fillable = [
        'user_id', // Add user_id to the fillable array
        // Add other fillable fields if needed
    ];
    public function user()
    {
        return $this->belongsTo(User::class);
    }
      public function accountManager()
    {
        return $this->belongsTo(User::class, 'account_manager_id');
    }
    public function outReport()
    {
        return $this->belongsTo(OutReport::class, 'id', 'report_id');
    }
}
