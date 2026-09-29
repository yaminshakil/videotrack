@php use App\Support\Money; @endphp

{{-- Per-channel summary cards. Expects $channelStats, and optionally $title,
     $hint, $topicsRoute (route name for the per-channel topics link) and
     $topicsParam (the query key the route expects the channel id in). --}}
<section class="dash-block">
  <div class="dash-block-head">
    <h2>{{ $title ?? 'Your channels' }}</h2>
    @if (! empty($hint))
      <span class="dash-block-hint">{{ $hint }}</span>
    @endif
  </div>

  @if ($channelStats->isEmpty())
    <p class="dash-empty">
      {{ $empty ?? 'No channels yet. Channels are created by the seeder or from the admin panel.' }}
    </p>
  @else
    <div class="chans">
      @foreach ($channelStats as $row)
        <article class="chancard">
          <header>
            <h3>{{ $row['channel']->icon }} {{ $row['channel']->name }}</h3>
            <span class="chancard-badge">{{ $row['channel']->badge }}</span>
          </header>

          <div class="chancard-bar" role="img"
               aria-label="{{ $row['done'] }} of {{ $row['total'] }} topics completed">
            <span style="width:{{ $row['total'] > 0 ? round($row['done'] / $row['total'] * 100) : 0 }}%"></span>
          </div>

          <div class="nums">
            <div class="n">Topics<b>{{ $row['total'] }}</b></div>
            <div class="n">Done<b>{{ $row['done'] }}</b></div>
            <div class="n">Pending<b>{{ $row['pending'] }}</b></div>
            <div class="n">Staff<b>{{ $row['staff'] }}</b></div>
            <div class="n earned">Earned<b>{{ Money::tk($row['earned']) }}</b></div>
            <div class="n">This month<b>{{ Money::tk($row['earnedMonth']) }}</b></div>
          </div>

          @if ($row['unassigned'] > 0)
            <p class="chancard-warn">{{ $row['unassigned'] }} unassigned</p>
          @endif

          @if (! empty($topicsRoute))
            <a class="chancard-link" href="{{ route($topicsRoute, ! empty($topicsParam) ? [$topicsParam => $row['channel']->id] : []) }}">
              Manage topics →
            </a>
          @endif
        </article>
      @endforeach
    </div>
  @endif
</section>
