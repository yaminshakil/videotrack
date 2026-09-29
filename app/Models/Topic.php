<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Str;

class Topic extends Model
{
    /** How far back the "recently added" strip on the topic pages reaches. */
    public const RECENT_DAYS = 3;

    /** How many of them it shows before it says "and N more". */
    public const RECENT_LIMIT = 10;

    /**
     * How many topics the table shows before offering to show the rest.
     *
     * The list runs to several hundred rows and a Livewire action re-renders all of
     * it, so a one-line edit used to ship the whole table to be redrawn. A hundred
     * rows is a screenful or two of scrolling either side of "enough to work with".
     */
    public const PREVIEW_LIMIT = 100;

    protected $fillable = [
        'channel_id', 'title', 'category', 'link', 'assigned_to', 'added_by', 'added_by_label',
        'added_by_key',
        'sort_order', 'is_done', 'completed_at',
        'video_url', 'video_title', 'video_channel', 'video_published_at', 'completed_by', 'earned_amount',
    ];

    protected function casts(): array
    {
        return [
            'is_done' => 'boolean',
            'completed_at' => 'datetime',
            'earned_amount' => 'decimal:2',
            'video_published_at' => 'date',
        ];
    }

    public function channel(): BelongsTo
    {
        return $this->belongsTo(Channel::class);
    }

    public function employee(): BelongsTo
    {
        return $this->belongsTo(Employee::class, 'assigned_to');
    }

    public function completer(): BelongsTo
    {
        return $this->belongsTo(Employee::class, 'completed_by');
    }

    /** The employee who added this topic themselves, if any (null = admin/seed). */
    public function adder(): BelongsTo
    {
        return $this->belongsTo(Employee::class, 'added_by');
    }

    /**
     * Who added the topic, as a name captured at creation time. Covers every role,
     * unlike adder(), which is an employee foreign key and so null for staff.
     */
    public function addedByLabel(): ?string
    {
        return $this->added_by_label ?: $this->adder?->name;
    }

    /**
     * Whether the signed-in account identified by $key (and named $name) is the one
     * that added this topic — the test behind the Recently added strip's Edit and
     * Delete buttons.
     *
     * added_by_key decides it whenever both sides have one, because it is an identity
     * rather than a name. Rows written before that column existed only ever recorded
     * the name, so those fall back to comparing it: weaker (names are not unique), but
     * it is the same string the row already shows as "added by", and a topic nobody
     * can be shown to own stays closed to everyone rather than opening to all.
     */
    public function isAddedBy(?string $key, ?string $name): bool
    {
        if ($key !== null && $this->added_by_key !== null) {
            return $this->added_by_key === $key;
        }

        return $name !== null
            && $this->added_by_label !== null
            && Str::lower($this->added_by_label) === Str::lower($name);
    }

    /** Standard display ordering: channel, category, sort order. */
    public function scopeOrdered(Builder $q): Builder
    {
        return $q->join('channels', 'channels.id', '=', 'topics.channel_id')
            ->select('topics.*')
            ->orderBy('channels.sort_order')
            ->orderBy('topics.category')
            ->orderBy('topics.sort_order')
            ->orderBy('topics.id');
    }

    /** Only topics added within the last $days. */
    public function scopeRecent(Builder $q, ?int $days = null): Builder
    {
        return $q->where('created_at', '>=', now()->subDays($days ?? self::RECENT_DAYS));
    }

    /**
     * The "recently added" strip for the admin and manager topic pages, newest
     * first. $base is the caller's own pre-scoped query (channel scope, active
     * filter) so this never widens what they are allowed to see, and $total is
     * the full count behind the truncated list.
     *
     * @return array{items: Collection<int, static>, total: int}
     */
    public static function recentlyAdded(Builder $base, ?int $days = null, ?int $limit = null): array
    {
        $window = $base->clone()->recent($days);

        return [
            'items' => $window->clone()
                ->with(['channel', 'adder'])
                ->orderByDesc('topics.created_at')
                ->orderByDesc('topics.id')
                ->limit($limit ?? self::RECENT_LIMIT)
                ->get(),
            'total' => $window->clone()->count(),
        ];
    }

    /**
     * Mark done now. Snapshots who completed it and what it paid at this
     * moment (the assigned employee's rate for this channel).
     */
    public function markDone(): void
    {
        $rate = $this->assigned_to
            ? (float) Rate::where('channel_id', $this->channel_id)
                ->where('employee_id', $this->assigned_to)->value('amount')
            : 0.0;

        $this->forceFill([
            'is_done' => true,
            'completed_at' => now(),
            'completed_by' => $this->assigned_to,
            'earned_amount' => $rate,
        ])->save();
    }

    /** Undo completion and remove the earning that came with it. */
    public function markUndone(): void
    {
        $this->forceFill([
            'is_done' => false,
            'completed_at' => null,
            'completed_by' => null,
            'earned_amount' => 0,
        ])->save();
    }

    public function toggleDone(): void
    {
        $this->is_done ? $this->markUndone() : $this->markDone();
    }

    /** Deterministic category label for a topic title. */
    public static function categoryFor(string $title): string
    {
        $t = mb_strtolower($title);

        if (Str::contains($t, ['linux', 'ubuntu', 'debian', 'fedora', 'gpu', 'cuda', 'rocm',
            'ollama', 'comfyui', 'pytorch', 'tensorflow'])) {
            return 'Linux / GPU';
        }
        if (Str::contains($t, 'windows')) {
            return 'Windows';
        }
        if (Str::contains($t, 'mac')) {
            return 'macOS';
        }
        if (Str::contains($t, ['claude', 'gemini', 'gpt', 'astra', 'ai ', ' ai'])) {
            return 'AI Tools';
        }

        return 'Other';
    }
}
