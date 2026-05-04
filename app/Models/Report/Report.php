<?php

namespace App\Models\Report;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Report extends Model
{
    use HasFactory;

    public function user()
    {
        return $this->belongsTo(User::class, 'display_phone_number', 'whatsapp_number');
    }
    public function chats()
    {
        return $this->hasMany(ChatHistory::class,'report_id', 'id');
    }
    public function activeAgent()
    {
        return $this->hasOne(AgentHasReport::class, 'wa_id', 'wa_id');
    }
    public function reportHasBadges()
    {
        return $this->hasMany(ReportHasBadge::class, 'report_id', 'wa_id');
    }

    public function badges()
    {
        return $this->belongsToMany(Badge::class, 'report_has_badges', 'report_id', 'badge_id');
    }
}
