@extends('layouts.app')

@section('title', 'Creators & Rates — Admin')
@section('sidebar', 1)

@push('styles')
<style>
  .head{display:flex;justify-content:space-between;align-items:center;gap:14px;flex-wrap:wrap;margin-bottom:22px}
  h1{font-size:26px;margin:0}
  .tagline{color:var(--muted);font-size:15px;margin-top:4px}
  .block{background:var(--panel);border:1px solid var(--border);border-radius:22px;padding:18px;margin-bottom:24px;box-shadow:var(--shadow)}
  .block h2{font-size:18px;margin:0 0 12px}
  .grid{display:grid;grid-template-columns:1.2fr 1fr 1fr 1fr auto;gap:9px;align-items:end}
  .grid label{display:block;font-size:13px;color:var(--muted);margin:0 0 4px}
  .grid input{width:100%;padding:9px 11px;border-radius:12px;border:1px solid var(--border);
        background:var(--panel2);color:var(--text);font-size:15px}
  button{padding:9px 13px;border-radius:12px;border:0;cursor:pointer;font-weight:700;font-size:15px;color:#fff;
        background:linear-gradient(135deg,var(--accent),var(--accent2));width:auto}
  button.danger{background:linear-gradient(135deg,#dc2626,#b91c1c)}
  table{width:100%;border-collapse:collapse;font-size:15px}
  th,td{padding:9px 10px;text-align:left;border-bottom:1px solid var(--border)}
  th{color:var(--muted);font-weight:600;font-size:13px;text-transform:uppercase;letter-spacing:.03em}
  .rowform{display:flex;gap:7px;align-items:center}
  .rowform input:not([type=checkbox]){padding:6px 9px;border-radius:10px;border:1px solid var(--border);background:var(--panel2);
        color:var(--text);font-size:14px;width:auto}
  .pills{display:flex;gap:8px;flex-wrap:wrap}
  .ch{border:1px solid var(--border);border-radius:14px;padding:10px 12px;font-size:14px}
  .muted{color:var(--muted)}
  a{color:var(--accent);text-decoration:none;font-size:15px}
  .msgs{display:flex;flex-direction:column;gap:8px;margin-bottom:14px}
  .msg{padding:10px 12px;border-radius:10px;font-size:15px}
  .msg.ok{background:rgba(22,163,74,.1);border:1px solid rgba(22,163,74,.35);color:var(--ok-text)}
  .msg.err{background:rgba(239,68,68,.1);border:1px solid rgba(239,68,68,.35);color:var(--err-text)}
  select.emp{min-width:150px;width:auto;padding:7px 9px}
  .wrap{padding-top:20px}
  .tscroll{overflow-x:auto;-webkit-overflow-scrolling:touch}
  .rowform{flex-wrap:wrap}
  @media(max-width:850px){
    .grid{grid-template-columns:1fr 1fr}
    .grid button{grid-column:1/-1}
    /* Stacking every field of a row (checkbox, title, raw link, picker) onto its
       own line made a 478-topic list roughly 2.5x taller on a phone than on
       desktop. The title wraps next to its checkbox instead, the raw link (often
       a full YouTube URL, and already secondary to the title) is dropped rather
       than wrapped across several lines, and the picker gets its own full-width
       line since it needs the room. */
    .assign .t{flex:1 1 auto !important;min-width:0}
    .assign>.muted{display:none}
    select.emp{width:100%;flex:1 1 100%}
    .block{padding:14px}
    .etable tr:first-child{display:none}
  }
  @media(max-width:520px){.grid{grid-template-columns:1fr}}
</style>
@endpush

@section('body')
<div class="wrap">
  @include('admin._nav')
  @include('admin._topbar')

  <div class="head">
    <div>
      <h1>👥 Creators &amp; Rates</h1>
      <div class="tagline">Create creators, set what each channel pays per completed topic, and assign topics. Earnings are on the <a href="{{ route('admin.payroll') }}">Payroll</a> page.</div>
    </div>
  </div>

  @include('admin._messages')

  <!-- 1. Add / edit creators -->
  <form class="block" method="post" action="{{ route('admin.employees.store') }}" autocomplete="off">
    @csrf
    <h2>Add creator</h2>
    <div class="grid">
      <div><label>Full name</label><input type="text" name="name" required placeholder="e.g. Rahim" autocomplete="off"></div>
      <div><label>Username (login)</label><input type="text" name="username" required placeholder="rahim" autocomplete="off"></div>
      <div><label>Password</label><input type="password" name="password" required autocomplete="new-password"></div>
      <div></div>
      <button type="submit">＋ Add</button>
    </div>
  </form>

  @if ($employees->isNotEmpty())
  <div class="block">
    <h2>Creators</h2>
    <div class="tscroll"><table class="etable">
      <tr><th>Name</th><th>Username</th><th>Active</th><th>Password</th><th></th></tr>
      @foreach ($employees as $e)
      <tr>
        <td colspan="4">
          <form method="post" action="{{ route('admin.employees.update', $e) }}" class="rowform">
            @csrf @method('PUT')
            <input type="text" name="name" value="{{ $e->name }}" style="width:130px">
            <input type="text" name="username" value="{{ $e->username }}" style="width:120px">
            <label class="rowform"><input type="checkbox" name="is_active" value="1" @checked($e->is_active)> active</label>
            <input type="password" name="password" placeholder="new password (blank = keep)" style="width:210px" autocomplete="new-password">
            <span class="muted" style="font-size:12px;white-space:nowrap">✍️ {{ $e->added_topics_count }} {{ Str::plural('topic', $e->added_topics_count) }} added</span>
            <button type="submit">Save</button>
          </form>
        </td>
        <td>
          <form method="post" action="{{ route('admin.employees.destroy', $e) }}">
            @csrf @method('DELETE')
            <button type="submit" class="danger" onclick="return confirm('Delete {{ addslashes($e->name) }}?

Their earnings history AND payment records are deleted too, so you will lose the record of what you owe them. To just stop them logging in, untick Active and Save instead.')">✕</button>
          </form>
        </td>
      </tr>
      @endforeach
    </table></div>
  </div>
  @endif

  <!-- 2. Rate matrix (channel x creator) -->
  @include('partials._rate-editor', [
    'rateAction' => route('admin.rates.save'),
    'rateChannels' => $channels,
    'rateEmployees' => $employees,
    'rates' => $rates,
  ])

  <!-- 3. Assign topics -->
  <div class="block">
    <h2>Assign topics</h2>
    @php $curChan = null; @endphp
    @foreach ($topics as $t)
      @if ($curChan !== $t->channel_id)
        @php $curChan = $t->channel_id; @endphp
        <h3 style="font-size:15px;color:var(--muted);margin:18px 0 8px;border-bottom:1px solid var(--border);padding-bottom:5px">
          {{ $t->channel->icon }} {{ $t->channel->name }}</h3>
      @endif
      <form method="post" action="{{ route('admin.topics.assign', $t) }}" class="rowform assign" style="margin-bottom:9px">
        @csrf @method('PUT')
        <span style="width:24px;text-align:center">{{ $t->is_done ? '✅' : '☐' }}</span>
        <span class="t" style="flex:0 0 40%;font-size:15px;overflow-wrap:anywhere">{{ $t->title }}</span>
        <span class="muted" style="flex:1;font-size:13px">{{ $t->link }}</span>
        @if ($employees->isNotEmpty())
          <select name="employee_id" class="emp" onchange="this.form.submit()">
            <option value="0">— unassigned —</option>
            @foreach ($employees as $e)
              <option value="{{ $e->id }}" @selected($t->assigned_to === $e->id)>{{ $e->name }}</option>
            @endforeach
          </select>
        @else
          <span class="muted" style="font-size:13px">Add a creator first.</span>
        @endif
      </form>
    @endforeach
  </div>

  <p style="text-align:center;margin:10px 0 34px">
    <a href="{{ route('admin.topics.index') }}">← Back to topic management</a>
  </p>
</div>
@endsection
