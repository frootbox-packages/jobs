<?php

/*
 * SPDX-License-Identifier: MIT
 */

declare(strict_types=1);

namespace Frootbox\Jobs\Tests;

use Frootbox\Core\Action\AccessDeniedException;
use Frootbox\Core\Action\Action;
use Frootbox\Core\Action\Channel;
use Frootbox\Core\Action\Context;
use Frootbox\Core\Action\Query;
use Frootbox\Core\Action\Undo\UndoAction;
use Frootbox\Core\Auth\Actor;
use Frootbox\Core\Plugin\CreateSpace;
use Frootbox\Core\Plugin\Space;
use Frootbox\Core\Plugin\SpaceScopes;
use Frootbox\Core\Search\Search;
use Frootbox\Core\User\AssignRole;
use Frootbox\Core\User\CreateUser;
use Frootbox\Jobs\Job\CreateJob;
use Frootbox\Jobs\Job\DeleteJob;
use Frootbox\Jobs\Job\DiscardJob;
use Frootbox\Jobs\Job\JobStore;
use Frootbox\Jobs\Job\JobSummary;
use Frootbox\Jobs\Job\ListJobs;
use Frootbox\Jobs\Job\PublishJobs;
use Frootbox\Jobs\Job\ScheduleJob;
use Frootbox\Jobs\Job\ShowJob;
use Frootbox\Jobs\Job\UnpublishJob;
use Frootbox\Jobs\Job\UpdateJob;
use Frootbox\Jobs\JobPlugin;
use Frootbox\Jobs\JobSource;
use PHPUnit\Framework\Attributes\CoversClass;

/**
 * An editor with a role only within one space of the jobs (decision 0245).
 */
#[CoversClass(JobPlugin::class)]
#[CoversClass(CreateJob::class)]
#[CoversClass(UpdateJob::class)]
#[CoversClass(PublishJobs::class)]
#[CoversClass(ListJobs::class)]
#[CoversClass(JobSource::class)]
final class SpaceRightsTest extends JobsTestCase
{
    private string $training;

    private string $cook;

    private string $apprentice;

    private Context $editor;

    protected function setUp(): void
    {
        parent::setUp();

        $this->training = $this->runner()->run(new CreateSpace(JobStore::TYPE, 'Ausbildung'))->id;
        $this->cook = $this->job(['de' => ['title' => 'Koch (m/w/d)', 'teaser' => 'Für die Küche am Hafen.']]);
        $this->apprentice = $this->job(['de' => ['title' => 'Azubi Koch (m/w/d)', 'teaser' => 'Lernen in der Küche am Hafen.']], space: $this->training);

        $user = $this->runner()->run(new CreateUser('jane@example.org', 'Jane', 'kaffeebohne42'))->user;
        $this->runner()->run(new AssignRole($user->id, 'editor', 'space:' . SpaceScopes::id(JobStore::TYPE, $this->training)));
        $this->editor = new Context(Actor::user($user->id, $user->name), Channel::Api);
    }

    public function testWorksOnlyOnTheJobsOfTheSpace(): void
    {
        $new = $this->allowed(new CreateJob(['de' => ['title' => 'Azubi Service']], space: $this->training))->id;
        $this->denied(new CreateJob(['de' => ['title' => 'Service']]), 'Not in the default space.');

        $this->allowed(new UpdateJob($this->apprentice, ['de' => ['teaser' => 'Drei Jahre in der Küche.']]));
        $this->denied(new UpdateJob($this->cook, ['de' => ['teaser' => 'Neu.']]));

        $this->allowed(new PublishJobs([$this->apprentice, $new]));
        $this->denied(new PublishJobs([$this->apprentice, $this->cook]), 'Every job counts.');
        $this->allowed(new ScheduleJob($this->apprentice));
        $this->allowed(new UnpublishJob($this->apprentice));
        $this->denied(new UnpublishJob($this->cook));
        $this->allowed(new UpdateJob($new, ['de' => ['teaser' => 'Bald.']]));
        $this->allowed(new DiscardJob($new));
        $this->denied(new DeleteJob($this->cook));

        self::assertSame($this->apprentice, $this->runner()->ask(new ShowJob($this->apprentice), $this->editor)->id);
        $this->deniedQuery(new ShowJob($this->cook));

        // What the editor did, the editor may undo.
        $this->runner()->run(new UpdateJob($this->apprentice, ['de' => ['teaser' => 'Rückgängig.']]), $this->editor);
        $this->allowed(new UndoAction($this->lastEntry()->id));
    }

    public function testMovesOnlyBetweenSpacesWithTheRole(): void
    {
        $this->denied(new UpdateJob($this->apprentice, space: Space::DEFAULT), 'The target needs the role too.');
        $this->denied(new UpdateJob($this->cook, space: $this->training), 'So does the source.');

        $archive = $this->runner()->run(new CreateSpace(JobStore::TYPE, 'Archiv'))->id;
        $this->runner()->run(new AssignRole($this->editor->actor->id ?? 0, 'editor', 'space:' . SpaceScopes::id(JobStore::TYPE, $archive)));
        $this->allowed(new UpdateJob($this->apprentice, space: $archive));
        self::assertSame($archive, $this->get(JobPlugin::class)->space($this->apprentice), 'The draft decides.');
    }

    public function testListsAndFindsOnlyTheJobsOfTheSpace(): void
    {
        self::assertSame(['Azubi Koch (m/w/d)'], self::titles($this->runner()->ask(new ListJobs(), $this->editor)->items));
        self::assertSame([], self::titles($this->runner()->ask(new ListJobs(space: Space::DEFAULT), $this->editor)->items));
        self::assertSame(['Azubi Koch (m/w/d)', 'Koch (m/w/d)'], self::titles($this->runner()->ask(new ListJobs())->items), 'A role everywhere sees all.');

        $found = array_map(static fn($hit): string => $hit->item, $this->runner()->ask(new Search('hafen'), $this->editor));
        self::assertSame([$this->apprentice], array_values(array_unique($found)));
        self::assertCount(2, array_unique(array_map(static fn($hit): string => $hit->item, $this->runner()->ask(new Search('hafen')))));
    }

    /**
     * @param list<JobSummary> $items
     *
     * @return list<string>
     */
    private static function titles(array $items): array
    {
        return array_map(static fn(JobSummary $job): string => $job->title, $items);
    }

    /**
     * @template T
     *
     * @param Action<T> $action
     *
     * @return T
     */
    private function allowed(Action $action): mixed
    {
        return $this->runner()->run($action, $this->editor);
    }

    /**
     * @param Action<mixed> $action
     */
    private function denied(Action $action, string $message = ''): void
    {
        try {
            $this->runner()->run($action, $this->editor);
            self::fail(\sprintf('%s was allowed. %s', $action::class, $message));
        } catch (AccessDeniedException) {
            self::assertSame('denied', $this->lastEntry()->status->value, $message);
        }
    }

    /**
     * @param Query<mixed> $query
     */
    private function deniedQuery(Query $query): void
    {
        try {
            $this->runner()->ask($query, $this->editor);
            self::fail(\sprintf('%s was allowed.', $query::class));
        } catch (AccessDeniedException) {
            $this->addToAssertionCount(1);
        }
    }
}
