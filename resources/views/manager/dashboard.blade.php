@extends('layouts.app')

@section('title', 'Dashboard — Manager')
@section('sidebar', 1)

@php use App\Support\Money; @endphp

@section('body')
<div class="wrap">
  @include('manager._nav')
  @include('admin._topbar', ['topbarName' => $manager->name, 'topbarRole' => 'Manager'])

  <div class="dhead dash-head">
    <div>
      <h1>Hi, {{ $manager->name }} 👋</h1>
      <p class="sub">Add topics to your channels, assign them to creators, and keep an eye on what they are earning.</p>
    </div>
    <div class="actions">
      <a href="{{ route('manager.earnings') }}" class="ghost">💰 Earnings</a>
      <a href="{{ route('manager.topics', ['panel' => 'add']) }}" class="solid">＋ Add topic</a>
    </div>
  </div>

  @include('admin._messages')

  <section class="stats2">
    <div class="stat2">
      <div class="top">
        <div><div class="val">{{ $total }}</div><div class="lbl">Topics</div></div>
        <div class="icon tone-violet">🛠️</div>
      </div>
      <div class="delta">{{ $channels->count() }} {{ Str::plural('channel', $channels->count()) }}</div>
    </div>
    <div class="stat2">
      <div class="top">
        <div><div class="val">{{ $done }}</div><div class="lbl">Completed</div></div>
        <div class="icon tone-teal">✅</div>
      </div>
      <div class="delta">{{ $total - $done }} still to do</div>
    </div>
    <div class="stat2">
      <div class="top">
        <div><div class="val">{{ $total > 0 ? round($done / $total * 100) : 0 }}%</div><div class="lbl">Completion</div></div>
        <div class="icon tone-amber">📈</div>
      </div>
      <div class="delta">across your channels</div>
    </div>
    <div class="stat2">
      <div class="top">
        <div><div class="val">{{ Money::tk($earned) }}</div><div class="lbl">Earned by staff</div></div>
        <div class="icon tone-blue">💰</div>
      </div>
      <div class="delta"><a href="{{ route('manager.earnings') }}" style="color:var(--accent);text-decoration:none">View earnings →</a></div>
    </div>
  </section>

  @include('partials._channel-cards', [
    'channelStats' => $channelStats,
    'title' => '📺 Your channels',
    'topicsRoute' => 'manager.topics',
    'topicsParam' => 'channel',
    'empty' => 'You don\'t have any channels yet. Ask the admin to assign you one on the Managers page — until then you can\'t add or assign topics.',
  ])

  <details class="block dash-details">
    <summary>💵 Pay rates for your channels</summary>
    <p class="rate-hint">What a creator earns for completing one topic in each channel. It is used the next time a topic is completed — earnings already recorded keep the rate they were completed at.</p>

    @include('partials._rate-editor', [
      'rateAction' => route('manager.rates.save'),
      'rateChannels' => $channels,
      'rateEmployees' => $employees,
      'rates' => $rates,
      'rateClass' => '',
      'rateTitle' => 'Pay rate per completed topic (channel × creator)',
      'rateHint' => 'Only the channels assigned to you are listed.',
    ])
  </details>
</div>
@endsection
