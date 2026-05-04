<?php

namespace App\Models\Settings;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class BrandingConfiguration extends Model
{
    use HasFactory;

    /**
     * The attributes that are mass assignable.
     *
     * @var array<int, string>
     */
    protected $fillable = [
        'user_id',
        'logo',
        'host_url',
        'login_bg',
        'terms',
        'privacy',
        'copyright'
    ];

    /**
     * The attributes that should be cast.
     *
     * @var array<string, string>
     */
    protected $casts = [
        'copyright' => 'array',
    ];

    /**
     * Get the user that owns the branding configuration.
     */
    public function user()
    {
        return $this->belongsTo(User::class);
    }
}
