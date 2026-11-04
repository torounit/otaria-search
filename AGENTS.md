# AGENTS.md — Otaria Search

Specification for AI agents (and humans) working on this plugin.

## Goal

Replace WordPress's `LIKE` search with **semantic similarity search** stored in
MariaDB's native `VECTOR` column. Embeddings come from the **WordPress core AI
Client embedding API** (`wp_ai_client_embedding()`, WP 7.2+), so the plugin is
not tied to one vendor.

## Environment

| Requirement | Version                                                         |
| ----------- | --------------------------------------------------------------- |
| WordPress   | **7.2+** (core AI Client embedding API)                         |
| PHP         | 8.2+                                                            |
| MariaDB     | **11.7+** (11.8 LTS recommended) for `VECTOR`                   |
| MySQL       | Not supported (no `VECTOR`). Fail fast with an admin notice.    |

An embedding-capable AI provider plugin (e.g. AI Provider for OpenAI 1.1+, AI
Provider for LM Studio 0.2+) must be configured and its model selected on the
admin page. Without one, search falls through to default WordPress search.

## Architecture

```
save_post ──► Indexer::enqueue (wp_schedule_single_event)
                │
                ▼
        Indexer::run_job ── Chunker → string[]
                │           otaria_search_embedding(texts)->generate_embeddings()
                ▼
        Repository::replace_post_chunks(post_id, chunks)

is_search() ──► Search::pre_get_posts
                │  query → otaria_search_embedding(s)->generate_embeddings()
                ▼
        Repository::search_similar(...)        WP_Query (LIKE, orderby=relevance)
                │  vector ranking                 │  lexical ranking
                ▼                                 ▼
        Rank_Fusion::fuse([vector, lexical], k)
                │
                ▼
        post__in (ordered), LIKE clause removed
```

### Design decisions

- **All AI calls go through core.** `otaria_search_embedding()` returns core's
  `WP_AI_Client_Embedding_Builder`, bound with `using_provider_model()` to the
  selected model. The plugin never calls an AI API directly. Core never picks a
  model implicitly (vectors from different models are not comparable), so no
  selected model means a core `WP_Error`.
- **One post → N chunks.** Long posts are split rather than truncated. Rows
  are per chunk; search aggregates to posts by `MIN(distance)`.
- **Separate table** `{$prefix}otaria_search_embeddings`, not post meta: no
  autoload, simple uninstall.
- **Async indexing.** `save_post` only schedules; embedding runs on cron, so
  saving stays fast and provider failures don't break the editor.
- **Replace, don't upsert.** Reindexing a post deletes and re-inserts all its
  rows in one transaction.
- **Fail open, match strictly.** Rewrite only when
  `is_main_query() && is_search() && s !== ''`. On an embedding error, fall
  through to default search. When the search runs and nothing matches, return
  nothing (`post__in = [0]`), not the LIKE results.
- **Hybrid by default.** Embeddings rank exact tokens (product codes, names)
  poorly, so vector results are fused with WordPress's own LIKE search by
  Reciprocal Rank Fusion, which needs only ranks (cosine distance and LIKE
  relevance are not comparable).
- **LIKE removal is scoped to the query.** The rewritten query carries the
  `otaria_search` query var, and a permanent `posts_search` filter empties
  the clause only for it (a filter added/removed around the query leaks:
  `fields=ids` queries return before `the_posts`). Core hides
  password-protected posts from logged-out visitors inside that clause, so the
  rewrite restores it with `has_password = false`.

## Storage

`Schema.php` creates the table with raw `$wpdb->query()` (`dbDelta()` does not
understand `VECTOR(N)` / `VECTOR INDEX`), gated by `otaria_search_db_version`.
One row per chunk: `post_id`, `chunk_index`, `post_type`, `dimensions`,
`embedding VECTOR(N)` with a cosine `VECTOR INDEX`, `chunk_text`,
`content_hash`, `updated_at`.

- `N` comes from the selected model. A model with a different dimension needs
  the table dropped and recreated; the admin Reindex does this automatically.
- `content_hash` = SHA-256 of `Settings::get_embedding_key()`
  (`provider:model`, plus the document prefix if set), title and content
  (length-prefixed). The indexer skips posts whose hash is unchanged, so a
  normal Reindex re-embeds after a same-dimension model switch or a prefix
  change. **Any setting that changes how chunks are embedded belongs in the
  embedding key**, so both the hash and `update-settings`' `reindex_required`
  follow it.

