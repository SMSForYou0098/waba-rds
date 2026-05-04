<?php

namespace App\Models\Billing;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
use Spatie\Permission\Models\Role;

class UserPlan extends Model
{
    use SoftDeletes;

    protected $fillable = [
        'user_id', 'plan_id', 'role_id',
        'starts_at', 'expires_at', 'status',
        'auto_renew', 'meta'
    ];

    protected $casts = [
        'starts_at' => 'datetime',
        'expires_at' => 'datetime',
        'auto_renew' => 'boolean',
    ];

    // encrypt meta at rest
    public function setMetaAttribute($value)
    {
        if ($value === null) {
            $this->attributes['meta'] = null;
            return;
        }

        // encode then encrypt -- safe for arrays/objects
        $this->attributes['meta'] = encrypt(json_encode($value));
    }

    public function getMetaAttribute($value)
    {
        if (empty($value)) {
            return null;
        }

        try {
            return json_decode(decrypt($value), true);
        } catch (\Exception $e) {
            return null;
        }
    }

    public function user()
    {
        return $this->belongsTo(User::class);
    }

    public function plan()
    {
        return $this->belongsTo(Plan::class);
    }

    public function role()
    {
        return $this->belongsTo(Role::class, 'role_id');
    }
}
