# AGENTS.md — WP MariaDB Vector Search

Specification for AI agents (and humans) implementing this plugin.

## Goal

Replace WordPress's default `LIKE`-based search with **semantic similarity search**
backed by MariaDB's native `VECTOR` column type. Embeddings are produced through
the **WordPress core AI Client embedding API (WP 7.2+, `wp_ai_client_embedding()`)**,
which standardizes access to embedding providers without locking the plugin to a
single vendor.

## Environment

| Requirement       | Version                                                    |
| ----------------- | ---------------------------------------------------------- |
| WordPress         | **7.2+** (required — core AI Client embedding API)         |
| PHP               | 8.2+                                                       |
| MariaDB           | **11.7+ (11.8 LTS recommended)** — required for `VECTOR`   |
| MySQL             | Not supported (no `VECTOR` type). Fail fast with admin notice. |

An AI provider plugin that implements embedding generation must be installed
and configured (e.g. AI Provider for OpenAI 1.1+, or AI Provider for LM Studio
0.2+), and its model selected on the admin page. Without a selected,
embedding-capable model the plugin gracefully falls through to the default
WordPress search.

`dbDelta()` does **not** understand `VECTOR(N)` / `VECTOR INDEX`. Use raw
`$wpdb->query()` for DDL, gated by a versioned option
`wp_mariadb_vector_search_db_version`.

## Architecture

```
save_post ──► Indexer::enqueue (wp_schedule_single_event)
                │
                ▼
        Indexer::run_job
                │  Chunker → string[]
                ▼
        wp_mariadb_vector_search_embedding(texts)->generate_embeddings()  ──► core AI Client → provider plugin
                │  Embedding[] → float[][]
                ▼
        Repository::replace_post_chunks(post_id, chunks)

is_search() ──► Search::pre_get_posts
                │  query string → wp_mariadb_vector_search_embedding(s)->generate_embeddings()
                ▼
        Repository::search_similar(query_vec, post_types, max_distance, max_results, max_relative_distance)
                │  vector ranking                    WP_Query (LIKE, orderby=relevance)
                ▼                                      │  lexical ranking
        Rank_Fusion::fuse([vector, lexical], k) ◄──────┘
                │
                ▼
        post__in (ordered), LIKE clause removed
```

### Design decisions

- **All AI calls go through core.** `wp_mariadb_vector_search_embedding()`
  returns core's `WP_AI_Client_Embedding_Builder` (from `wp_ai_client_embedding()`)
  bound via `using_provider_model()` to the model selected in settings. Provider
  resolution, authentication, HTTP and response parsing belong to core and the
  provider plugins; the plugin never talks to an AI API directly. Core never
  picks a model implicitly (vectors from different models are not comparable),
  so an unselected model yields a core `WP_Error`.
- **Chunking, one post → N vectors.** Long posts lose information when
  truncated to a model's token limit, so we split them. Storage is per-chunk;
  search aggregates back to posts via `MIN(distance)`.
- **Separate table.** Embeddings live in `{$prefix}mariadb_vector_embeddings`,
  not in `wp_posts` or `wp_postmeta`. Avoids autoloading, simplifies uninstall.
- **Async indexing.** `save_post` only schedules; embedding happens on cron
  to keep editor save fast and to survive AI provider failures.
- **Replace, don't upsert.** When a post is reindexed, all its rows are
  DELETEd then re-INSERTed inside a transaction. Avoids stale chunks.
- **Search override is opt-out-safe.** Filter `pre_get_posts` only when
  `is_main_query() && is_search() && s != ''`. On embedding error fall through
  to default WordPress search rather than returning nothing. When the search
  runs but nothing matches, return no results (`post__in = [0]`) rather than
  the LIKE results.
- **Hybrid by default.** Vector results are fused with WordPress's own LIKE
  search results (a sub `WP_Query`, `orderby=relevance`) by Reciprocal Rank
  Fusion. Embeddings rank exact tokens (product codes, names) poorly; LIKE
  catches them. RRF needs only ranks, since cosine distance and LIKE relevance
  are not comparable. The distance thresholds still apply to the vector side.