## Components

| File                         | Responsibility                                                                 |
| ---------------------------- | ------------------------------------------------------------------------------ |
| `otaria-search.php`        | Bootstrap, activation hooks, `otaria_search_embedding()`.                    |
| `includes/Plugin.php`        | Composition root: instantiates components and registers hooks.                |
| `includes/Schema.php`        | Table install/upgrade, MariaDB version check and notice. `table_name()` is the only place the table name is spelled out. |
| `includes/Settings.php`      | The settings option: getters with defaults, `get_embedding_key()`, `update()`, `sanitize_settings()`. |
| `includes/Repository.php`    | `$wpdb` wrapper: replace/delete chunks, `search_similar()`, vector serialization. |
| `includes/Model_Catalog.php` | Lists models the AI Client registry reports as embedding-capable.             |
| `includes/Abilities.php`     | Abilities used by the admin UI: `get-status`, `reindex`, `select-model`, `update-settings`, `preview-search`. |
| `includes/Content_Hash.php`  | `content_hash` computation.                                                    |
| `includes/Chunker.php`       | Strip blocks/HTML, split with overlap, prepend title.                          |
| `includes/Indexer.php`       | Post hooks, cron worker, password-protected exclusion, `remove_deselected_post_types()`. |
| `includes/Search.php`        | `pre_get_posts` rewrite, hybrid fusion, fallback.                              |
| `includes/Rank_Fusion.php`   | Reciprocal Rank Fusion. Pure PHP.                                              |
| `includes/CLI.php`           | `wp otaria-search reindex [--post-type=] [--force] [--batch=]` (only under WP-CLI). |
| `includes/Cron_Backfill.php` | Batched cron reindex of all posts; progress in a transient.                    |
| `includes/Admin.php`, `src/admin/` | Settings page (React): status, model, prefixes, search, post types, indexing, Reindex. |
| `uninstall.php`              | Drop the table, delete all `otaria_search_*` options.                        |

## Search

`Repository::search_similar()` runs in two steps so the `VECTOR INDEX` is used
(a `GROUP BY` on the distance expression defeats it):

1. SQL: top `max_results × overscan` (overscan 5) chunks by
   `VEC_DISTANCE_COSINE(embedding, VEC_FromText(%s))`, filtered by post type.
2. PHP: group by post (`MIN(d)`), keep `d <= max_distance` and
   `d <= best + max_relative_distance`, slice to `max_results`
   (`Search::MAX_RESULTS` = 200).

With hybrid on, the vector ranking and the LIKE ranking (up to `max_results`)
are fused: score = `Σ 1 / (k + rank)`, `k` = `Search::RRF_K` (60). Posts found
by both rank first; ties keep vector order.

## Chunking

- `excerpt_remove_blocks()` then `wp_strip_all_tags()`.
- Split on paragraph → sentence → character boundaries; defaults
  `chunk_size_chars` 2000, `chunk_overlap_chars` 300 (code constants in
  `Chunker.php`, not settings).
- Prepend `"{title}\n\n"` to every chunk.

## Settings

Single option `otaria_search_settings` (array). Other option:
`otaria_search_db_version`.

| Key | Default | Notes |
| --- | ------- | ----- |
| `provider`, `model` | — | Selected via `select-model`. |
| `dimensions` | 1536 | Probed by `select-model`, never entered by hand. |
| `max_distance` | 0.65 | Cosine distance (0–2). Model-specific: cleared on model change. |
| `max_relative_distance` | 0.10 | Distance behind the best match (0–2). Cleared on model change. Trims the tail; `max_distance` stays loose because distance scales differ between models. |
| `query_prefix`, `document_prefix` | `''` | For models such as E5 (`query: ` / `passage: `). Applied before embedding; `chunk_text` is stored without it. Spaces kept, control characters removed. Cleared on model change. |
| `hybrid` | `true` | `false` = vector only. |
| `exclude_password_protected` | `true` | Indexing sends content to the provider. Existing posts follow on the next Reindex. |
| `post_types` | all available | Available = public, searchable, minus `attachment`; unavailable saved types are ignored. Deselecting deletes that type's rows; `null` restores all, including types registered later. |

Rules:

