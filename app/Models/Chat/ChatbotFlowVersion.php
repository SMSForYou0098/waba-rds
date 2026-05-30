<?php

namespace App\Models\Chat;

use App\Enums\Chat\FlowStatus;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Validation\ValidationException;

class ChatbotFlowVersion extends Model
{
    use HasFactory;
    use SoftDeletes;

    protected $fillable = [
        'user_id',
        'group_id',
        'name',
        'slug',
        'version',
        'status',
        'definition',
        'is_active',
        'published_at',
        'published_by',
        'legacy_group_id',
    ];

    protected $casts = [
        'definition' => 'array',
        'is_active' => 'boolean',
        'published_at' => 'datetime',
        'status' => FlowStatus::class,
        'version' => 'integer',
    ];

    protected static function booted(): void
    {
        static::deleting(function (ChatbotFlowVersion $flow): void {
            if ($flow->is_active && $flow->status === FlowStatus::Published) {
                throw ValidationException::withMessages([
                    'flow' => ['Cannot delete an active published flow. Unpublish it first.'],
                ]);
            }
        });
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function group(): BelongsTo
    {
        return $this->belongsTo(ChatbotGroup::class, 'group_id');
    }

    public function publisher(): BelongsTo
    {
        return $this->belongsTo(User::class, 'published_by');
    }

    public function conversationSessions(): HasMany
    {
        return $this->hasMany(ConversationSession::class, 'flow_version_id');
    }

    public function scopeForUser(Builder $query, int $userId): Builder
    {
        return $query->where('user_id', $userId);
    }

    public function scopeActive(Builder $query): Builder
    {
        return $query->where('is_active', true);
    }

    public function scopePublished(Builder $query): Builder
    {
        return $query->where('status', FlowStatus::Published);
    }

    public function scopeDraft(Builder $query): Builder
    {
        return $query->where('status', FlowStatus::Draft);
    }

    public function scopeInGroup(Builder $query, int $groupId): Builder
    {
        return $query->where('group_id', $groupId);
    }

    public function isDraft(): bool
    {
        return $this->status === FlowStatus::Draft;
    }
}
