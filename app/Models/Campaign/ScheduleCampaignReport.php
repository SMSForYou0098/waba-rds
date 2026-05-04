<?php

namespace App\Models\Campaign;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class ScheduleCampaignReport extends Model
{
    use HasFactory;
    public function outReports()
    {
        return $this->hasMany(OutReport::class, 'status_id', 'message_id');
    }

    public function ScheduleCampaign()
    {
        return $this->belongsTo(ScheduleCampaign::class, 'campaign_id', 'id');
    }
}
