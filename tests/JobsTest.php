<?php

/*
 * SPDX-License-Identifier: MIT
 */

declare(strict_types=1);

namespace Frootbox\Jobs\Tests;

use DateTimeImmutable;
use Frootbox\Core\Action\InvalidInputException;
use Frootbox\Core\Page\PublishPages;
use Frootbox\Core\Schedule\Scheduler;
use Frootbox\Core\Search\Search;
use Frootbox\Core\Url\Urls;
use Frootbox\Core\User\Roles;
use Frootbox\Jobs\Job\CreateJob;
use Frootbox\Jobs\Job\DeleteJob;
use Frootbox\Jobs\Job\DiscardJob;
use Frootbox\Jobs\Job\JobData;
use Frootbox\Jobs\Job\JobEditor;
use Frootbox\Jobs\Job\JobStatus;
use Frootbox\Jobs\Job\JobStore;
use Frootbox\Jobs\Job\JobType;
use Frootbox\Jobs\Job\ListJobs;
use Frootbox\Jobs\Job\PublishJobs;
use Frootbox\Jobs\Job\Salary;
use Frootbox\Jobs\Job\SalaryPeriod;
use Frootbox\Jobs\Job\ScheduleJob;
use Frootbox\Jobs\Job\ShowJob;
use Frootbox\Jobs\Job\UnpublishJob;
use Frootbox\Jobs\Job\UpdateJob;
use Frootbox\Jobs\Job\Workplace;
use Frootbox\Jobs\JobSource;
use PHPUnit\Framework\Attributes\CoversClass;

#[CoversClass(JobEditor::class)]
#[CoversClass(JobStore::class)]
#[CoversClass(JobData::class)]
#[CoversClass(Salary::class)]
#[CoversClass(CreateJob::class)]
#[CoversClass(UpdateJob::class)]
#[CoversClass(ListJobs::class)]
#[CoversClass(JobSource::class)]
final class JobsTest extends JobsTestCase
{
    public function testCreatesADraftAndPublishesIt(): void
    {
        $location = $this->location('Café am Hafen');
        $contact = $this->person('Lena', 'Hoffmann');
        $details = $this->runner()->run(new CreateJob(
            ['de' => ['title' => 'Barista (m/w/d)', 'teaser' => 'Für unser Café.', 'text' => self::text('Du bereitest Kaffee zu.')]],
            ['full-time', 'part-time', 'full-time'],
            '2027-02-01',
            Workplace::OnSite,
            ['min' => 2800, 'max' => 3200, 'per' => 'month'],
            $location,
            $contact,
        ));

        self::assertSame(JobStatus::Draft, $details->status);
        $data = $details->current();
        self::assertSame([JobType::FullTime, JobType::PartTime], $data->types, 'Each kind once.');
        self::assertSame('barista-m-w-d', $data->translations['de']->slug);
        self::assertSame('2027-02-01', $data->start);
        self::assertEquals(new Salary(2800, 3200, SalaryPeriod::Month, 'EUR'), $data->salary);
        self::assertSame([$location, $contact], [$data->location, $data->contact]);

        $this->runner()->run(new PublishJobs([$details->id]));

        $shown = $this->runner()->ask(new ShowJob($details->id));
        self::assertSame(JobStatus::Published, $shown->status);
        self::assertSame([], $shown->urls, 'Without a page with the list there is no address yet.');
    }

    public function testChecksWhatItTakesIn(): void
    {
        $errors = static function (callable $run): array {
            try {
                $run();
            } catch (InvalidInputException $error) {
                return array_keys($error->errors);
            }

            return [];
        };

        self::assertSame(['/types/0'], $errors(fn() => $this->runner()->run(new CreateJob(['de' => ['title' => 'A']], ['all-day']))));
        self::assertSame(['/salary/max'], $errors(fn() => $this->runner()->run(new CreateJob(['de' => ['title' => 'A']], salary: ['min' => 3000, 'max' => 2000]))));
        self::assertSame(['/salary/min', '/salary/per'], $errors(fn() => $this->runner()->run(new CreateJob(['de' => ['title' => 'A']], salary: ['min' => -1, 'per' => 'week']))));
        self::assertSame(['/location'], $errors(fn() => $this->runner()->run(new CreateJob(['de' => ['title' => 'A']], location: '01JNOLOCATION0000000000000'))));
        self::assertSame(['/start'], $errors(fn() => $this->runner()->run(new CreateJob(['de' => ['title' => 'A']], start: '2027-02-31'))));
    }

