@use(App\Models\Topic)

{{--
  "Recently added" strip for the admin and manager topic pages: anything added in
  the last Topic::RECENT_DAYS days, newest first. The same topics still appear in
  their channel group below — this is a summary, not a move.

  Only the person who added a topic gets Edit and Delete on it, because the strip
  exists to fix what you have just typed. Ownership is decided by the host through
  $canManageRecent, which every action re-checks server-side: a component call
  arrives at the shared /livewire/update endpoint, so hiding a button is not a
  check. The default below is therefore the closed one, so a caller that forgets
  to pass the test shows no controls rather than everyone's.

  Editing happens here, in the strip, instead of jumping to the row in the list
  below. That list is grouped into channel tables and runs to several hundred
  rows, so the row for a topic added seconds ago is normally off-screen or hidden
  behind the active search, and opening it there left the strip looking like it
  had done nothing. The editor reuses the table's .tedit styles.

  Expects: $recent = ['items' => Collection<Topic>, 'total' => int]
  Optional: $canManageRecent = fn (Topic $topic): bool — the row's owner
           $editingRecent      id of the open strip row, or null
           $recentDraft        that row's fields
--}}

@php $canManage = $canManageRecent ?? fn () => false @endphp

@if ($recent['total'] > 0)
  @push('styles')
  <style>
    .recent{background:var(--panel);border:1px solid var(--border);border-radius:22px;
      padding:16px 18px;margin-bottom:18px;box-shadow:var(--shadow)}
    .recent h2{font-size:17px;margin:0 0 4px;display:flex;align-items:baseline;gap:9px;flex-wrap:wrap}
    .recent h2 .rc{font-size:13px;font-weight:400}
    .recent ul{list-style:none;margin:10px 0 0;padding:0;display:flex;flex-direction:column;gap:2px}
    .recent li{display:flex;align-items:center;gap:10px;padding:7px 0;border-top:1px solid var(--border);flex-wrap:wrap}
    .recent li:first-child{border-top:0}
    .recent .rt{font-weight:600;font-size:15px;flex:1;min-width:180px;overflow-wrap:anywhere}
    .recent .rc{color:var(--muted);font-size:13px;white-space:nowrap}
    .recent .by{color:var(--muted);font-size:13px;white-space:nowrap}
    .recent .by b{color:var(--text);font-weight:600}
    .recent .badge{font-size:12px;padding:3px 8px;border-radius:999px;background:var(--chip-bg);color:var(--chip-text);white-space:nowrap}
    .recent .badge.set{background:rgba(22,163,74,.12);color:var(--done)}
    .recent .more{color:var(--muted);font-size:13px;margin:10px 0 0}
    .recent .racts{display:flex;gap:6px;margin-left:auto}
    .recent .racts .btn-sm{width:auto;padding:4px 11px;font-size:13px;white-space:nowrap}
    .recent .racts .del{background:linear-gradient(135deg,#dc2626,#b91c1c)}
    .recent li.recent-open{background:var(--panel2);border-radius:12px;padding-left:8px;padding-right:8px}
    .recent li.recent-edit{display:block;padding:0 0 8px}
    .recent .recent-edit .tedit{background:var(--panel2);border:1px solid var(--border);border-radius:14px}
  </style>
  @endpush

  <section class="recent">
    <h2>
      🆕 Recently added
      <span class="rc">added in the last {{ Topic::RECENT_DAYS }} days · newest first</span>
    </h2>

    <ul>
      @foreach ($recent['items'] as $r)
        @php
          $isMine = $canManage($r);
          $isOpenHere = $isMine && ($editingRecent ?? null) === $r->id;
        @endphp

        <li wire:key="recent-{{ $r->id }}" class="{{ $isOpenHere ? 'recent-open' : '' }}">
          <span class="rt">{{ $r->title }}</span>
          <span class="badge">{{ $r->channel->icon }} {{ $r->channel->name }}</span>
          <span class="badge {{ $r->assigned_to ? 'set' : '' }}">
            {{ $r->employee?->name ?? 'Unassigned' }}
          </span>
          @if ($r->addedByLabel())
            <span class="by">by <b>{{ $r->addedByLabel() }}</b></span>
          @endif
          <span class="rc" title="{{ $r->created_at->format('j M Y, g:i A') }}">{{ $r->created_at->diffForHumans() }}</span>

          @if ($isMine)
            <span class="racts">
              <button type="button" class="btn-sm" data-recent-edit="{{ $r->id }}"
                      wire:click="editRecent({{ $r->id }})"
                      title="Edit this topic">✏️ Edit</button>
              <button type="button" class="btn-sm del" data-recent-delete="{{ $r->id }}"
                      wire:click="deleteRecent({{ $r->id }})"
                      wire:confirm="Delete this topic?" title="Delete this topic">🗑️ Delete</button>
            </span>
          @endif
        </li>

        @if ($isOpenHere)
          <li class="recent-edit" wire:key="recent-edit-{{ $r->id }}">
            <form class="tedit" wire:submit="saveRecentEdit">
              <div class="tedit-grid">
                <div>
                  <label for="r-{{ $r->id }}-title">Title</label>
                  <input type="text" id="r-{{ $r->id }}-title" wire:model="recentDraft.title">
                  @error('recentDraft.title')<div class="adderr">{{ $message }}</div>@enderror
                </div>
                <div>
                  <label for="r-{{ $r->id }}-cat">Category</label>
                  <input type="text" id="r-{{ $r->id }}-cat" wire:model="recentDraft.category">
                  @error('recentDraft.category')<div class="adderr">{{ $message }}</div>@enderror
                </div>
                <div>
                  <label for="r-{{ $r->id }}-link">Link</label>
                  <input type="url" id="r-{{ $r->id }}-link" wire:model="recentDraft.link" placeholder="https://…">
                  @error('recentDraft.link')<div class="adderr">{{ $message }}</div>@enderror
                </div>
              </div>
              <div class="tedit-actions">
                <button type="submit" class="btn-sm" wire:loading.attr="disabled" wire:target="saveRecentEdit">Save changes</button>
                <button type="button" class="btn-sm" wire:click="cancelRecentEdit">Cancel</button>
              </div>
            </form>
          </li>
        @endif
      @endforeach
    </ul>

    @if ($recent['total'] > $recent['items']->count())
      <p class="more">and {{ $recent['total'] - $recent['items']->count() }} more in the last {{ Topic::RECENT_DAYS }} days…</p>
    @endif
  </section>
@endif
