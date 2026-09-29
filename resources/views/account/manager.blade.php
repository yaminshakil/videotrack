@extends('layouts.app')

@section('title', 'My account — Manager')
@section('sidebar', 1)

@section('body')
<div class="wrap">
  @include('manager._nav')
  @include('admin._topbar', ['topbarName' => $manager->name, 'topbarRole' => 'Manager'])

  <div class="dhead">
    <div>
      <h1>🔑 My account</h1>
      <p class="sub">Your sign-in details. Only the admin can change these for you otherwise, so you can do it here.</p>
    </div>
  </div>

  @include('admin._messages')

  @include('account._form', [
      'action' => route('manager.account.update'),
      'heading' => 'Sign-in details',
      'tagline' => 'Used to sign in to this manager panel. Nothing here is shared with creators.',
      'roleLabel' => 'Manager',
      'user' => $manager,
  ])

  <p style="text-align:center;margin:10px 0 34px">
    <a href="{{ route('manager.dashboard') }}" style="color:var(--accent);text-decoration:none;font-size:15px">← Back to dashboard</a>
  </p>
</div>
@endsection
