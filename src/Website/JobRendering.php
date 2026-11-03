<?php

/*
 * SPDX-License-Identifier: MIT
 */

declare(strict_types=1);

namespace Frootbox\Jobs\Website;

use DateTimeImmutable;
use DateTimeInterface;
use Frootbox\Core\Config\Config;
use Frootbox\Core\Field\Field;
use Frootbox\Core\Field\FieldTypes;
use Frootbox\Core\Url\Urls;
use Frootbox\Jobs\Job\JobData;
use Frootbox\Jobs\Job\JobStore;
use Frootbox\Jobs\Job\JobText;
use Frootbox\Jobs\Job\JobType;
use Frootbox\Jobs\Job\PublishedJob;
use Frootbox\Jobs\Job\Salary;
use Frootbox\Jobs\Job\Workplace;
use Frootbox\People\Location\LocationData;
use Frootbox\People\Location\LocationStore;
use Frootbox\People\Website\Cards;
use IntlDateFormatter;
use NumberFormatter;
use Twig\Markup;

/**
 * A job as the views of the blocks use it (decision 0231): texts, what
 * kind of job it is, place, start and salary in the words of the
 * language, the image as HTML and the structured data for search
 * engines. Texts for visitors stay English keys, the views translate them.
 */
final readonly class JobRendering
{
    /** The image of a teaser: three columns on wide screens. */
    public const string TEASER_SIZES = '(min-width: 72rem) 24rem, (min-width: 40rem) 50vw, 100vw';

    /** The image above the text of a job. */
    public const string DETAIL_SIZES = '(min-width: 72rem) 48rem, 100vw';

    public function __construct(
        private FieldTypes $types,
        private Urls $urls,
        private Config $config,
        private LocationStore $locations,
        private Cards $cards,
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function teaser(PublishedJob $job, string $language, bool $image = true): array
    {
        $text = $job->data->text($language) ?? new JobText('', '');
        $location = $this->location($job->data);

        return [
            'id' => $job->id,
            // For object_field() and object_value() (decision 0240).
            'target' => JobStore::target($job->id),
            'title' => $text->title,
            'teaser' => $text->teaser,
            'url' => $this->url($job->id, $language),
            'types' => array_map(static fn(JobType $type): string => $type->title(), $job->data->types),
            'workplace' => Workplace::OnSite === $job->data->workplace ? null : $job->data->workplace->title(),
            'location' => null === $location ? null : $location->text($language)->name,
            'start' => null === $job->data->start ? null : self::date($job->data->start, $language),
            'image' => $image ? $this->image($job->data, '3:2', self::TEASER_SIZES, false) : '',
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function detail(PublishedJob $job, string $language): array
    {
        $text = $job->data->text($language) ?? new JobText('', '');
        $html = $this->types->get('richtext')->output($text->text, new Field('text', 'richtext', 'Text'));

        return [
            ...$this->teaser($job, $language),
            'salary' => null === $job->data->salary ? null : self::salary($job->data->salary, $language),
            'per' => $job->data->salary?->per->value,
            'posted' => self::formatted($job->posted, $language),
            'postedAt' => $job->posted->format('Y-m-d'),
            'image' => $this->image($job->data, null, self::DETAIL_SIZES, true),
            'text' => $html,
            'contact' => null === $job->data->contact ? null : $this->cards->contact($job->data->contact, $language),
            'data' => new Markup(self::json($this->structured($job, $text, \is_string($html) || $html instanceof Markup ? (string) $html : '', $language)), 'UTF-8'),
        ];
    }

    public function url(string $id, string $language): ?string
    {
        return $this->urls->href(JobStore::target($id), $language);
    }

    /**
     * The published location of a job, null without one.
     */
    public function location(JobData $data): ?LocationData
    {
        return null === $data->location ? null : $this->locations->published($data->location);
    }

    /**
     * An amount or range in the words of the language, e.g. "2.800 – 3.200 €".
     */
    public static function salary(Salary $salary, string $language): string
    {
        $format = static function (int $amount) use ($salary, $language): string {
            if (class_exists(NumberFormatter::class)) {
                $formatter = new NumberFormatter($language, NumberFormatter::CURRENCY);
                $formatter->setTextAttribute(NumberFormatter::CURRENCY_CODE, $salary->currency);
                $formatter->setAttribute(NumberFormatter::MAX_FRACTION_DIGITS, 0);
                $formatted = $formatter->format($amount);

                if (\is_string($formatted)) {
                    return $formatted;
                }
            }

            return number_format($amount, 0, ',', '.') . ' ' . $salary->currency;
        };

        return match (true) {
            null !== $salary->min && null !== $salary->max && $salary->min !== $salary->max => $format($salary->min) . ' – ' . $format($salary->max),
            default => $format($salary->min ?? $salary->max ?? 0),
        };
    }

    /**
     * A day as "2027-02-01" in the words of the language, e.g. "1. Februar 2027".
     */
    public static function date(string $day, string $language): string
    {
        $date = DateTimeImmutable::createFromFormat('!Y-m-d', $day);

        return false === $date ? $day : self::formatted($date, $language);
    }

    private static function formatted(DateTimeInterface $date, string $language): string
    {
        if (class_exists(IntlDateFormatter::class)) {
            $formatted = new IntlDateFormatter($language, IntlDateFormatter::LONG, IntlDateFormatter::NONE, $date->getTimezone())->format($date);

            if (\is_string($formatted)) {
                return $formatted;
            }
        }

        return $date->format('Y-m-d');
    }

    /**
     * A job for search engines (schema.org JobPosting): kind, place,
     * salary, when it was posted and until when one can apply.
     *
     * @return array<string, mixed>
     */
    private function structured(PublishedJob $job, JobText $text, string $html, string $language): array
    {
        $data = $job->data;
        $location = $this->location($data);
        // The address of the website from the config: blocks do not know the request.
        $site = $this->config->string('app.url', '');
        $site = '' === $site ? null : rtrim($site, '/');
        $name = $this->config->string('app.name', '');
        $types = array_values(array_unique(array_map(static fn(JobType $type): string => $type->schema(), $data->types)));

        return array_filter([
            '@context' => 'https://schema.org',
            '@type' => 'JobPosting',
            'title' => $text->title,
            'description' => '' === trim(strip_tags($html)) ? ('' === $text->teaser ? $text->title : $text->teaser) : $html,
            'datePosted' => $job->posted->format('Y-m-d'),
            'validThrough' => $job->until?->format(DateTimeInterface::ATOM),
            'employmentType' => match (\count($types)) {
                0 => null,
                1 => $types[0],
                default => $types,
            },
            'hiringOrganization' => '' === $name ? null : array_filter(['@type' => 'Organization', 'name' => $name, 'sameAs' => $site], static fn(?string $value): bool => null !== $value),
            'jobLocation' => null === $location || [] === $location->address() ? null : [
                '@type' => 'Place',
                'address' => array_filter([
                    '@type' => 'PostalAddress',
                    'streetAddress' => $location->street,
                    'postalCode' => $location->postalCode,
                    'addressLocality' => $location->city,
                    'addressCountry' => $location->country,
                ], static fn(string $value): bool => '' !== $value),
            ],
            'jobLocationType' => Workplace::Remote === $data->workplace ? 'TELECOMMUTE' : null,
            'applicantLocationRequirements' => Workplace::Remote === $data->workplace && null !== $location && '' !== $location->country ? ['@type' => 'Country', 'name' => $location->country] : null,
            'jobStartDate' => $data->start,
            'baseSalary' => null === $data->salary ? null : [
                '@type' => 'MonetaryAmount',
                'currency' => $data->salary->currency,
                'value' => array_filter([
                    '@type' => 'QuantitativeValue',
                    'minValue' => $data->salary->min,
                    'maxValue' => $data->salary->max,
                    'unitText' => $data->salary->per->schema(),
                ], static fn(int|string|null $value): bool => null !== $value),
            ],
            'url' => $this->urls->absolute(JobStore::target($job->id), $language, $site),
        ], static fn(mixed $value): bool => null !== $value);
    }

    /**
     * @param array<string, mixed> $data
     */
    private static function json(array $data): string
    {
        // "</" cannot end the script early.
        return str_replace('</', '<\/', json_encode($data, \JSON_UNESCAPED_SLASHES | \JSON_UNESCAPED_UNICODE | \JSON_THROW_ON_ERROR));
    }

    private function image(JobData $data, ?string $ratio, string $sizes, bool $eager): Markup|string
    {
        if (null === $data->image) {
            return '';
        }

        $output = $this->types->get('image')->output($data->image, new Field('image', 'image', 'Image', settings: array_filter(['ratio' => $ratio, 'sizes' => $sizes, 'eager' => $eager], static fn(mixed $value): bool => null !== $value)));

        return $output instanceof Markup ? $output : (\is_string($output) ? $output : '');
    }
}
