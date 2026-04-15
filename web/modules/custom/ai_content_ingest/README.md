# AI Content Ingest

Staging pipeline for the AI content engine. Polls external sources (RSS via
`drupal/feeds`, GitHub activity via the public REST API), normalises each
inbound item into an unpublished **Source Item** node, and hands the backlog
off to [`ai_content_drafter`](../ai_content_drafter/README.md) for grouping
and AI drafting.

This module does **only** ingestion. It never calls an LLM. It never
publishes anything. It writes Source Item nodes with `status = 0` and that
is the entire surface it touches in the database.

---

## Why it exists

A solo author who runs a personal site wants articles that are grounded
in their own activity (commits, releases) and the wider news that matters
to them, without writing every word manually. The traditional approach is
"RSS reader → bookmark → write later", which never happens. This module
collapses the bookmark step into Drupal so the next stage of the pipeline
can act on it the moment a new event lands, with full deduplication and a
human-managed topic taxonomy as the organisational spine.

It is intentionally generic: target bundle, watched repos, polled event
types, and rate limits are all configuration. Nothing about this module is
specific to the site that ships it.

---

## Architecture

```
┌────────────────────────────── Drupal ──────────────────────────────┐
│                                                                    │
│   Topic taxonomy           Source Item content type                │
│  ┌──────────┐              ┌────────────────────────────────────┐  │
│  │ Topic 1  │◀─── ref ─────│ field_topic                        │  │
│  │ Topic 2  │              │ field_source_url                   │  │
│  │ ...      │              │ field_source_type  (rss | github)  │  │
│  └──────────┘              │ field_external_id  (dedup key)     │  │
│                            │ field_processed    (drafter flag)  │  │
│                            │ body                                │  │
│                            │ title                               │  │
│                            └─────────────▲──────────────────────┘  │
│                                          │ create unpublished      │
│                                          │                         │
│   ┌──────────────────┐         ┌─────────┴────────┐                │
│   │ drupal/feeds     │  RSS    │ Feeds importer   │                │
│   │ importer (admin- │────────▶│ (per source)     │                │
│   │ configured)      │         └──────────────────┘                │
│   └──────────────────┘                                             │
│                                                                    │
│   ┌──────────────────┐  cron   ┌──────────────────┐                │
│   │ Hook(cron)       │────────▶│ Queue:           │                │
│   │ AiContentIngest- │         │ ai_content_      │                │
│   │ Hooks            │         │ ingest_github    │                │
│   └──────────────────┘         └─────────┬────────┘                │
│                                          │ processItem             │
│                                          ▼                         │
│   ┌──────────────────┐         ┌──────────────────┐                │
│   │ GithubPollWorker │────────▶│ GithubPoller     │                │
│   │ (QueueWorker     │  poll() │ (Service)        │                │
│   │ plugin)          │         └─────────┬────────┘                │
│   └──────────────────┘                   │                         │
│                                          │ POST                    │
│                                          ▼                         │
│                                  ┌───────────────┐                 │
│                                  │  Drupal State │                 │
│                                  │  per-repo     │                 │
│                                  │  last_event   │                 │
│                                  └───────────────┘                 │
│                                                                    │
└────────────────────────────────────────────────────────────────────┘
                                          │
                                          │  HTTPS
                                          ▼
                              api.github.com/repos/.../events
                                  (public, no token)
```

---

## What goes where

### Content type: `Source Item`

Created at module install (well — by the admin step that scaffolds it
via `drush php:eval` or a recipe) with these fields:

| Field | Type | Required | Purpose |
|---|---|---|---|
| `title` | string | yes | Short human-readable summary built by the source-specific summariser |
| `body` | text_with_summary (plain_text) | no | The raw payload the AI drafter will read |
| `field_source_url` | link | yes | Canonical URL pointing back at the source (commit, release, RSS item) |
| `field_source_type` | list_string | yes | One of `rss` or `github` |
| `field_external_id` | string (255) | yes | Provider-side stable id used for deduplication |
| `field_topic` | entity_reference (taxonomy_term → topic) | no | Optional clustering hint |
| `field_processed` | boolean | no | Set to `1` by the drafter once the item has been written into a draft |

`status` is always `0`. There is no published view of a Source Item — it
is staging data, not published content.

### Taxonomy vocabulary: `Topic`

