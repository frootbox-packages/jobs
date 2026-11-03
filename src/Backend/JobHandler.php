<?php

/*
 * SPDX-License-Identifier: MIT
 */

declare(strict_types=1);

namespace Frootbox\Jobs\Backend;

use Frootbox\Backend\View\BackendView;
use Frootbox\Core\Action\AccessDeniedException;
use Frootbox\Core\Action\ActionException;
use Frootbox\Core\Action\Runner;
use Frootbox\Core\Language\Languages;
use Frootbox\Core\Media\ShowMedium;
use Frootbox\Database\EntityNotFoundException;
use Frootbox\Jobs\Job\JobData;
use Frootbox\Jobs\Job\JobType;
use Frootbox\Jobs\Job\SalaryPeriod;
use Frootbox\Jobs\Job\ShowJob;
use Frootbox\Jobs\Job\Workplace;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\RequestHandlerInterface;

/**
 * A new job or one to edit (decisions 0220, 0231): text by language,
 * kind, start, workplace, salary, location, contact and image in the
 * draft; publishing, times, offline and deleting each run their own
 * action.
 */
final readonly class JobHandler implements RequestHandlerInterface
{
    public function __construct(
        private BackendView $view,
        private Runner $runner,
        private Languages $languages,
    ) {}

    public function handle(ServerRequestInterface $request): ResponseInterface
    {
        if (null === $this->view->user()) {
            return $this->view->requireUser($request) ?? $this->view->redirect('/');
        }

        $id = $request->getAttribute('id');
        $choices = [
            'types' => array_map(static fn(JobType $type): array => ['value' => $type->value, 'label' => $type->title()], JobType::cases()),
            'workplaces' => array_map(static fn(Workplace $place): array => ['value' => $place->value, 'label' => $place->title()], Workplace::cases()),
            'periods' => array_map(static fn(SalaryPeriod $period): array => ['value' => $period->value, 'label' => 'per ' . $period->value], SalaryPeriod::cases()),
            'locations' => $this->choices('frootbox/people/location.choices'),
            'people' => $this->choices('frootbox/people/person.choices'),
        ];

        if (!\is_string($id)) {
            $space = $request->getQueryParams()['space'] ?? null;

            return $this->view->render($request, '@frootbox/jobs/backend/job-new.twig', [
                'language' => $this->languages->default(),
                ...$choices,
                'space' => \is_string($space) && '' !== $space ? $space : null,
            ]);
        }

        $details = $this->runner->ask(new ShowJob($id));
        $chosen = $request->getQueryParams()['language'] ?? null;
        $language = \is_string($chosen) && $this->languages->has($chosen) ? $this->languages->get($chosen) : $this->languages->default();
        $data = $details->current();
        $default = $this->languages->default()->code;

        return $this->view->render($request, '@frootbox/jobs/backend/job.twig', [
            'job' => $details,
            'data' => $data,
            'title' => $this->title($data, $language->code, $default),
            'language' => $language,
            'languages' => $this->languages->all(),
            'translation' => $data->translations[$language->code] ?? null,
            'source' => $data->text($default),
            'image' => $this->image($data),
            ...$choices,
        ]);
    }

    private function title(JobData $data, string $language, string $default): string
    {
        foreach ([$language, $default] as $code) {
            $title = $data->translations[$code]->title ?? '';

            if ('' !== $title) {
                return $title;
            }
        }

        foreach ($data->translations as $text) {
            if ('' !== $text->title) {
                return $text->title;
            }
        }

        return '';
    }

    /**
     * Published locations or people of the module people as choices;
     * none if the user may not see them.
     *
     * @return list<array{value: string, label: string}>
     */
    private function choices(string $query): array
    {
        try {
            $result = $this->runner->call($query, []);
        } catch (ActionException) {
            return [];
        }

        $choices = [];

        foreach (\is_array($result) ? $result : [] as $choice) {
            if (\is_array($choice) && \is_string($choice['value'] ?? null) && \is_string($choice['label'] ?? null)) {
                $choices[] = ['value' => $choice['value'], 'label' => $choice['label']];
            }
        }

        return $choices;
    }

    /**
     * The preview and file name of the image, null without one or if it
     * is gone or may not be seen.
     *
     * @return array{preview: string, file: string}|null
     */
    private function image(JobData $data): ?array
    {
        $media = $data->media();

        if (null === $media) {
            return null;
        }

        try {
            $medium = $this->runner->ask(new ShowMedium($media));
        } catch (AccessDeniedException|EntityNotFoundException) {
            return null;
        }

        return ['preview' => $medium->preview, 'file' => $medium->fileName];
    }
}