- **Write through `Settings::update( $changes )`.** It merges into the saved
  option (`null` removes a key → default), sanitizes and saves. Abilities write
  only through it: `select-model` for the model (probes it first), and
  `update-settings` for everything else. **A new setting is added to
  `update-settings`, not given its own ability.**
- `update-settings` changes only the given keys, rejects the whole call on an
  unavailable post type, and returns the settings in effect plus
  `added_post_types` and `reindex_required` (added post types, a changed
  embedding key or a changed password-protected exclusion; a non-force Reindex
  applies all three). It is annotated destructive.
- **`sanitize_settings()` is pure**: it validates the given array, drops
  invalid values and unknown keys, and never reads the saved option. As the
  `register_setting()` callback, a raw `update_option()` replaces the whole
  option.
- Read through the `Settings` getters, which apply defaults.
- `show_in_rest` is off; the admin page goes through abilities.
- Row deletion for deselected post types hangs off the option update hook, so
  it happens however the option is written.

## Extension points

The plugin defines no filters of its own. Core's AI Client filters apply, e.g.
`wp_ai_client_default_request_timeout` (core default 30 s; it receives the
builder class, so raise it only for `WP_AI_Client_Embedding_Builder`) and
`wp_ai_client_prevent_embedding`.

## `$wpdb` notes

- Pass vectors as text through `VEC_FromText(%s)`. **Never use `%f`**
  (locale-dependent, lossy): format with `number_format($f, 10, '.', '')`.
- `VECTOR` columns come back as raw bytes; select `VEC_ToText(embedding)` to
  read them in PHP.

## Development — TDD

Work in red → green → refactor cycles; no production code without a failing
test that demands it. Commit after each green-and-clean cycle.

```bash
npm run test:php:base  # fast (recommended; requires wp-env)
npm run test:php       # full suite
npm run lint:php       # PHPCS (lint:php:fix = PHPCBF; requires wp-env)
npm run lint:js        # ESLint + Prettier (lint:js:fix)
npm run typecheck      # tsc --noEmit
npm run plugin-check   # Plugin Check (requires the default wp-env)
```

- **Unit tests** (`tests/phpunit/unit/`): pure PHP, no WordPress, no wp-env.
- **Integration tests** (`tests/phpunit/integration/`): `$wpdb`, hooks and the
  core AI Client inside wp-env (MariaDB 11.7+). The bootstrap registers
  `Fake_Embedding_Provider`; select it with `Fake_Embedding_Provider::select()`
  and set output via `Fake_Embedding_Provider::$vector`. Simulate provider
  failure with `wp_ai_client_prevent_embedding`. Never call a real LLM.
- Test files are named `*_Test.php`.
- The husky pre-commit hook runs lint-staged (PHPCBF/PHPCS on the host via
  `vendor/bin`, Prettier, ESLint, `tsc`, `lint:pkg-json`), so it needs local
  PHP and `composer install`.

## README, CI and releases

- `README.md` is the WordPress.org readme, for site owners. Developer docs go
  here. Record user-visible changes under `### Unreleased` in its Changelog.
- `lint.yml` and `test.yml` run on PRs and pushes to `main` (Markdown-only
  changes skipped). `plugin-check.yml` runs Plugin Check on the same events,
  plus changes to `README.md`, which it validates: on the plugin as
  `.distignore` ships it, without `build/` (generated by `@wordpress/build`).
  It fails on errors only. All three also gate releases.
- Releases: follow the `bump-version` skill (`.claude/skills/bump-version`).
  `bin/check-version.sh` validates version consistency; WordPress.org deploy
  runs only when the `WPORG_DEPLOY` variable is `true`.
- `.distignore` decides what ships (`build/`, production `vendor/` and
  `composer.json`, not `src/` or `routes/`); `.gitattributes` `export-ignore`
  shapes GitHub source archives. Keep the two in step when adding files.

## Manual verification

1. `wp-env start`; confirm MariaDB 11.7+ (`wp db query "SELECT VERSION();"`).
2. Configure a provider (Settings > Connectors) and select its model on
   Settings > Otaria Search.
3. Create posts → `wp cron event run --due-now` → check rows with
   `SELECT post_id, chunk_index, VEC_ToText(embedding) FROM wp_otaria_search_embeddings`.
4. `wp otaria-search reindex`.
5. Search with semantically related but lexically different terms
   (e.g. "猫" vs. "ねこ" / "feline").
6. Disable the provider → search falls back to default, `WP_Error` logged.
7. Delete the plugin → the table is gone.
