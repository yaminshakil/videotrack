<?php

namespace App\Http\Controllers;

use App\Models\Channel;
use App\Models\Rate;
use App\Models\Topic;
use App\Services\YouTube;
use App\Support\Earnings;
use Carbon\Carbon;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

class EmployeePortalController extends Controller
{
    public function dashboard(Request $request)
    {
        $employee = Auth::guard('employee')->user();

        // Everything this employee has completed (the earnings ledger).
        $ledger = Topic::where('completed_by', $employee->id)->where('is_done', true)
            ->whereNotNull('completed_at')->get(['id', 'completed_at', 'earned_amount']);

        return view('employee.dashboard', [
            'employee'  => $employee,
            'summary'   => Earnings::summary($ledger),
            'history'   => Earnings::history($ledger),
            'monthPick' => $this->monthPick($employee->id, (string) $request->query('month', '')),
        ]);
    }

    /** The per-channel checklist for admin-assigned topics: add a video link, mark a topic done. */
    public function topics(Request $request)
    {
        $employee = Auth::guard('employee')->user();
        $channelId = $request->query('channel');

        return view('employee.topics', [
            'employee'  => $employee,
            'groups'    => $this->channelGroups($employee->id, $channelId, addedOnly: false),
            'channelId' => $channelId,
        ]);
    }

    /** The per-channel list of topics this employee added themselves. */
    public function customTopics(Request $request)
    {
        $employee = Auth::guard('employee')->user();
        $channelId = $request->query('channel');

        return view('employee.custom-topics', [
            'employee'  => $employee,
            'groups'    => $this->channelGroups($employee->id, $channelId, addedOnly: true),
            'channelId' => $channelId,
        ]);
    }

    /** Group one employee's topics by channel, either the admin-assigned set or just the ones they added. */
    private function channelGroups(int $employeeId, ?string $channelId, bool $addedOnly)
    {
        $channels = Channel::orderBy('sort_order')
            ->when($channelId, fn ($q) => $q->where('id', $channelId))
            ->get();

        $topics = Topic::ordered()->with('channel')
            ->where('topics.assigned_to', $employeeId)
            ->when($addedOnly, fn ($q) => $q->where('topics.added_by', $employeeId))
            ->when(! $addedOnly, fn ($q) => $q->whereNull('topics.added_by'))
            ->when($channelId, fn ($q) => $q->where('topics.channel_id', $channelId))
            ->get();

        $rates = Rate::where('employee_id', $employeeId)->pluck('amount', 'channel_id');
        $byChannel = $topics->groupBy('channel_id');

        return $channels->mapWithKeys(function ($channel) use ($byChannel, $rates) {
            $items = $byChannel->get($channel->id, collect());
            $done  = $items->where('is_done', true);

            return [$channel->id => [
                'channel' => $channel,
                'rate'    => (float) ($rates[$channel->id] ?? 0),
                'items'   => $items,
                'done'    => $done->count(),
                'total'   => $items->count(),
                'earn'    => (float) $done->sum('earned_amount'),
            ]];
        });
    }

    /** An employee adding their own topic to a channel, tracked separately from admin-assigned ones. */
    public function storeTopic(Request $request)
    {
        $employee = Auth::guard('employee')->user();

        $data = $request->validate([
            'channel_id' => ['required', 'exists:channels,id'],
            'title'      => ['required', 'string', 'max:255'],
            'link'       => ['nullable', 'url:http,https', 'max:500'],
        ], ['channel_id.required' => 'Choose a channel first.', 'title.required' => 'Give the topic a title.']);

        $sortOrder = (int) Topic::where('channel_id', $data['channel_id'])->max('sort_order') + 10;

        Topic::create([
            'channel_id'  => $data['channel_id'],
            'title'       => $data['title'],
            'category'    => Topic::categoryFor($data['title']),
            'link'        => $data['link'] ?? '',
            'assigned_to' => $employee->id,
            'added_by'    => $employee->id,
            'added_by_label' => $employee->name,
            'added_by_key' => 'employee:'.$employee->id,
            'sort_order'  => $sortOrder,
        ]);

        return redirect()->to(route('employee.custom-topics', ['channel' => $data['channel_id']]))
            ->with('ok', 'Topic added.');
    }

    /** Editing a topic the employee added themselves. */
    public function updateTopic(Request $request, Topic $topic)
    {
        $this->authorizeOwnAdded($topic);

        if ($topic->is_done) {
            return $this->back($topic, error: 'Completed topics can\'t be edited.');
        }

        $data = $request->validate([
            'title' => ['required', 'string', 'max:255'],
            'link'  => ['nullable', 'url:http,https', 'max:500'],
        ], ['title.required' => 'Give the topic a title.']);

        $topic->update(['title' => $data['title'], 'link' => $data['link'] ?? '']);

        return $this->back($topic, ok: 'Topic updated.');
    }

