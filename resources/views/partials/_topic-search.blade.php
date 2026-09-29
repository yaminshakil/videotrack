{{-- Instant client-side filter for the admin and manager topic lists. Falls back to
     showing everything when JavaScript is off. --}}
<div class="topic-search" data-topic-search>
  <label for="topic-search-input">🔍 Search topics</label>
  <div class="ts-controls">
    <input id="topic-search-input" type="search" placeholder="Type part of a title, category, channel or assignee…"
           autocomplete="off" spellcheck="false">
    <button type="button" data-topic-search-clear title="Clear the search">✕</button>
  </div>
  <div class="ts-meta">
    <span data-topic-search-count role="status" aria-live="polite"></span>
    <span data-topic-search-empty hidden>No topic matches that. Clear the search to see them all again.</span>
  </div>
</div>
