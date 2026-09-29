<nav class="anav">
  <div class="anav-brand"><span class="mark">🛡️</span> <span>Video<b>Tracker</b></span></div>

  <div class="anav-group">
    <div class="anav-label">Main</div>
    <div class="anav-links">
      <a href="{{ route('manager.dashboard') }}" class="{{ request()->routeIs('manager.dashboard') ? 'on' : '' }}">📊 <span>Dashboard</span></a>

      <details class="anav-dd anav-dd-main" @if (request()->routeIs('manager.topics*')) open @endif>
        <summary class="{{ request()->routeIs('manager.topics*') ? 'on' : '' }}">🛠️ <span>Topics</span> <span class="arrow">▸</span></summary>
        <div class="anav-sub">
          <a href="{{ route('manager.topics', ['panel' => 'add']) }}"
             class="{{ request()->routeIs('manager.topics*') && request()->query('panel') === 'add' ? 'on' : '' }}">➕ <span>Add Topic</span></a>
          <a href="{{ route('manager.topics', ['panel' => 'show']) }}"
             class="{{ request()->routeIs('manager.topics*') && request()->query('panel') !== 'add' ? 'on' : '' }}">📋 <span>Show Topic</span></a>
        </div>
      </details>
      <a href="{{ route('manager.earnings') }}" class="{{ request()->routeIs('manager.earnings') ? 'on' : '' }}">💰 <span>Earnings</span></a>
    </div>
  </div>

  <div class="anav-group">
    <div class="anav-label">Account</div>
    <div class="anav-links">
      <a href="{{ route('manager.account') }}" class="{{ request()->routeIs('manager.account*') ? 'on' : '' }}">🔑 <span>My account</span></a>
      <a href="{{ route('tracker.index') }}" class="{{ request()->routeIs('tracker.*') ? 'on' : '' }}">📊 <span>Tracker</span></a>
    </div>
  </div>

  <button type="button" id="theme-toggle" class="anav-theme" aria-label="Toggle dark mode" title="Toggle dark mode">
    <span class="tt-icon">🌙</span> <span class="tt-label">Dark mode</span>
  </button>

  <form method="post" action="{{ route('logout') }}">@csrf<button type="submit">↩ Log out</button></form>
</nav>