<?php

/*
 * SPDX-License-Identifier: MIT
 */

declare(strict_types=1);

namespace Frootbox\Jobs\Tests;

use DateTimeImmutable;
use Frootbox\Core\Category\CreateCategory;
use Frootbox\Core\Page\PublishPages;
use Frootbox\Core\Plugin\CreateSpace;
use Frootbox\Forms\Form\AddField;
use Frootbox\Forms\Form\CreateForm;
use Frootbox\Forms\Form\FieldType;
use Frootbox\Forms\Form\PublishForm;
use Frootbox\Jobs\Blocks\JobList;
use Frootbox\Jobs\Blocks\JobTeaser;
use Frootbox\Jobs\Job\JobStore;
use Frootbox\Jobs\Job\ScheduleJob;
use Frootbox\Jobs\Job\UnpublishJob;
use Frootbox\Jobs\Job\Workplace;
use Frootbox\Jobs\JobPlugin;
use Frootbox\Jobs\Website\JobRendering;
use PHPUnit\Framework\Attributes\CoversClass;

#[CoversClass(JobList::class)]
#[CoversClass(JobTeaser::class)]
#[CoversClass(JobPlugin::class)]
#[CoversClass(JobRendering::class)]
final class WebsiteTest extends JobsTestCase
{
    private string $start;

    private string $jobs;

    protected function setUp(): void
    {
        parent::setUp();

        $this->start = $this->page(null, 'Start', [['block' => 'frootbox/jobs/JobTeaser', 'options' => ['count' => 2]]]);
        $this->jobs = $this->jobsPage($this->start, 'Jobs');
        $this->runner()->run(new PublishPages([$this->start, $this->jobs]));
    }

    public function testListsTheJobsTheNewestFirst(): void
    {
        $this->job(['de' => ['title' => 'Röster', 'teaser' => 'An der Maschine.']], ['full-time'], '2030-03-01');
        $this->job(['de' => ['title' => 'Barista']], ['part-time', 'mini-job']);
        $this->job(['en' => ['title' => 'Only English']]);

        $html = $this->html('/jobs');

        self::assertMatchesRegularExpression('~Barista.*Röster~s', $html, 'The newest first.');
        self::assertStringContainsString('<a href="/jobs/roester">Röster</a>', $html);
        self::assertStringContainsString('<span>Vollzeit</span><span class="jobs-facts__sep" aria-hidden="true"> · </span><span>Ab 1. März 2030</span>', $html);
        self::assertStringContainsString('<span>Teilzeit, Minijob</span><span class="jobs-facts__sep" aria-hidden="true"> · </span><span>Ab sofort</span>', $html);
        self::assertStringContainsString('<p class="jobs-item__text">An der Maschine.</p>', $html);
        self::assertStringNotContainsString('Only English', $html, 'Only jobs in the language of the page.');
    }

    public function testFiltersByKindAndLocationWithoutAScript(): void
    {
        $harbour = $this->location('Café am Hafen');
        $roastery = $this->location('Rösterei');
        $this->job(['de' => ['title' => 'Barista']], ['part-time'], location: $harbour);
        $this->job(['de' => ['title' => 'Röster']], ['full-time'], location: $roastery);
        $this->job(['de' => ['title' => 'Schichtleitung']], ['full-time'], location: $harbour);

        $html = $this->html('/jobs');
        self::assertStringContainsString('<nav class="jobs-filter" aria-label="Art der Stelle">', $html);
        self::assertStringContainsString('<a class="jobs-filter__link" href="/jobs" aria-current="page">Alle</a>', $html);
        self::assertStringContainsString('<a class="jobs-filter__link" href="/jobs?type=full-time">Vollzeit</a>', $html);
        self::assertStringContainsString('<a class="jobs-filter__link" href="/jobs?location=cafe-am-hafen">Café am Hafen</a>', $html);

        $html = $this->html('/jobs?type=full-time');
        self::assertStringContainsString('Röster', $html);
        self::assertStringContainsString('Schichtleitung', $html);
        self::assertStringNotContainsString('>Barista<', $html);
        self::assertStringContainsString('<a class="jobs-filter__link" href="/jobs?type=full-time&amp;location=roesterei">Rösterei</a>', $html, 'The other filter keeps the kind.');

        $html = $this->html('/jobs?type=full-time&location=cafe-am-hafen');
        self::assertStringContainsString('Schichtleitung', $html);
        self::assertStringNotContainsString('>Röster<', $html);

        self::assertStringContainsString('Barista', $this->html('/jobs?type=nothing'), 'An unknown filter shows all.');
    }

