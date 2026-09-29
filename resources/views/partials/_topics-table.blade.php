@use(App\Models\Topic)

{{--
  The topic table, shared by the admin and manager topic pages.

  One <section> per channel, its heading above the table rather than inside it, so a
  search that clears every row in a channel takes the heading with it too. Each
  section carries its own table — its own header row, its own scroll box — rather
  than one continuous table for every channel, so the heading reads as what it is
  instead of a colspan row dressed up to look like one.

  Expects:
    $groups            array of ['channel' => Channel, 'topics' => Collection<Topic>]
    $channelTotals     array of channel id => how many topics that channel holds in
                       total, so a section cut to the newest N can say so instead of
                       looking like all the channel has
    $editing           id of the open row, or null
    $draft             the open row's fields
    $employees         for the assignee picker
    $canAssign         bool  — show the per-row assignee picker (manager)
    $canToggleDone     bool  — show the done checkbox (admin)
    $showAll           bool  — showing every topic rather than the newest Topic::PREVIEW_LIMIT
    $topicTotal        int   — how many topics there are behind that cut
    $emptyMessage      str   — what an empty list should say, when "no topics match yet" is vague
--}}

@php
  $cols = 6;
@endphp

@forelse ($groups as $group)
  @php
    $shown = count($group['topics']);
    $held = ($channelTotals[$group['channel']->id] ?? $shown) - $shown;
  @endphp
  <section class="tgsection" wire:key="group-{{ $group['channel']->id }}" data-topic-group="{{ $group['channel']->id }}">
    <div class="tgroup-head">
      <span class="tg-name">{{ $group['channel']->icon }} {{ $group['channel']->name }}</span>
      <span class="tg-badge">{{ $group['channel']->badge }}</span>
      {{-- Say what is being held back per channel, not just in the footer: a heading
           that read "5 topics" over a channel of 193 was how most of its completed
           topics went missing without anything looking wrong. --}}
      @if ($held > 0)
        <span class="tg-count" title="{{ $shown }} of {{ $channelTotals[$group['channel']->id] }} — the rest are held back below">newest {{ $shown }} of {{ $channelTotals[$group['channel']->id] }}</span>
      @else
        <span class="tg-count">{{ $shown }} {{ \Illuminate\Support\Str::plural('topic', $shown) }}</span>
      @endif
    </div>

    <div class="tstable-wrap">
      <table class="tstable">
        <thead>
          <tr>
            <th class="c-status"><span class="vh">Done</span></th>
            <th>Topic</th>
            <th class="hide-sm c-cat">Category</th>
            <th>Assigned to</th>
            <th class="hide-sm c-by">Added by</th>
            <th class="c-act"><span class="vh">Actions</span></th>
          </tr>
        </thead>

        {{-- Every direct child of a tbody is keyed, so adding the editor row in the
             middle of a list cannot make morphdom realign the rows after it. --}}
        <tbody>
          @foreach ($group['topics'] as $t)
            @php
              $isEditing = ($editing ?? null) === $t->id;
              $haystack = trim($t->title.' '.$t->category.' '.$group['channel']->name
                .' '.($t->employee?->name ?? '')
                .' '.($t->addedByLabel() ?? ''));
            @endphp

            <tr class="trow{{ $t->is_done ? ' is-done' : '' }}"
                wire:key="row-{{ $t->id }}"
                data-topic-row="{{ $t->id }}"
                data-search="{{ $haystack }}">
              <td class="c-status">
                @if ($canToggleDone ?? false)
                  <label class="tdone" title="{{ $t->is_done ? 'Completed — untick to reopen' : 'Mark as done' }}">
                    <input type="checkbox" @checked($t->is_done) wire:click="toggle({{ $t->id }})">
                    <span class="tdone-box" aria-hidden="true">✓</span>
                  </label>
                @else
                  <span class="tdone-static" title="{{ $t->is_done ? 'Completed' : 'Pending' }}">
                    {{ $t->is_done ? '✅' : '☐' }}
                  </span>
                @endif
              </td>

              <td class="c-title">
                <span class="t-main">{{ $t->title }}</span>
                @if ($t->link)
                  <a class="t-ext" href="{{ $t->link }}" target="_blank" rel="noopener"
                     title="{{ $t->link }}">🔗</a>
                @endif
                <span class="t-sub">
                  <span class="ch-badge">{{ $group['channel']->badge }}</span>
                  {{ $t->created_at->diffForHumans() }}
                </span>
              </td>

              {{-- data-label is what the card layout on a phone puts in front of the
                   value, since the header row is not on screen there. --}}
              <td class="hide-sm c-cat" data-label="Category"><span class="t-cat">{{ $t->category }}</span></td>

              <td class="c-assignee" data-label="Assigned to">
                @if ($canAssign ?? false)
                  @if ($t->is_done)
                    <span class="pill pill-done" title="Completed topics keep their assignee">
                      {{ $t->employee?->name ?? '—' }}
                    </span>
                  @elseif ($employees->isNotEmpty())
                    <select class="emp" title="Assign to employee"
                            wire:change="assign({{ $t->id }}, $event.target.value)">
                      <option value="0">— unassigned —</option>
                      @foreach ($employees as $e)
                        <option value="{{ $e->id }}" @selected($t->assigned_to === $e->id)>{{ $e->name }}</option>
                      @endforeach
                    </select>
                  @else
                    <span class="t-sub">No employees yet.</span>
                  @endif
                @else
                  <span class="t-assignee{{ $t->assigned_to ? ' set' : '' }}">{{ $t->employee?->name ?? 'Unassigned' }}</span>
                @endif
              </td>

              <td class="hide-sm c-by" data-label="Added by">
                <span class="t-by">{{ $t->addedByLabel() ?? '—' }}</span>
              </td>

              <td class="c-act">
                <button type="button" class="btn-sm" wire:click="edit({{ $t->id }})"
                        title="Edit this topic">Edit</button>
                <button type="button" class="btn-sm btn-danger" wire:click="delete({{ $t->id }})"
                        wire:confirm="Delete this topic?" title="Delete this topic">Delete</button>
              </td>
            </tr>

            @if ($isEditing)
              <tr class="trow-edit" wire:key="edit-{{ $t->id }}" data-search="{{ $haystack }}">
                <td colspan="{{ $cols }}">
                  <form class="tedit" wire:submit="saveEdit">
                    <div class="tedit-grid">
                      <div>
                        <label for="t-{{ $t->id }}-title">Title</label>
                        <input type="text" id="t-{{ $t->id }}-title" wire:model="draft.title">
                        @error('draft.title')<div class="adderr">{{ $message }}</div>@enderror
                      </div>
                      <div>
                        <label for="t-{{ $t->id }}-cat">Category</label>
                        <input type="text" id="t-{{ $t->id }}-cat" wire:model="draft.category">
                        @error('draft.category')<div class="adderr">{{ $message }}</div>@enderror
                      </div>
                      <div>
                        <label for="t-{{ $t->id }}-link">Link</label>
                        <input type="url" id="t-{{ $t->id }}-link" wire:model="draft.link" placeholder="https://…">
                        @error('draft.link')<div class="adderr">{{ $message }}</div>@enderror
                      </div>
                    </div>
                    <div class="tedit-actions">
                      <button type="submit" class="btn-sm" wire:loading.attr="disabled" wire:target="saveEdit">Save changes</button>
                      <button type="button" class="btn-sm" wire:click="cancelEdit">Cancel</button>
                    </div>
                  </form>
                </td>
              </tr>
            @endif
          @endforeach
        </tbody>
      </table>
    </div>
  </section>
