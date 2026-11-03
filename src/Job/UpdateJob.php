<?php

/*
 * SPDX-License-Identifier: MIT
 */

declare(strict_types=1);

namespace Frootbox\Jobs\Job;

use Frootbox\Core\Action\Action;
use Frootbox\Core\Action\AsAction;
use Frootbox\Core\Action\Schema\Description;
use Frootbox\Core\Action\Schema\ListOf;
use Frootbox\Core\Action\Schema\Pattern;
use Frootbox\Database\Ulid;

/**
 * @implements Action<JobDetails>
 */
#[AsAction('frootbox/jobs/job.update', 'Changes the draft of a job; what is left out stays as it is')]
final readonly class UpdateJob implements Action
{
    private const string NAME = 'frootbox/jobs/job.update';

    /**
     * @param array<mixed>|null $translations
     * @param list<string>|null $types
     * @param array<mixed>|null $salary
     * @param array<mixed>|null $image
     */
    public function __construct(
        #[Pattern(Ulid::PATTERN)]
        public string $id,
        #[Description('Changes by language, e.g. {"de": {"title": "Barista (m/w/d)"}, "en": null}: fields left out stay, null removes a language')]
        public ?array $translations = null,
        #[ListOf('string')]
        #[Description('Kinds of the job, replacing the ones before')]
        public ?array $types = null,
        #[Pattern('^(\d{4}-\d{2}-\d{2})?$')]
        #[Description('The first day, e.g. "2027-02-01"; "" for as soon as possible')]
        public ?string $start = null,
        public ?Workplace $workplace = null,
        #[Description('The salary, replacing the one before: {"min": 2800, "max": 3200, "per": "month"}; {} removes it')]
        public ?array $salary = null,
        #[Description('The id of a location; "" removes it')]
        public ?string $location = null,
        #[Description('The id of a person to contact; "" removes it')]
        public ?string $contact = null,
        #[Description('An image from the media library: {"media": "<id>", "alt": "…"}; {} removes it, left out keeps it')]
        public ?array $image = null,
    ) {}

    public function handle(JobEditor $editor, JobStore $store): JobDetails
    {
        $current = $store->current($this->id);
        $data = new JobData(
            null === $this->types ? $current->types : $editor->types(self::NAME, $this->types),
            null === $this->start ? $current->start : ('' === $this->start ? null : $this->start),
            $this->workplace ?? $current->workplace,
            null === $this->salary ? $current->salary : $editor->salary(self::NAME, $this->salary),
            null === $this->location ? $current->location : $editor->reference(self::NAME, 'location', $this->location),
            null === $this->contact ? $current->contact : $editor->reference(self::NAME, 'contact', $this->contact),
            match (true) {
                null === $this->image => $current->image,
                [] === $this->image => null,
                default => $this->image,
            },
            null === $this->translations ? $current->translations : $editor->translations(self::NAME, $this->translations, $current->translations),
        );

        return $editor->saveDraft(self::NAME, $this->id, $data);
    }
}
