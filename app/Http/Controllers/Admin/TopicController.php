<?php

namespace App\Http\Controllers\Admin;

use App\Actions\TopicActions;
use App\Http\Controllers\Controller;
use App\Models\Topic;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

class TopicController extends Controller
{
    /**
     * Plain-form fallback for adding a topic (the admin Topics page itself now
     * posts through the Livewire component's own store()). Kept in sync with
     * TopicActions::createAssigned() rather than building the row by hand, so it
     * cannot drift out of step on fields like added_by_key.
     */
    public function store(Request $request, TopicActions $actions)
    {
        $data = $request->validate([
            'channel_id'  => ['required', 'exists:channels,id'],
            'title'       => ['required', 'string', 'max:255'],
            'category'    => ['nullable', 'string', 'max:100'],
            'link'        => ['nullable', 'url:http,https', 'max:500'],
            'employee_id' => ['nullable', 'integer', 'exists:employees,id'],
        ], ['channel_id.required' => 'Channel and title are required.']);

        $actions->createAssigned($data, $data['employee_id'] ?? null, Auth::guard('admin')->user()?->name);

        return back()->with('ok', 'Topic added.');
    }

    public function update(Request $request, Topic $topic)
    {
        $data = $request->validate([
            'title'    => ['required', 'string', 'max:255'],
            'category' => ['nullable', 'string', 'max:100'],
            'link'     => ['nullable', 'url:http,https', 'max:500'],
        ]);

        $wantDone = $request->boolean('is_done');

        $topic->fill([
            'title'    => $data['title'],
            'category' => trim($data['category'] ?? '') ?: 'Other',
            'link'     => $data['link'] ?? '',
        ])->save();

        // Completion goes through the model so the payroll snapshot stays consistent.
        if ($wantDone && ! $topic->is_done) {
            $topic->markDone();
        } elseif (! $wantDone && $topic->is_done) {
            $topic->markUndone();
        }

        return back()->with('ok', 'Topic updated.');
    }

    public function destroy(Topic $topic)
    {
        $topic->delete();

        return back()->with('ok', 'Topic deleted.');
    }
}
