<?php

namespace App\Models\Campaign;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class ScheduleCampaign extends Model
{
    use HasFactory;
    public function user()
    {
        return $this->belongsTo(User::class);
    }
    public function scheduleCampaignReports()
    {
        return $this->hasMany(ScheduleCampaignReport::class, 'campaign_id', 'id');
    }
}
