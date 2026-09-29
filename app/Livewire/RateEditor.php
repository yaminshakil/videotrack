<?php

namespace App\Livewire;

use App\Models\Channel;
use App\Models\Employee;
use App\Support\RateMatrix;
use Livewire\Component;

/**
 * The channel x employee pay-rate grid, shared by the admin and manager
 * dashboards. One component, so a rate can never be saved under different rules
 * depending on which page the admin happened to be standing on.
 *
 * The grid is only loaded into component state once the user opens the editor,
 * and the save passes the caller's own channels to RateMatrix::save, which
 * rejects anything outside them. So a manager cannot reach another manager's
 * rates by posting a channel id their page never rendered.
 */
class RateEditor extends Component
{
    /**
     * The channels this editor may show and save. Resolved on mount and re-checked
     * on every request, so it cannot be widened from the browser.
     *
     * @var array<int, int>
     */
    public array $channelIds = [];

    /** Rates keyed "channelId-employeeId", as strings so the inputs round-trip. */
    public array $rates = [];

    public bool $open = false;

    public string $title = 'Pay rates (channel × creator)';

    public string $hint = 'What a creator earns for completing one topic in each channel. It is used the next time a topic is completed — earnings already recorded keep the rate they were completed at.';

    /**
     * @param  \Illuminate\Support\Collection<int, Channel>|null  $channels  the caller's channels, or null for the admin (all of them)
     */
    public function mount($channels = null): void
    {
        $this->channelIds = $channels === null
            ? Channel::orderBy('sort_order')->orderBy('id')->pluck('id')->map(fn ($id) => (int) $id)->all()
            : collect($channels)->modelKeys();
    }

    public function toggle(): void
    {
        $this->open = ! $this->open;

        if ($this->open && $this->rates === []) {
            $this->loadRates();
        }
    }

    public function save(): void
    {
        $this->normalise();

        $rules = [];
        foreach (array_keys($this->rates) as $key) {
            $rules["rates.{$key}"] = ['nullable', 'numeric', 'min:0'];
        }

        $this->validate($rules, [
            'rates.*.numeric' => 'Pay rates must be a number of 0 or more.',
        ]);

        $grid = [];
        foreach ($this->rates as $key => $amount) {
            [$channelId, $employeeId] = array_map('intval', explode('-', $key, 2));

            // A blank cell has always meant "no rate", which is stored as 0.
            $grid[$channelId][$employeeId] = $amount === null ? 0 : (float) $amount;
        }

        $written = RateMatrix::save($grid, Channel::whereIn('id', $this->channelIds)->get());

        $this->loadRates();

        $this->dispatch('toast', message: $written === 1 ? '1 rate saved.' : $written.' rates saved.');
    }

    public function render()
    {
        $channels = Channel::whereIn('id', $this->channelIds)
            ->orderBy('sort_order')->orderBy('id')->get();

        return view('livewire.rate-editor', [
            'channels' => $channels,
            'employees' => Employee::where('is_active', true)->orderBy('name')->orderBy('id')->get(),
        ]);
    }

    /** Fill the editable cells from what is stored. */
    private function loadRates(): void
    {
        $matrix = RateMatrix::forChannels(
            Channel::whereIn('id', $this->channelIds)->get()
        );

        $this->rates = [];

        foreach ($matrix as $channelId => $byEmployee) {
            foreach ($byEmployee as $employeeId => $amount) {
                $this->rates[$channelId.'-'.$employeeId] = number_format((float) $amount, 2, '.', '');
            }
        }
    }

    /** An untouched field arrives as an empty string; treat it as "unset", not as a typo. */
    private function normalise(): void
    {
        foreach ($this->rates as $key => $amount) {
            if (is_string($amount) && trim($amount) === '') {
                $this->rates[$key] = null;
            }
        }
    }
}
