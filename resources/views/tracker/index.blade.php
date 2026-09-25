@extends('layouts.app')

@if ($isAdmin || $employee || $manager)
  @section('sidebar', 1)
@endif

@section('body')
<div class="wrap">
  @if ($isAdmin)
    @include('admin._nav')
    @include('admin._topbar')
  @elseif ($employee)
    @include('employee._nav')
    @include('admin._topbar', ['topbarName' => $employee->name, 'topbarRole' => 'Employee'])
  @elseif ($manager)
    @include('manager._nav')
    @include('admin._topbar', ['topbarName' => $manager->name, 'topbarRole' => 'Manager'])
  @endif

  <header>
    <div>
      <h1>🤖 AI Installation Topic Tracker</h1>
      <p class="sub">Track topic coverage for <b>How To Windows</b>, <b>World of Linux</b>, <b>Web Tech Knowledge</b> and <b>AI Tech</b>.</p>
      <p class="sub" style="margin-top:6px">
        <a href="{{ route('home') }}" style="color:var(--muted);text-decoration:none;font-size:13px">← Home</a>
        @if (! $isAdmin && ! $employee && ! $manager)
          &nbsp;·&nbsp;
          <a href="{{ route('login') }}" style="color:var(--muted);text-decoration:none;font-size:13px">🔐 Sign in</a>
        @endif
      </p>
    </div>
    <div class="stats">
      <div class="stat"><b id="s-total">{{ $stats['total'] }}</b><span>Total</span></div>
      <div class="stat"><b id="s-done">{{ $stats['done'] }}</b><span>Completed</span></div>
      <div class="stat"><b id="s-remaining">{{ $stats['remaining'] }}</b><span>Remaining</span></div>
    </div>
  </header>

  <form class="toolbar" method="get" action="{{ route('tracker.index') }}">
    <input type="search" name="q" placeholder="Search topics…" value="{{ $q }}">
    <select name="channel" onchange="this.form.submit()">
      <option value="all">All Channels</option>
      @foreach ($channels as $c)
        <option value="{{ $c->slug }}" @selected($channel === $c->slug)>{{ $c->name }}</option>
      @endforeach
    </select>
    <select name="status" onchange="this.form.submit()">
      <option value="all" @selected($status === 'all')>All Status</option>
      <option value="pending" @selected($status === 'pending')>Not Done</option>
      <option value="done" @selected($status === 'done')>Done</option>
    </select>
  </form>

  <div class="progress">
    <div class="progressTop"><span>Overall progress</span><span id="s-percent">{{ $stats['percent'] }}%</span></div>
    <div class="bar"><div class="fill" id="s-fill" style="width:{{ $stats['percent'] }}%"></div></div>
  </div>

  <main class="content">
    @if ($groups->isEmpty())
      <div class="empty">No topics match your filters.</div>
    @endif

    @foreach ($groups as $g)
      <section class="group" data-gk="{{ $g['key'] }}">
        <div class="groupTitle">
          <h2>{{ $g['channel']->icon }} {{ $g['channel']->name }} · {{ $g['category'] }}</h2>
          <span class="count" id="gk-{{ $g['key'] }}">{{ $g['items']->where('is_done', true)->count() }}/{{ $g['items']->count() }} done</span>
        </div>
        <div class="list">
          @foreach ($g['items'] as $t)
            <div class="topic {{ $t->is_done ? 'done' : '' }}" data-gk="{{ $g['key'] }}" id="t-{{ $t->id }}">
              <input type="checkbox" id="c-{{ $t->id }}" @checked($t->is_done)
                     data-url="{{ route('tracker.toggle', $t) }}" onchange="toggle(this)" @disabled(! $isAdmin)>
              <label for="c-{{ $t->id }}">{{ $t->title }}</label>
              @if ($t->link !== '')
                <a class="tlink" href="{{ $t->link }}" target="_blank" rel="noopener" title="Open: {{ $t->title }}">&#x1F517;</a>
              @endif
              @if ($t->video_url)
                <a class="tlink" href="{{ $t->video_url }}" target="_blank" rel="noopener" title="Video: {{ $t->video_title ?: $t->video_url }}">▶</a>
              @endif
              <span class="badge">{{ $g['channel']->badge }}</span>
            </div>
          @endforeach
        </div>
      </section>
    @endforeach
  </main>

  <footer>
    @if ($isAdmin) Progress is saved in the database and shared across all your devices.
    @else Read-only view — topics are completed by assigned employees from their own portal. @endif
  </footer>
</div>
@endsection

@push('scripts')
<script>
const CSRF = document.querySelector('meta[name=csrf-token]').content;

function toggle(input){
  fetch(input.dataset.url, {
      method: 'POST',
      headers: {'X-CSRF-TOKEN': CSRF, 'Accept': 'application/json'}
    })
    .then(r => r.json())
    .then(d => {
      if(!d.ok){ input.checked = !input.checked; alert(d.error || 'Error saving.'); return; }
      const row = input.closest('.topic');
      row.classList.toggle('done', d.is_done);
      input.checked = d.is_done;
      document.getElementById('s-total').textContent = d.stats.total;
      document.getElementById('s-done').textContent = d.stats.done;
      document.getElementById('s-remaining').textContent = d.stats.remaining;
      document.getElementById('s-percent').textContent = d.stats.percent + '%';
      document.getElementById('s-fill').style.width = d.stats.percent + '%';
      // update just this group's counter
      const gk = row.dataset.gk;
      const g  = document.querySelectorAll('.group[data-gk="'+CSS.escape(gk)+'"] .topic').length;
      const gp = document.querySelectorAll('.group[data-gk="'+CSS.escape(gk)+'"] .topic.done').length;
      const el = document.getElementById('gk-'+gk);
      if(el) el.textContent = gp + '/' + g + ' done';
      // if a status filter hides this topic now, hide the row
      const status = document.querySelector('[name=status]').value;
      if(status !== 'all' && ((status === 'done' && !d.is_done) || (status === 'pending' && d.is_done))){
        row.style.display = 'none';
      }
    })
    .catch(() => { input.checked = !input.checked; alert('Network error.'); });
}
</script>
@endpush
