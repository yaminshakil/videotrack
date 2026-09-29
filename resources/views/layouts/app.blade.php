<!DOCTYPE html>
<html lang="en">
<head>
@php($headTitle = trim($__env->yieldContent('title')) ?: 'Video Tracker')
@include('partials._head', ['title' => $headTitle])
</head>
<body @hasSection('sidebar') class="has-sidebar" @endif>
@yield('body')

@hasSection('sidebar')
@else
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
