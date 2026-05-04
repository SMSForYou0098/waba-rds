<?php

namespace App\Models\Report;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class AgentHasReport extends Model
{
    use HasFactory;    
    protected $fillable = [
        'agent_id',
        'display_phone_number',
        'wa_id',
    ];


    public function agent()
    {
        return $this->belongsTo(User::class);
    }
    
    public function supportAgent()
    {
        return $this->belongsTo(SupportAgent::class, 'agent_id');
    }

}
