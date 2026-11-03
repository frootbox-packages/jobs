<?php

/*
 * SPDX-License-Identifier: MIT
 */

declare(strict_types=1);

namespace Frootbox\Jobs\Tests;

use Frootbox\Backend\Tests\Support\BackendTestCase;
use Frootbox\Core\Action\Context;
use Frootbox\Core\Extension\Extensions;
use Frootbox\Core\Plugin\CreateSpace;
use Frootbox\Core\Plugin\SpaceScopes;
use Frootbox\Core\User\AssignRole;
use Frootbox\Core\User\CreateRole;
use Frootbox\Jobs\Backend\JobHandler;
use Frootbox\Jobs\Backend\JobsHandler;
use Frootbox\Jobs\Job\CreateJob;
use Frootbox\Jobs\Job\JobStore;
use Frootbox\Jobs\JobsExtension;
use Frootbox\People\Tests\PeopleTestCase;
use PHPUnit\Framework\Attributes\CoversClass;

#[CoversClass(JobsHandler::class)]
#[CoversClass(JobHandler::class)]
#[CoversClass(JobsExtension::class)]
final class BackendTest extends BackendTestCase
{
    protected function extensions(): Extensions
    {
        return new Extensions([self::backend(), PeopleTestCase::package(), JobsTestCase::package()], 'jobs-backend');
    }

    protected function config(): array
    {
        return ['languages' => ['de' => ['title' => 'Deutsch'], 'en' => ['title' => 'English']]];
    }

    public function testEditorsWriteAndPublishJobs(): void
    {
        $this->createUser('editor@example.org', ['editor']);
        $session = $this->signIn('editor@example.org');
        $location = $this->id($this->action('frootbox/people/location.create', ['translations' => ['de' => ['name' => 'Café am Hafen']], 'city' => 'Hamburg'], $session));
        $this->action('frootbox/people/location.publish', ['id' => $location], $session);

        $list = $this->html($this->request('GET', '/cms/vacancies', session: $session));
        self::assertStringContainsString('href="/cms/vacancies" aria-current="page"', $list, 'The sidebar has the screen.');
        self::assertStringContainsString('href="/cms/vacancies/new"', $list);
        self::assertStringContainsString('Noch keine Stellen', $list);

        $new = $this->html($this->request('GET', '/cms/vacancies/new', session: $session));
        self::assertStringContainsString('<fb-form action="frootbox/jobs/job.create" next="/cms/vacancies/{id}"', $new);
        self::assertStringContainsString('<fb-rich-text', $new);
        self::assertStringContainsString('name="types[]"', $new);
        self::assertStringContainsString('name="salary.min"', $new);
        self::assertStringContainsString('<option value="' . $location . '">Café am Hafen</option>', $new, 'The locations of the module people.');

        $created = $this->action('frootbox/jobs/job.create', [
            'translations' => ['de' => ['title' => 'Barista (m/w/d)', 'teaser' => 'Für unser Café.', 'text' => ['type' => 'doc', 'content' => []]]],
            'types' => ['part-time'],
            'start' => '',
            'workplace' => 'on-site',
            'salary' => ['min' => 14, 'max' => null, 'per' => 'hour', 'currency' => 'EUR'],
            'location' => $location,
            'contact' => '',
            'image' => [],
            'tags' => ['Quereinstieg'],
        ], $session);
        $id = $this->id($created);

        $show = $this->html($this->request('GET', '/cms/vacancies/' . $id, session: $session));
        self::assertStringContainsString('<h1 class="fb-heading-1" tabindex="-1" data-fb-focus>Barista (m/w/d)</h1>', $show);
        self::assertStringContainsString('value="barista-m-w-d"', $show);
        self::assertMatchesRegularExpression('/name="types\[\]"[^>]*value="part-time"[^>]*checked/', $show);
        self::assertMatchesRegularExpression('~name="tags\[\]" value="[0-9A-Z]{26}" checked>\s*<span class="fb-check__text"><span>Quereinstieg</span>~', $show, 'A typed word became a tag.');
        self::assertStringContainsString('<option value="' . $location . '" selected>Café am Hafen</option>', $show);
        self::assertStringContainsString('<option value="hour" selected>pro Stunde</option>', $show);
        self::assertStringContainsString('value="14"', $show);
        self::assertStringContainsString('<fb-form action="frootbox/jobs/job.publish"', $show);

        $this->action('frootbox/jobs/job.publish', ['ids' => [$id]], $session);
        $published = $this->html($this->request('GET', '/cms/vacancies/' . $id, session: $session));
        self::assertStringContainsString('commandfor="job-unpublish"', $published);

        $list = $this->html($this->request('GET', '/cms/vacancies', session: $session));
        self::assertStringContainsString('href="/cms/vacancies/' . $id . '">Barista (m/w/d)</a>', $list);
        self::assertStringContainsString('Teilzeit', $list);
    }

