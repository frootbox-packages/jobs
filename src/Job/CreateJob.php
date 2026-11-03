<?php

/*
 * SPDX-License-Identifier: MIT
 */

declare(strict_types=1);

namespace Frootbox\Jobs\Job;

use Frootbox\Core\Action\Action;
use Frootbox\Core\Action\AsAction;
use Frootbox\Core\Action\Schema\Description;
use Frootbox\Core\Action\Schema\Length;
use Frootbox\Core\Action\Schema\ListOf;
use Frootbox\Core\Action\Schema\Pattern;
use Frootbox\Core\Plugin\Space;
use Frootbox\Database\Ulid;

/**
 * @implements Action<JobDetails>
 */
#[AsAction('frootbox/jobs/job.create', 'Creates a job as a draft; frootbox/jobs/job.publish puts it online')]
final readonly class CreateJob implements Action
{
    private const string NAME = 'frootbox/jobs/job.create';

    /**
     * @param array<mixed>      $translations
     * @param list<string>      $types
     * @param array<mixed>|null $salary
     * @param array<mixed>|null $image
     * @param list<string>      $categories
     * @param list<string>      $tags
     */
    public function __construct(
        #[Description('Title, slug, teaser and text by language: {"de": {"title": "Barista (m/w/d)", "teaser": "…", "text": {"type": "doc", "content": []}}}. The slug is made from the title if left out; the text holds tasks, profile and what the job offers.')]
        public array $translations,
        #[ListOf('string')]
        #[Description('Kinds of the job: full-time, part-time, mini-job, apprenticeship, dual-study, internship, freelance')]
        public array $types = [],
        #[Pattern('^(\d{4}-\d{2}-\d{2})?$')]
        #[Description('The first day, e.g. "2027-02-01"; left out or "" for as soon as possible')]
        public string $start = '',
        #[Description('Where the work is done: on-site, hybrid or remote')]
        public Workplace $workplace = Workplace::OnSite,
        #[Description('The salary in whole units, e.g. {"min": 2800, "max": 3200, "per": "month", "currency": "EUR"}; one amount is enough')]
        public ?array $salary = null,
        #[Description('The id of a location from frootbox/people/location.list, or ""')]
        public string $location = '',
        #[Description('The id of a person to contact from frootbox/people/person.list, or ""')]
        public string $contact = '',
        #[Description('An image from the media library: {"media": "<id>", "alt": "…"}')]
        public ?array $image = null,
        #[Length(1, 64)]
        #[Description('The space of the jobs, "default" for the default one; see space.list')]
        public string $space = Space::DEFAULT,
        #[ListOf('string')]
        #[Description('Ids of categories, see category-group.list and category.list')]
        public array $categories = [],
        #[ListOf('string')]
        #[Description('Ids, keys or labels of tags; a new word becomes a tag unless only presets count, see tag.choices')]
        public array $tags = [],
    ) {}

    public function handle(JobEditor $editor): JobDetails
    {
        $data = new JobData(
            $editor->types(self::NAME, $this->types),
            '' === $this->start ? null : $this->start,
            $this->workplace,
            null === $this->salary ? null : $editor->salary(self::NAME, $this->salary),
            $editor->reference(self::NAME, 'location', $this->location),
            $editor->reference(self::NAME, 'contact', $this->contact),
            [] === $this->image ? null : $this->image,
            $editor->translations(self::NAME, $this->translations),
            $this->space,
            $editor->categories(self::NAME, $this->categories),
            $editor->tags(self::NAME, $this->space, $this->tags),
        );

        return $editor->saveDraft(self::NAME, Ulid::generate(), $data);
    }
}
