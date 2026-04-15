# AI Content Drafter

The drafting half of the AI content engine. Reads unprocessed Source Items
from [`ai_content_ingest`](../ai_content_ingest/README.md), groups them into
clusters, asks a large language model to write an article in the author's
voice grounded in the source material, fills SEO metadata, injects semantic
internal links, and saves the result as an unpublished node in a content
moderation `draft` state.

It also exposes a public **semantic search endpoint** at
`/api/semantic-search` for the Nuxt frontend, and (optionally, via
`drupal/ai_ckeditor` and `drupal/ai_content_suggestions`) backs the
inline field-assistance buttons inside the node edit form with a local
Ollama model so interactive edits never touch a hosted API.

This module is a hard always-draft system. It will never auto-publish.
The invariant is enforced in code, not just in config.

---

## Why it exists

A drafted article that needs human review is always more useful than no
draft at all. The bottleneck on a personal site is not "what should I
write about" — that's a steady stream of commits, releases, and news.
The bottleneck is the activation energy of starting a draft. This module
removes that step: by the time the author opens the admin, there are
already three or four article drafts based on real recent activity, each
in their voice, each with valid metadata, each with two or three internal
links to existing related content, each one click away from publishing
or rejecting.

The same vector index that powers the drafter's "find related existing
posts" lookup also powers the public semantic search endpoint, so the
frontend gets meaningful "find similar" results for free.

---

## Pipeline

```
┌─────────── Source Items (unpublished, field_processed = 0) ────────────┐
│                                                                        │
│  src/Service/SourceItemGrouper                                         │
│    1. Pull all unprocessed Source Items                                │
│    2. Bucket by field_topic (untagged → its own bucket)                │
│    3. Within each bucket, split by time window                         │
│    4. Cap each cluster at max_group_size                               │
│                                                                        │
└──────────┬─────────────────────────────────────────────────────────────┘
           │ list<list<NodeInterface>>
           ▼
┌──────────────────────────────────────────────────────────────────────────┐
│                                                                          │
│  src/Service/DraftPipeline::draftCluster (per cluster)                   │
│                                                                          │
│  ┌─────────────────────────────┐   ┌────────────────────────────────┐    │
│  │ StyleSampleCollector        │   │ search_api content index        │   │
│  │ - internalSamples()         │──▶│ + dense_vector re-rank          │   │
│  │   (Solr kNN over rendered_  │   │ + Ollama nomic-embed-text       │   │
│  │    item, top-N)             │   │ → top-N semantically similar    │   │
│  │ - externalSamples()         │   │   articles + projects + WX      │   │
│  │   (cached fetch from public │   └────────────────────────────────┘    │
│  │    WP REST endpoint)        │                                         │
│  └─────────────────────────────┘                                         │
│                                                                          │
│            ▼                                                             │
│                                                                          │
│  ┌─────────────────────────────┐                                         │
│  │ Anthropic chat (or override │   1st chat call → body draft           │
│  │  global ai.settings default │   System: voice + structure            │
│  │  with the drafter override) │   User: source material + style        │
│  └─────────────────────────────┘                                         │
│            ▼                                                             │
│                                                                          │
│  ┌─────────────────────────────┐                                         │
│  │ Anthropic chat              │   2nd chat call → SEO JSON             │
│  │  (same provider)            │   System: "return JSON only"           │
│  │                             │   User: title + body                   │
│  └─────────────────────────────┘                                         │
│            ▼                                                             │
│                                                                          │
│  ┌─────────────────────────────┐                                         │
│  │ SemanticLinker              │   Embed body via Ollama                │
│  │                             │   kNN against the same Solr index      │
│  │                             │   For each candidate, find a literal   │
│  │                             │   anchor phrase in the body            │
│  │                             │   Inject inline <a href> per match     │
│  └─────────────────────────────┘                                         │
│            ▼                                                             │
│                                                                          │
│  ┌─────────────────────────────┐                                         │
│  │ DraftWriter                 │   Force status = 0                     │
│  │                             │   Xss::filterAdmin() the body          │
│  │                             │   field_metatags = JSON-encoded SEO    │
│  │                             │   field_ai_drafted = 1                 │
│  │                             │   moderation_state = draft             │
│  │                             │   Save the node                        │
│  │                             │   Notify (Rocket.Chat or email)        │
│  └─────────────────────────────┘                                         │
│            ▼                                                             │
│                                                                          │
│   article (draft, awaiting review at /admin/content/ai-drafts)           │
│                                                                          │
└──────────────────────────────────────────────────────────────────────────┘
```