    public function testFiltersByCategoryWithTheCategoriesBelow(): void
    {
        $kitchen = $this->runner()->run(new CreateCategory(JobStore::CATEGORIES, ['de' => ['title' => 'Küche']]))->id;
        $pastry = $this->runner()->run(new CreateCategory(JobStore::CATEGORIES, ['de' => ['title' => 'Konditorei']], $kitchen))->id;
        $service = $this->runner()->run(new CreateCategory(JobStore::CATEGORIES, ['de' => ['title' => 'Service']]))->id;
        $this->runner()->run(new CreateCategory(JobStore::CATEGORIES, ['de' => ['title' => 'Verwaltung']]));
        $this->job(['de' => ['title' => 'Konditor']], categories: [$pastry]);
        $this->job(['de' => ['title' => 'Barista']], categories: [$service]);
        $this->job(['de' => ['title' => 'Spülkraft']], publish: false, categories: [$kitchen]);

        $html = $this->html('/jobs');
        self::assertStringContainsString('<nav class="jobs-filter" aria-label="Kategorie">', $html);
        self::assertStringContainsString('<a class="jobs-filter__link" href="/jobs?category=konditorei">Konditorei</a>', $html);
        self::assertStringNotContainsString('?category=kueche"', $html, 'Only categories with published jobs.');
        self::assertStringNotContainsString('Verwaltung', $html);

        $html = $this->html('/jobs?category=kueche');
        self::assertStringContainsString('Konditor', $html, 'With the categories below.');
        self::assertStringNotContainsString('>Barista<', $html);
        self::assertStringNotContainsString('Spülkraft', $html);
    }

    public function testShowsAJobAtItsAddressWithStructuredData(): void
    {
        $location = $this->location('Café am Hafen');
        $contact = $this->person('Lena', 'Hoffmann', 'lena@example.org');
        $id = $this->job(
            ['de' => ['title' => 'Barista (m/w/d)', 'teaser' => 'Für unser Café.', 'text' => self::text('Du bereitest Kaffee zu.')]],
            ['full-time', 'part-time'],
            '2030-03-01',
            Workplace::Hybrid,
            ['min' => 2800, 'max' => 3200, 'per' => 'month'],
            $location,
            $contact,
        );
        $this->runner()->run(new ScheduleJob($id, unpublishAt: new DateTimeImmutable('2030-02-15 23:59')));

        $html = $this->html('/jobs/barista-m-w-d');

        self::assertStringContainsString('<title>Barista (m/w/d) – Testsite</title>', $html);
        self::assertStringContainsString('<meta name="description" content="Für unser Café.">', $html);
        self::assertMatchesRegularExpression('~<h\d class="jobs-job__title">Barista \(m/w/d\)</h\d>~', $html);
        self::assertMatchesRegularExpression('~<span>Vollzeit, Teilzeit</span>.*<span>Teilweise remote</span>.*<span>Café am Hafen</span>.*<span>Ab 1. März 2030</span>.*<span>2\.800\s€ – 3\.200\s€ im Monat</span>~su', $html);
        self::assertStringContainsString('<p>Du bereitest Kaffee zu.</p>', $html);
        self::assertStringContainsString('<p class="jobs-contact__name">Lena Hoffmann</p>', $html);
        self::assertStringContainsString('<a class="jobs-job__mail" href="mailto:lena@example.org?subject=Barista%20%28m%2Fw%2Fd%29">Per E-Mail bewerben</a>', $html, 'Without a form the application goes by mail.');
        self::assertStringContainsString('<a href="/jobs">Alle Stellen</a>', $html);

        self::assertSame(1, preg_match('~<script type="application/ld\+json">(\{"@context":"https://schema.org","@type":"JobPosting".*?)</script>~', $html, $match));
        $data = json_decode($match[1], true, flags: \JSON_THROW_ON_ERROR);
        self::assertIsArray($data);
        self::assertSame('Barista (m/w/d)', $data['title']);
        self::assertSame('<p>Du bereitest Kaffee zu.</p>', $data['description']);
        self::assertSame(['FULL_TIME', 'PART_TIME'], $data['employmentType']);
        self::assertSame(['@type' => 'Organization', 'name' => 'Testsite', 'sameAs' => 'https://example.org'], $data['hiringOrganization']);
        self::assertSame(['@type' => 'Place', 'address' => ['@type' => 'PostalAddress', 'streetAddress' => 'Hafenstraße 12', 'postalCode' => '20457', 'addressLocality' => 'Hamburg', 'addressCountry' => 'DE']], $data['jobLocation']);
        self::assertSame(['@type' => 'MonetaryAmount', 'currency' => 'EUR', 'value' => ['@type' => 'QuantitativeValue', 'minValue' => 2800, 'maxValue' => 3200, 'unitText' => 'MONTH']], $data['baseSalary']);
        self::assertSame('2030-03-01', $data['jobStartDate']);
        self::assertIsString($data['validThrough'] ?? null);
        self::assertStringStartsWith('2030-02-15T23:59', $data['validThrough']);
        self::assertSame(new DateTimeImmutable()->format('Y-m-d'), $data['datePosted']);
        self::assertSame('https://example.org/jobs/barista-m-w-d', $data['url']);
        self::assertStringNotContainsString('jobs-list', $html, 'Not the list.');

        $this->runner()->run(new UnpublishJob($id));
        self::assertSame(410, $this->statusOf('/jobs/barista-m-w-d'));
    }

