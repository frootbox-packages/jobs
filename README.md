# frootbox/jobs

Jobs for the Frootbox CMS: open positions with drafts, versions and
planned publishing, the kind of job, start, workplace, salary, a location
and a contact from `frootbox/people`, and two blocks for any theme.

- **Jobs** (`frootbox/jobs/JobList`) lists the open jobs, the newest
  first, filtered by kind (`?type=part-time`) and location
  (`?location=<slug of its name>`) without a script. Each published job
  gets an address below the first page with this block, e.g.
  `/jobs/barista`; there the block shows the job with its contact,
  structured data `JobPosting` and the application form chosen in its
  options (`form`, from `frootbox/forms`). Each entry of that form keeps
  the job it is about.
- **Newest jobs** (`frootbox/jobs/JobTeaser`) shows the newest few with a
  link to the list.

Jobs have no tables: drafts and published versions live in `revisions`.
When a job goes offline by itself (`unpublishAt`), search engines learn
the day as `validThrough`.

## Kinds of jobs

`full-time`, `part-time`, `mini-job`, `apprenticeship`, `dual-study`,
`internship`, `freelance`; a job may have several. The workplace is
`on-site`, `hybrid` or `remote`; a salary is
`{"min": 2800, "max": 3200, "per": "month", "currency": "EUR"}`.

## Actions and queries

Everything goes through actions, also from the REST API and the AI:
`frootbox/jobs/job.create`, `.update`, `.publish`, `.unpublish`,
`.schedule`, `.discard`, `.delete` and the queries
`frootbox/jobs/job.list` and `.show`. The role "editor" gets
`frootbox/jobs/*` on installation.

## In a template

```twig
<a href="{{ url('frootbox/jobs/job:' ~ id) }}">…</a>
```

## Backend

With `frootbox/backend` installed, the screen "Jobs" appears in the
sidebar at `/cms/vacancies`.

## License

MIT
