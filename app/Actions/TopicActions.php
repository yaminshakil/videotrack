<?php

namespace App\Actions;

use App\Models\Channel;
use App\Models\Employee;
use App\Models\Topic;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\Rule;
use Symfony\Component\HttpKernel\Exception\HttpException;

/**
 * Creating and editing the topic rows that the admin, the managers and the
 * employees all share.
 *
 * The three portals differ only in who is allowed to touch which topic, so the
 * rules live here once and each caller passes its own constraints in.
 */
class TopicActions
{
    /**
     * A topic a manager or admin adds, assigned straight to an employee.
     *
     * added_by stays null on purpose: it is the employee key that decides whether
     * a topic belongs on the employee's "My Topics" list or their "Custom Topics"
     * one, so staff-added topics must not carry it. added_by_label is what records
     * that a manager or the admin added it, and added_by_key is the comparable
     * half of that, so only they can edit or delete it from the recent strip.
     */
    public function createAssigned(array $data, ?int $employeeId, ?string $addedBy): Topic
    {
        return Topic::create([
            'channel_id' => $data['channel_id'],
            'title' => $data['title'],
            'category' => trim($data['category'] ?? '') ?: 'Other',
            'link' => $data['link'] ?? '',
            'assigned_to' => $employeeId,
            'added_by' => null,
            'added_by_label' => $addedBy,
            'added_by_key' => $addedBy === null ? null : $this->currentAdderKey(),
            'sort_order' => (int) Topic::where('channel_id', $data['channel_id'])->max('sort_order') + 10,
        ]);
    }

    public function update(Topic $topic, array $data): Topic
    {
        $topic->fill([
            'title' => $data['title'],
            'category' => trim($data['category'] ?? '') ?: 'Other',
            'link' => $data['link'] ?? '',
        ])->save();

        return $topic;
    }

    public function delete(Topic $topic): void
    {
        $topic->delete();
    }

    /**
     * Assign, or unassign with 0. A completed topic keeps its assignee because
     * its earning is already recorded against that employee.
     *
     * @throws HttpException 422 when already done
     */
    public function assign(Topic $topic, int $employeeId): Topic
    {
        abort_if($topic->is_done, 422, 'Completed topics keep their assignee (their earnings are already recorded). Reopen the topic first to reassign it.');

        $topic->assigned_to = $employeeId > 0 && Employee::whereKey($employeeId)->where('is_active', true)->exists()
            ? $employeeId
            : null;
        $topic->save();

        return $topic;
    }

    public function toggle(Topic $topic): Topic
    {
        $topic->toggleDone();

        return $topic;
    }

    /**
     * Shared validation for the add-topic form, in all three portals.
     *
     * Pass the caller's own channel ids to restrict where a topic may be created.
     * The restriction lives here rather than being merged in by the caller so it
     * cannot be silently dropped: PHP's `+` keeps the left operand's value for a
     * duplicate key, so `$rules + ['channel_id' => ...]` would do nothing at all.
     */
    public function createRules(?array $allowedChannelIds = null): array
    {
        return [
            'channel_id' => $allowedChannelIds === null
                ? ['required', 'exists:channels,id']
                : ['required', 'integer', Rule::in($allowedChannelIds)],
            'title' => ['required', 'string', 'max:255'],
            'category' => ['nullable', 'string', 'max:100'],
            'link' => ['nullable', 'url:http,https', 'max:500'],
        ];
    }

    public function createMessages(): array
    {
        return [
            'channel_id.required' => 'Channel and title are required.',
            'title.required' => 'Give the topic a title.',
        ];
    }

    /**
     * Shared validation for an edit, in every portal and in both of the two editors
     * a topic page can have open at once.
     *
     * $prefix is the property the draft hangs under — 'draft' for the row editor in
     * the table, 'recentDraft' for the one in the Recently added strip. A component
     * validates its own state by path, so an unprefixed `title` would test the
     * add-topic form's title box, which is empty, and fail on the wrong field.
     */
    public function updateRules(string $prefix = ''): array
    {
        $key = fn (string $field) => $prefix === '' ? $field : $prefix.'.'.$field;

        return [
            $key('title') => ['required', 'string', 'max:255'],
            $key('category') => ['nullable', 'string', 'max:100'],
            $key('link') => ['nullable', 'url:http,https', 'max:500'],
        ];
    }

