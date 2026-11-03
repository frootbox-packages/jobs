<?php

/*
 * SPDX-License-Identifier: MIT
 */

declare(strict_types=1);

namespace Frootbox\Jobs;

use Frootbox\Backend\Screen;
use Frootbox\Backend\SearchCommand;
use Frootbox\Backend\SearchLink;
use Frootbox\Core\Category\CategoryGroup;
use Frootbox\Core\Extension\Extension;
use Frootbox\Core\Extension\Registry;
use Frootbox\Jobs\Backend\JobHandler;
use Frootbox\Jobs\Backend\JobsHandler;
use Frootbox\Jobs\Job\CreateJob;
use Frootbox\Jobs\Job\DeleteJob;
use Frootbox\Jobs\Job\DiscardJob;
use Frootbox\Jobs\Job\JobStore;
use Frootbox\Jobs\Job\ListJobs;
use Frootbox\Jobs\Job\PublishJobs;
use Frootbox\Jobs\Job\ScheduleJob;
use Frootbox\Jobs\Job\ShowJob;
use Frootbox\Jobs\Job\UnpublishJob;
use Frootbox\Jobs\Job\UpdateJob;

/**
 * Jobs as a plugin (decisions 0219, 0231, 0232): jobs with drafts in
 * spaces, their addresses below the page that shows the space, the scheduler and the search.
 * The screens come only with the backend.
 */
final class JobsExtension implements Extension
{
    public function register(Registry $registry): void
    {
        $registry->action(CreateJob::class);
        $registry->action(UpdateJob::class);
        $registry->action(PublishJobs::class);
        $registry->action(UnpublishJob::class);
        $registry->action(ScheduleJob::class);
        $registry->action(DiscardJob::class);
        $registry->action(DeleteJob::class);
        $registry->query(ListJobs::class);
        $registry->query(SpaceChoices::class);
        $registry->query(ShowJob::class);

        $registry->plugin(JobPlugin::class);
        $registry->add(CategoryGroup::class, new CategoryGroup(JobStore::CATEGORIES, 'Categories of the jobs', [JobStore::TYPE]));
        $registry->scheduled(JobSchedule::class);
        $registry->searchSource(JobSource::class);

        if (class_exists(Screen::class)) {
            $this->backend($registry);
        }
    }

    /**
     * The screens in the backend (decision 0220), only if it is installed.
     */
    private function backend(Registry $registry): void
    {
        $registry->mount('backend.path', '/cms')
            ->get('/vacancies', JobsHandler::class, 'frootbox.jobs.jobs')
            ->get('/vacancies/new', JobHandler::class, 'frootbox.jobs.jobs.new')
            ->get('/vacancies/{id:[0-9A-Za-z]{26}}', JobHandler::class, 'frootbox.jobs.jobs.show');

        $registry->add(Screen::class, new Screen('Jobs', 'frootbox.jobs.jobs', Screen::CONTENT, 'briefcase', JobsHandler::PERMISSION, order: 19));
        $registry->add(SearchLink::class, new SearchLink(JobStore::TYPE, 'frootbox.jobs.jobs.show', 'id', 'briefcase'));
        $registry->add(SearchCommand::class, new SearchCommand('New job', 'frootbox.jobs.jobs.new', 'frootbox/jobs/job.create'));
    }
}
