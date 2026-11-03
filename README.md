# frootbox/jobs

Jobs for the Frootbox CMS: open positions with drafts, versions and
planned publishing, the kind of job, start, workplace, salary, a location
and a contact from `frootbox/people`, categories, tags and two blocks
for any theme.

- **Jobs** (`frootbox/jobs/JobList`) is the view of the plugin (decision
  0232): a page that shows a space of the jobs lists its open jobs at
  `plugin()`, the newest first, filtered by category (`?category=<slug>`, with
  the categories below it), kind (`?type=part-time`) and location
  (`?location=<slug of its name>`) and tag (`?tag=<slug>`) without a
  script. Each
  published job gets an address below that page, e.g. `/jobs/barista`;
  there the view shows the job with its contact,
  structured data `JobPosting` and the application form chosen in its
  options (`form`, from `frootbox/forms`). Each entry of that form keeps
  the job it is about.
- **Newest jobs** (`frootbox/jobs/JobTeaser`) shows the newest few of all
  spaces or of one, optionally only those with a tag, with a link to the
  list.

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
`frootbox/jobs/job.list` and `.show`. Categories are the ones of the
core (decision 0233) in the group `frootbox/jobs/categories`; the
actions take `categories` as a list of their ids. Tags are the common
stock of the core (decision 0239): the actions take `tags` as ids, keys
or labels, and new words become tags unless `config/tags.yaml` closes
the list for `frootbox/jobs/job` or the space. The role "editor"
gets `frootbox/jobs/*` on installation.

Further fields (decision 0240): a view of the theme declares them under
`object: fields:` in its header, e.g. an override of `JobList`, and
the actions take them as `fields`; `object.fields` with the type
`frootbox/jobs/job` lists them. The form in the backend shows them under
"More fields".

## In a template

```twig
<a href="{{ url('frootbox/jobs/job:' ~ id) }}">…</a>
{{ object_field(job, 'subtitle') }}  {# a further field, job as the view gets it #}
```

## Backend

With `frootbox/backend` installed, the screen "Jobs" appears in the
sidebar at `/cms/vacancies`.

## License

MIT
