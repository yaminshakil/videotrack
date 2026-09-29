{{-- Shared <head> for both the Blade pages and the Livewire pages, so the two
     layouts cannot drift apart. Each layout passes $title: a Blade page reads
     its @section('title'), a Livewire page passes a plain variable, because
     sections are not carried through a component's layout. --}}
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<meta name="csrf-token" content="{{ csrf_token() }}">
<title>{{ $title ?? 'Video Tracker' }}</title>
<script>
// Applied before first paint so there's no flash of the wrong theme.
(function () {
  try {
    var t = localStorage.getItem('theme');
    if (t === 'dark' || (!t && window.matchMedia('(prefers-color-scheme: dark)').matches)) {
      document.documentElement.setAttribute('data-theme', 'dark');
    }
  } catch (e) {}
})();
</script>
{{-- ?v= is the file's mtime, so the long cache lifetime set in public/.htaccess
     never serves a stale stylesheet after a deploy. --}}
<link rel="stylesheet" href="{{ asset('css/app.css') }}?v={{ @filemtime(public_path('css/app.css')) }}">
<link rel="stylesheet" href="{{ asset('css/dashboard.css') }}?v={{ @filemtime(public_path('css/dashboard.css')) }}">
@livewireStyles
@stack('styles')
