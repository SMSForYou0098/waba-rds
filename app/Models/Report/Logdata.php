<?php

namespace App\Models\Report;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Logdata extends Model
{
    use HasFactory;
    protected $fillable = ['logs','display_phone_number','reprocessed_at','message_id','status','wa_id'];

    public function getLogsAttribute($value)
    {
        return unserialize($value);
    }
}
