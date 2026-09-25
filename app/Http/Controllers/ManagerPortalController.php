<?php

namespace App\Http\Controllers;

use App\Models\Channel;
use App\Models\Employee;
use App\Models\Manager;
use App\Models\Rate;
use App\Models\Topic;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\Rule;

/**
 * Manager portal: managers add topics to the channels assigned to them by the
 * admin, assign those topics to employees, and monitor what employees earned
 * in those channels. Everything is scoped to the manager's assigned channels.
 */
class ManagerPortalController extends Controller
{
    public function dashboard()
    {
        $manager = $this->manager();
        $channelIds = $this->channelIds();

        $topics = Topic::whereIn('channel_id', $channelIds)->get(['channel_id', 'assigned_to', 'is_done', 'earned_amount']);
        $byChannel = $topics->groupBy('channel_id');

        $channels = $this->managerChannels()->map(function (Channel $ch) use ($byChannel) {
            $items = $byChannel->get($ch->id, collect());
            $done = $items->where('is_done', true);
            $employees = $items->pluck('assigned_to')->filter()->unique()->count();

            return [
                'channel' => $ch,
                'total' => $items->count(),
                'done' => $done->count(),
                'pending' => $items->count() - $done->count(),
                'employees' => $employees,
                'earned' => (float) $done->sum('earned_amount'),
            ];
        });

        return view('manager.dashboard', [
            'manager' => $manager,
            'channels' => $channels,
            'total' => $topics->count(),
            'done' => $topics->where('is_done', true)->count(),
            'earned' => (float) $topics->where('is_done', true)->sum('earned_amount'),
        ]);
    }

    /** All topics in the manager's channels, filterable by one of their channels. */
    public function topics(Request $request)
    {
        $channelId = $request->query('channel');
        $channels = $this->managerChannels();

        $topics = Topic::ordered()->with('channel', 'employee')
            ->whereIn('topics.channel_id', $channels->pluck('id'))
            ->when($channelId, fn ($q) => $q->where('topics.channel_id', $channelId))
            ->get();

        return view('manager.topics', [
            'manager' => $this->manager(),
            'channels' => $channels,
            'topics' => $topics,
            'channelId' => $channelId,
            'employees' => Employee::where('is_active', true)->orderBy('name')->orderBy('id')->get(),
        ]);
    }

    /** A manager adding a topic, only into one of their own channels. */
    public function storeTopic(Request $request)
    {
        $ids = $this->channelIds();
        $data = $request->validate([
            'channel_id' => ['required', 'integer', Rule::in($ids)],
            'title' => ['required', 'string', 'max:255'],
            'category' => ['nullable', 'string', 'max:100'],
            'link' => ['nullable', 'url:http,https', 'max:500'],
        ], ['channel_id.required' => 'Channel and title are required.']);

        $data['category'] = trim($data['category'] ?? '') ?: 'Other';
        $data['link'] = $data['link'] ?? '';
        $data['sort_order'] = (int) Topic::where('channel_id', $data['channel_id'])->max('sort_order') + 10;

        Topic::create($data);

        return back()->with('ok', 'Topic added.');
    }

    public function updateTopic(Request $request, Topic $topic)
    {
        $this->authorizeTopic($topic);

        $data = $request->validate([
            'title' => ['required', 'string', 'max:255'],
            'category' => ['nullable', 'string', 'max:100'],
            'link' => ['nullable', 'url:http,https', 'max:500'],
        ]);

        $topic->fill([
            'title' => $data['title'],
            'category' => trim($data['category'] ?? '') ?: 'Other',
            'link' => $data['link'] ?? '',
        ])->save();

        return back()->with('ok', 'Topic updated.');
    }

    public function destroyTopic(Topic $topic)
    {
        $this->authorizeTopic($topic);

        $topic->delete();

        return back()->with('ok', 'Topic deleted.');
    }

