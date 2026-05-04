<?php

namespace App\Models\Report;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Badge extends Model
{
    use HasFactory;
    
    protected $fillable = [
        'title',
        'user_id'
    ];
    
    public function reportHasBadges()
    {
        return $this->hasMany(ReportHasBadge::class, 'badge_id', 'id');
    }
    
    public function reports()
    {
        return $this->belongsToMany(Report::class, 'report_has_badges', 'badge_id', 'report_id');
    }
    
    public function user()
    {
        return $this->belongsTo(User::class, 'user_id', 'id');
    }
}
