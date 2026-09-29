@extends('layouts.app')

@section('title', 'Video Tracker — Plan, assign and pay for video topics')

@push('styles')
<style>
  .nav{display:flex;justify-content:space-between;align-items:center;gap:12px;margin-bottom:56px;flex-wrap:wrap}
  .brand{font-weight:800;font-size:20px}
  .nav a.btn{width:auto}
  a.btn{display:inline-block;padding:13px 22px;border-radius:11px;text-decoration:none;color:#fff;font-weight:700;
        font-size:16px;background:linear-gradient(135deg,var(--accent),var(--accent2))}
  a.btn.ghost{background:transparent;border:1px solid var(--border);color:var(--text)}
  a.btn.ghost:hover{border-color:var(--accent)}
  .hero{text-align:center;max-width:760px;margin:0 auto 46px}
  .hero h1{font-size:48px;line-height:1.1;margin:0 0 16px}
  .hero h1 span{background:linear-gradient(90deg,var(--accent),var(--accent2));-webkit-background-clip:text;background-clip:text;color:transparent}
  .hero p{color:var(--muted);font-size:19px;line-height:1.6;margin:0 0 26px}
  .cta{display:flex;gap:12px;justify-content:center;flex-wrap:wrap}
  .kpis{display:flex;gap:12px;justify-content:center;flex-wrap:wrap;margin-bottom:56px}
  .kpis .stat{min-width:130px}
  h2.sec{text-align:center;font-size:28px;margin:0 0 22px}
  .features{display:grid;grid-template-columns:repeat(3,1fr);gap:14px;margin-bottom:56px}
  .feat{background:var(--panel);border:1px solid var(--border);border-radius:16px;padding:20px;box-shadow:var(--shadow)}
  .feat .i{font-size:28px;margin-bottom:8px}
  .feat h3{margin:0 0 6px;font-size:18px}
  .feat p{margin:0;color:var(--muted);font-size:16px;line-height:1.55}
  .chs{display:grid;grid-template-columns:repeat(4,1fr);gap:12px;margin-bottom:56px}
  .ch{background:var(--panel);border:1px solid var(--border);border-radius:16px;padding:18px;text-align:center;box-shadow:var(--shadow)}
  .ch .i{font-size:32px}
  .ch b{display:block;margin:8px 0 2px}
  .ch span{color:var(--muted);font-size:15px}
  .steps{display:grid;grid-template-columns:repeat(3,1fr);gap:14px;margin-bottom:56px;counter-reset:s}
  .step{border:1px dashed var(--border);border-radius:16px;padding:18px 20px;counter-increment:s;background:var(--panel)}
  .step:before{content:counter(s);display:inline-grid;place-items:center;width:28px;height:28px;border-radius:50%;
        background:linear-gradient(135deg,var(--accent),var(--accent2));color:#fff;font-weight:800;font-size:15px;margin-bottom:8px}
  .step h3{margin:0 0 4px;font-size:17px}
  .step p{margin:0;color:var(--muted);font-size:16px;line-height:1.5}
  .final{text-align:center;background:var(--panel);border:1px solid var(--border);border-radius:20px;padding:36px 20px;box-shadow:var(--shadow)}
  .final h2{margin:0 0 10px;font-size:28px}
  .final p{color:var(--muted);margin:0 0 20px}
  .nav a.btn,.brand{white-space:nowrap}
  @media(max-width:850px){.features,.steps{grid-template-columns:1fr}.chs{grid-template-columns:1fr 1fr}.hero h1{font-size:36px}}
  @media(max-width:520px){
    .nav{margin-bottom:36px}
    .brand{font-size:17px}
    .nav a.btn{padding:10px 13px;font-size:15px}
    .hero h1{font-size:32px}
    .hero p{font-size:17px}
    .kpis .stat{min-width:0;flex:1 1 42%}
  }
</style>
@endpush

@section('body')
<div class="wrap">
  <nav class="nav">
    <div class="brand">🤖 Video Tracker</div>
    <div style="display:flex;gap:10px">
      <a class="btn ghost" href="{{ route('tracker.index') }}">View tracker</a>
      <a class="btn" href="{{ route('login') }}">Sign in</a>
    </div>
  </nav>

  <section class="hero">
    <h1>Plan every video topic. <span>Know exactly what's done.</span></h1>
    <p>One shared place to track installation-guide topics across all your channels, hand them to your team,
       and see what each person has earned — no spreadsheets.</p>
    <div class="cta">
      <a class="btn" href="{{ route('login') }}">Sign in to your account</a>
      <a class="btn ghost" href="{{ route('tracker.index') }}">Browse the topic list</a>
    </div>
  </section>

  <div class="kpis">
    <div class="stat"><b>{{ $total }}</b><span>Topics tracked</span></div>
    <div class="stat"><b>{{ $done }}</b><span>Completed</span></div>
    <div class="stat"><b>{{ $channels->count() }}</b><span>Channels</span></div>
    <div class="stat"><b>{{ $total ? round($done / $total * 100) : 0 }}%</b><span>Overall progress</span></div>
  </div>

  <h2 class="sec">Everything in one tracker</h2>
  <section class="features">
    <div class="feat"><div class="i">✅</div><h3>Live progress</h3>
      <p>Tick a topic done and totals, progress bars and group counters update instantly, on every device.</p></div>
    <div class="feat"><div class="i">🔎</div><h3>Search &amp; filter</h3>
      <p>Find any topic by title, then narrow by channel or by done / not done.</p></div>
    <div class="feat"><div class="i">🔗</div><h3>Tutorial links</h3>
      <p>Attach the finished video or article to each topic so nothing gets lost.</p></div>
    <div class="feat"><div class="i">👥</div><h3>Team assignments</h3>
      <p>Give every topic an owner. Employees see only what's assigned to them.</p></div>
    <div class="feat"><div class="i">💰</div><h3>Per-channel pay rates</h3>
      <p>Set what each channel pays each employee per completed topic. Earnings add up automatically.</p></div>
    <div class="feat"><div class="i">🛠️</div><h3>Admin panel</h3>
      <p>Add, edit, categorise and delete topics, and manage employees, rates and passwords.</p></div>
  </section>

  <h2 class="sec">Your channels</h2>
  <section class="chs">
    @foreach ($channels as $c)
      <div class="ch"><div class="i">{{ $c->icon }}</div><b>{{ $c->name }}</b><span>{{ $c->topics_count }} topics</span></div>
    @endforeach
  </section>

  <h2 class="sec">How it works</h2>
  <section class="steps">
    <div class="step"><h3>Admin sets up</h3><p>Add topics, create employee accounts and set pay rates per channel.</p></div>
    <div class="step"><h3>Employees work</h3><p>Each employee signs in, sees their assigned topics and marks them done.</p></div>
    <div class="step"><h3>Everyone sees progress</h3><p>The tracker and earnings update the moment a topic is completed.</p></div>
  </section>

  <section class="final">
    <h2>Ready to get started?</h2>
    <p>Admins and employees use the same sign-in page.</p>
    <a class="btn" href="{{ route('login') }}">Sign in →</a>
  </section>

  <footer>Video Tracker</footer>
</div>
@endsection
