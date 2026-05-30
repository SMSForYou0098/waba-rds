<?php

namespace App\Models\Chat;

use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ConversationSession extends Model
{
    use HasFactory;

    protected $fillable = [
        'user_id',
        'wa_id',
        'display_phone_number',
        'flow_version_id',
        'current_node_id',
        'awaiting_input',
        'vars',
        'meta',
        'expires_at',
    ];

    protected $casts = [
        'vars' => 'array',
        'meta' => 'array',
        'awaiting_input' => 'boolean',
        'expires_at' => 'datetime',
    ];

    public function flowVersion(): BelongsTo
    {
        return $this->belongsTo(ChatbotFlowVersion::class, 'flow_version_id');
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function scopeForFlowVersion(Builder $query, int $flowVersionId): Builder
    {
        return $query->where('flow_version_id', $flowVersionId);
    }

    public function scopeForCustomer(Builder $query, int $userId, string $waId): Builder
    {
        return $query->where('user_id', $userId)->where('wa_id', $waId);
    }

    public function scopeExpired(Builder $query): Builder
    {
        return $query->whereNotNull('expires_at')->where('expires_at', '<', now());
    }
}
