<?php

namespace App\Models\Billing;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

class Plan extends Model
{
    use HasFactory, SoftDeletes;

    /**
     * The attributes that are mass assignable.
     *
     * @var array
     */
    protected $fillable = [
        'title',
        'role_id',
        'monthly_price',
        'yearly_price',
        'custom_price',
        'button_text',
        'features',
        'recommended',
        'active',
    ];
    protected $casts = [
        'features' => 'array',
    ];

}
