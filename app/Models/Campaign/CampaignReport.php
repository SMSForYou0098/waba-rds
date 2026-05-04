<?php

namespace App\Models\Campaign;

use App\Models\Report\OutReport;
use App\Models\Settings\ErrorCode;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class CampaignReport extends Model
{
    use HasFactory;
	protected $fillable = [
        'status', 
    ];

    public function outReports()
    {
        return $this->hasMany(OutReport::class, 'status_id', 'message_id');
    }

    public function campaign()
    {
        return $this->belongsTo(Campaign::class, 'campaign_id', 'id');
    }
      public function errorCode()
    {
        return $this->belongsTo(ErrorCode::class, 'error_code', 'code'); // 'error_code' is the foreign key in CampaignReport
    }
}
