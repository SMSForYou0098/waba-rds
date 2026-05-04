<?php

namespace App\Models\Chat;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class ChatbotMemory extends Model
{
    use HasFactory;
    protected $fillable = [
        'user_id',
        'mobile_number',
        'key',
        'value'
    ];
}
