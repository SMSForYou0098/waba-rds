<?php

namespace App\Models;

use App\Models\Billing\Plan;
use App\Models\Chat\ChatbotAuth;
use App\Models\Settings\BrandingConfiguration;
use App\Models\Report\OutReport;
use App\Models\Campaign\ScheduleCampaign;
use App\Models\Contact\UserBlockNumber;
use App\Models\Report\Report;
use App\Models\Billing\PricingModel;
use App\Models\Template\CrouselPreset;
use App\Models\Auth\ApiKey;
use App\Models\Media\Media;
use App\Models\Chat\DefaultChatbot;
use App\Models\Settings\UserConfig;
use App\Models\Campaign\Campaign;
use App\Models\Chat\Chatbot;
use App\Models\Chat\SupportAgent;
use App\Models\Report\AgentHasReport;
use App\Models\Chat\ChatbotIdleTimer;
use App\Models\Billing\Balance;


use Illuminate\Contracts\Auth\MustVerifyEmail;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
// use Laravel\Sanctum\HasApiTokens;
use Spatie\Permission\Traits\HasRoles;
use Laravel\Passport\HasApiTokens;

class User extends Authenticatable implements MustVerifyEmail
{
    use HasApiTokens, HasFactory, Notifiable, HasRoles, SoftDeletes;

    protected $guard_name = 'api';

    /**
     * The attributes that are mass assignable.
     *
     * @var array<int, string>
     */
    protected $fillable = [
        'name',
        'email',
        'password',
    ];

    /**
     * The attributes that should be hidden for serialization.
     *
     * @var array<int, string>
     */
    protected $hidden = [
        'password',
        'remember_token',
    ];

    /**
     * The attributes that should be cast.
     *
     * @var array<string, string>
     */
    protected $casts = [
        'email_verified_at' => 'datetime',
        'password' => 'hashed',
    ];

    public function latestBalance()
    {
        return $this->hasOne(Balance::class, 'user_id')->latest();
    }
    public function idleTimerData()
    {
        return $this->hasOne(ChatbotIdleTimer::class);
    }

    public function usersUnder()
    {
        return $this->hasMany(User::class, 'reporting_user');
    }
    public function agentReports()
    {
        return $this->hasMany(AgentHasReport::class, 'agent_id');
    }
    public function supportAgents()
    {
        return $this->hasMany(SupportAgent::class, 'reporting_user');
    }
    public function supportAgent()
    {
        return $this->hasOne(SupportAgent::class);
    }
    public function chatbots()
    {
        return $this->hasMany(Chatbot::class);
    }
    public function campaigns()
    {
        return $this->hasMany(Campaign::class);
    }
    public function reportingUser()
    {
        return $this->belongsTo(User::class, 'reporting_user');
    }
    public function userConfig()
    {
        return $this->hasOne(UserConfig::class);
    }
    public function defaultChatbot()
    {
        return $this->hasOne(DefaultChatbot::class);
    }
    public function balance()
    {
        return $this->hasMany(Balance::class);
    }
    public function media()
    {
        return $this->hasMany(Media::class);
    }
    public function ApiKey()
    {
        return $this->hasMany(ApiKey::class);
    }
    public function Presets()
    {
        return $this->hasMany(CrouselPreset::class);
    }
    public function pricingModel()
    {
        return $this->hasOne(PricingModel::class);
    }
    public function reports()
    {
        return $this->hasMany(Report::class, 'display_phone_number', 'whatsapp_number');
    }
    public function blockedNumbers()
    {
        return $this->hasMany(UserBlockNumber::class);
    }
    public function scheduleCampaign()
    {
        return $this->hasMany(ScheduleCampaign::class);
    }
    public function outReports()
    {
        return $this->hasMany(OutReport::class);
    }
    public function brandingConfiguration()
    {
        return $this->hasOne(BrandingConfiguration::class);
    }
    public function chatbotAuth()
    {
        return $this->hasMany(ChatbotAuth::class)->where('status', 'Active');
    }

    public function activePlan()
    {
        return $this->belongsTo(Plan::class, 'plan_id');
    }
}
