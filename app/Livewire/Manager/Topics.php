<?php

namespace App\Livewire\Manager;

use App\Actions\TopicActions;
use App\Livewire\Concerns\FiltersTopics;
use App\Livewire\Concerns\ManagesRecentTopics;
use App\Livewire\RoleComponent;
use App\Models\Channel;
use App\Models\Manager;
use App\Models\Topic;
use Illuminate\Support\Collection;
use Livewire\Attributes\Url;
use Symfony\Component\HttpKernel\Exception\HttpException;

/**
 * A manager's topics: only the channels they are attached to, behind the same
 * add/show split as the admin page — add a topic, or show the topics of one chosen
 * channel, picked by the sidebar's ?panel= link — with the assignee picker and the
 * in-place row editor.
 *
 * Every action re-derives the manager's channels from the database and refuses
 * anything outside them, because a component call arrives at the shared
 * /livewire/update endpoint without this page's route middleware. The Recently
 * added strip narrows further still, to the topics this manager added themselves.
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

    /** The channel filter, kept in ?channel= so the old links still work. */
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
        return 'manager';
    }

    public function store(TopicActions $actions)
    {
        $data = $this->validate(
            $actions->createRules($this->channelIds()->all()) + [
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
        $topic = $this->findEditable($id);

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

    public function saveEdit(TopicActions $actions): void
    {
        $this->validate(
            $actions->updateRules('draft'),
            $actions->updateMessages('draft'),
        );

        $topic = $this->findEditable($this->editing);

        $actions->update($topic, $this->draft);

        $this->cancelEdit();

        $this->dispatch('toast', message: 'Topic updated.');
    }

    /** Assign, or unassign with 0. A completed topic keeps its assignee. */
    public function assign(int $id, $employeeId): void
    {
        try {
            $this->actions()->assign($this->findEditable($id), (int) $employeeId);
            $this->dispatch('toast', message: 'Assignment updated.');
        } catch (HttpException $e) {
            // A completed topic keeps its assignee; say why instead of failing silently.
            $this->addError('assign', $e->getMessage());
        }
    }

    public function delete(int $id, TopicActions $actions): void
    {
        $topic = $this->findEditable($id);

        if ($this->editing === $id) {
            $this->cancelEdit();
        }

        // The same topic can be open in the strip's own editor at the same time.
        if ($this->editingRecent === $id) {
            $this->cancelRecentEdit();
        }

        $actions->delete($topic);

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
        $channels = $this->managerChannels();
        $filter = $this->filter !== '' ? (int) $this->filter : null;

        // The filter narrows within the manager's own channels; it can never widen
        // them, so ?channel=<someone else's> simply matches nothing. The same scope
        // feeds the table and its count. The status/assignee/added filters apply
        // here too, but not to the strip below the form.
        $scope = $actions->filtered(
            Topic::query()->with('channel', 'employee')
                ->whereIn('topics.channel_id', $channels->modelKeys())
                ->when($filter, fn ($q) => $q->where('topics.channel_id', $filter)),
            $this->statusFilter, $this->assigneeFilter, $this->addedFilter,
        );

        $topics = $actions->tableTopics($scope, $this->showAll);

        return view('livewire.manager.topics', [
            'manager' => $this->user(),
            'channels' => $channels,
            // What each of the manager's channels holds, so the chooser can show an
            // empty one as empty before it is picked.
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
            // The recent strip follows the same channel scope and filter as the list.
            'recent' => Topic::recentlyAdded(
                Topic::query()
                    ->with('channel', 'employee')
                    ->whereIn('channel_id', $channels->modelKeys())
                    ->when($filter, fn ($q) => $q->where('channel_id', $filter))
            ),
            'canManageRecent' => fn (Topic $topic) => $actions->ownsRecent($topic),
        ])->layout('layouts.livewire', ['title' => 'Topics — Manager']);
    }

    /**
     * The channel scope applies to the strip's own actions as well, so a manager
     * cannot drive editRecent/deleteRecent at a topic in a channel they were
     * unhooked from after the strip was rendered.
     */
    protected function recentTopicInScope(int $id): Topic
    {
        return $this->findEditable($id);
    }

    /** A topic in one of the manager's own channels, or 403. */
    private function findEditable(int $id): Topic
    {
        $topic = Topic::findOrFail($id);

        abort_unless($this->channelIds()->contains($topic->channel_id), 403);

        return $topic;
    }

    private function actions(): TopicActions
    {
        return app(TopicActions::class);
    }

    private function channelIds(): Collection
    {
        return $this->user()->channels()->pluck('channels.id');
    }

    private function managerChannels(): Collection
    {
        return Channel::whereIn('id', $this->channelIds())->orderBy('sort_order')->orderBy('id')->get();
    }
}
