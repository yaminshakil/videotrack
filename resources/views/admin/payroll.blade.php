@extends('layouts.app')

@section('title', 'Payroll — Admin')
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

  .cards{display:grid;grid-template-columns:repeat(4,1fr);gap:10px;margin-bottom:12px}
  .card{background:var(--panel);border:1px solid var(--border);border-radius:18px;padding:14px 16px;box-shadow:var(--shadow)}
  .card .l{font-size:13px;color:var(--muted);text-transform:uppercase;letter-spacing:.04em}
  .card .v{font-size:25px;font-weight:800;margin-top:4px}
  .card.due{background:linear-gradient(135deg,rgba(37,99,235,.1),rgba(79,70,229,.1))}
  .card.due .v{color:var(--accent)}
  .card.ok .v{color:var(--done)}
  .card.bonus{background:linear-gradient(135deg,rgba(180,83,9,.09),rgba(217,119,6,.06))}
  .card.bonus .v{color:var(--warn)}
  .arrears{background:rgba(180,83,9,.08);border:1px solid rgba(180,83,9,.3);border-radius:12px;padding:10px 14px;
           font-size:15px;margin-bottom:16px;color:var(--warn)}

  .emp{background:var(--panel);border:1px solid var(--border);border-radius:22px;padding:18px 20px;margin-bottom:14px;box-shadow:var(--shadow)}
  .emp .top{display:flex;justify-content:space-between;align-items:flex-start;gap:10px;flex-wrap:wrap}
  .emp h3{margin:0;font-size:19px}
  .badge2{font-size:13px;font-weight:700;padding:4px 10px;border-radius:999px;white-space:nowrap}
  .b-paid{background:rgba(22,163,74,.12);color:var(--done)}
  .b-partial{background:rgba(180,83,9,.12);color:var(--warn)}
  .b-unpaid{background:rgba(220,38,38,.12);color:var(--danger)}
  .b-none,.b-over{background:var(--chip-bg);color:var(--chip-text)}
  .nums{display:grid;grid-template-columns:repeat(4,1fr);gap:10px;margin:12px 0}
  .nums div{font-size:14px;color:var(--muted)}
  .nums b{display:block;font-size:20px;color:var(--text);margin-top:2px}
  .nums .dueN b{color:var(--accent)}
  .nums .bonusN b{color:var(--warn)}
  .payform{display:grid;grid-template-columns:1fr 1fr 1.6fr auto;gap:8px;align-items:end;border-top:1px solid var(--border);padding-top:12px}
  .payform label{display:block;font-size:13px;color:var(--muted);margin-bottom:4px}
  .payform input{padding:10px 11px;font-size:15px}
  .payform button{width:auto;padding:11px 16px;font-size:15px;white-space:nowrap}
  .settled{border-top:1px solid var(--border);padding-top:12px;font-size:15px;color:var(--muted)}
  .plist{margin-top:12px;font-size:15px}
  .plist .p{display:flex;justify-content:space-between;align-items:center;gap:10px;padding:8px 0;border-top:1px solid var(--border)}
  .plist .p form{margin:0}
  .plist .p button{width:auto;padding:5px 10px;font-size:13px;background:transparent;border:1px solid var(--border);color:var(--muted);font-weight:600}
  .plist .p button:hover{border-color:var(--danger);color:var(--danger)}
  .plist .amt{font-weight:700;white-space:nowrap}
  .plist .note{color:var(--muted);overflow-wrap:anywhere}
  .bonusform{display:grid;grid-template-columns:1fr 2.2fr auto;gap:8px;align-items:end;
             border-top:1px solid var(--border);padding-top:12px;margin-top:12px}
  .bonusform label{display:block;font-size:13px;color:var(--muted);margin-bottom:4px}
  .bonusform input{padding:10px 11px;font-size:15px}
  .bonusform button{width:auto;padding:11px 16px;font-size:15px;white-space:nowrap}
  .bform-off{margin-top:12px}
  .bform-off summary{cursor:pointer;font-size:15px;color:var(--accent)}
  .bform-off[open] summary{margin-bottom:2px}
  .brow{display:flex;justify-content:space-between;align-items:center;gap:10px;
        padding:8px 0;border-top:1px solid var(--border)}
  .brow form{margin:0}
  .brow button{width:auto;padding:5px 10px;font-size:13px;background:transparent;border:1px solid var(--border);color:var(--muted);font-weight:600}
  .brow button:hover{border-color:var(--danger);color:var(--danger)}
  details.work{margin-top:10px}
  details.work summary{cursor:pointer;font-size:15px;color:var(--accent)}
  .tscroll{overflow-x:auto;-webkit-overflow-scrolling:touch}
  table{width:100%;border-collapse:collapse;font-size:15px}
  th,td{padding:8px 8px;text-align:left;border-bottom:1px solid var(--border)}
  th{color:var(--muted);font-weight:600;font-size:13px;text-transform:uppercase;letter-spacing:.03em}
  td.r,th.r{text-align:right}
  td.nw{white-space:nowrap}
  a.vl{color:var(--accent);text-decoration:none;overflow-wrap:anywhere}
  .block{background:var(--panel);border:1px solid var(--border);border-radius:22px;padding:18px 20px;margin:22px 0;box-shadow:var(--shadow)}
  .block h2{font-size:18px;margin:0 0 10px}
  tr.cur td{background:rgba(37,99,235,.06)}
  @media(max-width:700px){
    .cards{grid-template-columns:1fr}
    .nums{grid-template-columns:1fr 1fr}
    .payform{grid-template-columns:1fr 1fr}
    .payform .note-f,.payform button{grid-column:1/-1}
    .payform button{width:100%}
    .bonusform{grid-template-columns:1fr 1fr}
    .bonusform .why,.bonusform button{grid-column:1/-1}
    .bonusform button{width:100%}
    .monthbar h2{min-width:0;font-size:21px}
    .pwrap{padding:16px 14px 40px}
  }
