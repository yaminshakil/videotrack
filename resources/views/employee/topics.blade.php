@extends('layouts.app')

@section('title', 'My Topics')
@section('sidebar', 1)

@php use App\Support\Money; @endphp

@section('body')
<div class="wrap">
  @include('employee._nav')
  @include('admin._topbar', ['topbarName' => $employee->name, 'topbarRole' => 'Creator'])

  <div class="dhead">
    <div>
      <h1>My Topics</h1>
      <p class="sub" style="margin-top:6px">
        @if ($channelId && $groups->isNotEmpty())
          Showing <b>{{ $groups->first()['channel']->icon }} {{ $groups->first()['channel']->name }}</b> only ·
          <a href="{{ route('employee.topics') }}" style="color:var(--accent)">Show all channels</a>
        @else
          Add your YouTube video link to a topic, then tick it as done to earn. Topics you add yourself live on
          <a href="{{ route('employee.custom-topics') }}" style="color:var(--accent)">Custom Topics</a>.
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
        <div class="chempty-total">No admin-assigned topics yet in this channel.</div>
      @endif
    </div>
  @endforeach
</div>
@endsection

@push('scripts')
<script>window.EMPLOYEE_VIDEO_PREVIEW_URL = @json(route('video.preview'));</script>
<script src="{{ asset('js/employee-topics.js') }}?v={{ @filemtime(public_path('js/employee-topics.js')) }}"></script>
@endpush
