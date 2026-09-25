<?php

namespace App\Http\Controllers;

use App\Models\Channel;
use App\Models\Topic;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

class TrackerController extends Controller
{
    public function index(Request $request)
    {
        $q = trim((string) $request->query('q', ''));
        $channel = (string) $request->query('channel', 'all');
        $status = (string) $request->query('status', 'all');

        $employee = Auth::guard('employee')->user();
        $manager = Auth::guard('manager')->user();
        $channelIds = $manager?->channels()->pluck('channels.id');

        $topics = Topic::ordered()
            ->with('channel')
            ->when($manager, fn ($query) => $query->whereIn('channels.id', $channelIds))
            ->when($employee, fn ($query) => $query->where('topics.assigned_to', $employee->id))
            ->when($q !== '', fn ($query) => $query->where('topics.title', 'like', '%'.$q.'%'))
            ->when($channel !== 'all', fn ($query) => $query->where('channels.slug', $channel))
            ->when($status === 'done', fn ($query) => $query->where('topics.is_done', true))
            ->when($status === 'pending', fn ($query) => $query->where('topics.is_done', false))
            ->get();

        // Group by channel + category (section headers).
        $groups = $topics->groupBy(fn (Topic $t) => $t->channel_id.'|'.$t->category)
            ->map(fn ($items, $key) => [
                'key' => sha1($key),
                'channel' => $items->first()->channel,
                'category' => $items->first()->category,
                'items' => $items,
            ]);

        $scope = match (true) {
            (bool) $manager => fn ($query) => $query->whereIn('channel_id', $channelIds),
            (bool) $employee => fn ($query) => $query->where('assigned_to', $employee->id),
            default => null,
        };

        return view('tracker.index', [
            'groups' => $groups,
            'channels' => Channel::orderBy('sort_order')
                ->when($manager, fn ($query) => $query->whereIn('id', $channelIds))
                ->when($employee, fn ($query) => $query->whereIn('id', Topic::where('assigned_to', $employee->id)->pluck('channel_id')))
                ->get(),
            'stats' => $this->stats($scope),
            'q' => $q,
            'channel' => $channel,
            'status' => $status,
            'isAdmin' => (bool) $request->session()->get('tracker_admin'),
            'employee' => $employee,
            'manager' => $manager,
        ]);
    }

    public function toggle(Topic $topic): JsonResponse
    {
        $topic->toggleDone();

        return response()->json([
            'ok' => true,
            'is_done' => $topic->is_done,
            'stats' => $this->stats(),
        ]);
    }

    private function stats(?callable $scope = null): array
    {
        $base = fn () => Topic::query()->when($scope, fn ($query) => $scope($query));
        $total = $base()->count();
        $done = $base()->where('is_done', true)->count();

        return [
            'total' => $total,
            'done' => $done,
            'remaining' => $total - $done,
            'percent' => $total ? (int) round($done / $total * 100) : 0,
        ];
    }
}
