<?php

namespace App\Models\Cleanup;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class CleanUpRecord extends Model
{
    use HasFactory;
    protected $fillable = [
        'table_name',
        'action',
        'action_by',
        'user_id',
        'link',
        'file_name',
        'count',
        'before_date',
        'date_range',
    ];
    protected $casts = [
        'before_date' => 'datetime',
        'date_range' => 'datetime',
    ];
    public function actionBy()
    {
        return $this->belongsTo(User::class,'action_by', 'id');
    }
    public function user()
    {
        return $this->belongsTo(User::class);
    }
}