    public function testTheApplicationFormKnowsTheJob(): void
    {
        $form = $this->runner()->run(new CreateForm(['de' => ['title' => 'Bewerbung']], ['team@example.org']))->id;
        $this->runner()->run(new AddField($form, FieldType::Text, ['de' => ['label' => 'Name']], true));
        $this->runner()->run(new AddField($form, FieldType::Files, ['de' => ['label' => 'Lebenslauf']], true));
        $this->runner()->run(new PublishForm($form));
        $this->options($this->jobs, ['form' => $form]);
        $this->runner()->run(new PublishPages([$this->jobs]));
        $id = $this->job(['de' => ['title' => 'Barista']]);

        $html = $this->html('/jobs/barista');

        self::assertStringContainsString('<input type="hidden" name="_about" value="frootbox/jobs/job:' . $id . '">', $html);
        self::assertStringContainsString('enctype="multipart/form-data"', $html);
        self::assertMatchesRegularExpression('~<h1 class="jobs-job__title">Barista</h1>.*<h2 class="forms__title">Bewerbung</h2>~s', $html, 'The form sits one level below the job.');
        self::assertStringNotContainsString('jobs-job__mail', $html);
        self::assertStringNotContainsString('_about', $this->html('/jobs'), 'The list shows no form.');
    }

    public function testTheTeaserShowsTheNewestJobs(): void
    {
        $this->job(['de' => ['title' => 'Erste']]);
        $this->job(['de' => ['title' => 'Zweite']]);
        $this->job(['de' => ['title' => 'Dritte']]);

        $html = $this->html('/');

        self::assertMatchesRegularExpression('~Dritte.*Zweite~s', $html);
        self::assertStringNotContainsString('Erste', $html, 'Two of three.');
        self::assertStringContainsString('<a href="/jobs">Alle Stellen</a>', $html);
    }

    public function testASpaceHasItsJobsBelowItsOwnPage(): void
    {
        $training = $this->runner()->run(new CreateSpace(JobStore::TYPE, 'Ausbildung'))->id;
        $page = $this->jobsPage($this->jobs, 'Ausbildung', space: $training);
        $this->runner()->run(new PublishPages([$page]));
        $this->job(['de' => ['title' => 'Azubi Röster']], ['apprenticeship'], space: $training);
        $this->job(['de' => ['title' => 'Barista']]);

        self::assertSame(200, $this->statusOf('/jobs/ausbildung/azubi-roester'));
        self::assertSame(404, $this->statusOf('/jobs/azubi-roester'), 'Not below the page of another space.');
        self::assertStringNotContainsString('Barista', $this->html('/jobs/ausbildung'), 'Only the jobs of its space.');
        self::assertStringNotContainsString('Azubi Röster', $this->html('/jobs'));
    }
}
