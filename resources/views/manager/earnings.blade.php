@extends('layouts.app')

@section('title', 'Earnings — Manager')
@section('sidebar', 1)

@php use App\Support\Money; @endphp

@push('styles')
<style>
  .pwrap{max-width:1000px;margin:0 auto;padding:22px 18px 50px}
  h1{font-size:26px;margin:0 0 4px}
  .tagline{color:var(--muted);font-size:15px;margin-bottom:18px}
  .muted{color:var(--muted)}
  .msgs{display:flex;flex-direction:column;gap:8px;margin-bottom:16px}
  .msg{padding:10px 12px;border-radius:10px;font-size:15px}
  .msg.ok{background:rgba(22,163,74,.1);border:1px solid rgba(22,163,74,.35);color:var(--ok-text)}
  .msg.err{background:rgba(239,68,68,.1);border:1px solid rgba(239,68,68,.35);color:var(--err-text)}

  .monthbar{display:flex;align-items:center;justify-content:space-between;gap:10px;flex-wrap:wrap;margin-bottom:16px}
  .monthbar .nav{display:flex;align-items:center;gap:10px}
  .monthbar h2{margin:0;font-size:24px;min-width:190px;text-align:center}
  .monthbar .range{color:var(--muted);font-size:14px;text-align:center}
  a.arrow{display:inline-grid;place-items:center;width:40px;height:40px;border-radius:14px;border:1px solid var(--border);
          color:var(--text);text-decoration:none;font-size:20px;background:var(--panel);box-shadow:var(--shadow)}
  a.arrow:hover{border-color:var(--accent)}
  a.arrow.off{opacity:.3;pointer-events:none}
  .jump{display:flex;gap:8px;align-items:center}
  .jump input{width:auto;padding:9px 11px;font-size:15px}
  .jump button{width:auto;padding:10px 14px;font-size:15px}
  a.now{color:var(--accent);text-decoration:none;font-size:15px}

  .cards{display:grid;grid-template-columns:repeat(3,1fr);gap:10px;margin-bottom:16px}
  .card{background:var(--panel);border:1px solid var(--border);border-radius:18px;padding:14px 16px;box-shadow:var(--shadow)}
  .card .l{font-size:13px;color:var(--muted);text-transform:uppercase;letter-spacing:.04em}
  .card .v{font-size:25px;font-weight:800;margin-top:4px}
  .card.due .v{color:var(--accent)}

  .ch{background:var(--panel);border:1px solid var(--border);border-radius:22px;padding:18px 20px;margin-bottom:14px;box-shadow:var(--shadow)}
  .ch .top{display:flex;justify-content:space-between;align-items:flex-start;gap:10px;flex-wrap:wrap;margin-bottom:10px}
  .ch h3{margin:0;font-size:19px}
  .ch .totals{font-size:14px;color:var(--muted);white-space:nowrap}
  .ch .totals b{color:var(--accent);font-size:16px}
  .tscroll{overflow-x:auto;-webkit-overflow-scrolling:touch}
  table{width:100%;border-collapse:collapse;font-size:15px}
  th,td{padding:8px 8px;text-align:left;border-bottom:1px solid var(--border)}
  th{color:var(--muted);font-weight:600;font-size:13px;text-transform:uppercase;letter-spacing:.03em}
  td.r,th.r{text-align:right}
  td.nw{white-space:nowrap}
  tr.total td{font-weight:700;border-top:2px solid var(--border);border-bottom:0}
  .empty{color:var(--muted);font-size:15px;padding:4px 0}
  @media(max-width:700px){
    .cards{grid-template-columns:1fr}
    .monthbar h2{min-width:0;font-size:21px}
    .pwrap{padding:16px 14px 40px}
  }
</style>
@endpush