    public function testDraftsMayBeUnfinishedButPublishingChecksThem(): void
    {
        $id = $this->runner()->run(new CreateJob(['de' => ['title' => '', 'slug' => '']]))->id;

        try {
            $this->runner()->run(new PublishJobs([$id]));
            self::fail('A job without a title is not published.');
        } catch (InvalidInputException $error) {
            self::assertArrayHasKey('/ids/0/translations/de/title', $error->errors);
        }
    }

    public function testSlugsAreUniqueInALanguage(): void
    {
        $this->job(['de' => ['title' => 'Barista']]);
        $second = $this->job(['de' => ['title' => 'Barista', 'slug' => 'barista-2']]);

        try {
            $this->runner()->run(new UpdateJob($second, ['de' => ['slug' => 'barista']]));
            self::fail('The slug is taken.');
        } catch (InvalidInputException $error) {
            self::assertArrayHasKey('/translations/de/slug', $error->errors);
        }
    }

    public function testUpdatesOnlyWhatIsGiven(): void
    {
        $id = $this->job(['de' => ['title' => 'Barista']], ['part-time'], '2027-02-01', salary: ['min' => 14, 'per' => 'hour']);

        $this->runner()->run(new UpdateJob($id, workplace: Workplace::Hybrid));
        $data = $this->get(JobStore::class)->current($id);
        self::assertSame([[JobType::PartTime], '2027-02-01', Workplace::Hybrid, 14, SalaryPeriod::Hour], [$data->types, $data->start, $data->workplace, $data->salary?->min, $data->salary?->per]);

        $this->runner()->run(new UpdateJob($id, start: '', salary: ['min' => null, 'max' => null]));
        $data = $this->get(JobStore::class)->current($id);
        self::assertNull($data->start, 'As soon as possible.');
        self::assertNull($data->salary, 'Without amounts there is no salary.');
    }

    public function testTakesOfflineDiscardsAndDeletes(): void
    {
        $id = $this->job(['de' => ['title' => 'Barista']]);

        $offline = $this->runner()->run(new UnpublishJob($id));
        self::assertSame(JobStatus::Draft, $offline->status);
        self::assertNull($this->get(JobStore::class)->find($id));

        $this->runner()->run(new PublishJobs([$id]));
        $this->runner()->run(new UpdateJob($id, ['de' => ['title' => 'Barista (m/w/d)']]));
        $kept = $this->runner()->run(new DiscardJob($id));
        self::assertSame('Barista', $kept?->current()->translations['de']->title);

        $this->runner()->run(new DeleteJob($id));
        self::assertSame([], $this->get(JobStore::class)->ids());
    }

    public function testListsJobsByTitle(): void
    {
        $this->job(['de' => ['title' => 'Röster']]);
        $this->job(['de' => ['title' => 'Barista']], publish: false);

        $list = $this->runner()->ask(new ListJobs(search: null));

        self::assertSame(['Barista', 'Röster'], array_map(static fn($job): string => $job->title, $list->items));
        self::assertSame([JobStatus::Draft, JobStatus::Published], array_map(static fn($job): JobStatus => $job->status, $list->items));
        self::assertSame(['Röster'], array_map(static fn($job): string => $job->title, $this->runner()->ask(new ListJobs(search: 'rös'))->items));
    }

    public function testTheSchedulerTakesAJobOfflineAtTheEndOfApplications(): void
    {
        $id = $this->job(['de' => ['title' => 'Barista']]);
        $this->runner()->run(new ScheduleJob($id, unpublishAt: new DateTimeImmutable('+1 hour')));
        self::assertNotNull($this->get(JobStore::class)->find($id)?->until);

        $this->get(Scheduler::class)->run(new DateTimeImmutable('+2 hours'));

        self::assertNull($this->get(JobStore::class)->find($id));
    }

    public function testJobsAreFoundAndEditorsMayChangeThem(): void
    {
        $page = $this->jobsPage(null, 'Start');
        $this->runner()->run(new PublishPages([$page]));
        $id = $this->job(['de' => ['title' => 'Barista', 'teaser' => 'Milchschaum mit Herz.']]);

        $hits = $this->runner()->ask(new Search('milchschaum'));
        self::assertSame(['frootbox/jobs/job'], array_values(array_unique(array_map(static fn($hit): string => $hit->source, $hits))));
        self::assertSame($id, $hits[0]->item);
        self::assertSame('/barista', $this->get(Urls::class)->path(JobStore::target($id), 'de'));

        $published = iterator_to_array($this->get(JobSource::class)->published([$id]), false);
        self::assertSame(['Barista', 'de', JobStore::target($id)], [$published[0]->title, $published[0]->language, (string) $published[0]->target]);

        $editor = $this->get(Roles::class)->fetchOne(['where' => ['name' => 'editor']]);
        self::assertContains('frootbox/jobs/*', $editor->permissions ?? []);
    }
}
