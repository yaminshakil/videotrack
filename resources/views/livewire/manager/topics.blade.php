@push('styles')
<style>
  .admin-wrap{max-width:1100px;margin:0 auto;padding:30px 20px 50px}
  header.admin-head{display:flex;justify-content:space-between;align-items:start;gap:14px;flex-wrap:wrap;margin-bottom:22px}
  h1{font-size:26px;margin:0}
  .tagline{color:var(--muted);font-size:15px;margin-top:4px}
  form.grid{background:var(--panel);border:1px solid var(--border);border-radius:22px;padding:18px;margin-bottom:16px;box-shadow:var(--shadow)}
  .grid .fields{display:grid;grid-template-columns:1.2fr 1fr 1.8fr;gap:10px}
  .grid label{display:block;font-size:13px;color:var(--muted);margin:0 0 4px}
  .grid input,.grid select,.grid textarea{width:100%;padding:10px 12px;border-radius:12px;
        border:1px solid var(--border);background:var(--panel2);color:var(--text);font-size:15px}
  .grid button{margin-top:10px}
  .muted{color:var(--muted);font-size:14px}
  .adderr{color:var(--err-text);font-size:13px;margin-top:4px}
  .msgs{display:flex;flex-direction:column;gap:8px;margin-bottom:16px}
  .msg{padding:9px 12px;border-radius:10px;font-size:15px}
  .msg.err{background:rgba(239,68,68,.1);border:1px solid rgba(239,68,68,.35);color:var(--err-text)}
  .msg.ok{background:rgba(22,163,74,.1);border:1px solid rgba(22,163,74,.35);color:var(--ok-text)}
  .btn-danger{background:linear-gradient(135deg,#dc2626,#b91c1c)}
  .btn-sm{padding:7px 12px;font-size:14px;width:auto}
  @media(max-width:850px){
    .grid .fields,.grid .fields[style]{grid-template-columns:1fr !important}
    .admin-wrap{padding:16px 14px 40px}
  }
</style>
@endpush

<div class="wrap admin-wrap">
  @include('manager._nav')
  @include('admin._topbar', ['topbarName' => $manager->name, 'topbarRole' => 'Manager'])

  <header class="admin-head">
    <div>
      <h1>🛠️ Topics</h1>
      <div class="tagline">
        @if ($panel === 'add')
          Add a topic to one of the channels you manage.
        @else
          Every topic in your channels, grouped by channel. Add one from the sidebar.
        @endif
      </div>
    </div>
  </header>

  @include('admin._messages')
  @error('assign')<div class="msgs"><div class="msg err">{{ $message }}</div></div>@enderror

  @if ($panel === 'add')
    {{-- Add topics: the form (only into your channels), and what it has just produced --}}
    <section aria-label="Add topics">
      <form class="grid" wire:submit="store">
        <div class="fields">
          <div>
            <label for="ch-add">Channel</label>
            <select name="channel_id" id="ch-add" wire:model="channel_id">
              <option value="">Choose a channel…</option>
              @foreach ($channels as $c)
                <option value="{{ $c->id }}">{{ $c->icon }} {{ $c->name }}</option>
              @endforeach
            </select>
            @error('channel_id')<div class="adderr">{{ $message }}</div>@enderror
          </div>
          <div>
            <label for="title-add">Topic title</label>
            <input type="text" name="title" id="title-add" wire:model="title" placeholder="e.g. Install Ollama on Ubuntu">
            @error('title')<div class="adderr">{{ $message }}</div>@enderror
          </div>
          <div>
            <label for="link-add">Link (optional)</label>
            <input type="url" name="link" id="link-add" wire:model="link" placeholder="https://…">
            @error('link')<div class="adderr">{{ $message }}</div>@enderror
          </div>
        </div>
        <div class="fields" style="grid-template-columns:1fr 1fr auto">
          <div>
            <label for="cat-add">Category (optional)</label>
            <input type="text" name="category" id="cat-add" wire:model="category" placeholder="e.g. Local AI / RAG">
          </div>
          <div>
            <label for="emp-add">Assign to (optional)</label>
            <select name="employee_id" id="emp-add" wire:model="employee_id">
              <option value="">— unassigned —</option>
              @foreach ($employees as $e)
                <option value="{{ $e->id }}">{{ $e->name }}</option>
              @endforeach
            </select>
            @error('employee_id')<div class="adderr">{{ $message }}</div>@enderror
          </div>
          <div style="align-self:end">
            <button type="submit" wire:loading.attr="disabled" wire:target="store">
              <span wire:loading.remove wire:target="store">＋ Add topic</span>
              <span wire:loading wire:target="store">Saving…</span>
            </button>
          </div>
        </div>
      </form>

      @include('partials._recent-topics')
    </section>
  @else
    {{-- Show topics: the channel/status/assignee/added filters, a search box, then
         the list, all scoped to the channels you manage --}}
    <section aria-label="Show topics">
      @include('partials._topic-filters')

      @include('partials._topic-search')

      @php
        $filtered = $statusFilter !== 'all' || $assigneeFilter !== '' || $addedFilter !== '';
      @endphp

      @include('partials._topics-table', [
        'groups' => $groups,
        'channelTotals' => $channelTotals,
        'shownTotal' => $topics->count(),
        'canAssign' => true,
        'showAll' => $showAll,
        'topicTotal' => $topicTotal,
        // A blank list is the confusing part of a channel chooser, so say which case
        // this is instead of leaving "no topics match yet" to be read as a failed click.
        'emptyMessage' => match (true) {
            $filtered => 'No topics match these filters. Try clearing one, or pick another channel.',
            $filter === '' => 'No topics in your channels yet. Add one from the Add Topic page in the sidebar.',
            default => 'No topics in this channel yet. Pick another channel, or add one from the Add Topic page in the sidebar.',
        },
      ])
    </section>
  @endif
</div>

@push('scripts')
<script src="{{ asset('js/topic-search.js') }}?v={{ @filemtime(public_path('js/topic-search.js')) }}" defer></script>
@endpush
