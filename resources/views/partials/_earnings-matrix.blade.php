@php use App\Support\Money; @endphp

{{-- Creator x channel earnings matrix, shared by the admin and manager earnings pages.
     Expects $channels, $rows, and optionally $rates, $showUsername, $matrixTitle,
     $matrixHint, $employeeEarningsRoute (the name of a route taking an {employee},
     e.g. 'admin.employees.earnings' — the creator's name links to it when given).
     Each $row has: employee, cells[channel_id] => [done, earned], done, earned. --}}
<section class="dash-block emx">
  <h2>{{ $matrixTitle ?? 'Earnings by creator and channel' }}</h2>
  @if (! empty($matrixHint))
    <p class="emx-hint">{{ $matrixHint }}</p>
  @endif

  @if ($rows->isEmpty())
    <p class="emx-empty">
      Nothing has been completed yet, so there is nothing to report. Earnings appear here as soon as an
      creator completes a topic.
    </p>
  @else
    <div class="dash-table-scroll">
      <table class="emx-table">
        <thead>
          <tr>
            <th>Creator</th>
            @foreach ($channels as $c)
              <th class="r">{{ $c->icon }} {{ $c->name }}</th>
            @endforeach
            <th class="r">Total</th>
          </tr>
        </thead>
        <tbody>
          @foreach ($rows as $row)
            <tr>
              <td class="emx-name">
                @if (! empty($employeeEarningsRoute))
                  <a href="{{ route($employeeEarningsRoute, $row['employee']) }}">{{ $row['employee']->name }}</a>
                @else
                  {{ $row['employee']->name }}
                @endif
                @if (! empty($showUsername))
                  <small>{{ $row['employee']->username }}</small>
                @endif
              </td>
              @foreach ($channels as $c)
                @php
                  $cell = $row['cells'][$c->id] ?? ['done' => 0, 'earned' => 0.0];
                  $rate = $rates[$c->id][$row['employee']->id] ?? null;
                @endphp
                <td class="r {{ $cell['done'] > 0 ? '' : 'emx-zero' }}">
                  @if ($cell['done'] > 0)
                    {{-- A topic completed before any rate was ever set for this pair
                         still earned Tk 0 — worth showing as a real figure, not hiding
                         the completion behind the em dash reserved for "nothing done". --}}
                    <b>{{ Money::tk($cell['earned']) }}</b>
                    <span class="emx-n">{{ $cell['done'] }} {{ Str::plural('topic', $cell['done']) }}</span>
                    @if ($rate)
                      <span class="emx-n">{{ Money::tk($rate) }}/topic</span>
                    @endif
                  @elseif ($rate)
                    <span class="emx-n">{{ Money::tk($rate) }}/topic</span>
                  @else
                    &mdash;
                  @endif
                </td>
              @endforeach
              <td class="r">
                <b>{{ $row['earned'] > 0 ? Money::tk($row['earned']) : '—' }}</b>
                <span class="emx-n">{{ $row['done'] }} {{ Str::plural('topic', $row['done']) }}</span>
              </td>
            </tr>
          @endforeach
        </tbody>
        <tfoot>
          <tr class="emx-total">
            <td>Total</td>
            @foreach ($channels as $c)
              <td class="r">{{ Money::tk($rows->sum(fn ($r) => $r['cells'][$c->id]['earned'] ?? 0)) }}</td>
            @endforeach
            <td class="r">{{ Money::tk($rows->sum('earned')) }}</td>
          </tr>
        </tfoot>
      </table>
    </div>
  @endif
</section>
