<?php

/*
 * SPDX-License-Identifier: MIT
 */

declare(strict_types=1);

namespace Frootbox\Jobs\Job;

/**
 * A job as its draft or published version holds it (decision 0231).
 */
final readonly class JobData
{
    public const string DATE_PATTERN = '/^\d{4}-\d{2}-\d{2}$/';

    public const string ID_PATTERN = '/^[0-9A-HJKMNP-TV-Z]{26}$/';

    /**
     * @param list<JobType>          $types        Full time, part time …; several are fine
     * @param string|null            $start        The first day as "2027-02-01", null for as soon as possible
     * @param string|null            $location     The id of a location of the module people
     * @param string|null            $contact      The id of a person of the module people
     * @param array<mixed>|null      $image        The value of an image field: {"media": "01J…", "alt": "…"}
     * @param array<string, JobText> $translations By language
     */
    public function __construct(
        public array $types = [],
        public ?string $start = null,
        public Workplace $workplace = Workplace::OnSite,
        public ?Salary $salary = null,
        public ?string $location = null,
        public ?string $contact = null,
        public ?array $image = null,
        public array $translations = [],
    ) {}

    /**
     * @param array<mixed> $data
     */
    public static function fromArray(array $data): self
    {
        $translations = [];

        foreach (\is_array($data['translations'] ?? null) ? $data['translations'] : [] as $language => $text) {
            if (\is_array($text)) {
                $translations[(string) $language] = JobText::fromArray($text);
            }
        }

        $types = [];

        foreach (\is_array($data['types'] ?? null) ? $data['types'] : [] as $type) {
            $type = \is_string($type) ? JobType::tryFrom($type) : null;

            if (null !== $type && !\in_array($type, $types, true)) {
                $types[] = $type;
            }
        }

        return new self(
            $types,
            \is_string($data['start'] ?? null) && 1 === preg_match(self::DATE_PATTERN, $data['start']) ? $data['start'] : null,
            \is_string($data['workplace'] ?? null) ? Workplace::tryFrom($data['workplace']) ?? Workplace::OnSite : Workplace::OnSite,
            \is_array($data['salary'] ?? null) ? Salary::fromArray($data['salary']) : null,
            self::id($data['location'] ?? null),
            self::id($data['contact'] ?? null),
            \is_array($data['image'] ?? null) ? $data['image'] : null,
            $translations,
        );
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'types' => array_map(static fn(JobType $type): string => $type->value, $this->types),
            'start' => $this->start,
            'workplace' => $this->workplace->value,
            'salary' => $this->salary?->toArray(),
            'location' => $this->location,
            'contact' => $this->contact,
            'image' => $this->image,
            'translations' => array_map(static fn(JobText $text): array => $text->toArray(), $this->translations),
        ];
    }

    /**
     * The id of the image, if any.
     */
    public function media(): ?string
    {
        return \is_string($this->image['media'] ?? null) ? $this->image['media'] : null;
    }

    /**
     * The texts in a language, else in the first one.
     */
    public function text(string $language): ?JobText
    {
        return $this->translations[$language] ?? ([] === $this->translations ? null : array_values($this->translations)[0]);
    }

    private static function id(mixed $value): ?string
    {
        return \is_string($value) && 1 === preg_match(self::ID_PATTERN, $value) ? $value : null;
    }
}
