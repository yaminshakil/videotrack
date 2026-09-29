// Instant search for the admin and manager topic lists.
//
// Table rows carry the text to match in data-search, and every channel <tbody>
// carries data-topic-group, so a channel heading disappears when all of its rows
// are filtered out instead of being left stranded above an empty channel.
//
// The whole thing is set up as apply()/reapply() rather than a one-shot IIFE,
// because on the Livewire pages a save re-renders the list underneath a search
// that is still active: the filter has to be re-run on every morph, and the box
// has to keep the text the user had typed.
(function () {
  let box, input, clear, counter, empty, groups = [], term = '';

  const show = (node, visible) => {
    node.style.display = visible ? '' : 'none';
  };

  const run = () => {
    const q = term;
    let visible = 0;

    groups.forEach(group => {
      const rows = group.querySelectorAll('[data-search]');
      let shown = 0;

      Array.prototype.forEach.call(rows, row => {
        const haystack = (row.getAttribute('data-search') || '').toLowerCase();
        const matches = q === '' || haystack.indexOf(q) !== -1;

        show(row, matches);

        // The editor under an open row repeats its row's haystack so that it hides
        // along with the topic it belongs to, but it is not a topic of its own.
        if (matches && !row.classList.contains('trow-edit')) shown++;
      });

      show(group, shown > 0);
      visible += shown;
    });

    if (counter) {
      counter.textContent = q === ''
        ? ''
        : (visible === 1 ? '1 topic matches “' : visible + ' topics match “') + q + '”';
    }

    if (empty) {
      show(empty, visible === 0);
    }
  };

  const setup = () => {
    box = document.querySelector('[data-topic-search]');
    if (!box) return false;

    input = box.querySelector('input');
    clear = box.querySelector('[data-topic-search-clear]');
    counter = document.querySelector('[data-topic-search-count]');
    empty = document.querySelector('[data-topic-search-empty]');
    groups = Array.prototype.slice.call(document.querySelectorAll('[data-topic-group]'));

    if (input.dataset.searchBound) return true;
    input.dataset.searchBound = '1';

    // remember what was typed so it can be restored after a re-render
    term = input.value.trim().toLowerCase();

    input.addEventListener('input', () => {
      term = input.value.trim().toLowerCase();
      run();
    });
    input.addEventListener('search', () => {
      term = input.value.trim().toLowerCase();
      run();
    });

    if (clear) {
      clear.addEventListener('click', () => {
        term = '';
        input.value = '';
        run();
        input.focus();
      });
    }

    // "/" focuses the search box, the way it works in most tools.
    document.addEventListener('keydown', event => {
      if (event.key !== '/' || event.ctrlKey || event.metaKey || event.altKey) return;

      const active = document.activeElement;
      const typing = active && (active.tagName === 'INPUT' || active.tagName === 'TEXTAREA' || active.tagName === 'SELECT');

      if (!typing) {
        event.preventDefault();
        input.focus();
        input.select();
      }
    });

    return true;
  };

  // Re-collect the nodes and re-apply the filter. Called after each Livewire morph,
  // because the list is replaced but the search term is still what the user wants.
  const reapply = () => {
    if (!setup()) return;
    if (input && input.value.trim().toLowerCase() !== term) {
      input.value = term;
    }
    run();
  };

  document.addEventListener('livewire:init', () => {
    Livewire.hook('morph', () => reapply());
    Livewire.hook('morph.updated', () => reapply());

    // Editing from the Recently added strip opens the row in the list below,
    // which may be hidden by the active search or simply be far down the page.
    Livewire.on('scroll-to-topic', e => {
      const id = e.detail.id;

      if (input && input.value.trim() !== '') {
        term = '';
        input.value = '';
        run();
      }

      const row = document.querySelector('[wire\\:key="row-' + id + '"]')
        || document.querySelector('[data-topic-row="' + id + '"]');

      if (row) {
        row.scrollIntoView({ behavior: 'smooth', block: 'center' });
        row.classList.add('editing-flash');
        setTimeout(() => row.classList.remove('editing-flash'), 1200);
      }
    });

    reapply();
  });

  reapply();
})();
