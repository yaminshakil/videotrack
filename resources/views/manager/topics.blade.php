@extends('layouts.app')

@section('title', 'Topics — Manager')
@section('sidebar', 1)

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

  .filters{display:flex;gap:8px;flex-wrap:wrap;margin-bottom:16px}
  .filters a{color:var(--muted);text-decoration:none;font-size:14px;border:1px solid var(--border);border-radius:999px;padding:6px 14px;background:var(--panel)}
  .filters a.on{background:linear-gradient(135deg,var(--accent),var(--accent2));color:#fff;border-color:transparent}

  .trwrap{display:flex;gap:8px;align-items:center;margin-bottom:10px}
  .trwrap .trow{display:grid;grid-template-columns:auto 1.6fr 1fr 1.6fr auto;gap:10px;align-items:center;
        background:var(--panel);border:1px solid var(--border);border-radius:16px;padding:12px 14px;box-shadow:var(--shadow);flex:1;margin:0}
  .trow input,.trow select{width:100%;padding:7px 9px;border-radius:10px;border:1px solid var(--border);
        background:var(--panel2);color:var(--text);font-size:15px}
  select.emp{width:auto;min-width:140px}
  .trow .t-title{font-weight:600;font-size:16px}
  .muted{color:var(--muted);font-size:14px}
  .msgs{display:flex;flex-direction:column;gap:8px;margin-bottom:16px}
  .msg{padding:9px 12px;border-radius:10px;font-size:15px}
  .msg.err{background:rgba(239,68,68,.1);border:1px solid rgba(239,68,68,.35);color:var(--err-text)}
  .msg.ok{background:rgba(22,163,74,.1);border:1px solid rgba(22,163,74,.35);color:var(--ok-text)}
  .btn-danger{background:linear-gradient(135deg,#dc2626,#b91c1c)}
  .btn-sm{padding:7px 12px;font-size:14px;width:auto}
  .ch-badge{font-size:12px;padding:3px 8px;border-radius:999px;background:var(--chip-bg);color:var(--chip-text)}
  .assignform{display:flex;flex-direction:column;gap:6px}
  .assignform button{width:auto;padding:6px 10px;font-size:13px}
  .done-pill{font-size:12px;padding:3px 8px;border-radius:999px;font-weight:600;white-space:nowrap}
  .done-pill.yes{background:rgba(22,163,74,.12);color:var(--done)}
  .done-pill.no{background:var(--chip-bg);color:var(--chip-text)}
  @media(max-width:850px){
    .grid .fields,.grid .fields[style]{grid-template-columns:1fr !important}
    .trwrap{flex-direction:column;align-items:stretch;background:var(--panel);border:1px solid var(--border);border-radius:16px;padding:10px;margin-bottom:10px}
    .trwrap .trow{grid-template-columns:1fr;background:none;border:0;padding:0;margin:0}
    .trwrap .btn-danger{width:100%}
    .admin-wrap{padding:16px 14px 40px}
  }
</style>
@endpush

@section('body')
<div class="wrap admin-wrap">
  @include('manager._nav')
  @include('admin._topbar', ['topbarName' => $manager->name, 'topbarRole' => 'Manager'])

  <header class="admin-head">
    <div>
      <h1>🛠️ Topics</h1>
      <div class="tagline">Add topics to the channels you manage and assign them to employees. Completed topics keep their assignee.</div>
    </div>
  </header>

  @include('admin._messages')
  @if ($errors->has('assign'))<div class="msgs"><div class="msg err">{{ $errors->first('assign') }}</div></div>@endif

  <!-- Add new topic (only into your channels) -->
  <form class="grid" method="post" action="{{ route('manager.topics.store') }}">
    @csrf
    <div class="fields">
      <div>
        <label for="ch-add">Channel</label>
        <select name="channel_id" id="ch-add" required>
          @foreach ($channels as $c)
            <option value="{{ $c->id }}" @selected((string) $c->id === (string) $channelId)>{{ $c->icon }} {{ $c->name }}</option>
          @endforeach
        </select>
      </div>
      <div>
        <label for="title-add">Topic title</label>
        <input type="text" name="title" id="title-add" required placeholder="e.g. Install Ollama on Ubuntu">
      </div>
      <div>
        <label for="link-add">Link (optional)</label>
        <input type="url" name="link" id="link-add" placeholder="https://…">
      </div>
    </div>
    <div class="fields" style="grid-template-columns:1fr auto">
      <div>
        <label for="cat-add">Category (optional)</label>
        <input type="text" name="category" id="cat-add" placeholder="e.g. Local AI / RAG">
      </div>
      <div style="align-self:end">
        <button type="submit">＋ Add topic</button>
      </div>
    </div>
  </form>

  <!-- Channel filter -->
  <div class="filters">
    <a href="{{ route('manager.topics') }}" class="{{ $channelId ? '' : 'on' }}">All channels</a>
    @foreach ($channels as $c)
      <a href="{{ route('manager.topics', ['channel' => $c->id]) }}" class="{{ (string) $c->id === (string) $channelId ? 'on' : '' }}">{{ $c->icon }} {{ $c->name }}</a>
    @endforeach
  </div>

  @if ($topics->isEmpty())
    <p class="muted">No topics match yet. Add the first one above.</p>
  @endif

  <!-- Edit / assign / delete topics, grouped by channel -->
  @php $current = null; @endphp
  @foreach ($topics as $t)
    @if ($current !== $t->channel_id)
      @php $current = $t->channel_id; @endphp
      <h2 style="font-size:19px;margin:26px 0 8px">{{ $t->channel->icon }} {{ $t->channel->name }} <span class="muted" style="font-size:14px;font-weight:400">{{ $t->channel->badge }}</span></h2>
    @endif

    <div class="trwrap">
      <form class="trow" method="post" action="{{ route('manager.topics.update', $t) }}">
        @csrf @method('PUT')
        <div class="ch-badge">{{ $t->is_done ? '✅' : '☐' }}</div>
        <input type="text" name="title" value="{{ $t->title }}" class="t-title">
        <input type="text" name="category" value="{{ $t->category }}" class="muted">
        <input type="url" name="link" value="{{ $t->link }}" placeholder="https://…">
        <div style="display:flex;gap:9px;align-items:center;min-width:max-content">
          <button type="submit" class="btn-sm">Save</button>
        </div>
      </form>
      @if (! $t->is_done)
        <form class="assignform" method="post" action="{{ route('manager.topics.assign', $t) }}">
          @csrf @method('PUT')
          @if ($employees->isNotEmpty())
            <select name="employee_id" class="emp" title="Assign to employee">
              <option value="0">{{ $t->employee?->name ? '— unassigned —' : '— unassigned —' }}</option>
              @foreach ($employees as $e)
                <option value="{{ $e->id }}" @selected($t->assigned_to === $e->id)>{{ $e->name }}</option>
              @endforeach
            </select>
            <button type="submit" class="btn-sm">Assign</button>
          @else
            <span class="muted" style="font-size:13px">No employees yet.</span>
          @endif
        </form>
      @else
        <span class="done-pill yes" title="Completed topics keep their assignee">Done</span>
      @endif
      <form method="post" action="{{ route('manager.topics.destroy', $t) }}" onsubmit="return confirm('Delete this topic?')">
        @csrf @method('DELETE')
        <button type="submit" class="btn-sm btn-danger">Delete</button>
      </form>
    </div>
  @endforeach
</div>
@endsection