- **LIKE clause removal is scoped to the query.** The rewritten query is marked
  with the `wp_mariadb_vector_search` query var, and a permanent `posts_search`
  filter empties the clause only for marked queries. A filter added and removed
  around the query would leak: `fields=ids` queries return before `the_posts`.
  Core hides password-protected posts from logged-out visitors inside that
  clause, so the rewrite restores it with `has_password = false`.

## Schema

```sql
CREATE TABLE {$prefix}mariadb_vector_embeddings (
  id           BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  post_id      BIGINT UNSIGNED NOT NULL,
  chunk_index  SMALLINT UNSIGNED NOT NULL,
  post_type    VARCHAR(20)       NOT NULL,
  dimensions   SMALLINT UNSIGNED NOT NULL,
  embedding    VECTOR(:N)        NOT NULL,
  chunk_text   TEXT              NOT NULL,
  content_hash CHAR(64)          NOT NULL,
  updated_at   DATETIME          NOT NULL,
  UNIQUE KEY post_chunk (post_id, chunk_index),
  KEY post_type_idx (post_type),
  VECTOR INDEX (embedding) DISTANCE=cosine
) ENGINE=InnoDB;
```

`:N` is fixed at install time from the chosen model
(e.g. OpenAI `text-embedding-3-small` = 1536, `text-embedding-3-large` = 3072).
Changing the model to one with a different dimension requires dropping and
re-creating the table; the Admin page handles this automatically.

`content_hash` is a SHA-256 of the selected model (`provider:model`, plus the
document prefix when one is set), title and raw content (length-prefixed) —
used by the indexer to skip work when neither the post body nor the embedding
model has changed. Including the model means a model switch at the same
dimension is re-embedded by a normal (non-force) Reindex; the same goes for a
changed document prefix.

## Components

