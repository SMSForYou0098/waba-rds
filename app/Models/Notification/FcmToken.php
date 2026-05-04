<?php

namespace App\Models\Notification;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class FcmToken extends Model
{
    protected $fillable = [
        'user_id',
        'token',
        'device_type',
        'device_id',
        'browser_name',
        'browser_version',
        'os_name',
        'device_info',
    ];

	protected $casts = [
    	'device_info' => 'array',
	];
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
