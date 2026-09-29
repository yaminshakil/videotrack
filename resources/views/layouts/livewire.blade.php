{{-- Livewire's layout: the component HTML arrives as $slot instead of a
     @yield('body') section, which is what Livewire can re-render. Everything
     else is shared with layouts/app.blade.php through the _head partial.
     Expects optional $title and $sidebar (default true). --}}
<!DOCTYPE html>
<html lang="en">
<head>
@include('partials._head', ['title' => $title ?? 'Video Tracker'])
</head>
<body @class(['has-sidebar' => $sidebar ?? true])>
{{ $slot }}

@unless ($sidebar ?? true)
  <button type="button" id="theme-toggle" class="theme-toggle" aria-label="Toggle dark mode" title="Toggle dark mode">
    <span class="tt-icon">🌙</span>
  </button>
@endunless

@include('partials._toast')
@stack('scripts')
@livewireScripts
@include('partials._theme-script')
</body>
</html>