| File                              | Responsibility                                                                                     |
| --------------------------------- | -------------------------------------------------------------------------------------------------- |
| `wp-mariadb-vector-search.php`    | Bootstrap. Registers activation hooks. `wp_mariadb_vector_search_embedding()` (core builder bound to the selected model). |
| `includes/Plugin.php`             | Composition root. Instantiates components and registers hooks.                                     |
| `includes/Schema.php`             | Install/upgrade table. Verify MariaDB version. Admin notice on incompatibility.                    |
| `includes/Settings.php`           | The settings option: getters with defaults (`get_thresholds()`, `is_hybrid()`, `get_post_types()`, `get_prefixes()`), `update()` for partial writes, `sanitize_settings()`. |
| `includes/Repository.php`         | `$wpdb` wrapper. `replace_post_chunks()`, `delete_post()`, `delete_other_post_types()`, `search_similar()`. Vector text serialization. |
| `includes/Model_Catalog.php`      | Enumerates models the AI Client registry reports as embedding-capable.                             |
| `includes/Abilities.php`          | Abilities for the admin UI: `get-status`, `reindex`, `select-model` (probes the model's dimensions, then saves settings; clears thresholds and prefixes when the model changes), `save-search-settings` (thresholds and hybrid), `save-prefixes` (reports whether the document prefix changed, which needs a Reindex), `save-post-types` (deletes the rows of deselected types; reports added types, which need a Reindex), `preview-search` (nearest posts with distances and whether the thresholds keep them), `save-index-settings`. |
| `includes/Content_Hash.php`       | SHA-256 hash of post title + content.                                                              |
| `includes/Chunker.php`            | Block strip + paragraph/sentence/char splitting with overlap. Title prepended to each chunk.       |
| `includes/Indexer.php`            | `save_post`/`delete_post`/`trashed_post` hooks. Cron worker that embeds chunks and stores them via the repository. Removes password-protected posts from the index unless the setting allows them. |
| `includes/Search.php`             | `pre_get_posts` rewrite to `post__in` + `orderby=post__in`; hybrid fusion with the LIKE search. Safe fallback to default search. |
| `includes/Rank_Fusion.php`        | Reciprocal Rank Fusion of ranked post ID lists. Pure PHP.                                          |
| `includes/CLI.php`                | `wp mariadb-vector reindex [--post-type=] [--force] [--batch=]`. Loaded only if `WP_CLI`.          |
| `includes/Cron_Backfill.php`      | Batched cron-driven reindex of all posts. Progress in transient.                                   |
| `includes/Admin.php`              | Settings page: status, model selector, prefixes, search settings (hybrid toggle, thresholds with preview), post types, indexing settings, unified Reindex button (auto-detects dimension diff). |
| `uninstall.php`                   | `DROP TABLE`, delete all `wp_mariadb_vector_search_*` options.                                     |

## Similarity search query

Two-step query to keep `VECTOR INDEX` usable (a `GROUP BY` directly on the
distance expression defeats the index):

```sql
-- Inner: index-driven top-(max_results * overscan) chunks
SELECT post_id,
       VEC_DISTANCE_COSINE(embedding, VEC_FromText(%s)) AS d
FROM   {table}
WHERE  post_type IN (...)
ORDER  BY d ASC
LIMIT  %d;

-- Outer (PHP-side): group by post_id, keep MIN(d),
--   filter d <= max_distance and d <= MIN(d) + max_relative_distance,
--   slice to max_results
```

The default `overscan` of 5 covers typical content. `max_distance` (default
0.65) excludes posts that are too dissimilar; `max_relative_distance` (default
0.25) excludes posts far behind the best match (e.g. the default "Hello world!"
post); `max_results` (`Search::MAX_RESULTS`, 200) caps the result set and
ensures the VECTOR INDEX is engaged.

With hybrid search on, the vector ranking and the LIKE ranking (up to
`max_results` posts) are fused: each post scores `Σ 1 / (k + rank)` over the
lists it appears in (`k` = `Search::RRF_K`, 60), so posts found by both rank
first. Ties keep vector order.

## Chunking

- Strip blocks (`excerpt_remove_blocks()`) and HTML (`wp_strip_all_tags()`).
- Split on paragraph → sentence → character boundaries.
- Default `chunk_size_chars` = 2000, `chunk_overlap_chars` = 300 (good for
  Japanese + English mixed text; ~1000 tokens per chunk). These are code-level
  defaults in `Chunker.php`, not stored in the settings option.
- Prepend `"{title}\n\n"` to every chunk so isolated chunks retain topical
  context.
- Token-count approximation: `mb_strlen($text) / 2` (close enough for size
  budgeting — actual tokenization is the provider's job).

## Settings (`wp_mariadb_vector_search_settings`, single option, array)

| Key          | Default | Purpose                                                     |
| ------------ | ------- | ----------------------------------------------------------- |
| `provider`   | —       | AI provider id (e.g. `openai`, `lm_studio`).                |
| `model`      | —       | Embedding model id (e.g. `text-embedding-3-small`).         |
| `dimensions` | 1536    | Detected by probing the model in `select-model` (`EmbeddingResult::getDimensions()`); never entered manually. Schema's `VECTOR(N)`. Changing to a different dimension requires Reindex. |
| `max_distance` | 0.65 | Search threshold (cosine distance, 0–2). Set on the admin page; absent = default. Removed (`null`) when `select-model` switches to another model, since distances are model-specific. |
| `max_relative_distance` | 0.25 | Search threshold behind the best match (0–2). Same lifecycle as `max_distance`. |
| `hybrid` | `true` | Fuse vector results with the LIKE search (`Settings::is_hybrid()`). Set on the admin page; `false` = vector-only. Kept across model changes. |
| `exclude_password_protected` | true | Leave password-protected posts out of the index, since indexing sends their content to the provider. Set on the admin page; kept across model changes. Existing posts follow a change on the next Reindex. |
| `query_prefix` / `document_prefix` | `''` | Prepended before embedding to search terms (Search, preview) and to chunks (Indexer; `chunk_text` is stored without it). Models such as E5/Nemotron need `query: ` / `passage: `. Entered by hand from the model card (no presets; no API reports them); spaces are kept, control characters removed. Cleared by `select-model` on a model change, like the thresholds. |
| `post_types` | all available | Post types to index and search (`Settings::get_post_types()`). Available = public, searchable, minus `attachment` (never published). Saved types that become unavailable are ignored. Set on the admin page; `save-post-types` deletes the rows of deselected types. |

Writing the option:

- **Use `Settings::update( $changes )`** for partial writes. It merges the
  changes into the saved option (`null` removes a key, restoring its default),
  then sanitizes and saves. Abilities write settings only through it.
- **`sanitize_settings()` is pure.** It validates and normalizes the given
  array and drops invalid values and unknown keys; it never reads the saved
  option. It is the `register_setting()` callback, so a raw
  `update_option()` replaces the whole option.
- Read settings through the `Settings` getters, which apply the defaults.
- The option is not exposed in the REST settings endpoint (`show_in_rest`
  off): the admin page goes through abilities.

Other options:

- `wp_mariadb_vector_search_db_version` — schema migration marker.

## Extension points (filters/actions)

The plugin defines no filters of its own; settings live on the admin page.
Embedding requests honor core's AI Client filters, e.g.
`wp_ai_client_default_request_timeout` (HTTP timeout, core default 30 s; it
receives the builder class, so raise it only for `WP_AI_Client_Embedding_Builder`
when slow local models need longer) and `wp_ai_client_prevent_embedding`. The
plugin does not set its own timeout.

## `$wpdb` notes

- `VECTOR` is a binary type and is unaffected by `utf8mb4`.
- Pass vectors as JSON-style text wrapped by `VEC_FromText(%s)`. **Do not** use
  `%f` for floats: locale-dependent and lossy. Format with
  `number_format($f, 10, '.', '')` and a locale-stable join.
- `$wpdb` returns `VECTOR` columns as raw bytes. When reading vectors back in
  PHP, prefer `SELECT VEC_ToText(embedding)`.

## Development workflow — Test-Driven Development

This plugin is implemented **TDD-first**. Every component is built in tight
red → green → refactor cycles. No production code without a failing test that
demands it.

Rules:

1. **Red**: add or extend a test in `tests/phpunit/...` that fails for the
   right reason.
2. **Green**: write the minimum code in `includes/...` to make it pass — no
   more. Resist designing for hypothetical needs.
3. **Refactor**: clean up under the safety of green tests. Run `npm run lint:php` and `npm run lint:js`.
4. Commit after each green-and-clean cycle.

Test commands:

```bash
npm run test:php:base  # fast (recommended; requires wp-env running)
npm run test:php       # full suite
npm run lint:php       # PHPCS (lint:php:fix to apply PHPCBF; requires wp-env running)
npm run lint:js        # ESLint + Prettier (lint:js:fix to apply fixes)
npm run typecheck      # tsc --noEmit
```

Layering:

- **Unit tests** (`tests/phpunit/unit/`): pure-PHP logic, no WordPress.
  Examples: vector serialization, chunker, text normalization, hashing, model catalog.
  These must run without `wp-env`.
- **Integration tests** (`tests/phpunit/integration/`): exercise `$wpdb`,
  hooks, and the core AI Client. Run inside `wp-env` (WordPress 7.2+) against
  MariaDB 11.7+. The bootstrap registers `Fake_Embedding_Provider` in the AI
  Client default registry; tests select it with `Fake_Embedding_Provider::select()`
  and control output via `Fake_Embedding_Provider::$vector`, so the real core
  builder runs end-to-end without any network calls. Simulate provider failure
  with core's `wp_ai_client_prevent_embedding` filter. Never call a real LLM in CI.

Test file naming convention: `*_Test.php`.

## Manual verification

1. `wp-env start` (ensure the image is MariaDB 11.7+; check with
   `wp-env run cli wp db query "SELECT VERSION();"`).
2. Configure an embedding-capable provider plugin (Settings > Connectors) and
   select its model on Settings > Vector Search.
3. Activate this plugin; check `SHOW CREATE TABLE wp_mariadb_vector_embeddings`.
4. Create posts → `wp cron event run --due-now` → inspect:
   `wp db query "SELECT post_id, chunk_index, VEC_ToText(embedding) FROM wp_mariadb_vector_embeddings WHERE post_id=N;"`
5. `wp mariadb-vector reindex` — confirm progress bar and final count.
6. Search with semantically related but lexically distinct terms
   (e.g. "猫" matching posts that only say "ねこ" / "feline");
   compare ordering vs. default search.
7. Disable the provider → search → confirm fallback to default behavior + WP_Error in log.
8. `npm run test:php:base`, `npm run lint:php` and `npm run lint:js`.
9. Delete the plugin → `SHOW TABLES LIKE 'wp_mariadb_vector_embeddings'` returns empty.
