<?php

namespace App\Models\Settings;

use App\Models\User;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class UserConfig extends Model
{
    use HasFactory;

    protected $table = 'userconfigs';

    /**
     * Mass-assignable columns (e.g. {@see \App\Services\Meta\EmbeddedSignupService::persistStep} via updateOrCreate).
     *
     * @var list<string>
     */
    protected $fillable = [
        'user_id',
        'meta_access_token',
        'app_id',
        'whatsapp_business_account_id',
        'business_account_id',
        'whatsapp_phone_id',
        'onboarding_status',
    ];

    public function user()
    {
        return $this->belongsTo(User::class);
    }
}
