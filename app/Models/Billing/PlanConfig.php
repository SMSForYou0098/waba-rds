<?php

namespace App\Models\Billing;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class PlanConfig extends Model
{
    use HasFactory;

    protected $fillable = [
        'plan_id',
        'support_agent',
      	'contact_group',
      	'media_storage'
    ];

    public function plan()
    {
        return $this->belongsTo(Plan::class);
    }
}
