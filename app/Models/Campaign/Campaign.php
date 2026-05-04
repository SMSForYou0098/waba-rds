<?php

namespace App\Models\Campaign;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Campaign extends Model
{
    use HasFactory;
    public function campaignReports()
    {
        return $this->hasMany(CampaignReport::class, 'campaign_id', 'id');
    }
}
