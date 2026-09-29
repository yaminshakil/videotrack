<?php

namespace App\Http\Controllers;

use App\Actions\TopicActions;
use App\Models\Channel;
use App\Models\Employee;
use App\Models\Manager;
use App\Models\Topic;
use App\Support\ChannelStats;
use App\Support\Earnings;
use App\Support\EarningsReport;
use App\Support\Month;
use App\Support\RateMatrix;
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
        $channels = $this->managerChannels();

        $topics = Topic::whereIn('channel_id', $this->channelIds())->get(['channel_id', 'assigned_to', 'is_done', 'earned_amount']);

        return view('manager.dashboard', [
            'manager' => $manager,
            'channels' => $channels,
            'channelStats' => ChannelStats::forChannels($channels),
            'total' => $topics->count(),
            'done' => $topics->where('is_done', true)->count(),
            'earned' => (float) $topics->where('is_done', true)->sum('earned_amount'),
            'employees' => Employee::where('is_active', true)->orderBy('name')->orderBy('id')->get(),
            'rates' => RateMatrix::forChannels($channels),
        ]);
    }

    /**
     * A manager adding a topic, only into one of their own channels. Plain-form
     * fallback (the manager Topics page itself now posts through the Livewire
     * component's own store()); kept in sync with TopicActions::createAssigned()
     * rather than building the row by hand, so it cannot drift out of step on
     * fields like added_by_key.
     */
    public function storeTopic(Request $request, TopicActions $actions)
    {
        $ids = $this->channelIds();
        $data = $request->validate([
            'channel_id' => ['required', 'integer', Rule::in($ids)],
            'title' => ['required', 'string', 'max:255'],
            'category' => ['nullable', 'string', 'max:100'],
            'link' => ['nullable', 'url:http,https', 'max:500'],
            'employee_id' => ['nullable', 'integer', 'exists:employees,id'],
        ], ['channel_id.required' => 'Channel and title are required.']);

        $actions->createAssigned($data, $data['employee_id'] ?? null, $this->manager()->name);

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

        $topic->assigned_to = $employeeId > 0 && Employee::whereKey($employeeId)->where('is_active', true)->exists()
            ? $employeeId
            : null;
        $topic->save();

        return back()->with('ok', 'Assignment updated.');
    }

    /** Per-channel breakdown of what each employee earned, for one chosen month. */
    public function earnings(Request $request)
    {
        $request->validate(['month' => ['nullable', 'date_format:Y-m']]);

        $month = Month::resolve($request->input('month'));
        $channels = $this->managerChannels();

        $report = EarningsReport::build($channels, $month);

        return view('manager.earnings', [
            'manager' => $this->manager(),
            'month' => $month,
            'start' => $report['start'],
            'end' => $report['end'],
            'prevMonth' => $report['prevMonth'],
            'nextMonth' => $report['nextMonth'],
            'isCurrent' => $month >= now()->format('Y-m'),
            'rows' => $report['rows'],
            'managerChannels' => $channels,
            'channelStats' => ChannelStats::forChannels($channels),
            'rates' => RateMatrix::forChannels($channels),
            'earned' => $report['allEarned'],
            'done' => $report['allDone'],
            'monthDone' => $report['periodDone'],
            'monthEarned' => $report['periodEarned'],
        ]);
    }

    /**
     * One employee's earnings, by day/week/month/year, scoped to the manager's own
     * channels only — the same employee-picker lists every active employee (as it
     * does for assigning topics), but what they earned in another manager's
     * channel is not this manager's to see.
     */
    public function employeeEarnings(Employee $employee)
    {
        $ledger = Topic::where('completed_by', $employee->id)->where('is_done', true)
            ->whereNotNull('completed_at')
            ->whereIn('channel_id', $this->channelIds())
            ->get(['id', 'completed_at', 'earned_amount']);

        return view('manager.employee-earnings', [
            'manager' => $this->manager(),
            'employee' => $employee,
            'summary' => Earnings::summary($ledger),
            'history' => Earnings::history($ledger),
        ]);
    }

    /**
     * Pay rates for the manager's own channels.
     *
     * RateMatrix::save() rejects any channel id outside the manager's set, so a
     * crafted payload cannot rewrite another channel's payroll.
     */
    public function saveRates(Request $request)
    {
        $request->validate(['channels' => ['array'], 'channels.*.*' => ['nullable', 'numeric', 'min:0']]);

        RateMatrix::save((array) $request->input('channels', []), $this->managerChannels());

        return back()->with('ok', 'Pay rates saved.');
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
