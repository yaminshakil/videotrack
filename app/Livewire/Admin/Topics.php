<?php

namespace App\Livewire\Admin;

use App\Actions\TopicActions;
use App\Livewire\Concerns\FiltersTopics;
use App\Livewire\Concerns\ManagesRecentTopics;
use App\Livewire\RoleComponent;
use App\Models\Admin;
use App\Models\Topic;
use Livewire\Attributes\Url;

/**
 * Every topic in every channel, editable in place.
 *
 * The page shows one of two things — the add-topic form, or the list of one chosen
 * channel's topics — decided by the ?panel= query string the sidebar's "Add Topic"
 * and "Show Topic" links carry, so it needs no script to pick between them.
 *
 * The list runs to several hundred rows, so the rows are read-only until one is
 * actually being edited: only the open row's draft is part of the component
 * state, instead of a bound input for every title, category and link on the
 * page. That keeps each request to a handful of fields rather than the whole
 * list, and it is why the Done checkbox and the Edit button act immediately
 * while the text fields need an explicit Save.
 *
 * Note the two editors on this page: the table below lets an admin change any
 * topic, while the Recently added strip only offers the topics this admin added
 * themselves, because that strip is about fixing what you have just typed.
 */
class Topics extends RoleComponent
{
    use ManagesRecentTopics, FiltersTopics;

    /** Add-topic form. */
    public string $channel_id = '';

    public string $title = '';

    public string $category = '';

    public string $link = '';

    public string $employee_id = '';

    /**
     * Which of the two pages this is: 'add' (the form) or 'show' (the list). Kept in
     * ?panel= so the sidebar's "Add Topic" / "Show Topic" links each land on the
     * right one, and so it survives a Livewire round trip (a failed save stays on
     * the add page instead of reverting to the default).
     */
    #[Url(as: 'panel', history: false)]
    public string $panel = 'show';

    /**
     * Which channel's topics to list, chosen on the Show Topic page. Kept in
     * ?channel= so a filtered list is a link that can be shared or reloaded, and so
     * the manager page's filter works the same way here.
     */
    #[Url(as: 'channel', history: false)]
    public string $filter = '';

    /** The row currently open for editing, if any. */
    public ?int $editing = null;

    /** The open row's fields. */
    public array $draft = [];

    /**
     * Whether the table is showing every topic or just the newest Topic::PREVIEW_LIMIT
     * of them. Off by default because the table is re-rendered on every action, so
     * its size is the cost of every save.
     */
    public bool $showAll = false;

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

    public function store(TopicActions $actions)
    {
        $data = $this->validate(
            $actions->createRules() + [
                'employee_id' => ['nullable', 'integer', 'exists:employees,id'],
            ],
            $actions->createMessages(),
        );

        $actions->createAssigned($data, (int) ($data['employee_id'] ?? 0) ?: null, $this->user()?->name);

        $this->reset(['title', 'category', 'link', 'employee_id']);

        $this->dispatch('toast', message: 'Topic added.');
    }

    /** Open a row for editing, seeded from what is stored. */
    public function edit(int $id): void
    {
        $topic = Topic::findOrFail($id);

        $this->editing = $id;
        $this->draft = [
            'title' => (string) $topic->title,
            'category' => (string) $topic->category,
            'link' => (string) $topic->link,
        ];

        $this->resetValidation();

        // The strip and the list are far apart on the page, so bring the row
        // into view rather than leaving the save button off-screen.
        $this->dispatch('scroll-to-topic', id: $id);
    }

    public function cancelEdit(): void
    {
        $this->editing = null;
        $this->draft = [];
        $this->resetValidation();
    }

    /** Persist the open row. */
    public function saveEdit(TopicActions $actions): void
    {
        $this->validate(
            $actions->updateRules('draft'),
            $actions->updateMessages('draft'),
        );

        $actions->update(Topic::findOrFail($this->editing), $this->draft);

        $this->cancelEdit();

        $this->dispatch('toast', message: 'Topic updated.');
    }

    public function toggle(int $id, TopicActions $actions): void
    {
        $actions->toggle(Topic::findOrFail($id));
    }

    public function delete(int $id, TopicActions $actions): void
    {
        // Deleting the row that is open would leave the draft pointing at nothing.
        if ($this->editing === $id) {
            $this->cancelEdit();
        }

        // The same topic can be open in the strip's own editor at the same time.
        if ($this->editingRecent === $id) {
            $this->cancelRecentEdit();
        }

        $actions->delete(Topic::findOrFail($id));

        $this->dispatch('toast', message: 'Topic deleted.');
    }

    /** Show the held-back topics, for when the newest hundred are not enough. */
    public function showAllTopics(): void
    {
        $this->showAll = true;
    }

    /** Back to the newest hundred, which is what every save re-renders. */
    public function showNewestOnly(): void
    {
        $this->showAll = false;
        $this->cancelEdit();
    }

    public function render(TopicActions $actions)
    {
        $filter = $this->filter !== '' ? (int) $this->filter : null;
        $channels = $actions->channels();

        // The filter narrows within every channel the admin can see. A channel that
        // does not exist simply matches nothing rather than widening the scope, and
        // the same query feeds the table and its count. The status/assignee/added
        // filters apply here too, but not to the strip below the form — that one
        // is about what was just added, not about finding a particular topic.
        $scope = $actions->filtered(
            Topic::query()->with('channel', 'employee')
                ->when($filter, fn ($q) => $q->where('topics.channel_id', $filter)),
            $this->statusFilter, $this->assigneeFilter, $this->addedFilter,
        );

        $topics = $actions->tableTopics($scope, $this->showAll);

        return view('livewire.admin.topics', [
            'channels' => $channels,
            // What each channel holds, so the chooser can show an empty one as empty
            // before it is picked. Unfiltered on purpose: that is the comparison the
            // chooser is for.
            'channelCounts' => $actions->countsByChannel($channels->modelKeys()),
            'employees' => $actions->activeEmployees(),
            'topics' => $topics,
            'groups' => $actions->groupedByChannel($topics),
            'topicTotal' => $this->showAll ? $topics->count() : $actions->tableTotal($scope),
            // Same scope as topicTotal, so a section that is holding rows back can say
            // how many of its own it is holding rather than reporting the cut as if it
            // were the whole channel.
            'channelTotals' => $this->showAll ? [] : $actions->channelTotals($scope),
            'showAll' => $this->showAll,
            // The strip follows the same filter as the list, and shows each row's
            // assignee, so employee has to be eager loaded here: without it every save
            // pays one extra query per row the strip lists.
            'recent' => Topic::recentlyAdded(
                Topic::query()
                    ->with('employee')
                    ->when($filter, fn ($q) => $q->where('topics.channel_id', $filter))
            ),
            // The strip renders its controls per row, and ownership is a per-row
            // answer, so the view is handed the test rather than a precomputed list.
            'canManageRecent' => fn (Topic $topic) => $actions->ownsRecent($topic),
        ])->layout('layouts.livewire', ['title' => 'Topics — Admin']);
    }
}