    /** Assign (or unassign) an employee to a topic in one of the manager's channels. */
    public function assign(Request $request, Topic $topic)
    {
        $this->authorizeTopic($topic);

        if ($topic->is_done) {
            return back()->withErrors(['assign' => 'Completed topics keep their assignee (their earnings are already recorded). Reopen the topic first to reassign it.']);
        }

        $employeeId = (int) $request->input('employee_id', 0);

        $topic->assigned_to = $employeeId > 0 && Employee::whereKey($employeeId)->exists() ? $employeeId : null;
        $topic->save();

        return back()->with('ok', 'Assignment updated.');
    }

    /** Per-channel breakdown of what each employee earned, for one chosen month. */
    public function earnings(Request $request)
    {
        $request->validate(['month' => ['nullable', 'date_format:Y-m']]);

        $month = (string) $request->input('month', now()->format('Y-m'));
        $start = Carbon::createFromFormat('!Y-m', $month)->startOfMonth();
        $end = $start->copy()->endOfMonth();

        $channels = $this->managerChannels();
        $employees = Employee::orderBy('name')->orderBy('id')->get();

        $rates = Rate::whereIn('channel_id', $channels->pluck('id'))->get();
        $completed = Topic::whereIn('channel_id', $channels->pluck('id'))
            ->where('is_done', true)->whereNotNull('completed_by')->whereNotNull('completed_at')
            ->get(['channel_id', 'completed_by', 'completed_at', 'earned_amount']);

        $rows = $channels->map(function (Channel $ch) use ($employees, $rates, $completed, $start, $end) {
            $channelDone = $completed->where('channel_id', $ch->id);

            $staff = $employees->map(function (Employee $e) use ($ch, $rates, $channelDone, $start, $end) {
                $rate = (float) ($rates->first(fn ($r) => $r->employee_id === $e->id && $r->channel_id === $ch->id)?->amount ?? 0);
                $earned = $channelDone->where('completed_by', $e->id);

                return [
                    'employee' => $e,
                    'rate' => $rate,
                    'done' => $earned->count(),
                    'earned' => (float) $earned->sum('earned_amount'),
                    'done_month' => $earned->filter(fn ($t) => $t->completed_at->between($start, $end))->count(),
                    'earned_month' => (float) $earned->filter(fn ($t) => $t->completed_at->between($start, $end))->sum('earned_amount'),
                ];
            })->reject(fn ($s) => $s['done'] === 0 && $s['rate'] == 0)->values();

            $inMonth = $channelDone->filter(fn ($t) => $t->completed_at->between($start, $end));

            return [
                'channel' => $ch,
                'staff' => $staff,
                'done' => $channelDone->count(),
                'earned' => (float) $channelDone->sum('earned_amount'),
                'done_month' => $inMonth->count(),
                'earned_month' => (float) $inMonth->sum('earned_amount'),
            ];
        });

        $monthDone = $completed->filter(fn ($t) => $t->completed_at->between($start, $end));
        $monthEarned = (float) $monthDone->sum('earned_amount');

        return view('manager.earnings', [
            'manager' => $this->manager(),
            'month' => $month,
            'start' => $start,
            'end' => $end,
            'prevMonth' => $start->copy()->subMonthNoOverflow()->format('Y-m'),
            'nextMonth' => $start->copy()->addMonthNoOverflow()->format('Y-m'),
            'isCurrent' => $month >= now()->format('Y-m'),
            'rows' => $rows,
            'earned' => (float) $completed->sum('earned_amount'),
            'done' => $completed->count(),
            'employees' => $employees->filter(fn ($e) => $completed->where('completed_by', $e->id)->count() > 0 || $rates->where('employee_id', $e->id)->count() > 0)->count(),
            'monthDone' => $monthDone->count(),
            'monthEarned' => $monthEarned,
        ]);
    }

    private function manager(): Manager
    {
        return Auth::guard('manager')->user();
    }

    private function channelIds(): Collection
    {
        return $this->manager()->channels()->pluck('channels.id');
    }

    private function managerChannels(): Collection
    {
        return Channel::whereIn('id', $this->channelIds())->orderBy('sort_order')->orderBy('id')->get();
    }

    private function authorizeTopic(Topic $topic): void
    {
        abort_unless($this->channelIds()->contains($topic->channel_id), 403);
    }
}
