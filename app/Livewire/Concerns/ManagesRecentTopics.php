<?php

namespace App\Livewire\Concerns;

use App\Actions\TopicActions;
use App\Models\Topic;

/**
 * Edit and delete for the "Recently added" strip, owned by whoever added the topic.
 *
 * The strip carries its own draft and its own open row rather than reaching into the
 * list below. That list runs to several hundred rows grouped into channel tables, so
 * the row for a topic added seconds ago is usually off-screen or hidden behind the
 * active search, and opening it there left the strip looking like it had done nothing.
 * Editing in place keeps the topic, the fields and the Save button in the same strip
 * of the page, which is the whole point of having the strip.
 *
 * The draft is kept apart from the host's own `draft` so opening a row here never
 * disturbs one that is open in the table below, and the two are saved by their own
 * actions rather than sharing one set of fields.
 *
 * Requires the host component to have the table editor's `?int $editing` and
 * `cancelEdit()`, so deleting a topic that is open in both places closes both.
 */
trait ManagesRecentTopics
{
    /** The strip row currently open for editing, if any. */
    public ?int $editingRecent = null;

    /** That row's fields. */
    public array $recentDraft = [];

    /** Open a strip row for editing. Only for a topic this account added. */
    public function editRecent(int $id, TopicActions $actions): void
    {
        $topic = $this->recentTopicToChange($id, $actions);

        $this->editingRecent = $topic->id;
        $this->recentDraft = [
            'title' => (string) $topic->title,
            'category' => (string) $topic->category,
            'link' => (string) $topic->link,
        ];

        $this->resetValidation();
    }

    public function cancelRecentEdit(): void
    {
        $this->editingRecent = null;
        $this->recentDraft = [];
        $this->resetValidation();
    }

    /** Persist the open strip row. Re-checks ownership: the state is caller-supplied. */
    public function saveRecentEdit(TopicActions $actions): void
    {
        // Nothing is open, so there is no row to save: the same 404 the id would
        // have produced, rather than a type error on the null id.
        abort_if($this->editingRecent === null, 404);

        $this->validate(
            $actions->updateRules('recentDraft'),
            $actions->updateMessages('recentDraft'),
        );

        $actions->update($this->recentTopicToChange($this->editingRecent, $actions), $this->recentDraft);

        $this->cancelRecentEdit();

        $this->dispatch('toast', message: 'Topic updated.');
    }

    /**
     * Delete a topic from the strip. Refused for anyone but the account that added
     * it, and it closes the editor first so a half-typed title is never left
     * pointing at a row that is gone.
     */
    public function deleteRecent(int $id, TopicActions $actions): void
    {
        $topic = $this->recentTopicToChange($id, $actions);

        if ($this->editingRecent === $topic->id) {
            $this->cancelRecentEdit();
        }

        if (($this->editing ?? null) === $topic->id) {
            $this->cancelEdit();
        }

        $actions->delete($topic);

        $this->dispatch('toast', message: 'Topic deleted.');
    }

    /**
     * The topic a strip action is allowed to touch, or 403.
     *
     * Ownership is checked here, on every action, rather than trusted from the
     * rendered button: a component call arrives at the shared /livewire/update
     * endpoint, which carries neither the page's route middleware nor any proof that
     * the caller was ever shown the button.
     */
    private function recentTopicToChange(int $id, TopicActions $actions): Topic
    {
        $topic = $this->recentTopicInScope($id);

        abort_unless(
            $actions->ownsRecent($topic),
            403,
            'You can only edit or delete a topic you added yourself.',
        );

        return $topic;
    }

    /**
     * The topic as the host's own visibility rules already see it. The admin sees
     * every topic; a manager narrows this to their channels, so the strip cannot be
     * used as a way around the channel scope it is already filtered to.
     */
    protected function recentTopicInScope(int $id): Topic
    {
        return Topic::findOrFail($id);
    }
}
