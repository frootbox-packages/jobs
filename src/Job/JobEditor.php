<?php

/*
 * SPDX-License-Identifier: MIT
 */

declare(strict_types=1);

namespace Frootbox\Jobs\Job;

use DateTimeImmutable;
use Frootbox\Core\Action\InvalidInputException;
use Frootbox\Core\Language\Languages;
use Frootbox\Core\Media\MediaEditor;
use Frootbox\Core\Page\Slug;
use Frootbox\Core\Plugin\PluginSpaces;
use Frootbox\Core\Revision\RevisionStore;
use Frootbox\Core\Support\Message;
use Frootbox\Core\Url\AddressSync;
use Frootbox\Database\EntityNotFoundException;
use Frootbox\People\Location\LocationStore;
use Frootbox\People\Person\PersonStore;
use Frootbox\RichText\RichText;

/**
 * Changes jobs (decision 0231): every change goes into the draft,
 * publishing puts it online and gives it its address. The actions of the
 * jobs call it.
 */
final readonly class JobEditor
{
    public const int TITLE_LENGTH = 300;

    public const int TEASER_LENGTH = 1000;

    /** A salary above this is a typo. */
    public const int SALARY_MAX = 10_000_000;

    public function __construct(
        private JobStore $store,
        private RevisionStore $revisions,
        private MediaEditor $media,
        private RichText $richText,
        private AddressSync $addresses,
        private Languages $languages,
        private LocationStore $locations,
        private PersonStore $people,
        private PluginSpaces $spaces,
    ) {}

    /**
     * Checks a draft and saves it. Drafts may be unfinished: an empty
     * title only counts when publishing (decision 0101).
     *
     * @throws InvalidInputException
     */
    public function saveDraft(string $action, string $id, JobData $data): JobDetails
    {
        $errors = $this->check($id, $data, false);

        if ([] !== $errors) {
            throw new InvalidInputException($action, $errors);
        }

        $this->revisions->saveDraft(JobStore::TYPE, $id, $data->toArray());

        return $this->store->details($id);
    }

    /**
     * Applies translations from an action to the current ones. A language
     * set to null is removed; fields left out are kept. A new language
     * without a slug gets one from its title.
     *
     *     {"de": {"title": "Barista (m/w/d)", "teaser": "…"}, "en": null}
     *
     * @param array<mixed>           $input
     * @param array<string, JobText> $current
     *
     * @return array<string, JobText>
     *
     * @throws InvalidInputException
     */
    public function translations(string $action, array $input, array $current = []): array
    {
        if ([] !== $input && array_is_list($input)) {
            throw new InvalidInputException($action, ['/translations' => 'must be an object by language code, e.g. {"de": {"title": "Barista (m/w/d)"}}.']);
        }

        $errors = [];

        foreach ($input as $language => $value) {
            $language = (string) $language;
            $at = '/translations/' . $language;

            if (null === $value) {
                unset($current[$language]);

                continue;
            }

            if (!\is_array($value) || ([] !== $value && array_is_list($value))) {
                $errors[$at] = 'must be an object with "title" and optionally "slug", "teaser" and "text".';

                continue;
            }

            $wrong = false;

            foreach (['title', 'slug', 'teaser'] as $key) {
                if (\array_key_exists($key, $value) && !\is_string($value[$key])) {
                    $errors[$at . '/' . $key] = 'must be a text.';
                    $wrong = true;
                }
            }

            if (\array_key_exists('text', $value) && !\is_array($value['text'])) {
                $errors[$at . '/text'] = 'must be rich text as Tiptap JSON, e.g. {"type": "doc", "content": []}.';
                $wrong = true;
            }

            if ($wrong) {
                continue;
            }

            $before = $current[$language] ?? null;
            $title = \is_string($value['title'] ?? null) ? trim($value['title']) : $before->title ?? '';
            $slug = \is_string($value['slug'] ?? null) ? trim($value['slug']) : $before->slug ?? '';

            if ('' === $slug && (null === $before || !\array_key_exists('slug', $value))) {
                $slug = Slug::fromTitle($title, $language);
            }

            $current[$language] = new JobText(
                $title,
                $slug,
                \is_string($value['teaser'] ?? null) ? trim($value['teaser']) : $before->teaser ?? '',
                \is_array($value['text'] ?? null) ? $value['text'] : $before->text ?? JobText::EMPTY_TEXT,
            );
        }

        if ([] !== $errors) {
            throw new InvalidInputException($action, $errors);
        }

        return $current;
    }

    /**
     * The kinds of a job from an action, e.g. ["full-time", "part-time"].
     *
     * @param list<string> $values
     *
     * @return list<JobType>
     *
     * @throws InvalidInputException
     */
    public function types(string $action, array $values): array
    {
        $types = [];
        $errors = [];

        foreach ($values as $index => $value) {
            $type = JobType::tryFrom($value);

            if (null === $type) {
                $errors['/types/' . $index] = new Message('must be one of {types}.', ['types' => implode(', ', array_map(static fn(JobType $type): string => $type->value, JobType::cases()))]);
            } elseif (!\in_array($type, $types, true)) {
                $types[] = $type;
            }
        }

        if ([] !== $errors) {
            throw new InvalidInputException($action, $errors);
        }

        return $types;
    }

    /**
     * A salary from an action: {"min": 2800, "max": 3200, "per": "month",
     * "currency": "EUR"}; {} or both amounts empty for none.
     *
     * @param array<mixed> $value
     *
     * @throws InvalidInputException
     */
    public function salary(string $action, array $value): ?Salary
    {
        $errors = [];

        foreach (['min', 'max'] as $key) {
            if (null !== ($value[$key] ?? null) && (!\is_int($value[$key]) || $value[$key] < 0 || $value[$key] > self::SALARY_MAX)) {
                $errors['/salary/' . $key] = new Message('must be a whole amount from 0 to {max}.', ['max' => self::SALARY_MAX]);
            }
        }

        if (null !== ($value['per'] ?? null) && (!\is_string($value['per']) || null === SalaryPeriod::tryFrom($value['per']))) {
            $errors['/salary/per'] = 'must be "hour", "month" or "year".';
        }

        if (null !== ($value['currency'] ?? null) && (!\is_string($value['currency']) || 1 !== preg_match(Salary::CURRENCY_PATTERN, $value['currency']))) {
            $errors['/salary/currency'] = 'must be a code of three capital letters, e.g. "EUR".';
        }

        if ([] === $errors && \is_int($value['min'] ?? null) && \is_int($value['max'] ?? null) && $value['max'] < $value['min']) {
            $errors['/salary/max'] = 'must not be below "min".';
        }

        if ([] !== $errors) {
            throw new InvalidInputException($action, $errors);
        }

        return Salary::fromArray($value);
    }

    /**
     * A location or person of the module people: "" for none, else it
     * must exist. One deleted later simply drops out on the website.
     *
     * @throws InvalidInputException
     */
    public function reference(string $action, string $field, string $id): ?string
    {
        $id = trim($id);

        if ('' === $id) {
            return null;
        }

        $known = 'location' === $field ? $this->locations->ids() : $this->people->ids();

        if (!\in_array($id, $known, true)) {
            throw new InvalidInputException($action, ['/' . $field => 'location' === $field ? new Message('There is no location {id}.', ['id' => $id]) : new Message('There is no person {id}.', ['id' => $id])]);
        }

        return $id;
    }

    /**
     * Publishes the drafts of jobs together.
     *
     * @param list<string> $ids
     *
     * @return list<JobDetails>
     *
     * @throws InvalidInputException
     */
    public function publish(string $action, array $ids): array
    {
        $ids = array_values(array_unique($ids));

        if ([] === $ids) {
            throw new InvalidInputException($action, ['/ids' => 'needs at least one job id.']);
        }

        $errors = [];
        $drafts = [];

        foreach ($ids as $index => $id) {
            $draft = $this->store->draft($id);

            if (null === $draft) {
                $errors['/ids/' . $index] = \sprintf('The job %s has no draft to publish.', $id);

                continue;
            }

            $drafts[$id] = $draft;

            foreach ($this->check($id, $draft, true, $ids) as $pointer => $message) {
                $errors['/ids/' . $index . $pointer] = $message;
            }
        }

        // Two jobs of the same batch may not take the same address.
        $taken = [];

        foreach ($drafts as $id => $draft) {
            foreach ($draft->translations as $language => $text) {
                $key = $language . '|' . $text->slug;

                if (isset($taken[$key])) {
                    $errors['/ids/' . array_search($id, $ids, true) . '/translations/' . $language . '/slug'] = new Message('Another job has the slug "{slug}" already.', ['slug' => $text->slug]);
                }

                $taken[$key] = true;
            }
        }

        if ([] !== $errors) {
            throw new InvalidInputException($action, $errors);
        }

        $media = [];

        foreach ($drafts as $id => $draft) {
            $this->revisions->publish(JobStore::TYPE, $id);

            if (null !== $draft->media()) {
                $media[] = $draft->media();
            }
        }

        // Media become public with the first published job that uses them.
        $this->media->makePublic($media);
        $this->addresses->sync();

        return array_map($this->store->details(...), $ids);
    }

    /**
     * Takes a job offline; it stays as a draft.
     *
     * @throws InvalidInputException
     */
    public function unpublish(string $action, string $id): JobDetails
    {
        $published = $this->store->published($id) ?? throw new InvalidInputException($action, ['/id' => \sprintf('The job %s is not published.', $id)]);

        if (null === $this->store->draft($id)) {
            $this->revisions->saveDraft(JobStore::TYPE, $id, $published->toArray());
        }

        $this->revisions->archive(JobStore::TYPE, $id);
        $this->addresses->sync();

        return $this->store->details($id);
    }

    /**
     * Removes the draft. A job that was never published is gone then.
     *
     * @return JobDetails|null null if the job is gone
     *
     * @throws InvalidInputException
     */
    public function discard(string $action, string $id): ?JobDetails
    {
        if (null === $this->store->draft($id)) {
            throw new InvalidInputException($action, ['/id' => 'The job has no draft.']);
        }

        $this->revisions->discard(JobStore::TYPE, $id);

        return null === $this->store->published($id) ? null : $this->store->details($id);
    }

    /**
     * Deletes the published state and the draft. The versions stay.
     *
     * @throws EntityNotFoundException
     */
    public function delete(string $id): void
    {
        $this->store->details($id);
        $published = null !== $this->store->published($id);

        $this->revisions->discard(JobStore::TYPE, $id);
        $this->revisions->archive(JobStore::TYPE, $id);

        if ($published) {
            $this->addresses->sync();
        }
    }

    /**
     * Plans when the draft is published and when the job goes offline,
     * e.g. at the end of applications.
     *
     * @throws InvalidInputException
     * @throws EntityNotFoundException
     */
    public function schedule(string $action, string $id, ?DateTimeImmutable $publishAt, ?DateTimeImmutable $unpublishAt): JobDetails
    {
        $this->store->details($id);
        $errors = [];

        if (!$this->revisions->publishAt(JobStore::TYPE, $id, $publishAt) && null !== $publishAt) {
            $errors['/publishAt'] = 'The job has no draft to publish.';
        }

        if (!$this->revisions->unpublishAt(JobStore::TYPE, $id, $unpublishAt) && null !== $unpublishAt) {
            $errors['/unpublishAt'] = 'The job is not published.';
        }

        if (null !== $publishAt && null !== $unpublishAt && $unpublishAt <= $publishAt) {
            $errors['/unpublishAt'] = 'must be after "publishAt".';
        }

        if ([] !== $errors) {
            throw new InvalidInputException($action, $errors);
        }

        return $this->store->details($id);
    }

    /**
     * The errors of a job by JSON pointer.
     *
     * @param list<string> $batch Jobs published together, their slugs are checked among themselves
     *
     * @return array<string, string|Message>
     */
    private function check(string $id, JobData $data, bool $publishing, array $batch = []): array
    {
        $errors = [];

        if ([] === $data->translations) {
            $errors['/translations'] = 'needs at least one language, e.g. {"de": {"title": "Barista (m/w/d)"}}.';
        }

        $slugs = $this->slugs([$id, ...$batch], $publishing);

        foreach ($data->translations as $language => $text) {
            $at = '/translations/' . $language;

            if (!$this->languages->has($language)) {
                $errors[$at] = new Message('"{language}" is no language of the website.', ['language' => $language]);

                continue;
            }

            if ('' === $text->title && $publishing) {
                $errors[$at . '/title'] = 'is required.';
            } elseif (mb_strlen($text->title) > self::TITLE_LENGTH) {
                $errors[$at . '/title'] = new Message('must be at most {max} characters.', ['max' => self::TITLE_LENGTH]);
            }

            if ('' === $text->slug) {
                if ($publishing || '' !== $text->title) {
                    $errors[$at . '/slug'] = 'is required.';
                }
            } elseif (mb_strlen($text->slug) > Slug::MAX_LENGTH || 1 !== preg_match('/' . Slug::PATTERN . '/u', $text->slug)) {
                $errors[$at . '/slug'] = new Message('must be at most {max} lower-case letters, digits and hyphens, e.g. "neue-sorten".', ['max' => Slug::MAX_LENGTH]);
            } elseif (isset($slugs[$language . '|' . $text->slug])) {
                $errors[$at . '/slug'] = new Message('Another job has the slug "{slug}" already.', ['slug' => $text->slug]);
            }

            if (mb_strlen($text->teaser) > self::TEASER_LENGTH) {
                $errors[$at . '/teaser'] = new Message('must be at most {max} characters.', ['max' => self::TEASER_LENGTH]);
            }

            foreach ($this->richText->validate($text->text) as $violation) {
                $errors[$at . '/text' . $violation->pointer] = $violation->message;
            }
        }

        $day = null === $data->start ? false : DateTimeImmutable::createFromFormat('!Y-m-d', $data->start);

        if (null !== $data->start && (false === $day || $day->format('Y-m-d') !== $data->start)) {
            $errors['/start'] = 'must be a day, e.g. "2027-02-01".';
        }

        if (!$this->spaces->exists(JobStore::TYPE, $data->space)) {
            $errors['/space'] = 'There is no such space of the jobs.';
        }

        if (null !== $data->image && (null === $data->media() || 1 !== preg_match(JobData::ID_PATTERN, $data->media()))) {
            $errors['/image'] = 'must be an image as {"media": "<id>", "alt": "…"}.';
        }

        return $errors;
    }

    /**
     * The slugs other jobs hold, as "language|slug": when publishing
     * those that are published, else also those of drafts.
     *
     * @param list<string> $except
     *
     * @return array<string, true>
     */
    private function slugs(array $except, bool $published): array
    {
        $slugs = [];

        foreach ($this->store->ids() as $id) {
            if (\in_array($id, $except, true)) {
                continue;
            }

            foreach (array_filter([$this->store->published($id), $published ? null : $this->store->draft($id)]) as $data) {
                foreach ($data->translations as $language => $text) {
                    $slugs[$language . '|' . $text->slug] = true;
                }
            }
        }

        return $slugs;
    }
}