---

## Services

### `ai_content_drafter.source_grouper`

`Drupal\ai_content_drafter\Service\SourceItemGrouper`

Pulls unprocessed Source Items, buckets them by `field_topic` (with an
"untagged" bucket for items missing a topic ref), sorts each bucket
newest-first, and walks the list emitting clusters that respect both
the time window and a hard `max_group_size` cap.

The bucket is a plain numeric-indexed list — items are NOT keyed by
created timestamp. A burst import of N events inside one wall-clock
second produces N distinct list entries instead of being silently
deduplicated by the timestamp key. (This was a real bug in an earlier
revision and is preserved here as a deliberate design choice.)

`markProcessed(array $cluster): void` flips `field_processed = 1` on
every node in a cluster. The pipeline calls this only after a draft
save succeeds, so a chat failure does NOT leak processed-flag state.

### `ai_content_drafter.style_sample_collector`

`Drupal\ai_content_drafter\Service\StyleSampleCollector`

Two methods, both returning a list of `{title, body, url}` structs:

#### `internalSamples(array $cluster, int $count)`

1. Concatenates the cluster's titles + first 400 chars of body into a
   query string.
2. Runs that query against the configured search_api index
   (default `content`) which has the dense_vector re-ranking
   processor enabled.
3. Walks the result items, skips Source Items and any nodes already
   in the cluster, returns up to `$count` candidates.

Body extraction tries `body`, `field_description`, `field_teaser` in
that order. HTML is stripped, whitespace collapsed, output capped at
1600 characters so the LLM context window doesn't bloat.

#### `externalSamples(int $count)`

1. Reads `style_sample_url` from config.
2. Validates against a strict regex
   (`^https://[A-Za-z0-9.\-]+/wp-json/wp/v2/[a-z_/-]+$`) — non-https
   URLs and anything not matching the WordPress REST shape are
   rejected.
3. Caches the response in `cache.default` keyed by URL + count for
   1 hour, so multiple drafts in one cron tick share a single
   external HTTP fetch.
4. Returns up to `$count` posts as the same `{title, body, url}`
   shape, with HTML stripped and bodies trimmed to 1600 chars.

The cache TTL is short enough that an updated source post propagates
within an hour, long enough to keep cron runs cheap.

### `ai_content_drafter.semantic_linker`

`Drupal\ai_content_drafter\Service\SemanticLinker`

`inject(string $body, int $maxLinks, list<int> $excludeNids): array`

1. Strips HTML from the drafted body, embeds it via the configured
   Ollama provider (default `nomic_embed_text_latest`).
2. Runs a kNN query against the search_api content index for up to
   `maxLinks * 3` candidates.
3. For each candidate node (skipping Source Items, the cluster's
   own ids, and previously-linked nodes), looks for an anchor phrase
   present LITERALLY in the body — first the full title, then a
   4-word prefix, then a 3-word prefix. Case-insensitive
   `stripos()` matching.
4. Wraps the first match with an `<a href>` pointing at the
   candidate's relative URL, using `htmlspecialchars()` on both
   anchor and href.
5. Returns `{body, links}` where `links` is the list of injections
   actually made.

