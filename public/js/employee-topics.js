// Shared behaviour for the creator "My Topics" and "Custom Topics" pages.
(function () {
  // Per-channel topic search
  document.querySelectorAll('.ch-search-input').forEach(input => {
    const channel = input.closest('.channel');
    const rows    = channel.querySelectorAll('.et');
    const empty   = channel.querySelector('.chempty');
    input.addEventListener('input', () => {
      const q = input.value.trim().toLowerCase();
      let visible = 0;
      rows.forEach(row => {
        const title = row.querySelector('.tt').textContent.toLowerCase();
        const match = q === '' || title.includes(q);
        row.style.display = match ? '' : 'none';
        if (match) visible++;
      });
      if (empty) empty.style.display = visible === 0 ? 'block' : 'none';
    });
  });

  window.showAddForm = function (btn) {
    const form = btn.nextElementSibling;
    form.style.display = 'flex';
    form.querySelector('input[name=title]').focus();
    btn.style.display = 'none';
  };

  window.showTopicEdit = function (btn) {
    const et = btn.closest('.et');
    const form = et.querySelector('.editform');
    form.style.display = 'flex';
    form.querySelector('input[name=title]').focus();
    btn.style.display = 'none';
  };

  window.showEdit = function (btn) {
    const et = btn.closest('.et');
    const f = et.querySelector('.vform');
    f.style.display = 'flex';
    f.querySelector('input').focus();
    btn.remove();
  };

  // Live title preview while pasting/typing a link
  const PREVIEW_URL = window.EMPLOYEE_VIDEO_PREVIEW_URL;
  document.querySelectorAll('.vid-input').forEach(input => {
    const out = input.closest('.row2').querySelector('.vprev');
    let timer, seq = 0;
    const run = () => {
      const url = input.value.trim();
      if (url.length < 8) { out.textContent = ''; out.className = 'vprev'; return; }
      const mine = ++seq;
      out.className = 'vprev'; out.textContent = 'Checking link…';
      fetch(PREVIEW_URL + '?url=' + encodeURIComponent(url), { headers: { 'Accept': 'application/json' } })
        .then(r => r.json()).then(d => {
          if (mine !== seq) return;
          if (d.ok) {
            out.className = 'vprev ok';
            const title = d.title ? d.title : 'Link looks valid (title unavailable right now)';
            let extra = '';
            if (d.channel) extra += ' — by ' + d.channel;
            if (d.publishedAt) {
              const dt = new Date(d.publishedAt + 'T00:00:00');
              extra += ' · uploaded ' + dt.toLocaleDateString(undefined, { day: 'numeric', month: 'short', year: 'numeric' });
            }
            out.textContent = '✓ ' + title + extra;
          } else {
            out.className = 'vprev bad'; out.textContent = d.error || 'Invalid link.';
          }
        }).catch(() => { if (mine === seq) { out.className = 'vprev'; out.textContent = ''; } });
    };
    input.addEventListener('input', () => { clearTimeout(timer); timer = setTimeout(run, 500); });
  });
})();
