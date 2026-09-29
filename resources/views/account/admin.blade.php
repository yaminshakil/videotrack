@extends('layouts.app')

@section('title', 'My account — Admin')
@section('sidebar', 1)

@section('body')
<div class="wrap">
  @include('admin._nav')
  @include('admin._topbar', ['topbarName' => $admin->name, 'topbarRole' => 'Administrator'])

  <div class="dhead">
    <div>
      <h1>🔑 My account</h1>
      <p class="sub">Your sign-in details. The password is stored as a hash, and changing it here replaces the one in your .env file.</p>
    </div>
  </div>

  @include('admin._messages')

  @include('account._form', [
      'action' => route('admin.account.update'),
      'heading' => 'Sign-in details',
      'tagline' => 'Used to sign in to this panel. Nothing here is shared with creators or managers.',
      'roleLabel' => 'Administrator',
      'user' => $admin,
  ])

  <p style="text-align:center;margin:10px 0 34px">
    <a href="{{ route('admin.dashboard') }}" style="color:var(--accent);text-decoration:none;font-size:15px">← Back to dashboard</a>
  </p>
</div>
@endsection
