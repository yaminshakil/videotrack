{{-- Pay-rate matrix (channel x creator), used by the creators page and both dashboards.
     Expects $rateAction, $rateChannels, $rateEmployees, $rates, and optionally
     $rateTitle / $rateHint / $rateSubmit. --}}
<form class="{{ $rateClass ?? 'block rate-editor' }}" method="post" action="{{ $rateAction }}">
  @csrf
  @method('PUT')

  <h2>{{ $rateTitle ?? 'Pay rate per completed topic (per channel × creator)' }}</h2>
  <p class="muted rate-hint">
    {{ $rateHint ?? 'When a creator completes a topic in a channel, they earn this amount. Leave 0 if no rate.' }}
  </p>

  @if ($rateChannels->isEmpty())
    <p class="muted">There are no channels yet, so there is nothing to set a rate for.</p>
  @elseif ($rateEmployees->isEmpty())
    <p class="muted">There are no active creators yet. Add one on the Creators page first.</p>
  @else
    <div class="tscroll">
      <table class="ratetable">
        <thead>
          <tr>
            <th>Channel</th>
            @foreach ($rateEmployees as $e)
              <th>{{ $e->name }}</th>
            @endforeach
          </tr>
        </thead>
        <tbody>
          @foreach ($rateChannels as $c)
            <tr>
              <th class="nw">{{ $c->icon }} {{ $c->name }}</th>
              @foreach ($rateEmployees as $e)
                @php $amt = $rates[$c->id][$e->id] ?? null; @endphp
                <td>
                  <input type="number" step="0.01" min="0" class="rate-input"
                         name="channels[{{ $c->id }}][{{ $e->id }}]"
                         value="{{ $amt !== null ? number_format($amt, 2, '.', '') : '' }}"
                         placeholder="0.00"
                         aria-label="{{ $c->name }} rate for {{ $e->name }}">
                </td>
              @endforeach
            </tr>
          @endforeach
        </tbody>
      </table>
    </div>

    <button type="submit">{{ $rateSubmit ?? '💾 Save all rates' }}</button>
  @endif
</form>
