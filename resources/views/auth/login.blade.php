@extends('layouts.app')

@section('title', 'Sign in — Video Tracker')

@push('styles')
<style>
  body{display:grid;place-items:center;min-height:100vh}
  .card{max-width:420px;width:92%;background:var(--panel);border:1px solid var(--border);
        border-radius:18px;padding:30px;box-shadow:var(--shadow)}
  .card h1{font-size:24px;margin:0 0 6px}
  .card .sub{font-size:15px;margin-bottom:14px}
  .card input{margin:7px 0}
  .card button{margin-top:10px}
  .err{background:rgba(239,68,68,.1);border:1px solid rgba(239,68,68,.35);color:var(--err-text);padding:10px 12px;
       border-radius:10px;font-size:15px;margin-bottom:8px}
  .back{display:inline-block;margin-top:16px;color:var(--muted);text-decoration:none;font-size:15px}
  .back:hover{color:var(--accent)}
</style>
@endpush

@section('body')
<form class="card" method="post" action="{{ route('login.submit') }}">
  @csrf
  <h1>🔐 Sign in</h1>
  <p class="sub">Admins and employees sign in here — you'll be taken to the right place automatically.</p>
  @error('username')<div class="err">{{ $message }}</div>@enderror
  <input type="text" name="username" placeholder="Username" value="{{ old('username') }}" required autofocus autocomplete="username">
  <input type="password" name="password" placeholder="Password" required autocomplete="current-password">
  <button type="submit">Sign in →</button>
  <a class="back" href="{{ route('home') }}">← Back to home</a>
</form>
@endsection
