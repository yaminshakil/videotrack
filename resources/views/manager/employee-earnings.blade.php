@extends('layouts.app')

@section('title', $employee->name.' — Earnings — Manager')
@section('sidebar', 1)

@push('styles')
<style>
  @media(max-width:700px){.wrap{padding:20px 14px 40px}}
</style>
@endpush

@section('body')
<div class="wrap">
  @include('manager._nav')
  @include('admin._topbar', ['topbarName' => $manager->name, 'topbarRole' => 'Manager'])

  <div class="dhead dash-head">
    <div>
      <h1>{{ $employee->name }}'s earnings</h1>
      <p class="sub">By day, week, month and year — only the channels assigned to you.</p>
    </div>
    <div class="actions">
      <a href="{{ route('manager.earnings') }}" class="ghost">← Earnings</a>
    </div>
  </div>

  @include('partials._earnings-breakdown')
</div>
@endsection