The choice to use literal-match anchors instead of asking the LLM to
pick anchor phrases is deliberate: model-suggested phrases tend to
hallucinate text that doesn't appear in the body, breaking the
substitution. Conservative literal matching is explainable, debuggable,
and safe to expose to a draft-then-review workflow.

### `ai_content_drafter.draft_writer`

`Drupal\ai_content_drafter\Service\DraftWriter`

`write(array $draft): NodeInterface`

Builds the node values, applies sanitisation, persists, dispatches the
notifier. Specifically:

- `title` is mb_substr-truncated to 255 chars.
- `status` is hardcoded `0`. Even if the drafter passes `status = 1`,
  this is overwritten before save.
- `field_ai_drafted = 1` if the bundle has it.
- Body is `Xss::filterAdmin()`'d before storage. The model output is
  treated as untrusted input.
- Summary is `Xss::filter()`'d (slightly stricter — fewer tags allowed).
- If the bundle has any field of type `metatag` (machine name doesn't
  matter), `findMetatagField()` locates it and the drafter's SEO output
  is JSON-encoded into that field. Compatible with metatag 2.x's
  storage format.
- If `content_moderation` module is enabled, sets
  `moderation_state` from `ai_content_ingest.settings.default_moderation_state`.
- Calls `DraftNotifier::notify($node)` post-save. Best-effort. Failure
  does not roll back the save.

### `ai_content_drafter.draft_notifier`

`Drupal\ai_content_drafter\Service\DraftNotifier`

`notify(NodeInterface $draft): void`

Two sinks, evaluated in order:

#### Rocket.Chat incoming webhook

If `rocketchat_webhook` is set in config, the notifier POSTs an
incoming-webhook payload containing:

- `text`: short summary line
- `attachments[0].title`: node title
- `attachments[0].title_link`: absolute admin edit URL
- `attachments[0].text`: word count + moderation state + nid
- `attachments[0].color`: `#4caf50`

The webhook URL is regex-validated against
`^https://[A-Za-z0-9.\-]+/hooks/[A-Za-z0-9/_-]+$`. Other URLs are
rejected and logged as warnings.

#### Email fallback

If `notification_email` is set and the webhook is empty, the notifier
sends a plain-text email via Drupal's `MailManagerInterface`. The
`hook_mail` implementation lives in `ai_content_drafter.module` (the
only procedural file in the module — `hook_mail` doesn't yet support
OOP attributes in Drupal 11.3).

Both sinks fail soft. A 502 from Rocket.Chat or a transport failure
in the mail handler is logged and swallowed.

### `ai_content_drafter.pipeline`

`Drupal\ai_content_drafter\Service\DraftPipeline`

The orchestrator. `run(int $max): int` is the only public method.
Loops over clusters from the grouper, drafts up to `$max` of them,
returns the number actually saved.

`draftCluster(array $cluster, ImmutableConfig $settings): ?NodeInterface`
runs the per-cluster work:

1. Fetch internal + external style samples.
2. Build the body chat input (`buildBodyPrompt`).
3. Invoke the chat provider (`invokeChat`).
4. Parse the response into title + body (`parseDraftedBody`). The body
   prompt instructs the model to put the title on the first line as
   `TITLE: ...`.
5. Build the SEO chat input (`buildSeoPrompt`).
6. Invoke chat for SEO JSON.
7. Parse the SEO JSON, defensive against the model wrapping it in
   markdown code fences.
8. Inject semantic links into the body.
9. Hand the assembled draft to `DraftWriter::write()`.

Failures at any step (empty body, JSON parse fail, embedding error)
log + return `NULL`. The outer loop catches `Throwable` per cluster, so
one bad cluster never poisons the rest of a cron run.

#### `invokeChat()` provider override

The chat call resolves its provider/model from
`ai_content_drafter.settings.drafter_chat_provider` and
`drafter_chat_model` first, falling back to
`ai.settings.default_providers.chat` only when both are empty.

