@use(App\Models\Channel)

{{--
  The Show Topic page's filters: which channel, status, assignee, and how recently a
  topic was added — one dropdown each, in one row. Channel used to be its own row of
  pill buttons; it lives here now so all four filters sit together. Unlike the instant
  text search, all four run server-side against the full set of topics, not just the
  ones currently on screen, because each is exact enough to be worth a real query.

  Expects: $channels        — the channels this viewer may see
           $filter          — the selected channel id as a string, '' for all of them
           $channelCounts   — [channel_id => topic count], for the viewer's channels.
                               Always unfiltered by the other three — that is the
                               comparison the chooser is for.
           $statusFilter    — 'all' | 'done' | 'pending'
           $assigneeFilter  — '' for everyone, '0' for unassigned, else a creator id
           $addedFilter     — '' for any time, else 'today' | '7' | '30'
           $employees       — for the assignee dropdown
--}}
@php
  $chosen = null;

  foreach ($channels as $candidate) {
    if ((string) $filter === (string) $candidate->id) {
      $chosen = $candidate;
      break;
    }
  }

  $count = fn (Channel $channel) => $channelCounts[(int) $channel->id] ?? 0;
  $all = array_sum($channelCounts);
  $shown = $chosen ? $count($chosen) : $all;
  $anyFilterActive = $filter !== '' || $statusFilter !== 'all' || $assigneeFilter !== '' || $addedFilter !== '';
@endphp

<div class="tfilters" role="group" aria-label="Filter topics by channel, status, assignee and date added">
  <label>
    <span>Channel</span>
    <select wire:model.live="filter">
      <option value="">All channels ({{ $all }})</option>
      @foreach ($channels as $c)
        <option value="{{ $c->id }}">{{ $c->icon }} {{ $c->name }} ({{ $count($c) }})</option>
      @endforeach
    </select>
  </label>

  <label>
    <span>Status</span>
    <select wire:model.live="statusFilter">
      <option value="all">All</option>
      <option value="pending">Pending</option>
      <option value="done">Done</option>
    </select>
  </label>

  <label>
    <span>Assigned to</span>
    <select wire:model.live="assigneeFilter">
      <option value="">Everyone</option>
      <option value="0">Unassigned</option>
      @foreach ($employees as $e)
        <option value="{{ $e->id }}">{{ $e->name }}</option>
      @endforeach
    </select>
  </label>

  <label>
    <span>Added</span>
    <select wire:model.live="addedFilter">
      <option value="">Any time</option>
      <option value="today">Today</option>
      <option value="7">Last 7 days</option>
      <option value="30">Last 30 days</option>
    </select>
  </label>

  @if ($anyFilterActive)
    <button type="button" class="tf-clear" wire:click="clearFilters">Clear filters</button>
  @endif
</div>

<p class="tf-note">
  {{ $chosen ? 'Showing '.$chosen->name : 'Showing every channel' }} ·
  {{ $shown }} {{ \Illuminate\Support\Str::plural('topic', $shown) }}
</p>
