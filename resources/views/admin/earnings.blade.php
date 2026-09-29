@extends('layouts.app')

@section('title', 'Earnings — Admin')
@section('sidebar', 1)

@php use App\Support\Money; @endphp

@section('body')
<div class="wrap">
  @include('admin._nav')
  @include('admin._topbar')

  <div class="dhead dash-head">
    <div>
      <h1>💰 Earnings</h1>
      <p class="sub">What every creator earned, and which channel it came from. A figure is locked in when a topic is completed, so changing a pay rate later never rewrites past earnings.</p>
    </div>
  </div>

  @include('admin._messages')

  {{-- Month switcher --}}
  <div class="dash-monthbar">
    <div class="nav">
      <a class="arrow {{ $allTime ? 'off' : '' }}" href="{{ route('admin.earnings', ['month' => $report['prevMonth']]) }}" aria-label="Previous month">‹</a>
      <div>
        <h2>{{ $allTime ? 'All time' : $report['start']->format('F Y') }}</h2>
        <div class="range">{{ $allTime ? 'Since the beginning' : $report['start']->format('j M').' – '.$report['end']->format('j M Y') }}</div>
      </div>
      <a class="arrow {{ $allTime ? 'off' : '' }}" href="{{ route('admin.earnings', ['month' => $report['nextMonth']]) }}" aria-label="Next month">›</a>
    </div>
    <div class="jump">
      @unless($allTime || $report['month'] === now()->format('Y-m'))
        <a class="now" href="{{ route('admin.earnings') }}">This month</a>
      @endunless
      <a class="now {{ $allTime ? 'on' : '' }}" href="{{ route('admin.earnings', ['month' => 'all']) }}">{{ $allTime ? '✓ All time' : 'All time' }}</a>
    </div>
  </div>

  <section class="dash-cards">
    <div class="dash-card due">
      <div class="l">Earned {{ $allTime ? 'all time' : 'in '.$report['start']->format('F') }}</div>
      <div class="v">{{ Money::tk($report['periodEarned']) }}</div>
      @unless($allTime)<div class="s">{{ Money::tk($report['allEarned']) }} all time</div>@endunless
    </div>
    <div class="dash-card">
      <div class="l">Topics completed</div>
      <div class="v">{{ $report['periodDone'] }}</div>
      @unless($allTime)<div class="s">{{ $report['allDone'] }} all time</div>@endunless
    </div>
    <div class="dash-card">
      <div class="l">Creators paid</div>
      <div class="v">{{ $report['rows']->count() }}</div>
    </div>
    <div class="dash-card">
      <div class="l">Average per topic</div>
      <div class="v">{{ $report['periodDone'] > 0 ? Money::tk($report['periodEarned'] / $report['periodDone']) : '—' }}</div>
    </div>
  </section>

  @include('partials._earnings-matrix', [
    'channels' => $channels,
    'rows' => $report['rows'],
    'rates' => $rates,
    'showUsername' => true,
    'employeeEarningsRoute' => 'admin.employees.earnings',
  ])

  <p class="dash-foot">
    <a href="{{ route('admin.payroll') }}">→ Go to payroll</a>
  </p>
</div>
@endsection
