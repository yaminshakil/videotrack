<?php

namespace App\Livewire\Concerns;

use Livewire\Attributes\Url;

/**
 * The Show Topic page's status / assignee / added-since filters, shared by the admin
 * and manager topic pages. Each lives in its own query parameter, independent of the
 * channel chooser and of each other, so any combination is a link that can be shared
 * or reloaded.
 *
 * The host component is expected to pass these straight into TopicActions::filtered()
 * when it builds the table's scope, and to declare its own `?string $filter` for the
 * channel chooser — clearFilters() resets that one too, since all four now sit in one
 * row and "clear" reads as "clear everything in it".
 */
trait FiltersTopics
{
    #[Url(as: 'status', history: false)]
    public string $statusFilter = 'all';

    #[Url(as: 'assignee', history: false)]
    public string $assigneeFilter = '';

    #[Url(as: 'added', history: false)]
    public string $addedFilter = '';

    /** Back to every topic, the way the page starts out. */
    public function clearFilters(): void
    {
        $this->filter = '';
        $this->statusFilter = 'all';
        $this->assigneeFilter = '';
        $this->addedFilter = '';
    }
}