This lets the global default chat provider be a fast cheap local model
(e.g. `ollama / qwen2_5_3b` for `ai_ckeditor` and
`ai_content_suggestions` field assistance) while the long-form drafting
explicitly stays on a high-quality hosted model
(e.g. `anthropic / claude-sonnet-4-5-20250929`). Two workloads, two
models, one config layer.

### `ai_content_drafter.draft_run` queue worker

`@QueueWorker(id = "ai_content_drafter_run")`

Single-job worker that calls `DraftPipeline::run($max)`. Cron enqueues
one job per tick via the `Hook('cron')` implementation in
`AiContentDrafterHooks`. Per-cron time budget is 120 seconds, which
fits a few drafts comfortably even on a CPU-only Ollama setup.

---

## Hooks

### `Hook('cron')` on `AiContentDrafterHooks`

Reads `enabled` from settings — short-circuits if off. Reads
`max_drafts_per_run` from `ai_content_ingest.settings`. Enqueues one
`{'max': $max}` item into the `ai_content_drafter_run` queue. Cron then
processes that queue under its own time budget.

### `hook_mail` in `ai_content_drafter.module`

The only procedural hook in the module. Provides the body + subject for
the email fallback notifier under the `draft_ready` mail key. Sets
content type to `text/plain; charset=UTF-8`.

---

## Public endpoint: `/api/semantic-search`

`src/Controller/SemanticSearchController::search`

`GET /api/semantic-search?q=<text>&limit=<1..25>`

Returns a `CacheableJsonResponse` with the shape:

```json
{
  "query": "string",
  "count": 5,
  "limit": 5,
  "results": [
    {
      "id": 21,
      "uuid": "...",
      "bundle": "project",
      "title": "...",
      "url": "https://drupal.madsnorgaard.net/...",
      "score": 0.6722,
      "changed": 1775213852
    }
  ]
}
```

### Behaviour

- **Query validation**: `q` must be 3-500 characters. Outside that
  range → 400 Bad Request.
- **Limit clamping**: defaults to 10, clamped to `[1, 25]`.
- **Embedding**: round-trips a single embedding call via the
  configured Ollama provider for upstream connectivity validation.
  The actual kNN re-ranking happens server-side inside Solr via
  `search_api_solr_dense_vector`'s query subscriber.
- **Access control**: search_api access checking is **left ON**. The
  query also adds an explicit `status = 1` filter. Unpublished nodes
  cannot leak through this public endpoint.
- **Rate limiting**: Drupal's flood service, 30 requests per client
  IP per 60 seconds, key `ai_content_drafter.semantic_search`.
  Returns 429 with a `Retry-After` once tripped.
- **Caching**: Cache contexts on `url.query_args:q` and
  `url.query_args:limit`. Cache tags include `node_list` and a
  custom `semantic_search` tag. Max age 300 seconds. Repeated
  identical queries hit Drupal's page cache rather than recomputing.

### Failure modes

| Condition | HTTP | Body |
|---|---|---|
| Missing or short `q` | 400 | text error |
| Flood threshold tripped | 429 | text error + Retry-After |
| Index unavailable | 403 | text error |
| Embedding provider down | 503 | JSON `{count: 0, error: "Search temporarily unavailable"}` |
| Solr query failure | 503 | JSON `{count: 0, error: "Search temporarily unavailable"}` |

---

## Field assistance integration

When `drupal/ai_ckeditor` and `drupal/ai_content_suggestions` are
enabled (they ship as part of the `drupal/ai` ecosystem), they
inherit the global `ai.settings.default_providers.chat` provider for
their internal chat calls. By configuring that default to a local
Ollama chat model (e.g. `qwen2_5_3b`), all field-level AI assistance
in CKEditor 5 (Summarize, Translate, Modify with prompt, Complete)
runs against the local model with zero hosted-API egress and no
per-call cost.

The drafter module itself **does not** ship CKEditor 5 plugins. It
only documents and supports the split-model pattern via the
`drafter_chat_provider` / `drafter_chat_model` config keys, which
override the global default specifically for the long-form drafting
pipeline.

