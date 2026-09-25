@extends('layouts.app')

@section('title', 'Managers — Admin')
@section('sidebar', 1)

@push('styles')
<style>
  .head{display:flex;justify-content:space-between;align-items:center;gap:14px;flex-wrap:wrap;margin-bottom:22px}
  h1{font-size:26px;margin:0}
  .tagline{color:var(--muted);font-size:15px;margin-top:4px}
  .block{background:var(--panel);border:1px solid var(--border);border-radius:22px;padding:18px;margin-bottom:24px;box-shadow:var(--shadow)}
  .block h2{font-size:18px;margin:0 0 12px}
  .grid{display:grid;grid-template-columns:1.2fr 1fr 1fr auto;gap:9px;align-items:end}
  .grid label{display:block;font-size:13px;color:var(--muted);margin:0 0 4px}
  .grid input{width:100%;padding:9px 11px;border-radius:12px;border:1px solid var(--border);
        background:var(--panel2);color:var(--text);font-size:15px}
  button{padding:9px 13px;border-radius:12px;border:0;cursor:pointer;font-weight:700;font-size:15px;color:#fff;
        background:linear-gradient(135deg,var(--accent),var(--accent2));width:auto}
  button.danger{background:linear-gradient(135deg,#dc2626,#b91c1c)}
  table{width:100%;border-collapse:collapse;font-size:15px}
  th,td{padding:8px 10px;text-align:left;border-bottom:1px solid var(--border);vertical-align:top}
  th{color:var(--muted);font-weight:600;font-size:13px;text-transform:uppercase;letter-spacing:.03em}
  .rowform{display:flex;gap:7px;align-items:center;flex-wrap:wrap}
  .rowform input:not([type=checkbox]){padding:6px 9px;border-radius:10px;border:1px solid var(--border);background:var(--panel2);
        color:var(--text);font-size:14px;width:auto}
  .chips{display:flex;gap:6px;flex-wrap:wrap;margin:4px 0 10px}
  .chip{display:flex;align-items:center;gap:6px;font-size:14px;border:1px solid var(--border);border-radius:999px;padding:4px 11px;cursor:pointer}
  .chip.on{background:rgba(37,99,235,.12);border-color:var(--accent)}
  .chip input{accent-color:var(--accent)}
  .muted{color:var(--muted)}
  .msgs{display:flex;flex-direction:column;gap:8px;margin-bottom:14px}
  .msg{padding:10px 12px;border-radius:10px;font-size:15px}
  .msg.ok{background:rgba(22,163,74,.1);border:1px solid rgba(22,163,74,.35);color:var(--ok-text)}
  .msg.err{background:rgba(239,68,68,.1);border:1px solid rgba(239,68,68,.35);color:var(--err-text)}
  .tscroll{overflow-x:auto;-webkit-overflow-scrolling:touch}
  .namerow{display:flex;align-items:center;gap:8px;min-width:220px}
  .wrap{padding-top:20px}
  @media(max-width:850px){
    .grid{grid-template-columns:1fr 1fr}
    .grid button{grid-column:1/-1}
    .block{padding:14px}
    .rowform{flex-direction:column;align-items:stretch}
    .rowform input[type=text],.rowform input[type=password]{width:100%}
    table tr:first-child{display:none}
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
      <h1>🛡️ Managers</h1>
      <div class="tagline">Managers add topics to the channels you give them, assign those topics to employees, and can watch what employees earn in those channels.</div>
    </div>
  </div>

  @include('admin._messages')

  <!-- Add a manager -->
  <form class="block" method="post" action="{{ route('admin.managers.store') }}">
    @csrf
    <h2>Add manager</h2>
    <div class="grid">
      <div><label>Full name</label><input type="text" name="name" required placeholder="e.g. Karim"></div>
      <div><label>Username (login)</label><input type="text" name="username" required placeholder="karim"></div>
      <div><label>Password</label><input type="password" name="password" required></div>
      <button type="submit">＋ Add</button>
    </div>
    <h2 style="margin:18px 0 8px">Channels they can manage</h2>
    <div class="chips">
      @forelse ($channels as $c)
        <label class="chip"><input type="checkbox" name="channels[]" value="{{ $c->id }}"> {{ $c->icon }} {{ $c->name }}</label>
      @empty
        <span class="muted">No channels exist yet.</span>
      @endforelse
    </div>
  </form>

  @if ($managers->isNotEmpty())
  <div class="block">
    <h2>Managers</h2>
    <div class="tscroll"><table>
      <tr><th>Manager</th><th>Active</th><th>Channels</th><th>Password</th><th></th></tr>
      @foreach ($managers as $m)
      <tr>
        <td colspan="4">
          <form method="post" action="{{ route('admin.managers.update', $m) }}">
            @csrf @method('PUT')
            <div class="rowform">
              <input type="text" name="name" value="{{ $m->name }}" style="width:130px">
              <input type="text" name="username" value="{{ $m->username }}" style="width:110px">
              <label class="rowform" style="font-size:14px"><input type="checkbox" name="is_active" value="1" @checked($m->is_active)> active</label>
              <input type="password" name="password" placeholder="new password (blank = keep)" style="width:180px">
              <button type="submit">Save</button>
            </div>
            <div class="chips" style="margin-top:10px">
              @forelse ($channels as $c)
                <label class="chip {{ $m->channels->contains($c->id) ? 'on' : '' }}">
                  <input type="checkbox" name="channels[]" value="{{ $c->id }}" @checked($m->channels->contains($c->id))>
                  {{ $c->icon }} {{ $c->name }}
                </label>
              @empty
                <span class="muted" style="font-size:13px">No channels exist yet.</span>
              @endforelse
            </div>
            @if ($m->channels->isEmpty())
              <div class="muted" style="font-size:13px;margin-top:6px">No channels assigned — this manager can't do anything until you tick at least one.</div>
            @endif
          </form>
        </td>
        <td style="white-space:nowrap">
          <form method="post" action="{{ route('admin.managers.destroy', $m) }}">
            @csrf @method('DELETE')
            <button type="submit" class="danger" onclick="return confirm('Delete {{ addslashes($m->name) }}? They will lose access to all their channels.')">✕</button>
          </form>
        </td>
      </tr>
      @endforeach
    </table></div>
  </div>
  @endif

  <p style="text-align:center;margin:10px 0 34px">
    <a href="{{ route('admin.employees.index') }}" style="color:var(--accent);text-decoration:none;font-size:15px">← Manage employees &amp; rates</a>
  </p>
</div>
@endsection