<?php

namespace App\Models\Chat;

use App\Models\Report\AgentHasReport;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Laravel\Passport\HasApiTokens;
use Spatie\Permission\Traits\HasRoles;

class SupportAgent extends Authenticatable
{
    use HasApiTokens, HasFactory,HasRoles,SoftDeletes;

    public function user()
    {
        return $this->belongsTo(User::class);
    }
    public function reportingUser()
    {
        return $this->belongsTo(User::class, 'reporting_user', 'id');
    }
  	public function agentReports()
    {
        return $this->hasMany(AgentHasReport::class, 'agent_id', 'user_id');
    }


}
