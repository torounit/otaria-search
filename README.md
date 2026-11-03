# WP MariaDB Vector Search

Replaces WordPress's default `LIKE`-based search with cosine similarity search powered by MariaDB's native `VECTOR` type, fused with the `LIKE` results so exact-term matches are still found.

## Requirements

| Dependency | Minimum version |
|------------|----------------|
| WordPress  | 7.2+           |
| PHP        | 8.2+           |
| MariaDB    | 11.7+          |

Embeddings are generated via the **WordPress 7.2 core AI Client embedding API** (`wp_ai_client_embedding()`). Install and configure (Settings > Connectors) an AI provider plugin that supports embedding generation, e.g. AI Provider for OpenAI 1.1+, then select its model on Settings > Vector Search. If no model is selected or the provider fails, indexing is skipped and search falls back to the standard WordPress LIKE search.

## Installation

1. Place the plugin in `wp-content/plugins/wp-mariadb-vector-search/`.
2. Activate it from the WordPress admin. The `{prefix}mariadb_vector_embeddings` table is created automatically on activation.

## Usage

### Automatic indexing

Posts are indexed (or removed from the index) automatically whenever they are published, updated, or deleted. Password-protected posts are left out by default, since indexing sends their content to the embedding provider; this can be changed on the admin page.

### Search

Front-end searches embed the search term and look up the closest posts in the index. By default (*Hybrid search* on the admin page) the results are combined with WordPress's standard `LIKE` search using Reciprocal Rank Fusion (RRF): posts found by both rank first, and posts that only contain the exact term (product codes, names) still appear. If nothing matches, the search shows no results.

### Admin page (Settings > Vector Search)

Displays MariaDB VECTOR support status, indexed post count, table dimensions, and backfill progress.

**Embedding Model** — Select one of the models that configured providers report as embedding-capable. Saving runs the `wp-mariadb-vector-search/select-model` ability, which embeds a short probe text with the model to detect its native vector dimension, then stores provider, model and dimensions together. If the probe fails, the error is shown and the saved settings are left unchanged. The embeddings table is not changed at this point. Switching to another model resets the search thresholds to their defaults and clears the prefixes.

**Prefixes** — Texts prepended before embedding: the query prefix to search terms, the document prefix to post content. Some models are trained with them (e.g. `query: ` / `passage: ` for E5 and Nemotron) and lose accuracy without; their model card gives the exact strings, including trailing spaces, which are kept. Empty by default. The query prefix takes effect immediately; a changed document prefix needs a Reindex, which re-embeds every post.

**Search** — *Hybrid search* fuses the vector results with WordPress's standard search (on by default); turn it off for vector-only results. The thresholds are the maximum cosine distance a post may have from the query (`0` = identical, smaller = more similar), and the maximum distance it may fall behind the closest post, which drops generic matches such as the default "Hello world!" post. Suitable values depend on the model, so they are set here per model. The preview embeds a query and lists the nearest posts with their cosine distances and whether the entered (unsaved) thresholds keep them. Posts containing the search term are still added by hybrid search.

**Post Types** — The post types that are indexed and searched: public, searchable ones except attachments (all of them until saved). Unchecking a post type removes its posts from the index; a checked post type that was not indexed before needs a Reindex.

**Indexing** — *Do not index password-protected posts* (on by default). Run Reindex after changing it: posts that are now excluded are removed from the index, and posts that are now allowed are embedded.

**Reindex** — Schedules a background cron job to embed all existing posts. Behaviour depends on the current state:

- **Dimensions match** — Non-destructive. Each post's rows are replaced individually; existing vectors for other posts are untouched. Optionally check *Force reindex* to re-embed posts whose content has not changed.
- **Dimensions differ** — The embeddings table must be recreated. A warning is shown and a confirmation checkbox is required. On confirmation the table is dropped and recreated at the new dimension, then a full reindex is scheduled.
- **Table not yet installed** — Creates the table at the saved model's dimensions, then schedules a full reindex. No confirmation needed.

### WP-CLI

```bash
# Reindex all posts
wp mariadb-vector reindex

# Limit to a specific post type
wp mariadb-vector reindex --post-type=post

# Re-embed even posts whose content has not changed
wp mariadb-vector reindex --force

# Set the batch size (default: 50)
wp mariadb-vector reindex --batch=100
```

## Configuration

Stored in the `wp_mariadb_vector_search_settings` option (array). Values are written by the admin page; missing keys fall back to their defaults.

| Key          | Description |
|--------------|-------------|
| `provider`   | AI provider id (e.g. `openai`, `lm_studio`). |
| `model`      | Embedding model id (e.g. `text-embedding-3-small`). |
| `dimensions` | Vector dimensions — auto-detected by probing the API when a model is saved. Fixed at table creation; changing to a different dimension requires a Reindex (which recreates the table). |
| `max_distance` | Search threshold set on the admin page. Default `0.65`. |
| `max_relative_distance` | Search threshold set on the admin page. Default `0.25`. |
| `hybrid` | Whether hybrid search is on, set on the admin page. Default `true`. |
| `exclude_password_protected` | Leave password-protected posts out of the index. Set on the admin page. Default `true`. |
| `post_types` | Post types to index and search, set on the admin page. Default all public, searchable post types except attachments. |
| `query_prefix` | Prepended to search terms before embedding, set on the admin page. Default empty. |
| `document_prefix` | Prepended to post chunks before embedding, set on the admin page. Default empty. |

Chunk settings are code-level defaults in `Chunker.php` and are not stored in the option:

| Setting              | Default | Description |
|----------------------|---------|-------------|
| `chunk_size_chars`   | 2000    | Target chunk size in characters. |
| `chunk_overlap_chars`| 300     | Overlap between adjacent chunks in characters. |

## Filters

### Core AI Client filters

Embedding requests go through the core AI Client, so its filters apply too.
The default HTTP timeout is core's (30 seconds). Local models that load on the
first request (e.g. LM Studio) may need longer; the filter receives the builder
class, so the timeout can be raised for embeddings only:

```php
add_filter(
    'wp_ai_client_default_request_timeout',
    function ( float $timeout, string $builder_type ): float {
        return 'WP_AI_Client_Embedding_Builder' === $builder_type ? 120.0 : $timeout;
    },
    10,
    2
);
```

## Uninstall

Deleting the plugin runs `uninstall.php`, which drops the embeddings table and removes all related options.

## Development

### Running tests

```bash
npm install
composer install

# Start wp-env (MariaDB 11.7+) and run PHPUnit
npm run test:php
```

### File structure

```
includes/
  Admin.php                  — Settings > Vector Search admin page
  Chunker.php                — HTML-strip + paragraph/sentence chunking with overlap
  CLI.php                    — WP-CLI reindex command
  Content_Hash.php           — SHA-256 hash of post title + content
  Cron_Backfill.php          — Batched cron-driven backfill for existing posts
  Indexer.php                — chunk → embed → store pipeline
  Model_Catalog.php          — Lists embedding-capable models from the AI Client registry
  Plugin.php                 — Plugin lifecycle and hook registration
  Repository.php             — $wpdb wrapper: vector CRUD and similarity search
  Schema.php                 — DDL management (CREATE / DROP / version check)
  Search.php                 — pre_get_posts hook: rewrites search to vector similarity
```
