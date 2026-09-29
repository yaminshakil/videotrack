@extends('layouts.app')

@section('title', 'My Topics & Earnings')
@section('sidebar', 1)

@php use App\Support\Money; @endphp

@push('styles')
<style>
  h1{font-size:24px;margin:0 0 4px;letter-spacing:0}
  .sub{font-size:15px}

  .cards{display:grid;grid-template-columns:repeat(4,1fr);gap:10px;margin-bottom:12px}
  .card{background:var(--panel);border:1px solid var(--border);border-radius:18px;padding:14px 16px;box-shadow:var(--shadow)}
  .card .l{font-size:13px;color:var(--muted);text-transform:uppercase;letter-spacing:.04em}
  .card .v{font-size:24px;font-weight:800;margin:4px 0 2px}
  .card .n{font-size:14px;color:var(--muted)}
  .alltime{display:flex;justify-content:space-between;gap:10px;flex-wrap:wrap;align-items:center;
           background:linear-gradient(135deg,rgba(37,99,235,.1),rgba(79,70,229,.1));
           border:1px solid var(--border);border-radius:18px;padding:14px 18px;margin-bottom:22px}
  .alltime b{font-size:24px;color:var(--accent)}

  .panel{background:var(--panel);border:1px solid var(--border);border-radius:22px;padding:18px 20px;margin-bottom:22px;box-shadow:var(--shadow)}
  .panel h2{font-size:18px;margin:0 0 12px}
  .monthform{margin-bottom:12px}
  .monthform input{width:auto;padding:9px 12px;border-radius:10px;border:1px solid var(--border);
        background:var(--panel2);color:var(--text);font-size:15px}
  .monthres{display:grid;grid-template-columns:1fr 1fr;gap:10px}
  .mcard{background:var(--panel2);border:1px solid var(--border);border-radius:18px;padding:14px 16px}
  .mcard .l{font-size:13px;color:var(--muted);text-transform:uppercase;letter-spacing:.04em}
  .mcard .v{font-size:24px;font-weight:800;margin:4px 0 2px}
  .mcard .n{font-size:14px;color:var(--muted)}
  .tabs{display:flex;gap:6px;flex-wrap:wrap;margin-bottom:12px}
  .tabs button{width:auto;padding:7px 14px;font-size:14px;font-weight:600;background:transparent;border:1px solid var(--border);color:var(--muted)}
  .tabs button.on{background:linear-gradient(135deg,var(--accent),var(--accent2));border-color:transparent;color:#fff}
  .hist{display:none}
  .hist.on{display:block}
  table.h{width:100%;border-collapse:collapse;font-size:15px}
  table.h th,table.h td{padding:8px 6px;text-align:left;border-bottom:1px solid var(--border)}
  table.h th{color:var(--muted);font-size:13px;font-weight:600;text-transform:uppercase}
  table.h td:last-child,table.h th:last-child,table.h td:nth-child(2),table.h th:nth-child(2){text-align:right}
  .none{color:var(--muted);font-size:15px;padding:6px 0}
  .flashmsg{padding:11px 13px;border-radius:11px;font-size:15px;margin-bottom:14px}
  .flashmsg.ok{background:rgba(22,163,74,.1);border:1px solid rgba(22,163,74,.35);color:var(--ok-text)}
  .flashmsg.err{background:rgba(239,68,68,.1);border:1px solid rgba(239,68,68,.35);color:var(--err-text)}

  @media(max-width:700px){
    .cards{grid-template-columns:1fr 1fr}
    .wrap{padding:20px 14px 40px}
    .monthres{grid-template-columns:1fr}
  }
</style>
@endpush

@section('body')
<div class="wrap">
  @include('employee._nav')
  @include('admin._topbar', ['topbarName' => $employee->name, 'topbarRole' => 'Creator'])

  <div class="dhead">
    <div>
      <h1>Hi, {{ $employee->name }} 👋</h1>
      <p class="sub" style="margin-top:6px">Here's how your work is going. Head to <a href="{{ route('employee.topics') }}" style="color:var(--accent)">My Topics</a> to add video links and mark topics done.</p>
    </div>
  </div>

  @if (session('ok'))<div class="flashmsg ok">{{ session('ok') }}</div>@endif
  @if ($errors->has('video'))<div class="flashmsg err">{{ $errors->first('video') }}</div>@endif

  {{-- Earnings --}}
  <section class="stats2">
    @foreach ([
      ['k' => 'today', 'label' => 'Today', 'icon' => '🕐', 'tone' => 'violet'],
      ['k' => 'week', 'label' => 'This Week', 'icon' => '📅', 'tone' => 'blue'],
      ['k' => 'month', 'label' => 'This Month', 'icon' => '🗓️', 'tone' => 'teal'],
      ['k' => 'year', 'label' => 'This Year', 'icon' => '📆', 'tone' => 'amber'],
    ] as $c)
      <div class="stat2">
        <div class="top">
          <div>
            <div class="val">{{ Money::tk($summary[$c['k']]['amount']) }}</div>
            <div class="lbl">{{ $c['label'] }}</div>
          </div>
          <div class="icon tone-{{ $c['tone'] }}">{{ $c['icon'] }}</div>
        </div>
        <div class="delta">{{ $summary[$c['k']]['count'] }} {{ Str::plural('topic', $summary[$c['k']]['count']) }}</div>
      </div>
    @endforeach
  </section>
  <div class="alltime">
    <span>Total earned so far <span class="muted" style="color:var(--muted);font-size:14px">({{ $summary['all']['count'] }} {{ Str::plural('topic', $summary['all']['count']) }})</span></span>
    <b>{{ Money::tk($summary['all']['amount']) }}</b>
  </div>

  <section class="panel">
    <h2>Pick a month</h2>
    <form method="get" class="monthform">
      <input type="month" name="month" value="{{ $monthPick['value'] }}" max="{{ now()->format('Y-m') }}" onchange="this.form.submit()">
    </form>
    <div class="monthres">
      <div class="mcard">
        <div class="l">{{ $monthPick['label'] }}</div>
        <div class="v">{{ $monthPick['videos_uploaded'] }}</div>
        <div class="n">{{ Str::plural('video', $monthPick['videos_uploaded']) }} uploaded</div>
      </div>
      <div class="mcard">
        <div class="l">{{ $monthPick['label'] }}</div>
        <div class="v">{{ Money::tk($monthPick['earned']) }}</div>
        <div class="n">earned · {{ $monthPick['topics_done'] }} {{ Str::plural('topic', $monthPick['topics_done']) }} done</div>
      </div>
    </div>
  </section>

  <section class="panel">
    <h2>Earnings history</h2>
    <div class="tabs" role="tablist">
      @foreach (['day' => 'Daily', 'week' => 'Weekly', 'month' => 'Monthly', 'year' => 'Yearly'] as $k => $label)
        <button type="button" data-tab="{{ $k }}" class="{{ $k === 'day' ? 'on' : '' }}">{{ $label }}</button>
      @endforeach
    </div>
    @foreach (['day' => 'Day', 'week' => 'Week', 'month' => 'Month', 'year' => 'Year'] as $k => $col)
      <div class="hist {{ $k === 'day' ? 'on' : '' }}" data-hist="{{ $k }}">
        @if (empty($history[$k]))
          <div class="none">No completed topics yet.</div>
        @else
          <table class="h">
            <tr><th>{{ $col }}</th><th>Topics</th><th>Earned</th></tr>
            @foreach ($history[$k] as $r)
              <tr><td>{{ $r['label'] }}</td><td>{{ $r['count'] }}</td><td><b>{{ Money::tk($r['amount']) }}</b></td></tr>
            @endforeach
          </table>
        @endif
      </div>
    @endforeach
  </section>
</div>
@endsection

@push('scripts')
<script>
// History tabs
document.querySelectorAll('.tabs button').forEach(b => b.addEventListener('click', () => {
  document.querySelectorAll('.tabs button').forEach(x => x.classList.toggle('on', x === b));
  document.querySelectorAll('.hist').forEach(h => h.classList.toggle('on', h.dataset.hist === b.dataset.tab));
}));
</script>
@endpush
