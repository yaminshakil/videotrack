{{-- In-page feedback for Livewire actions, so a save confirms itself without the
     flash-and-redirect round trip. Reachable from PHP with
     $this->dispatch('toast', message: '...') and from Blade with
     @livewire('flash-toast', ...) style events; see app/Livewire/Concerns/Toasts. --}}
<div id="toast-host" class="toast-host" aria-live="polite" aria-atomic="true"></div>

@push('scripts')
<script>
(function () {
  var host = document.getElementById('toast-host');
  if (!host) return;

  var show = function (message, kind) {
    if (!message) return;
    var el = document.createElement('div');
    el.className = 'toast toast-' + (kind || 'ok');
    el.textContent = message;
    host.appendChild(el);
    // next frame so the entry transition actually runs
    requestAnimationFrame(function () { el.classList.add('in'); });
    setTimeout(function () {
      el.classList.remove('in');
      setTimeout(function () { el.remove(); }, 300);
    }, 3200);
  };

  window.appToast = show;

  document.addEventListener('livewire:init', function () {
    Livewire.on('toast', function (e) { show(e.detail.message, e.detail.kind); });
    Livewire.on('toast-error', function (e) { show(e.detail.message, 'err'); });
  });
})();
</script>
@endpush