A flat list of subjects the site cares about. Admin-managed via the
standard Drupal taxonomy UI. Source Items optionally reference one term
through `field_topic`. The drafter uses the term as a primary clustering
key — items sharing a topic and falling inside a configurable time window
become a single draft.

There is no restriction on what topics exist. Renaming, merging, deleting
is the admin's call.

---

## Services

### `ai_content_ingest.github_poller`

Class: `Drupal\ai_content_ingest\Service\GithubPoller`

Polls one repository at a time and writes Source Items. Behaviour:

- Calls `https://api.github.com/repos/{owner}/{repo}/events` with the
  GitHub-recommended JSON Accept header and an explicit User-Agent.
- Walks the events list newest-first until it hits the last id it has
  already seen for this repo (tracked in Drupal's State API under
  `ai_content_ingest.github_last_event:{owner}/{repo}`). Stops at the
  first known id.
- Skips events whose `type` is not in the configured allowlist (default
  `PushEvent`, `ReleaseEvent`).
- Builds a per-event-type human summary:

| Event | Title | URL | Body |
|---|---|---|---|
| `PushEvent` (full payload) | "{repo}: N commits pushed to {branch}" | `/commit/{first_sha}` | newline-joined commit messages |
| `PushEvent` (compact payload) | "{repo}: push to {branch} (HEAD {sha8})" | `/compare/{before}...{head}` | "Push to {branch}. Head {head}. Compare {before}..{head}" |
| `ReleaseEvent` | "{repo}: release {name}" | `release.html_url` | release notes body |
| `PullRequestEvent` | "{repo}: PR {title} ({action})" | `pull_request.html_url` | PR body |
| `IssuesEvent` | "{repo}: issue {title} ({action})" | `issue.html_url` | issue body |

- For each summary, looks up `field_external_id = {event_id}` in node
  storage. If found, skips. If not, creates a new Source Item.
- Writes the new last-seen event id to State **after** the loop so
  partial failures don't lose the position.

Two layers of input validation guard against SSRF:

1. The settings form regex-validates each `owner/repo` slug against
   `^[A-Za-z0-9][A-Za-z0-9._-]{0,38}/[A-Za-z0-9._-]{1,100}$` before save.
2. `GithubPoller::poll()` re-applies the same regex before any HTTP call,
   so a caller bypassing the form (e.g. drush, custom code) cannot
   smuggle URL path segments into `api.github.com`.

The Guzzle request is bounded by a 10 s read timeout and 5 s connect
timeout. A slow GitHub response cannot stall the queue worker beyond
that.

### Hook class: `AiContentIngestHooks`

`#[Hook('cron')]` implementation that:

- Reads `ai_content_ingest.settings.github_repos` and
  `github_poll_interval_seconds` from config.
- Skips if no repos are configured.
- Skips if the last poll happened less than `github_poll_interval_seconds`
  ago (tracked in State under `ai_content_ingest.last_github_poll`).
- For each configured repo (after a defence-in-depth slug regex check),
  enqueues a `{'repo': '{owner}/{repo}'}` item into the
  `ai_content_ingest_github` queue.
- Updates the last-poll state.

Cron also runs the actual queue worker (`GithubPollWorker`) for that
queue under its 30-second per-cron-run time budget.

### Queue worker plugin: `GithubPollWorker`

`@QueueWorker(id = "ai_content_ingest_github")`

Receives `{'repo': string}` items, looks up the configured event types
from `ai_content_ingest.settings.github_event_types`, normalises short
names (`push` → `PushEvent`, `release` → `ReleaseEvent`,
`pr|pull_request` → `PullRequestEvent`, `issue|issues` → `IssuesEvent`),
and calls `GithubPoller::poll()`.

### CLI noise filter: `CliNoiseFilterLoggerFactory`

Decorates Drupal's `logger.factory` service to suppress one specific
class of noisy log entries: pathauto's pattern matcher logs a
`ContextException: Assigned contexts were not satisfied: node` whenever
a node is saved from a CLI/cron/queue worker context that has no active
route. The exception itself is harmless (pathauto catches it
internally) but pollutes drush stdout.

The decorator only activates under `PHP_SAPI === 'cli'` and only wraps
the `pathauto` channel. Web request logging is unchanged. No other
channels or messages are touched.

---

## Configuration

Stored in `ai_content_ingest.settings`. Edited via
`/admin/config/ai/content-ingest`.

