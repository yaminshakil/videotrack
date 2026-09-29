@php use App\Support\Money; @endphp

@push('styles')
<style>
  .admin-wrap{max-width:1100px;margin:0 auto;padding:30px 20px 50px}
</style>
@endpush

<div class="wrap">
  @include('admin._nav')
  @include('admin._topbar')

  <div class="dhead dash-head">
    <div>
      <h1>Dashboard</h1>
      <p class="sub">Everything happening across all channels, and the quickest way to each job.</p>
    </div>
    <div class="actions">
      <a href="{{ route('admin.earnings') }}" class="ghost">📈 Earnings</a>
      <a href="{{ route('admin.payroll') }}" class="ghost">💰 Payroll</a>
      <a href="{{ route('admin.topics.index', ['panel' => 'add']) }}" class="solid">＋ Add topic</a>
    </div>
  </div>

  @include('admin._messages')

  <section class="stats2">
    <div class="stat2">
      <div class="top">
        <div>
          <div class="val">{{ $stats['employees']['value'] }}</div>
          <div class="lbl">Total Creators</div>
        </div>
        <div class="icon tone-{{ $stats['employees']['tone'] }}">{{ $stats['employees']['icon'] }}</div>
      </div>
      <div class="delta">{{ $stats['employees']['sub'] }}</div>
    </div>

    <div class="stat2">
      <div class="top">
        <div>
          <div class="val">{{ Money::tk($stats['earned']['value']) }}</div>
          <div class="lbl">Earned This Month</div>
        </div>
        <div class="icon tone-{{ $stats['earned']['tone'] }}">{{ $stats['earned']['icon'] }}</div>
      </div>
      <div class="delta {{ $stats['earned']['trend'] }}">
        @if ($stats['earned']['trend'] === 'up') ↑ @elseif ($stats['earned']['trend'] === 'down') ↓ @endif
        {{ $stats['earned']['sub'] }}
      </div>
    </div>

    <div class="stat2">
      <div class="top">
        <div>
          <div class="val">{{ $stats['completed']['value'] }}</div>
          <div class="lbl">Completed This Month</div>
        </div>
        <div class="icon tone-{{ $stats['completed']['tone'] }}">{{ $stats['completed']['icon'] }}</div>
      </div>
      <div class="delta {{ $stats['completed']['trend'] }}">
        @if ($stats['completed']['trend'] === 'up') ↑ @elseif ($stats['completed']['trend'] === 'down') ↓ @endif
        {{ $stats['completed']['sub'] }}
      </div>
    </div>

    <div class="stat2">
      <div class="top">
        <div>
          <div class="val">{{ $stats['rate']['value'] }}%</div>
          <div class="lbl">Completion Rate</div>
        </div>
        <div class="icon tone-{{ $stats['rate']['tone'] }}">{{ $stats['rate']['icon'] }}</div>
      </div>
      <div class="delta">{{ $stats['rate']['sub'] }}</div>
    </div>
  </section>

  <div class="dash-cols">
    <div class="dash-main">
      <section class="dash-block">
        <div class="dash-block-head">
          <h2>🕓 Recent activity</h2>
          <a href="{{ route('admin.topics.index') }}" class="chancard-link">View all topics →</a>
        </div>

        @if ($recent->isEmpty())
          <p class="dash-empty">No topics yet. Add the first one to get going.</p>
        @else
          <div class="dash-table-scroll">
            <table class="rtable">
              <tr>
                <th>Topic</th>
                <th class="hide-sm">Assigned to</th>
                <th class="hide-sm">Updated</th>
                <th>Earned</th>
                <th>Status</th>
                <th></th>
              </tr>
              @foreach ($recent as $t)
                <tr>
                  <td>
                    <span class="t-main">{{ Str::limit($t->title, 44) }}</span>
                    <span class="t-sub">{{ $t->channel->icon }} {{ $t->channel->name }}</span>
                  </td>
                  <td class="nw hide-sm">{{ $t->employee->name ?? '—' }}</td>
                  <td class="nw hide-sm">{{ $t->updated_at->format('j M Y') }}</td>
                  <td class="nw">{{ $t->is_done ? Money::tk($t->earned_amount) : '—' }}</td>
                  <td>
                    @if ($t->is_done)
                      <span class="pill pill-done">● Done</span>
                    @else
                      <span class="pill pill-pending">● Pending</span>
                    @endif
                  </td>
                  <td>
                    @if ($t->video_url)
                      <a class="btn-view" href="{{ $t->video_url }}" target="_blank" rel="noopener">👁 View</a>
                    @else
                      <span style="font-size:14px;color:var(--muted)">—</span>
                    @endif
                  </td>
                </tr>
              @endforeach
            </table>
          </div>
        @endif
      </section>
    </div>

    <div class="dash-side">
      @include('partials._channel-cards', [
        'channelStats' => $channelStats,
        'title' => '📺 Channels',
        'hint' => $channels->count().' in total',
        'topicsRoute' => 'admin.topics.index',
      ])

      <section class="dash-block">
        <div class="dash-block-head"><h2>⚡ Quick actions</h2></div>
        <div class="chans">
          <a class="chancard-link" href="{{ route('admin.employees.index') }}">👥 Creators &amp; rates</a>
          <a class="chancard-link" href="{{ route('admin.managers.index') }}">🛡️ Managers</a>
          <a class="chancard-link" href="{{ route('admin.earnings') }}">📈 Earnings by channel</a>
          <a class="chancard-link" href="{{ route('admin.payroll') }}">💰 Payroll</a>
          @if (Auth::guard('admin')->check())
            <a class="chancard-link" href="{{ route('admin.account') }}">🔑 My account</a>
          @endif
        </div>
      </section>
    </div>
  </div>

  <livewire:rate-editor />
</div>