</style>
@endpush

@section('body')
<div class="pwrap">
  @include('admin._nav')
  @include('admin._topbar')

  <h1>💰 Payroll</h1>
  <div class="tagline">Pay each employee monthly, for the 1st to the last day of the month. Earnings are locked in when a topic is completed, so changing a rate later never alters a past month. Bonuses add on top of those earnings and are paid out the same way.</div>

  @include('admin._messages')

  {{-- Month switcher --}}
  <div class="monthbar">
    <div class="nav">
      <a class="arrow" href="{{ route('admin.payroll', ['month' => $prevMonth]) }}" aria-label="Previous month">‹</a>
      <div>
        <h2>{{ $start->format('F Y') }}</h2>
        <div class="range">{{ $start->format('j M') }} – {{ $end->format('j M Y') }} ({{ $start->daysInMonth }} days)</div>
      </div>
      <a class="arrow {{ $isCurrent ? 'off' : '' }}" href="{{ route('admin.payroll', ['month' => $nextMonth]) }}" aria-label="Next month">›</a>
    </div>
    <form class="jump" method="get" action="{{ route('admin.payroll') }}">
      <input type="month" name="month" value="{{ $month }}" max="{{ now()->format('Y-m') }}" aria-label="Choose month">
      <button type="submit">Go</button>
      @unless($month === now()->format('Y-m'))<a class="now" href="{{ route('admin.payroll') }}">This month</a>@endunless
    </form>
  </div>

  <section class="cards">
    <div class="card"><div class="l">Earned in {{ $start->format('F') }}</div><div class="v">{{ Money::tk($totalEarned) }}</div></div>
    <div class="card {{ $totalBonus > 0 ? 'bonus' : '' }}"><div class="l">Bonuses</div><div class="v">{{ Money::tk($totalBonus) }}</div></div>
    <div class="card"><div class="l">Paid so far</div><div class="v">{{ Money::tk($totalPaid) }}</div></div>
    <div class="card {{ $totalDue <= 0 ? 'ok' : 'due' }}"><div class="l">{{ $totalDue <= 0 ? 'Status' : 'Still to pay' }}</div>
      <div class="v">{{ $totalDue <= 0 ? ($totalEarned + $totalBonus > 0 ? '✓ All paid' : '—') : Money::tk($totalDue) }}</div></div>
  </section>

  @if ($totalArrears > 0)
    <div class="arrears">⚠ Unpaid from earlier months: <b>{{ Money::tk($totalArrears) }}</b> — use the ‹ arrow to go back and pay it.</div>
  @endif

  @if ($rows->isEmpty())
    <p class="muted">No employees yet. <a class="vl" href="{{ route('admin.employees.index') }}">Add one</a>.</p>
  @endif

  {{-- One card per employee --}}
  @foreach ($rows as $r)
    @php $e = $r['employee']; @endphp
    <section class="emp" id="emp-{{ $e->id }}">
      <div class="top">
        <h3>{{ $e->name }}@unless($e->is_active) <span class="muted" style="font-size:14px;font-weight:400">(inactive)</span>@endunless</h3>
        <span class="badge2 b-{{ $r['status'] }}">
          {{ ['paid' => '✓ Paid in full', 'partial' => 'Partly paid', 'unpaid' => 'Not paid yet', 'none' => 'Nothing earned', 'over' => 'Overpaid'][$r['status']] }}
        </span>
      </div>

      <div class="nums">
        <div>Earned<b>{{ Money::tk($r['earned']) }}</b><span>{{ $r['topics']->count() }} {{ Str::plural('topic', $r['topics']->count()) }}</span></div>
        <div class="bonusN">Bonus<b>{{ Money::tk($r['bonus']) }}</b><span>{{ $r['bonuses']->count() ? $r['bonuses']->count() . ' ' . Str::plural('bonus', $r['bonuses']->count()) : '—' }}</span></div>
        <div>Paid<b>{{ Money::tk($r['paid']) }}</b></div>
        <div class="dueN">{{ $r['due'] < 0 ? 'Overpaid by' : 'Still to pay' }}<b>{{ Money::tk(abs($r['due'])) }}</b></div>
      </div>

      @if ($r['arrears'] > 0)
        <div class="muted" style="font-size:14px;margin-bottom:10px;color:var(--warn)">+ {{ Money::tk($r['arrears']) }} unpaid from earlier months</div>
      @endif

      @if ($r['due'] > 0)
        <form class="payform" method="post" action="{{ route('admin.payments.store') }}">
          @csrf
          <input type="hidden" name="form" value="payment">
          <input type="hidden" name="employee_id" value="{{ $e->id }}">
          <input type="hidden" name="period" value="{{ $month }}">
          <div><label for="a{{ $e->id }}">Amount paid (Tk)</label>
            <input id="a{{ $e->id }}" type="number" name="amount" step="0.01" min="0.01" max="{{ $r['due'] }}" value="{{ old('form') === 'payment' && old('employee_id') == $e->id ? old('amount') : number_format($r['due'], 2, '.', '') }}" required></div>
          <div><label for="d{{ $e->id }}">Date paid</label>
            <input id="d{{ $e->id }}" type="date" name="paid_on" max="{{ now()->toDateString() }}" value="{{ now()->toDateString() }}" required></div>
          <div class="note-f"><label for="n{{ $e->id }}">Note (optional)</label>
            <input id="n{{ $e->id }}" type="text" name="note" maxlength="255" value="{{ old('form') === 'payment' && old('employee_id') == $e->id ? old('note') : '' }}" placeholder="e.g. bKash, cash…"></div>
          <button type="submit">Record payment</button>
        </form>
      @elseif ($r['earned'] > 0 || $r['bonus'] > 0)
        <div class="settled">✓ Nothing left to pay for {{ $start->format('F Y') }}.</div>
      @else
        <div class="settled">No completed topics in {{ $start->format('F Y') }}.</div>
      @endif

      @if ($r['payments']->isNotEmpty())
        <div class="plist">
          @foreach ($r['payments'] as $p)
            <div class="p">
              <div>
                <span class="amt">{{ Money::tk($p->amount) }}</span>
                <span class="muted"> · paid {{ $p->paid_on->format('j M Y') }}</span>
                @if ($p->note)<span class="note"> · {{ $p->note }}</span>@endif
              </div>
              <form method="post" action="{{ route('admin.payments.destroy', $p) }}" onsubmit="return confirm('Remove this {{ Money::tk($p->amount) }} payment? The amount will count as unpaid again.')">
                @csrf @method('DELETE')
                <button type="submit">Remove</button>
              </form>
            </div>
          @endforeach
        </div>
      @endif

      {{-- Bonuses. Always available, even for a month already settled: a bonus is a
           reward, so it is not something the page withholds on account of the balance. --}}
      @if ($r['bonuses']->isNotEmpty())
        <div class="plist">
          @foreach ($r['bonuses'] as $b)
            <div class="brow">
              <div>
                <span class="amt">{{ Money::tk($b->amount) }}</span>
                <span class="note"> · {{ $b->note }}</span>
              </div>
              <form method="post" action="{{ route('admin.bonuses.destroy', $b) }}" onsubmit="return confirm('Remove this {{ Money::tk($b->amount) }} bonus? It will stop counting towards what {{ $e->name }} is owed.')">
                @csrf @method('DELETE')
                <button type="submit">Remove</button>
              </form>
            </div>
          @endforeach
        </div>
      @endif

      @php $bonusOpen = old('form') === 'bonus' && old('employee_id') == $e->id; @endphp
      @if ($month === now()->format('Y-m'))
        <details class="bform-off" @if ($bonusOpen || $r['bonuses']->isNotEmpty()) open @endif>
          <summary>🎁 Give {{ $e->name }} a bonus</summary>
          <form class="bonusform" method="post" action="{{ route('admin.bonuses.store') }}">
            @csrf
            <input type="hidden" name="form" value="bonus">
            <input type="hidden" name="employee_id" value="{{ $e->id }}">
            <div><label for="ba{{ $e->id }}">Bonus amount (Tk)</label>
              <input id="ba{{ $e->id }}" type="number" name="amount" step="0.01" min="0.01" max="99999999"
                     value="{{ $bonusOpen ? old('amount') : '' }}" placeholder="e.g. 500" required></div>
            <div class="why"><label for="bn{{ $e->id }}">Reason (required)</label>
              <input id="bn{{ $e->id }}" type="text" name="note" maxlength="255" value="{{ $bonusOpen ? old('note') : '' }}"
                     placeholder="e.g. Best performer of September" required></div>
            <button type="submit">Give bonus</button>
          </form>
          <div class="muted" style="font-size:13px;margin-top:6px">Raises what {{ $e->name }} is owed for {{ now()->format('F Y') }} — it still has to be paid out like any other earning.</div>
        </details>
      @else
        <div class="muted" style="font-size:14px;margin-top:12px">🎁 Bonuses are given for the current month, not a past one.
          @if ($r['bonuses']->isNotEmpty()){{ $r['bonuses']->count() }} {{ Str::plural('bonus', $r['bonuses']->count()) }} for {{ $e->name }} {{ $r['bonuses']->count() === 1 ? 'was' : 'were' }} given back then.@else
            <a class="vl" href="{{ route('admin.payroll') }}">Open {{ now()->format('F Y') }}</a> to give one.
          @endif
        </div>
      @endif

      @if ($r['topics']->isNotEmpty())
        <details class="work">
          <summary>Show the {{ $r['topics']->count() }} completed {{ Str::plural('topic', $r['topics']->count()) }}</summary>
          <div class="tscroll">
            <table>
              <tr><th>Completed</th><th>Topic</th><th>Video</th><th class="r">Earned</th></tr>
              @foreach ($r['topics'] as $t)
                <tr>
                  <td class="nw">{{ $t->completed_at->format('j M, g:i A') }}</td>
                  <td>{{ $t->channel->icon }} {{ $t->title }}</td>
                  <td>@if ($t->video_url)<a class="vl" href="{{ $t->video_url }}" target="_blank" rel="noopener">{{ $t->video_title ?: $t->video_url }}</a>@else<span class="muted">—</span>@endif</td>
                  <td class="r nw">{{ Money::tk($t->earned_amount) }}</td>
                </tr>
              @endforeach
            </table>
          </div>
        </details>
      @endif
    </section>
  @endforeach

  {{-- Month by month --}}
  <section class="block">
    <h2>Month by month</h2>
    <div class="tscroll">
      <table>
        <tr><th>Month</th><th class="r">Earned</th><th class="r">Bonus</th><th class="r">Paid</th><th class="r">Still to pay</th></tr>
        @foreach ($history as $h)
          <tr class="{{ $h['month'] === $month ? 'cur' : '' }}">
            <td><a class="vl" href="{{ route('admin.payroll', ['month' => $h['month']]) }}">{{ $h['label'] }}</a></td>
            <td class="r nw">{{ Money::tk($h['earned']) }}</td>
            <td class="r nw">{{ $h['bonus'] > 0 ? Money::tk($h['bonus']) : '—' }}</td>
            <td class="r nw">{{ Money::tk($h['paid']) }}</td>
            <td class="r nw"><b>{{ $h['due'] > 0 ? Money::tk($h['due']) : ($h['earned'] + $h['bonus'] > 0 || $h['paid'] > 0 ? '✓' : '—') }}</b></td>
          </tr>
        @endforeach
      </table>
    </div>
  </section>
</div>
@endsection