| Key | Type | Default | Meaning |
|---|---|---|---|
| `target_bundle` | string | `article` | Target node bundle the drafter writes into. The drafter reads this. |
| `target_body_field` | string | `body` | Field machine name on the target bundle that receives the draft body |
| `default_moderation_state` | string | `draft` | Initial moderation state when content_moderation is enabled |
| `max_drafts_per_run` | integer | `5` | Hard cap on drafts the drafter creates per cron tick |
| `github_repos` | list of `owner/repo` strings | `[]` | Repositories to watch |
| `github_poll_interval_seconds` | integer | `3600` | Minimum seconds between polls per repo |
| `github_event_types` | list of strings | `[push, release]` | Allowlisted GitHub event types |

Empty `github_repos` short-circuits the cron hook — the module does
nothing at all until an admin opts in.

---

## Operational notes

- **First poll on a new repo** stores up to 30 events (one GitHub events
  page). Subsequent polls walk newest-first until they hit the previously
  stored event id and stop, so steady-state runs are tiny.
- **Public repositories only.** No OAuth/GitHub token support is wired
  up. If a configured repo is private, GitHub returns 404 and the poll
  logs an error + creates zero items.
- **Rate limit awareness.** GitHub's anonymous limit is 60 requests/hour
  per IP. With the default 1 h poll interval and a handful of repos,
  this is not close to being a problem. If it ever is, a future change
  can wire a `drupal/key`-backed token via the existing AI key pattern.
- **No content publishing.** This module never sets `status = 1`.
  Source Items only ever exist as unpublished staging data.

---

## Verification commands

```bash
# Show the current backlog
drush php:eval '$nids = \Drupal::entityQuery("node")->accessCheck(FALSE)->condition("type", "source_item")->condition("field_processed", 0)->execute(); echo count($nids) . " unprocessed source items\n";'

# Manually poll one repo (bypasses the cron throttle)
drush php:eval '$p = \Drupal::service("ai_content_ingest.github_poller"); echo $p->poll("madsnorgaard/drupal.madsnorgaard.net", ["PushEvent", "ReleaseEvent"]) . " new items\n";'

# Inspect what GitHub last-seen state we have for a repo
drush state:get 'ai_content_ingest.github_last_event:madsnorgaard/drupal.madsnorgaard.net'

# Reset the last-seen state to force a full re-poll on next run
drush state:delete 'ai_content_ingest.github_last_event:madsnorgaard/drupal.madsnorgaard.net'

# Validate one repo slug against the SSRF guard
php -r 'echo preg_match("#^[A-Za-z0-9][A-Za-z0-9._-]{0,38}/[A-Za-z0-9._-]{1,100}$#", "owner/repo") ? "ok\n" : "rejected\n";'
```

---

## Security model

| Surface | Hardening |
|---|---|
| External HTTP (GitHub) | 10 s read / 5 s connect timeouts. Strict response shape validation. JSON decode failures log + return zero items. |
| Repo slug input | Regex-validated at form submit and again inside the service before any HTTP call (defence in depth against SSRF / URL injection). |
| Logging | Errors are logged via the `ai_content_ingest` channel with placeholder substitution. No request bodies, headers, or repository contents are echoed to logs. |
| Saved nodes | `Xss` filtering happens downstream in `ai_content_drafter::DraftWriter` before any text from a Source Item reaches a published node. |
| Permission | `administer ai_content_ingest` permission is `restrict access: true`. The settings form uses it. |

---

## Extending

- **Add a new source type** — implement a service with a `poll()` method
  that follows the same pattern as `GithubPoller`, register it in
  `services.yml`, point a queue worker at it. The Source Item content
  type is generic enough to absorb any new source type by setting
  `field_source_type` to a new value.
- **Add per-repo topic mapping** — a future enhancement would let admins
  map `owner/repo` → topic id so created Source Items get
  `field_topic` set automatically. Today, the drafter clusters
  untagged items into a separate "untagged" bucket which is fine for
  starter use.
- **Switch the target bundle from article to something else** — change
  `ai_content_ingest.settings.target_bundle` and the configured
  `target_body_field`. The drafter respects both.

---

## Not implemented (deferred)

- GitHub authentication via `drupal/key` (private repos, higher rate
  limits)
- RSS Feeds importers shipped as default config (admins create their
  own via the Feeds UI)
- Webhook receiver endpoint (push-mode ingestion alongside the
  pull-mode poller)
- Automatic topic assignment via vector similarity at ingest time

---

## License

GPL-2.0-or-later, same as Drupal core.