@section('body')
<div class="pwrap">
  @include('manager._nav')
  @include('admin._topbar', ['topbarName' => $manager->name, 'topbarRole' => 'Manager'])

  <h1>💰 Earnings</h1>
  <div class="tagline">What employees earned, per channel you manage. Earnings are locked in when a topic is completed, so later rate changes never alter past records.</div>

  @include('admin._messages')

  {{-- Month switcher --}}
  <div class="monthbar">
    <div class="nav">
      <a class="arrow" href="{{ route('manager.earnings', ['month' => $prevMonth]) }}" aria-label="Previous month">‹</a>
      <div>
        <h2>{{ $start->format('F Y') }}</h2>
        <div class="range">{{ $start->format('j M') }} – {{ $end->format('j M Y') }}</div>
      </div>
      <a class="arrow {{ $isCurrent ? 'off' : '' }}" href="{{ route('manager.earnings', ['month' => $nextMonth]) }}" aria-label="Next month">›</a>
    </div>
    <form class="jump" method="get" action="{{ route('manager.earnings') }}">
      <input type="month" name="month" value="{{ $month }}" max="{{ now()->format('Y-m') }}" aria-label="Choose month">
      <button type="submit">Go</button>
      @unless($month === now()->format('Y-m'))<a class="now" href="{{ route('manager.earnings') }}">This month</a>@endunless
    </form>
  </div>

  <section class="cards">
    <div class="card"><div class="l">Earned in {{ $start->format('F') }}</div><div class="v">{{ Money::tk($monthEarned) }}</div></div>
    <div class="card"><div class="l">Topics done in {{ $start->format('F') }}</div><div class="v">{{ $monthDone }}</div></div>
    <div class="card due"><div class="l">All time earned</div><div class="v">{{ Money::tk($earned) }}</div></div>
  </section>

  @if ($rows->isEmpty())
    <p class="empty">No channels assigned yet — ask the admin to give you access to a channel.</p>
  @endif

  @foreach ($rows as $row)
    <section class="ch">
      <div class="top">
        <div>
          <h3>{{ $row['channel']->icon }} {{ $row['channel']->name }}</h3>
          <div class="muted" style="font-size:14px">{{ $row['channel']->badge }}</div>
        </div>
        <div class="totals">
          Earned in {{ $start->format('F') }}: <b>{{ Money::tk($row['earned_month']) }}</b>
          @if ($row['earned'] != $row['earned_month'])
            &nbsp;·&nbsp; all time <b>{{ Money::tk($row['earned']) }}</b>
          @endif
        </div>
      </div>

      @if ($row['staff']->isEmpty())
        <div class="empty">No earnings recorded in your channels yet.</div>
      @else
        <div class="tscroll">
          <table>
            <tr>
              <th>Employee</th>
              <th class="r">Rate / topic</th>
              <th class="r">Done {{ $start->format('M') }}</th>
              <th class="r">Earned {{ $start->format('M') }}</th>
              <th class="r">Done (all)</th>
              <th class="r">Earned (all)</th>
            </tr>
            @foreach ($row['staff'] as $s)
              <tr>
                <td class="nw">{{ $s['employee']->name }}</td>
                <td class="r nw">{{ $s['rate'] > 0 ? Money::tk($s['rate']) : '—' }}</td>
                <td class="r">{{ $s['done_month'] }}</td>
                <td class="r nw">{{ $s['earned_month'] > 0 ? Money::tk($s['earned_month']) : '—' }}</td>
                <td class="r">{{ $s['done'] }}</td>
                <td class="r nw{{ $s['earned'] > 0 ? '' : ' muted' }}">{{ $s['earned'] > 0 ? Money::tk($s['earned']) : '—' }}</td>
              </tr>
            @endforeach
            <tr class="total">
              <td>Total</td>
              <td class="r nw">—</td>
              <td class="r">{{ $row['done_month'] }}</td>
              <td class="r nw">{{ Money::tk($row['earned_month']) }}</td>
              <td class="r">{{ $row['done'] }}</td>
              <td class="r nw">{{ Money::tk($row['earned']) }}</td>
            </tr>
          </table>
        </div>
      @endif
    </section>
  @endforeach
</div>
@endsection