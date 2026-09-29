@php use App\Support\Money; @endphp

{{--
  One employee's earnings broken down by day / week / month / year. Included from
  the admin and manager per-employee earnings pages, so the figures always read the
  same way wherever they are looked at from. (The employee's own dashboard predates
  this partial and keeps its own copy of the same markup, styled together with the
  rest of that page.)

  Expects: $summary  — App\Support\Earnings::summary()'s result
           $history  — ['day' => [...], 'week' => [...], 'month' => [...], 'year' => [...]],
                        each from App\Support\Earnings::breakdown()
--}}
@push('styles')
<style>
  .alltime{display:flex;justify-content:space-between;gap:10px;flex-wrap:wrap;align-items:center;
           background:linear-gradient(135deg,rgba(37,99,235,.1),rgba(79,70,229,.1));
           border:1px solid var(--border);border-radius:18px;padding:14px 18px;margin-bottom:22px}
  .alltime b{font-size:24px;color:var(--accent)}
  .panel{background:var(--panel);border:1px solid var(--border);border-radius:22px;padding:18px 20px;margin-bottom:22px;box-shadow:var(--shadow)}
  .panel h2{font-size:18px;margin:0 0 12px}
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
</style>
@endpush

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
  <span>Total earned so far <span style="color:var(--muted);font-size:14px">({{ $summary['all']['count'] }} {{ Str::plural('topic', $summary['all']['count']) }})</span></span>
  <b>{{ Money::tk($summary['all']['amount']) }}</b>
</div>

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

@push('scripts')
<script>
// Earnings history tabs — several copies of this partial can be on one page in
// principle, so scope each set of tabs to its own panel rather than querying the
// whole document.
document.querySelectorAll('[data-tab]').forEach(b => {
  b.addEventListener('click', () => {
    const panel = b.closest('.panel');
    panel.querySelectorAll('[data-tab]').forEach(x => x.classList.toggle('on', x === b));
    panel.querySelectorAll('[data-hist]').forEach(h => h.classList.toggle('on', h.dataset.hist === b.dataset.tab));
  });
});
</script>
@endpush
