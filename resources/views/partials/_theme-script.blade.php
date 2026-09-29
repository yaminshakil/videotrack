{{-- The dark-mode toggle, shared by both layouts. Kept in one partial so the
     button and its state cannot drift apart. --}}
<script>
(function () {
  var btn = document.getElementById('theme-toggle');
  if (!btn) return;
  var icon  = btn.querySelector('.tt-icon');
  var label = btn.querySelector('.tt-label');

  var sync = function () {
    var dark = document.documentElement.getAttribute('data-theme') === 'dark';
    if (icon) icon.textContent = dark ? '☀️' : '🌙';
    if (label) label.textContent = dark ? 'Light mode' : 'Dark mode';
  };
  sync();

  btn.addEventListener('click', function () {
    var dark = document.documentElement.getAttribute('data-theme') === 'dark';
    if (dark) {
      document.documentElement.removeAttribute('data-theme');
      try { localStorage.setItem('theme', 'light'); } catch (e) {}
    } else {
      document.documentElement.setAttribute('data-theme', 'dark');
      try { localStorage.setItem('theme', 'dark'); } catch (e) {}
    }
    sync();
  });
})();
</script>