@empty
  <div class="tstable-wrap">
    <div class="tempty">{{ $emptyMessage ?? 'No topics match yet.' }}</div>
  </div>
@endforelse

{{-- The list is cut to the newest Topic::PREVIEW_LIMIT of each channel until the
     viewer asks for the rest, because every action re-renders all of it. Say plainly
     what is being held back, so a search that finds nothing reads as "not on this
     page" rather than "does not exist". Gated on what is actually missing rather than
     on the total passing PREVIEW_LIMIT: the cut is per channel now, so a table of
     four channels with 40 topics each is over that number and holds nothing back. --}}
@php
  $behindCut = max(0, ($topicTotal ?? 0) - ($shownTotal ?? $topicTotal));
@endphp
@if ($behindCut > 0)
  <div class="tmore" data-topic-cut>
    @if ($showAll ?? false)
      <span class="tmore-txt">Showing all {{ $topicTotal }} {{ \Illuminate\Support\Str::plural('topic', $topicTotal) }}.</span>
      <button type="button" class="btn-sm" wire:click="showNewestOnly"
              wire:loading.attr="disabled" wire:target="showNewestOnly"
              title="Go back to the shorter list, which is what every save re-renders">
        Show only the newest {{ Topic::PREVIEW_LIMIT }} per channel
      </button>
    @else
      <span class="tmore-txt">
        Showing the newest {{ Topic::PREVIEW_LIMIT }} of each channel, {{ $shownTotal }} of {{ $topicTotal }} topics in all. Search covers the ones on screen.
      </span>
      <button type="button" class="btn-sm" wire:click="showAllTopics"
              wire:loading.attr="disabled" wire:target="showAllTopics"
              title="Load every topic, not just the newest ones">
        Show all {{ $topicTotal }} topics
      </button>
    @endif
  </div>
@endif
