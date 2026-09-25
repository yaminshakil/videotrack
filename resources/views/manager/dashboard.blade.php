@extends('layouts.app')

@section('title', 'Dashboard — Manager')
@section('sidebar', 1)

@php use App\Support\Money; @endphp

@push('styles')
<style>
  h1{font-size:24px;margin:0 0 4px}
  .sub{font-size:15px;color:var(--muted);margin-top:6px}
  .stats2 .stat2 .val{font-size:22px}

  .chans{display:grid;grid-template-columns:repeat(auto-fill,minmax(280px,1fr));gap:12px;margin-top:6px}
  .chancard{background:var(--panel);border:1px solid var(--border);border-radius:20px;padding:16px 18px;box-shadow:var(--shadow)}
  .chancard h3{margin:0 0 2px;font-size:17px}
  .chancard .chan-sub{color:var(--muted);font-size:14px;margin-bottom:12px}
  .chancard .nums{display:grid;grid-template-columns:1fr 1fr;gap:10px}
  .chancard .n{font-size:13px;color:var(--muted);text-transform:uppercase;letter-spacing:.04em}
  .chancard .n b{display:block;font-size:21px;color:var(--text);margin-top:2px}
  .chancard .n.earned b{color:var(--accent)}
  .chancard a{color:var(--accent);text-decoration:none;font-size:14px}
  .block{background:var(--panel);border:1px solid var(--border);border-radius:22px;padding:18px 20px;margin-bottom:22px;box-shadow:var(--shadow)}
  .block h2{font-size:18px;margin:0 0 12px}
  .flashmsg{padding:11px 13px;border-radius:11px;font-size:15px;margin-bottom:14px}
  .flashmsg.ok{background:rgba(22,163,74,.1);border:1px solid rgba(22,163,74,.35);color:var(--ok-text)}
  .flashmsg.err{background:rgba(239,68,68,.1);border:1px solid rgba(239,68,68,.35);color:var(--err-text)}
</style>
@endpush

@section('body')
<div class="wrap">
  @include('manager._nav')
  @include('admin._topbar', ['topbarName' => $manager->name, 'topbarRole' => 'Manager'])

  <div class="dhead">
    <div>
      <h1>Hi, {{ $manager->name }} 👋</h1>
      <p class="sub">Add topics to your channels, assign them to employees, and keep an eye on what they're earning.</p>
    </div>
    <div class="actions">
      <a href="{{ route('manager.topics') }}" class="ghost">🛠️ Topics</a>
      <a href="{{ route('manager.topics') }}" class="solid">＋ Add topic</a>
    </div>
  </div>

  @if (session('ok'))<div class="flashmsg ok">{{ session('ok') }}</div>@endif
  @if ($errors->any())<div class="flashmsg err">{{ $errors->first() }}</div>@endif

  <section class="stats2">
    <div class="stat2">
      <div class="top">
        <div><div class="val">{{ $total }}</div><div class="lbl">Topics</div></div>
        <div class="icon tone-violet">🛠️</div>
      </div>
      <div class="delta">{{ $channels->count() }} {{ Str::plural('channel', $channels->count()) }}</div>
    </div>
    <div class="stat2">
      <div class="top">
        <div><div class="val">{{ $done }}</div><div class="lbl">Completed</div></div>
        <div class="icon tone-teal">✅</div>
      </div>
      <div class="delta">{{ $total - $done }} still to do</div>
    </div>
    <div class="stat2">
      <div class="top">
        <div><div class="val">{{ $done ? round($done / $total * 100) : 0 }}%</div><div class="lbl">Completion</div></div>
        <div class="icon tone-amber">📈</div>
      </div>
      <div class="delta">across your channels</div>
    </div>
    <div class="stat2">
      <div class="top">
        <div><div class="val">{{ Money::tk($earned) }}</div><div class="lbl">Earned by staff</div></div>
        <div class="icon tone-blue">💰</div>
      </div>
      <div class="delta"><a href="{{ route('manager.earnings') }}">View earnings →</a></div>
    </div>
  </section>

  @if ($channels->isEmpty())
    <div class="block">
      <h2>Your channels</h2>
      <p style="margin:0">You don't have any channels yet. Ask the admin to assign you one on the
        <a href="#" style="color:var(--accent)">Managers</a> page — until then you can't add or assign topics.</p>
    </div>
  @else
    <section class="block">
      <h2>Your channels</h2>
      <div class="chans">
        @foreach ($channels as $row)
          <div class="chancard">
            <h3>{{ $row['channel']->icon }} {{ $row['channel']->name }}</h3>
            <div class="chan-sub">{{ $row['channel']->badge }}</div>
            <div class="nums">
              <div class="n">Topics<b>{{ $row['total'] }}</b></div>
              <div class="n">Done<b>{{ $row['done'] }}</b></div>
              <div class="n">Assigned<b>{{ $row['employees'] }}</b></div>
              <div class="n earned">Earned<b>{{ Money::tk($row['earned']) }}</b></div>
            </div>
            <div style="margin-top:12px">
              <a href="{{ route('manager.topics', ['channel' => $row['channel']->id]) }}">Manage topics →</a>
            </div>
          </div>
        @endforeach
      </div>
    </section>
  @endif
</div>
@endsection