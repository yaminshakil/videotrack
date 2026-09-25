<nav class="anav">
  <div class="anav-brand"><span class="mark">🤖</span> <span>Topic<b>Tracker</b></span></div>

  <div class="anav-group">
    <div class="anav-label">Main</div>
    <div class="anav-links">
      <a href="{{ route('admin.dashboard') }}" class="{{ request()->routeIs('admin.dashboard') ? 'on' : '' }}">📊 <span>Dashboard</span></a>
      <a href="{{ route('admin.topics.index') }}" class="{{ request()->routeIs('admin.topics.*') ? 'on' : '' }}">🛠️ <span>Topics</span></a>
      <a href="{{ route('admin.employees.index') }}" class="{{ request()->routeIs('admin.employees.*') ? 'on' : '' }}">👥 <span>Employees</span></a>
      <a href="{{ route('admin.managers.index') }}" class="{{ request()->routeIs('admin.managers.*') ? 'on' : '' }}">🛡️ <span>Managers</span></a>
      <a href="{{ route('admin.payroll') }}" class="{{ request()->routeIs('admin.payroll') ? 'on' : '' }}">💰 <span>Payroll</span></a>
    </div>
  </div>

  <div class="anav-group">
    <div class="anav-label">Account</div>
    <div class="anav-links">
      <a href="{{ route('tracker.index') }}" class="{{ request()->routeIs('tracker.*') ? 'on' : '' }}">📋 <span>Tracker</span></a>
    </div>
  </div>

  <button type="button" id="theme-toggle" class="anav-theme" aria-label="Toggle dark mode" title="Toggle dark mode">
    <span class="tt-icon">🌙</span> <span class="tt-label">Dark mode</span>
  </button>

  <form method="post" action="{{ route('logout') }}">@csrf<button type="submit">↩ Log out</button></form>
</nav>