    public function updateMessages(string $prefix = ''): array
    {
        return [
            ($prefix === '' ? 'title' : $prefix.'.title').'.required' => 'Give the topic a title.',
        ];
    }

    /**
     * How many topics each of these channels holds, in one query.
     *
     * The channel chooser shows this beside every channel so an empty one is visible
     * before it is picked, rather than the list simply coming back blank afterwards.
     * Channels with no topics are absent from the map, which reads as zero in the view.
     *
     * @param  array<int, int>  $channelIds
     * @return array<int, int>
     */
    public function countsByChannel(array $channelIds): array
    {
        if ($channelIds === []) {
            return [];
        }

        return Topic::query()
            ->whereIn('topics.channel_id', $channelIds)
            ->selectRaw('topics.channel_id, count(*) as total')
            ->groupBy('topics.channel_id')
            ->pluck('total', 'topics.channel_id')
            ->map(fn ($total) => (int) $total)
            ->all();
    }

    /** The employee dropdown on the add-topic form, and on the assignee filter. */
    public function activeEmployees()
    {
        return Employee::where('is_active', true)->orderBy('name')->orderBy('id')->get();
    }

    /**
     * The Show Topic page's status / assignee / added-since filters, applied on top
     * of the caller's own scope (channel, manager's channels). Each is independent
     * and only narrows the query when the viewer actually picked something, so the
     * default of all three is the same unfiltered list as before they existed.
     *
     * $assignee is '' for everyone, '0' for unassigned, or an employee id as a
     * string — it comes straight off a <select>, which only ever gives strings.
     */
    public function filtered(Builder $scope, string $status, string $assignee, string $added): Builder
    {
        return $scope
            ->when($status === 'done', fn ($q) => $q->where('topics.is_done', true))
            ->when($status === 'pending', fn ($q) => $q->where('topics.is_done', false))
            ->when($assignee === '0', fn ($q) => $q->whereNull('topics.assigned_to'))
            ->when($assignee !== '' && $assignee !== '0', fn ($q) => $q->where('topics.assigned_to', (int) $assignee))
            ->when($added !== '', fn ($q) => $q->where('topics.created_at', '>=', $this->addedSince($added)));
    }

    /** What "added" filter value maps to, in wall-clock time from right now. */
    private function addedSince(string $added): \Illuminate\Support\Carbon
    {
        return match ($added) {
            'today' => now()->startOfDay(),
            '7' => now()->subDays(7),
            '30' => now()->subDays(30),
            default => now()->subDays(30),
        };
    }

    /**
     * Topics grouped by channel for the table, newest first within each group. Both
     * portals show the same table, so they get the same grouping.
     *
     * $topics only has to be newest-first overall for this to come out newest-first
     * per channel too — groupBy keeps each bucket in the order it found them in — so
     * the groups themselves are resorted by the channel's own sort_order afterwards,
     * independently of which channel happened to hold the single newest topic.
     *
     * @param  Collection<int, Topic>  $topics
     * @return array<int, array{channel: Channel, topics: Collection<int, Topic>}>
     */
    public function groupedByChannel(Collection $topics): array
    {
        return $topics
            ->groupBy('channel_id')
            ->map(fn ($rows, $channelId) => [
                'channel' => $rows->first()->channel,
                'topics' => $rows->values(),
            ])
            ->sortBy(fn ($group) => $group['channel']->sort_order)
            ->values()
            ->all();
    }