    public function testOthersSeeNoJobs(): void
    {
        $this->runner()->run(new CreateRole('guest-writer', 'Writer', ['page.tree']), Context::system());
        $this->createUser('writer@example.org', ['guest-writer']);
        $session = $this->signIn('writer@example.org');

        self::assertStringNotContainsString('href="/cms/vacancies"', $this->html($this->request('GET', '/cms', session: $session)));
        self::assertSame(403, $this->request('GET', '/cms/vacancies', session: $session)->getStatusCode());
    }

    public function testAnEditorWithinASpaceSeesOnlyItsJobs(): void
    {
        $training = $this->runner()->run(new CreateSpace(JobStore::TYPE, 'Ausbildung'), Context::system())->id;
        $outside = $this->runner()->run(new CreateJob(['de' => ['title' => 'Koch (m/w/d)']]), Context::system())->id;
        $inside = $this->runner()->run(new CreateJob(['de' => ['title' => 'Azubi Koch (m/w/d)']], space: $training), Context::system())->id;
        $trainer = $this->createUser('trainer@example.org', []);
        $this->runner()->run(new AssignRole($trainer, 'editor', 'space:' . SpaceScopes::id(JobStore::TYPE, $training)), Context::system());
        $session = $this->signIn('trainer@example.org');

        // One space only: no tabs, its list, new jobs go there (decision 0245).
        $list = $this->html($this->request('GET', '/cms/vacancies', session: $session));
        self::assertStringContainsString('href="/cms/vacancies/' . $inside . '"', $list);
        self::assertStringNotContainsString('href="/cms/vacancies/' . $outside . '"', $list);
        self::assertStringNotContainsString('class="fb-tabs"', $list);
        self::assertStringContainsString('href="/cms/vacancies/new?space=' . $training . '"', $list);
        self::assertSame(403, $this->request('GET', '/cms/vacancies/' . $outside, session: $session)->getStatusCode());

        $show = $this->html($this->request('GET', '/cms/vacancies/' . $inside, session: $session));
        self::assertStringNotContainsString('name="space"', $show, 'Nowhere to move it to.');

        // With a second space the tabs and the choice show only these two.
        $archive = $this->runner()->run(new CreateSpace(JobStore::TYPE, 'Archiv'), Context::system())->id;
        $this->runner()->run(new AssignRole($trainer, 'editor', 'space:' . SpaceScopes::id(JobStore::TYPE, $archive)), Context::system());
        $list = $this->html($this->request('GET', '/cms/vacancies', session: $session));
        self::assertStringContainsString('?space=' . $training . '"', $list);
        self::assertStringContainsString('?space=' . $archive . '"', $list);
        self::assertStringNotContainsString('?space=default', $list);
        $show = $this->html($this->request('GET', '/cms/vacancies/' . $inside, session: $session));
        self::assertStringContainsString('<option value="' . $archive . '">Archiv</option>', $show);
        self::assertStringNotContainsString('<option value="default"', $show);

        $this->createUser('admin@example.org', ['admin']);
        $user = $this->html($this->request('GET', '/cms/users/' . $trainer, session: $this->signIn('admin@example.org')));
        self::assertStringContainsString('id="user-spaces"', $user);
        self::assertStringContainsString('Stellenangebote · Ausbildung', $user);
        self::assertStringContainsString('value="space:frootbox/jobs/job:default"', $user, 'Every space can be chosen.');
    }

    /**
     * @param array<mixed> $answer
     */
    private function id(array $answer): string
    {
        self::assertIsArray($answer['data'] ?? null);
        $id = $answer['data']['id'] ?? null;
        self::assertIsString($id);

        return $id;
    }
}
