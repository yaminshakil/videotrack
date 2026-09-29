<?php

namespace App\Livewire\Admin;

use App\Livewire\DashboardStats;
use App\Livewire\RoleComponent;
use App\Models\Admin;
use App\Support\ChannelStats;

class Dashboard extends RoleComponent
{
    protected function guard(): string
    {
        return 'admin';
    }

    /** Before the admins table exists, the old session flag still opens the page. */
    protected function legacySessionKey(): ?string
    {
        return 'tracker_admin';
    }

    protected function guardTableExists(): bool
    {
        return Admin::tableExists();
    }

    public function render()
    {
        $channels = DashboardStats::channels();

        return view('livewire.admin.dashboard', [
            'stats' => DashboardStats::forAllChannels(),
            'recent' => DashboardStats::recent(),
            'channels' => $channels,
            'channelStats' => ChannelStats::forChannels($channels),
        ])->layout('layouts.livewire', ['title' => 'Dashboard — Admin']);
    }
}
