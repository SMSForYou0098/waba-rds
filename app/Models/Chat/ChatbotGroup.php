<?php

namespace App\Models\Chat;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class ChatbotGroup extends Model
{
    use HasFactory;
    protected $fillable = [
        'name',
        'description',
    ];

    /**
     * Get the chatbots associated with the group.
     */
    public function chatbots()
    {
        return $this->hasMany(Chatbot::class, 'group_id');
    }
}
