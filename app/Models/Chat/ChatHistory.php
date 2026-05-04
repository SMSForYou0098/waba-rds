<?php

namespace App\Models\Chat;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class ChatHistory extends Model
{
    use HasFactory;

    public function message()
    {
        return $this->belongsTo(User::class,'id', 'report_id');
    }
    public function supportAgent()
    {
        return $this->hasOne(User::class,'id', 'agent_id');
    }
  	public function supportAgentData()
    {
        return $this->hasOne(SupportAgent::class,'user_id', 'agent_id');
    }
     public function outReport()
    {
        return $this->hasOne(OutReport::class,'id', 'out_report_id');
    }
}