    /** Deleting a topic the employee added themselves. */
    public function destroyTopic(Topic $topic)
    {
        $this->authorizeOwnAdded($topic);

        $channelId = $topic->channel_id;

        if ($topic->is_done) {
            return redirect()->to(route('employee.custom-topics', ['channel' => $channelId]))
                ->withErrors(['video' => 'Completed topics can\'t be deleted.']);
        }

        $topic->delete();

        return redirect()->to(route('employee.custom-topics', ['channel' => $channelId]))
            ->with('ok', 'Topic deleted.');
    }

    /** Videos uploaded and money earned for one chosen calendar month. */
    private function monthPick(int $employeeId, string $requested): array
    {
        $month = preg_match('/^\d{4}-(0[1-9]|1[0-2])$/', $requested)
            ? Carbon::createFromFormat('Y-m', $requested)
            : now();

        $start = $month->copy()->startOfMonth();
        $end   = $month->copy()->endOfMonth();

        $videosUploaded = Topic::where('assigned_to', $employeeId)
            ->whereBetween('video_published_at', [$start->toDateString(), $end->toDateString()])
            ->count();

        $earned = Topic::where('completed_by', $employeeId)->where('is_done', true)
            ->whereBetween('completed_at', [$start, $end])
            ->selectRaw('COUNT(*) as cnt, COALESCE(SUM(earned_amount), 0) as amt')
            ->first();

        return [
            'value'           => $start->format('Y-m'),
            'label'           => $start->format('F Y'),
            'videos_uploaded' => $videosUploaded,
            'topics_done'     => (int) $earned->cnt,
            'earned'          => (float) $earned->amt,
        ];
    }

    /** Live preview used while typing a link: is it valid, and what is its title? */
    public function preview(Request $request, YouTube $youtube): JsonResponse
    {
        $result = $youtube->lookup((string) $request->input('url', ''));

        return response()->json($result);
    }

    /** Save the YouTube video an employee made for one of their topics. */
    public function saveVideo(Request $request, Topic $topic, YouTube $youtube)
    {
        $this->authorizeOwn($topic);

        if ($topic->is_done) {
            return $this->back($topic, error: 'This topic is already completed, so its video link is locked.');
        }

        $request->validate(['video_url' => ['required', 'string', 'max:500']],
            ['video_url.required' => 'Paste your YouTube video link first.']);

        $result = $youtube->lookup($request->input('video_url'));
        if (! $result['ok']) {
            return $this->back($topic, error: $result['error']);
        }

        $taken = Topic::where('video_url', $result['url'])->where('id', '!=', $topic->id)->exists();
        if ($taken) {
            return $this->back($topic, error: 'That video is already used for another topic. Each topic needs its own video.');
        }

        try {
            $topic->update([
                'video_url'          => $result['url'],
                'video_title'        => $result['title'],
                'video_channel'      => $result['channel'] ?? null,
                'video_published_at' => $result['publishedAt'] ?? null,
            ]);
        } catch (UniqueConstraintViolationException) {
            return $this->back($topic, error: 'That video is already used for another topic. Each topic needs its own video.');
        }

        return $this->back($topic, ok: 'Video link saved. You can now tick the topic as done.');
    }

    /** Remove a saved video link so a fresh one can be added. */
    public function removeVideo(Topic $topic)
    {
        $this->authorizeOwn($topic);

        if ($topic->is_done) {
            return $this->back($topic, error: 'This topic is already completed, so its video link is locked.');
        }

        $topic->update([
            'video_url'          => null,
            'video_title'        => null,
            'video_channel'      => null,
            'video_published_at' => null,
        ]);

        return $this->back($topic, ok: 'Video link removed.');
    }

    /** Employees may only complete topics assigned to themselves, and only once a video is attached. */
    public function toggle(Topic $topic)
    {
        $this->authorizeOwn($topic);

        if ($topic->is_done) {
            $topic->markUndone();

            return $this->back($topic, ok: 'Marked as not done.');
        }

        if (! $topic->video_url) {
            return $this->back($topic, error: 'Add your YouTube video link before ticking this topic.');
        }

        $topic->markDone();

        return $this->back($topic, ok: 'Marked as done — nice work!');
    }

    private function authorizeOwn(Topic $topic): void
    {
        abort_unless($topic->assigned_to === Auth::guard('employee')->id(), 403);
    }

    private function authorizeOwnAdded(Topic $topic): void
    {
        abort_unless($topic->added_by === Auth::guard('employee')->id(), 403);
    }

    private function back(Topic $topic, ?string $ok = null, ?string $error = null)
    {
        $route    = $topic->added_by ? 'employee.custom-topics' : 'employee.topics';
        $redirect = redirect()->to(route($route) . '#t-' . $topic->id);

        if ($ok) {
            $redirect->with('ok', $ok);
        }
        if ($error) {
            $redirect->withErrors(['video' => $error])->with('error_topic', $topic->id);
        }

        return $redirect;
    }
}