---

## Content moderation workflow

The module assumes a 4-state workflow on the target bundle:

```
draft → in_review → published → archived
```

`workflows.workflow.ai_content_moderation` is shipped in `config/sync`
of the parent project and applied to the `article` bundle. New AI
drafts land in `draft`. A reviewer transitions through `in_review`
to `published` via the standard moderation dropdown on the node edit
form.

If `content_moderation` is **not** installed, `DraftWriter` skips the
moderation_state field and relies on the always-`status=0` invariant
to keep drafts unpublished.

---

## Admin view: `/admin/content/ai-drafts`

`views.view.ai_drafts` ships in config. Filters:

- bundle = `article`
- `field_ai_drafted = 1`
- `status = 0`

Sort: created DESC. Permission: `administer nodes`. Mounted as a tab
under the standard content admin so reviewers find it without a
separate bookmark. Empty state copy: "Nothing in the review queue.
Check back after the next cron run."

---

## Configuration

Stored in `ai_content_drafter.settings`. Edited via
`/admin/config/ai/content-drafter`.

| Key | Type | Default | Meaning |
|---|---|---|---|
| `enabled` | boolean | `false` | Master switch — cron does nothing if off |
| `draft_search_index` | string | `content` | search_api index for kNN lookups |
| `style_sample_url` | string | `https://photo.madsnorgaard.net/wp-json/wp/v2/posts` | External WP REST endpoint for style samples |
| `style_sample_fetch_count` | integer | `3` | External samples per draft |
| `style_sample_internal_count` | integer | `3` | Internal samples per draft |
| `internal_link_count` | integer | `3` | Target inline links per draft |
| `author_voice_prompt` | text | (multi-line voice system prompt) | Appended to every draft system message |
| `group_time_window_seconds` | integer | `259200` (3 days) | Items beyond this gap form separate clusters |
| `min_group_size` | integer | `1` | Minimum cluster size to draft |
| `max_group_size` | integer | `6` | Max items per cluster |
| `rocketchat_webhook` | string | `''` | Notification target |
| `notification_email` | email | `''` | Notification fallback |
| `drafter_chat_provider` | string | `''` | Override for the chat provider used by drafting (empty = inherit global) |
| `drafter_chat_model` | string | `''` | Override for the chat model used by drafting |

`enabled = false` is the install-time default so a fresh deployment
does nothing surprising until an admin opts in.

---

## Verification commands

```bash
# Where the drafter currently stands
drush php:eval '$nids = \Drupal::entityQuery("node")->accessCheck(FALSE)->condition("type", "article")->condition("field_ai_drafted", 1)->condition("status", 0)->execute(); echo count($nids) . " AI drafts awaiting review\n";'

# Show the latest 3 AI drafts
drush php:eval '$nids = \Drupal::entityQuery("node")->accessCheck(FALSE)->condition("type", "article")->condition("field_ai_drafted", 1)->condition("status", 0)->sort("created", "DESC")->range(0, 3)->execute(); foreach (\Drupal\node\Entity\Node::loadMultiple($nids) as $n) { echo "  nid " . $n->id() . " | " . $n->getTitle() . "\n"; }'

# Run the pipeline once, manually, with the configured max_drafts_per_run
drush php:eval '$p = \Drupal::service("ai_content_drafter.pipeline"); echo "drafts written: " . $p->run((int) \Drupal::config("ai_content_ingest.settings")->get("max_drafts_per_run")) . "\n";'

# Hit the public endpoint
curl -sf 'https://drupal.madsnorgaard.net/api/semantic-search?q=headless%20Drupal&limit=5' | jq

# Check the chat provider currently used by the drafter (drafter override
# is applied if set, otherwise global default)
drush config:get ai_content_drafter.settings drafter_chat_provider
drush config:get ai_content_drafter.settings drafter_chat_model
drush config:get ai.settings default_providers.chat

# Reset the field_processed flag on a Source Item if you want to
# re-draft from it (useful when iterating on the voice prompt)
drush php:eval '$n = \Drupal\node\Entity\Node::load(123); $n->set("field_processed", 0); $n->save();'
```

