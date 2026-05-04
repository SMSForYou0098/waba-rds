<?php

namespace App\Models\Report;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class ReportHasBadge extends Model
{
    use HasFactory;

    protected $fillable = [
        'report_id',
        'badge_id',
        'user_id'
    ];

    public function report()
    {
        return $this->belongsTo(Report::class, 'report_id', 'id');
    }

    public function badge()
    {
        return $this->belongsTo(Badge::class, 'badge_id', 'id');
    }

    public function user()
    {
        return $this->belongsTo(User::class, 'user_id', 'id');
    }
}
