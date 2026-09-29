@extends('layouts.app')

@section('title', 'Earnings — Manager')
@section('sidebar', 1)

@php use App\Support\Money; @endphp

@section('body')
<div class="wrap">
  @include('manager._nav')
  @include('admin._topbar', ['topbarName' => $manager->name, 'topbarRole' => 'Manager'])

  <div class="dhead dash-head">
    <div>
      <h1>💰 Earnings</h1>
      <p class="sub">What creators earned, per channel you manage. Earnings are locked in when a topic is completed, so later rate changes never alter past records.</p>
    </div>
    <div class="actions">
      <a href="{{ route('manager.dashboard') }}" class="ghost">← Dashboard</a>
    </div>
  </div>

  @include('admin._messages')

  {{-- Month switcher --}}
  <div class="dash-monthbar">
    <div class="nav">
      <a class="arrow" href="{{ route('manager.earnings', ['month' => $prevMonth]) }}" aria-label="Previous month">‹</a>
      <div>
        <h2>{{ $start->format('F Y') }}</h2>
        <div class="range">{{ $start->format('j M') }} – {{ $end->format('j M Y') }}</div>
      </div>
      <a class="arrow {{ $isCurrent ? 'off' : '' }}" href="{{ route('manager.earnings', ['month' => $nextMonth]) }}" aria-label="Next month">›</a>
    </div>
    <form class="jump" method="get" action="{{ route('manager.earnings') }}">
      <input type="month" name="month" value="{{ $month }}" max="{{ now()->format('Y-m') }}" aria-label="Choose month">
      <button type="submit">Go</button>
      @unless($month === now()->format('Y-m'))<a class="now" href="{{ route('manager.earnings') }}">This month</a>@endunless
    </form>
  </div>

  <section class="dash-cards">
    <div class="dash-card due">
      <div class="l">Earned in {{ $start->format('F') }}</div>
      <div class="v">{{ Money::tk($monthEarned) }}</div>
      <div class="s">{{ $start->format('F Y') }}</div>
    </div>
    <div class="dash-card">
      <div class="l">Topics done</div>
      <div class="v">{{ $monthDone }}</div>
      <div class="s">in {{ $start->format('F') }}</div>
    </div>
    <div class="dash-card">
      <div class="l">All time earned</div>
      <div class="v">{{ Money::tk($earned) }}</div>
    </div>
  </section>

  @include('partials._channel-cards', [
    'channelStats' => $channelStats,
    'title' => '📺 Your channels',
    'hint' => $managerChannels->count().' assigned to you',
    'topicsRoute' => 'manager.topics',
    'topicsParam' => 'channel',
    'empty' => 'No channels assigned yet — ask the admin to give you access to a channel.',
  ])

  @include('partials._earnings-matrix', [
    'channels' => $managerChannels,
    'rows' => $rows,
    'rates' => $rates,
    'matrixTitle' => 'Earned per creator, per channel',
    'matrixHint' => 'Only the channels assigned to you are included. The small grey line under each figure is the pay rate for that channel.',
    'employeeEarningsRoute' => 'manager.employees.earnings',
  ])
</div>
@endsection
