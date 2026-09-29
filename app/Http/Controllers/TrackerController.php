<?php

namespace App\Http\Controllers;

use App\Models\Admin;
use App\Models\Channel;
use App\Models\Topic;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

class TrackerController extends Controller
{
    public function index(Request $request)
    {
        // All three filters are compared against or interpolated as plain strings.
        // A hand-edited or stale bookmark can send ?q[]=… instead of ?q=…, and
        // casting that array to a string is fatal in PHP 8 — which surfaced as a
        // 500 on a URL the user may have had bookmarked or auto-completed. Anything
        // that is not a scalar falls back to the "no filter" value instead.
        $filter = function (string $key, string $default) use ($request): string {
            $value = $request->query($key);

            return is_scalar($value) ? (string) $value : $default;
        };

        $q = trim($filter('q', ''));
        $channel = $filter('channel', 'all');
        $status = $filter('status', 'all');

        $employee = Auth::guard('employee')->user();
        $manager = Auth::guard('manager')->user();
        $channelIds = $manager?->channels()->pluck('channels.id');
        $isLegacyAdmin = ! Admin::tableExists() && $request->session()->get('tracker_admin') === true;

        $topics = Topic::ordered()
            ->with('channel')
            ->when($manager, fn ($query) => $query->whereIn('channels.id', $channelIds))
            ->when($employee, fn ($query) => $query->where('topics.assigned_to', $employee->id))
            ->when($q !== '', fn ($query) => $query->where('topics.title', 'like', '%'.$q.'%'))
            ->when($channel !== 'all', fn ($query) => $query->where('channels.slug', $channel))
            ->when($status === 'done', fn ($query) => $query->where('topics.is_done', true))
            ->when($status === 'pending', fn ($query) => $query->where('topics.is_done', false))
            ->get();

        // Group by channel + section label (header). An employee's own self-added
        // topics get their own section, named after them, instead of being filed
        // under their real category — added_by is the employee FK and is only set
        // on that path, never for admin or manager-added topics. addedByLabel()
        // falls back to the employee relation's name when the label itself is
        // null (a row from before added_by_label existed), so the section never
        // renders with a blank name.
        $label = fn (Topic $t) => $t->added_by ? $t->addedByLabel() : $t->category;

        $groups = $topics->groupBy(fn (Topic $t) => $t->channel_id.'|'.$label($t))
            ->map(fn ($items, $key) => [
                'key' => sha1($key),
                'channel' => $items->first()->channel,
                'category' => $label($items->first()),
                'isAddedByEmployee' => (bool) $items->first()->added_by,
                'items' => $items,
            ])
            // Channel order stays whatever the channels themselves use; within a
            // channel, what an employee added themselves leads, ahead of the
            // regular category sections, so a viewer sees "what's new from the
            // team" before wading into the full checklist.
            ->sortBy(fn ($g) => sprintf(
                '%05d|%d|%s',
                $g['channel']->sort_order,
                $g['isAddedByEmployee'] ? 0 : 1,
                $g['category']
            ))
            ->values();

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
            'isAdmin' => Auth::guard('admin')->check() || $isLegacyAdmin,
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
