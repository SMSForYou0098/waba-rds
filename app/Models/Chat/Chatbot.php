<?php

namespace App\Models\Chat;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Chatbot extends Model
{
    use HasFactory;
    protected $fillable = [
        'user_id',
        'created_at',
        'group_id',
        'sr_no',
        // Add other attributes here as needed
    ];
  
	public function group()
    {
        return $this->belongsTo(ChatbotGroup::class, 'group_id');
    }
    public function user()
    {
        return $this->belongsTo(User::class);
    }
    public function chatbotAuth()
    {
        return $this->belongsTo(ChatbotAuth::class, 'id', 'chatbot_id');
    }
    // Define parent relationship (self-relation)
    public function parent()
    {
        return $this->belongsTo(Chatbot::class, 'parent_id');
    }

    // Define children relationship (self-relation)
    public function children()
    {
        // return $this->hasMany(Chatbot::class, 'parent_id')->with('children'); // Load children recursively
        return $this->hasMany(Chatbot::class, 'parent_id')
            ->select(['id', 'keyword', 'parent_id']) // Select only specific fields
            ->with('children');
    }
}
