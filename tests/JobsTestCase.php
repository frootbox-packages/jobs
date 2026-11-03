<?php

/*
 * SPDX-License-Identifier: MIT
 */

declare(strict_types=1);

namespace Frootbox\Jobs\Tests;

use Frootbox\Core\Config\Environment;
use Frootbox\Core\Extension\ExtensionPackage;
use Frootbox\Core\Extension\Extensions;
use Frootbox\Core\Http\Exception\HttpException;
use Frootbox\Core\Page\CreatePage;
use Frootbox\Core\Page\PageHandler;
use Frootbox\Core\Page\ShowPage;
use Frootbox\Core\Page\TranslationData;
use Frootbox\Core\Page\UpdateBlock;
use Frootbox\Core\Plugin\AssignPlugin;
use Frootbox\Core\Plugin\Space;
use Frootbox\Core\Tests\Support\FixtureSite;
use Frootbox\Core\Tests\Support\NotesTestCase;
use Frootbox\Forms\Entry\Entries;
use Frootbox\Forms\Website\Sendings;
use Frootbox\Jobs\Job\CreateJob;
use Frootbox\Jobs\Job\JobStore;
use Frootbox\Jobs\Job\PublishJobs;
use Frootbox\Jobs\Job\Workplace;
use Frootbox\People\Location\CreateLocation;
use Frootbox\People\Location\PublishLocation;
use Frootbox\People\Person\CreatePerson;
use Frootbox\People\Person\PublishPerson;
use Frootbox\People\Tests\PeopleTestCase;
use Nyholm\Psr7\ServerRequest;
use Psr\Http\Message\ResponseInterface;

/**
 * A website of the core fixtures with jobs, people and forms installed.
 */
abstract class JobsTestCase extends NotesTestCase
{
    public const string ROOT = __DIR__ . '/..';

    public static function package(): ExtensionPackage
    {
        return ExtensionPackage::fromManifest('frootbox/jobs', (string) realpath(self::ROOT), psr4: ['Frootbox\Jobs\\' => ['src/'], 'Frootbox\Jobs\Migrations\\' => ['migrations/']]);
    }

    public static function forms(): ExtensionPackage
    {
        return ExtensionPackage::fromManifest('frootbox/forms', (string) realpath(self::ROOT . '/../forms'), psr4: ['Frootbox\Forms\\' => ['src/'], 'Frootbox\Forms\Migrations\\' => ['migrations/']]);
    }

    protected function tables(): array
    {
        return [Entries::TABLE, Sendings::TABLE];
    }

    protected function environment(): Environment
    {
        return Environment::Production;
    }

    protected function extensions(): Extensions
    {
        return new Extensions([...FixtureSite::extensions('base', 'site')->packages, PeopleTestCase::package(), self::forms(), self::package()], 'jobs');
    }

    protected function config(): array
    {
        return ['app' => ['name' => 'Testsite', 'url' => 'https://example.org'], 'theme' => 'acme/site', 'languages' => ['de' => ['title' => 'Deutsch'], 'en' => ['title' => 'English']]];
    }

    protected function env(): array
    {
        return ['MAILER_DSN' => 'memory://default'];
    }

    /**
     * A page, with the given blocks in its main area.
     *
     * @param list<array<string, mixed>> $blocks
     */
    protected function page(?string $parentId, string $title, array $blocks = [], string $language = 'de'): string
    {
        $content = ['fields' => ['heading' => $title], 'areas' => ['main' => $blocks]];

        return $this->runner()->run(new CreatePage('acme/base/Page', [$language => ['title' => $title, 'content' => $content]], $parentId))->id;
    }

    /**
     * A page that shows the jobs (decision 0232), with the options of the
     * view of the plugin.
     *
     * @param array<string, mixed> $options
     */
    protected function jobsPage(?string $parentId, string $title, array $options = [], string $space = Space::DEFAULT): string
    {
        $id = $this->page($parentId, $title);
        $details = $this->runner()->run(new AssignPlugin($id, JobStore::TYPE, $space));

        if ([] !== $options) {
            $this->options($id, $options, $details->draft->translations ?? []);
        }

        return $id;
    }

    /**
     * Sets the options of the view of the plugin of a page.
     *
     * @param array<string, mixed>             $options
     * @param array<string, TranslationData>|null $translations
     */
    protected function options(string $id, array $options, ?array $translations = null): void
    {
        $details = null === $translations ? $this->runner()->ask(new ShowPage($id)) : null;

        foreach ($translations ?? ($details->draft ?? $details?->published)->translations ?? [] as $language => $translation) {
            $view = $translation->content['plugin'] ?? null;

            if (\is_array($view) && \is_string($view['id'] ?? null)) {
                $this->runner()->run(new UpdateBlock($id, $language, $view['id'], options: $options));
            }
        }
    }

    /**
     * A job, published unless told otherwise.
     *
     * @param array<string, mixed> $translations
     * @param list<string>         $types
     * @param array<mixed>|null    $salary
     * @param list<string>         $categories
     * @param list<string>         $tags
     */
    protected function job(array $translations, array $types = ['full-time'], string $start = '', Workplace $workplace = Workplace::OnSite, ?array $salary = null, string $location = '', string $contact = '', bool $publish = true, string $space = Space::DEFAULT, array $categories = [], array $tags = []): string
    {
        $details = $this->runner()->run(new CreateJob($translations, $types, $start, $workplace, $salary, $location, $contact, space: $space, categories: $categories, tags: $tags));

        if ($publish) {
            $this->runner()->run(new PublishJobs([$details->id]));
        }

        return $details->id;
    }

    /**
     * A published location of the module people.
     */
    protected function location(string $name, string $city = 'Hamburg'): string
    {
        $id = $this->runner()->run(new CreateLocation(['de' => ['name' => $name]], 'Hafenstraße 12', '20457', $city, 'DE'))->id;
        $this->runner()->run(new PublishLocation($id));

        return $id;
    }

    /**
     * A published person of the module people.
     */
    protected function person(string $givenName, string $familyName, string $email = ''): string
    {
        $id = $this->runner()->run(new CreatePerson($givenName, $familyName, email: $email, translations: ['de' => ['role' => 'Leitung Café']]))->id;
        $this->runner()->run(new PublishPerson($id));

        return $id;
    }

    protected function request(string $path): ResponseInterface
    {
        return $this->get(PageHandler::class)->handle(new ServerRequest('GET', 'https://example.org' . $path));
    }

    protected function html(string $path): string
    {
        return (string) $this->request($path)->getBody();
    }

    protected function statusOf(string $path): int
    {
        try {
            return $this->request($path)->getStatusCode();
        } catch (HttpException $error) {
            return $error->status;
        }
    }

    /**
     * Rich text with one paragraph.
     *
     * @return array<string, mixed>
     */
    protected static function text(string $paragraph): array
    {
        return ['type' => 'doc', 'content' => [['type' => 'paragraph', 'content' => [['type' => 'text', 'text' => $paragraph]]]]];
    }
}
