@extends('layouts.app')

@section('title', $employee->name.' — Earnings — Admin')
@section('sidebar', 1)

@push('styles')
<style>
  @media(max-width:700px){.wrap{padding:20px 14px 40px}}
</style>
@endpush

@section('body')
<div class="wrap">
  @include('admin._nav')
  @include('admin._topbar')

  <div class="dhead dash-head">
    <div>
      <h1>{{ $employee->name }}'s earnings</h1>
      <p class="sub">By day, week, month and year — every channel, since {{ $employee->name }} started completing topics.</p>
    </div>
    <div class="actions">
      <a href="{{ route('admin.earnings') }}" class="ghost">← Earnings</a>
    </div>
  </div>

  @include('partials._earnings-breakdown')
</div>
@endsection
