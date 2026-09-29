@extends('layouts.app')

@section('title', 'Custom Topics')
@section('sidebar', 1)

@php use App\Support\Money; @endphp

@section('body')
<div class="wrap">
  @include('employee._nav')
  @include('admin._topbar', ['topbarName' => $employee->name, 'topbarRole' => 'Creator'])

  <div class="dhead">
    <div>
      <h1>Custom Topics</h1>
      <p class="sub" style="margin-top:6px">
        @if ($channelId && $groups->isNotEmpty())
          Showing <b>{{ $groups->first()['channel']->icon }} {{ $groups->first()['channel']->name }}</b> only ·
          <a href="{{ route('employee.custom-topics') }}" style="color:var(--accent)">Show all channels</a>
        @else
          Topics you've added yourself, kept separate from what admin assigned you on
          <a href="{{ route('employee.topics') }}" style="color:var(--accent)">My Topics</a>.
        @endif
      </p>
    </div>
  </div>

  @if (session('ok'))<div class="flashmsg ok">{{ session('ok') }}</div>@endif
  @if ($errors->has('video'))<div class="flashmsg err">{{ $errors->first('video') }}</div>@endif

  @foreach ($groups as $g)
    @php $channel = $g['channel']; @endphp
    <div class="channel" id="ch-{{ $channel->id }}" style="scroll-margin-top:20px">
      <div class="chhead">
        <h2>{{ $channel->icon }} {{ $channel->name }}</h2>
        <div class="rate">rate <b>{{ Money::tk($g['rate']) }}</b>/topic ·
             {{ $g['done'] }}/{{ $g['total'] }} done = <b>{{ Money::tk($g['earn']) }}</b></div>
      </div>

      @if ($g['total'] > 0)
        <div class="chsearch">
          <span class="chsearch-icon">🔍</span>
          <input type="search" class="ch-search-input" autocomplete="off"
                 placeholder="Search topics in {{ $channel->name }}…"
                 aria-label="Search topics in {{ $channel->name }}">
        </div>
        <div class="chempty">No topics match your search.</div>

        @foreach ($g['items'] as $t)
          @include('employee._topic-row', ['t' => $t, 'channel' => $channel])
        @endforeach
      @else
        <div class="chempty-total">You haven't added any custom topics to this channel yet.</div>
      @endif

      @php
        $addFailed = $errors->hasAny(['channel_id', 'title', 'link']) && (int) old('channel_id') === $channel->id;
      @endphp
      <button type="button" class="addtoggle" onclick="showAddForm(this)" style="{{ $addFailed ? 'display:none' : '' }}">
        ＋ Add a topic to {{ $channel->name }}
      </button>
      <form class="addform" method="post" action="{{ route('employee.topics.store') }}" style="{{ $addFailed ? 'display:flex' : 'display:none' }}">
        @csrf
        <input type="hidden" name="channel_id" value="{{ $channel->id }}">
        <input type="text" name="title" placeholder="Topic title…" required maxlength="255" value="{{ $addFailed ? old('title') : '' }}">
        <input type="url" name="link" placeholder="Reference link (optional)" value="{{ $addFailed ? old('link') : '' }}">
        <button type="submit">Add</button>
      </form>
      @if ($addFailed)
        <div class="adderr">{{ $errors->first('channel_id') ?: $errors->first('title') ?: $errors->first('link') }}</div>
      @endif
    </div>
  @endforeach
</div>
@endsection

@push('scripts')
<script>window.EMPLOYEE_VIDEO_PREVIEW_URL = @json(route('video.preview'));</script>
<script src="{{ asset('js/employee-topics.js') }}?v={{ @filemtime(public_path('js/employee-topics.js')) }}"></script>
@endpush