    /**
     * The topics the table shows, newest first, which is the newest PREVIEW_LIMIT of
     * them *in each channel* until the viewer asks for the rest.
     *
     * Every action on the page re-renders the table and ships it to the browser, so
     * its size is the cost of every save.
     *
     * The cut is per channel rather than one pool for the whole table because the
     * table is read as one section per channel, each with its own heading and its own
     * count. A single global pool spends the whole budget on whichever channels
     * happened to get the newest topics: with four channels it left How To Windows
     * showing 5 rows of its 193 and World of Linux 20 of its 212, so most of their
     * completed topics were simply absent while the heading still claimed that was
     * all the channel had. Per channel, every channel is truncated the same way, and
     * a channel only loses rows once it has more than PREVIEW_LIMIT of its own.
     *
     * $scope is the caller's own pre-scoped, un-ordered query — a manager's channels
     * and channel filter live in there — and is never widened here.
     *
     * @return Collection<int, Topic>
     */
    public function tableTopics(Builder $scope, bool $showAll, ?int $limit = null): Collection
    {
        if ($showAll) {
            return $this->newestFirst($scope->clone())->get();
        }

        $limit ??= Topic::PREVIEW_LIMIT;

        $newest = collect();
        foreach ($this->channelIdsIn($scope) as $channelId) {
            $newest = $newest->merge(
                $this->newestFirst($scope->clone()->where('topics.channel_id', $channelId))
                    ->limit($limit)
                    ->pluck('topics.id')
            );
        }

        return $this->newestFirst($scope->clone()->whereIn('topics.id', $newest))->get();
    }

    /**
     * The channel ids the scope can return, taken from the scope rather than from
     * every channel in the database so a manager's channels, and the channel filter,
     * bound the list the same way they bound the table. Only the set matters: the
     * result is ordered newest-first and grouped by the caller.
     *
     * @return Collection<int, int>
     */
    private function channelIdsIn(Builder $scope): Collection
    {
        return $scope->clone()->reorder()->distinct()->pluck('topics.channel_id');
    }

    private function newestFirst(Builder $q): Builder
    {
        return $q->orderByDesc('topics.created_at')->orderByDesc('topics.id');
    }

    /**
     * How many topics the table could show in total, so the footer can say what it is
     * holding back. Skipped entirely once the list is already showing everything,
     * where the row count is the total.
     */
    public function tableTotal(Builder $scope): int
    {
        return $scope->clone()->count();
    }

    /**
     * How many topics each channel holds within the scope, keyed by channel id, so a
     * section that is cut to the newest PREVIEW_LIMIT can say "newest 5 of 193" rather
     * than a bare "5 topics" that reads as the whole channel.
     *
     * @return array<int, int>
     */
    public function channelTotals(Builder $scope): array
    {
        return $scope->clone()
            ->reorder()
            ->selectRaw('topics.channel_id, count(*) as aggregate')
            ->groupBy('topics.channel_id')
            ->pluck('aggregate', 'topics.channel_id')
            ->map(fn ($count) => (int) $count)
            ->all();
    }

    public function channels()
    {
        return Channel::orderBy('sort_order')->orderBy('id')->get();
    }

    /** The name of whoever is adding the topic right now, for added_by_label. */
    public function currentAdderLabel(): ?string
    {
        return Auth::guard('admin')->user()?->name
            ?? Auth::guard('manager')->user()?->name
            ?? Auth::guard('employee')->user()?->name;
    }

    /**
     * The same person's identity, for added_by_key: "admin:1", "manager:4",
     * "employee:9". One string for all three role tables, which a single foreign
     * key column cannot be, and null when nobody is signed in.
     */
    public function currentAdderKey(): ?string
    {
        foreach (['admin', 'manager', 'employee'] as $guard) {
            if (($id = Auth::guard($guard)->id()) !== null) {
                return $guard.':'.$id;
            }
        }

        return null;
    }

    /**
     * Whether whoever is signed in now added this topic, which is what decides
     * whether the Recently added strip offers them Edit and Delete on it.
     */
    public function ownsRecent(Topic $topic): bool
    {
        return $topic->isAddedBy($this->currentAdderKey(), $this->currentAdderLabel());
    }

    /** Rule so a topic's employee_id must be a real, active employee. */
    public function employeeRule(): Rule
    {
        return Rule::exists('employees', 'id');
    }
}