---

## Security model

| Surface | Hardening |
|---|---|
| LLM output | `Xss::filterAdmin()` on the body, `Xss::filter()` on the summary, `htmlspecialchars()` on link anchors and hrefs. The model is treated as untrusted user input from end to end. |
| Public endpoint | Search access checking left ON. Explicit `status = 1` filter. 30 req / 60 s flood throttle per IP. Query length bounded. |
| Rocket.Chat webhook URL | Strict regex validation. Other URL shapes rejected before any HTTP call. |
| External style sample URL | Strict regex validation requiring https + the wp-json path shape. |
| Always-draft invariant | `status = 0` is forced in code in `DraftWriter::write()`, not just defaulted in config. A bug or admin misconfiguration cannot accidentally publish AI output. |
| Notification failures | Always logged at error level via the `ai_content_drafter` channel and never re-thrown. A transient outage cannot interrupt the drafting pipeline. |
| Permission gating | `administer ai_content_drafter` permission is `restrict access: true`. The settings form uses it. |

---

## Cost & operational model

For a Personal-site-scale install with the default config:

| Item | Per draft | Per day cap |
|---|---|---|
| Anthropic body draft | 1 chat call, ~1500 input + ~1000 output tokens | `max_drafts_per_run × cron_runs_per_day` |
| Anthropic SEO enrichment | 1 chat call, ~600 input + ~150 output tokens | same |
| Ollama embedding (semantic linker) | 1 embedding call | same |
| Ollama embedding (style sample lookup) | 1 embedding call (handled inside search_api) | same |

With `max_drafts_per_run = 3` and Drupal cron firing every 3 hours
(8 runs/day), the upper bound is 24 drafts/day. Realistic spend on
Anthropic at current Sonnet 4.5 pricing is **a few cents per day**.
Ollama is on-prem so the embedding work has zero per-call cost beyond
the CPU it already owns.

If `drafter_chat_provider = ollama` and `drafter_chat_model = qwen2.5:7b`
or similar, the drafting itself becomes free-but-slower and the only
external cost vanishes.

---

## Extending

- **Swap drafter chat provider** via the settings form. Anthropic, OpenAI,
  Gemini, Mistral, Ollama, Hugging Face — anything with a drupal/ai
  provider works.
- **Change target bundle** by editing `ai_content_ingest.settings.target_bundle`
  (the drafter respects it). The notifier and admin view both work
  against any bundle that has a `field_ai_drafted` boolean.
- **Plug in a new style sample source** by adding a method to
  `StyleSampleCollector` that returns the same `{title, body, url}`
  struct shape and is called from `DraftPipeline::buildBodyPrompt`.
- **Custom semantic linker logic** — subclass or replace the
  `ai_content_drafter.semantic_linker` service via service decoration.
  Other modules can use the same kNN index.
- **Custom notification sink** — extend `DraftNotifier::notify` or
  decorate the service. The contract is "given a saved node, dispatch
  one best-effort notification".
- **Use the `/api/semantic-search` endpoint** from any consumer.
  Public, JSON, cacheable, IP-rate-limited. CORS is left to the site's
  `services.yml` configuration.

---

## Not implemented (deferred)

- Inline image generation (the body is text-only; no DALL-E / SD calls)
- Multilingual drafting (English only for v1; the voice prompt
  explicitly says so)
- Per-cluster cost tracking via `ai_logging`
- A frontend "find similar" widget — the endpoint is ready, the Nuxt
  consumer ships in the frontend repo
- Automatic publishing on a delay (no — always-draft is a hard
  invariant, not a default)

---

## License

GPL-2.0-or-later, same as Drupal core.
