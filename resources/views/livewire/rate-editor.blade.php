{{-- Pay-rate grid (channel x employee). Livewire version of partials/_rate-editor.
     The cells only enter component state once the editor is opened, so a closed
     grid costs nothing on every other request. --}}
<div class="block">
  <button type="button" class="rate-toggle" wire:click="toggle" aria-expanded="{{ $open ? 'true' : 'false' }}">
    <span>{{ $open ? '▾' : '▸' }}</span> 💵 {{ $title }}
  </button>

  @if ($open)
    <div class="rate-editor" wire:loading.class="lw-busy" wire:target="save">
      <p class="muted rate-hint">{{ $hint }}</p>

      @if ($channels->isEmpty())
        <p class="muted">There are no channels yet, so there is nothing to set a rate for.</p>
      @elseif ($employees->isEmpty())
        <p class="muted">There are no active employees yet. Add one on the Employees page first.</p>
      @else
        <form wire:submit="save">
          <div class="tscroll">
            <table class="ratetable">
              <thead>
                <tr>
                  <th>Channel</th>
                  @foreach ($employees as $e)
                    <th>{{ $e->name }}</th>
                  @endforeach
                </tr>
              </thead>
              <tbody>
                @foreach ($channels as $c)
                  <tr wire:key="rate-{{ $c->id }}">
                    <th class="nw">{{ $c->icon }} {{ $c->name }}</th>
                    @foreach ($employees as $e)
                      @php $key = $c->id.'-'.$e->id; @endphp
                      <td>
                        <input type="number" step="0.01" min="0" class="rate-input"
                               wire:model="rates.{{ $key }}"
                               placeholder="0.00"
                               aria-label="{{ $c->name }} rate for {{ $e->name }}">
                        @error('rates.'.$key)<div class="adderr">{{ $message }}</div>@enderror
                      </td>
                    @endforeach
                  </tr>
                @endforeach
              </tbody>
            </table>
          </div>

          <button type="submit" wire:loading.attr="disabled" wire:target="save">
            <span wire:loading.remove wire:target="save">💾 Save all rates</span>
            <span wire:loading wire:target="save">Saving…</span>
          </button>
        </form>
      @endif
    </div>
  @endif
</div>

@error('rates')<div class="msg err">{{ $message }}</div>@enderror
