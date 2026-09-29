<nav class="anav">
  <div class="anav-brand"><span class="mark">🤖</span> <span>Video<b>Tracker</b></span></div>

  <div class="anav-group">
    <div class="anav-label">Main</div>
    <div class="anav-links">
      <a href="{{ route('admin.dashboard') }}" class="{{ request()->routeIs('admin.dashboard') ? 'on' : '' }}">📊 <span>Dashboard</span></a>

      <details class="anav-dd anav-dd-main" @if (request()->routeIs('admin.topics.*')) open @endif>
        <summary class="{{ request()->routeIs('admin.topics.*') ? 'on' : '' }}">🛠️ <span>Topics</span> <span class="arrow">▸</span></summary>
        <div class="anav-sub">
          <a href="{{ route('admin.topics.index', ['panel' => 'add']) }}"
             class="{{ request()->routeIs('admin.topics.*') && request()->query('panel') === 'add' ? 'on' : '' }}">➕ <span>Add Topic</span></a>
          <a href="{{ route('admin.topics.index', ['panel' => 'show']) }}"
             class="{{ request()->routeIs('admin.topics.*') && request()->query('panel') !== 'add' ? 'on' : '' }}">📋 <span>Show Topic</span></a>
        </div>
      </details>

      <a href="{{ route('admin.employees.index') }}" class="{{ request()->routeIs('admin.employees.*') ? 'on' : '' }}">👥 <span>Employees</span></a>
      <a href="{{ route('admin.managers.index') }}" class="{{ request()->routeIs('admin.managers.*') ? 'on' : '' }}">🛡️ <span>Managers</span></a>
      <a href="{{ route('admin.earnings') }}" class="{{ request()->routeIs('admin.earnings') ? 'on' : '' }}">📈 <span>Earnings</span></a>
      <a href="{{ route('admin.payroll') }}" class="{{ request()->routeIs('admin.payroll') ? 'on' : '' }}">💰 <span>Payroll</span></a>
    </div>
  </div>

  <div class="anav-group">
    <div class="anav-label">Account</div>
    <div class="anav-links">
      @if (Auth::guard('admin')->check())
        <a href="{{ route('admin.account') }}" class="{{ request()->routeIs('admin.account*') ? 'on' : '' }}">🔑 <span>My account</span></a>
      @endif
      <a href="{{ route('tracker.index') }}" class="{{ request()->routeIs('tracker.*') ? 'on' : '' }}">📊 <span>Tracker</span></a>
    </div>
  </div>

  <button type="button" id="theme-toggle" class="anav-theme" aria-label="Toggle dark mode" title="Toggle dark mode">
    <span class="tt-icon">🌙</span> <span class="tt-label">Dark mode</span>
  </button>

  <form method="post" action="{{ route('logout') }}">@csrf<button type="submit">↩ Log out</button></form>
</nav>