<nav class="anav">
  <div class="anav-brand"><span class="mark">🤖</span> <span>Video<b>Tracker</b></span></div>

  <div class="anav-group">
    <div class="anav-label">Main</div>
    <div class="anav-links">
      <a href="{{ route('employee.dashboard') }}" class="{{ request()->routeIs('employee.dashboard') ? 'on' : '' }}">📊 <span>Dashboard</span></a>
      <a href="{{ route('employee.custom-topics') }}" class="{{ request()->routeIs('employee.custom-topics') ? 'on' : '' }}">🧩 <span>Custom Topics</span></a>
      <a href="{{ route('tracker.index') }}" class="{{ request()->routeIs('tracker.*') ? 'on' : '' }}">📋 <span>Tracker</span></a>
    </div>
  </div>

  @if (! empty($navChannels) && $navChannels->isNotEmpty())
    <div class="anav-group">
      <details class="anav-dd" @if (request()->routeIs('employee.topics')) open @endif>
        <summary>📂 <span>Channels</span> <span class="arrow">▸</span></summary>
        <div class="anav-sub">
          @foreach ($navChannels as $c)
            <a href="{{ route('employee.topics', ['channel' => $c->id]) }}"
               class="{{ request()->routeIs('employee.topics') && request()->query('channel') == $c->id ? 'on' : '' }}">
              {{ $c->icon }} <span>{{ $c->name }}</span>
            </a>
          @endforeach
        </div>
      </details>
    </div>
  @endif

  <button type="button" id="theme-toggle" class="anav-theme" aria-label="Toggle dark mode" title="Toggle dark mode">
    <span class="tt-icon">🌙</span> <span class="tt-label">Dark mode</span>
  </button>

  <form method="post" action="{{ route('logout') }}">@csrf<button type="submit">↩ Log out</button></form>
</nav>
