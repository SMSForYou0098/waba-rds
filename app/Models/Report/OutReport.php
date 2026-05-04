<?php

namespace App\Models\Report;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class OutReport extends Model
{
    use HasFactory;
  
    public function campaignReport()
    {
        return $this->belongsTo(CampaignReport::class, 'message_id', 'status_id');
    }
    public function balance()
    {
        return $this->hasMany(Balance::class, 'report_id', 'id');
    }
  	public function ApiReport()
    {
        return $this->hasOne(ApiTemplateReport::class, 'report_id', 'id');
    }
    public function user()
    {
        return $this->belongsTo(User::class);
    }
    // public function user()
    // {
    //     return $this->belongsTo(User::class, 'display_phone_number', 'whatsapp_number');
    // }
}
