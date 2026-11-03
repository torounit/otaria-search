# Squirrel Search

Contributors: Toro_Unit  
Donate link: https://www.paypal.me/torounit  
Tags: search, semantic search, vector search, ai, mariadb  
Requires at least: 7.2  
Tested up to: 7.2  
Stable tag: 0.2.0  
Requires PHP: 8.2  
License: GPLv2 or later  
License URI: https://www.gnu.org/licenses/gpl-2.0.html  

Semantic and hybrid search for WordPress: finds posts by meaning, with embeddings stored in MariaDB's VECTOR column, alongside keyword matches.

## Description

Squirrel Search replaces the default WordPress search, which only finds posts containing the exact search term, with semantic search. A search for "cat" also finds posts that only say "kitten" or "feline", because posts and search terms are compared by meaning.

Meaning is captured by embeddings, created through the AI Client embedding API introduced in WordPress 7.2. It works with any AI provider plugin that supports embeddings, such as AI Provider for OpenAI or AI Provider for LM Studio. The embeddings are stored in a `VECTOR` column of your site's own MariaDB database, so no external vector database is needed.

By default, the results are combined with WordPress's standard keyword search (hybrid search), so exact terms such as product codes and names are still found.

GitHub: [https://github.com/torounit/squirrel-search](https://github.com/torounit/squirrel-search)

### Features

* Finds posts by meaning, not only by matching words
* Hybrid search: fuses semantic results with keyword matches (Reciprocal Rank Fusion)
* Provider-agnostic: uses the embedding model of any AI provider configured in WordPress
* Stores embeddings in MariaDB's native `VECTOR` type, with a vector index for fast lookups
* Indexes posts automatically when they are published, updated or deleted
* Search thresholds per model, with a preview of the nearest posts and their distances
* Choose which post types are indexed
* Query and document prefixes for models that need them (e.g. `query: ` / `passage: `)
* WP-CLI command to reindex all posts

### Requirements

* **MariaDB 11.7 or later** (11.8 LTS recommended). MySQL is not supported, since it has no `VECTOR` type.
* WordPress 7.2 or later, for the AI Client embedding API.
* An AI provider plugin that supports embedding generation, configured under **Settings > Connectors**.

### External services

To create embeddings, this plugin sends the following to the AI provider configured in WordPress (through the WordPress AI Client):

* The title and text of published posts, when they are indexed
* The search terms entered by visitors, when they search

Password-protected posts are not sent by default. Which service receives the data, and under which terms, depends on the AI provider plugin you use; a local provider such as LM Studio keeps it on your own machine.

## Installation

1. Upload the plugin files to the `/wp-content/plugins/squirrel-search` directory, or install the plugin through the WordPress plugins screen directly.
2. Activate the plugin through the **Plugins** screen in WordPress. The embeddings table is created on activation.
3. Configure an AI provider plugin that supports embeddings under **Settings > Connectors**.
4. On **Settings > Squirrel Search**, select an embedding model and save it.
5. Click **Reindex all posts** to index the existing posts. New and updated posts are indexed automatically.

## Frequently Asked Questions

### Does it work with MySQL?

No. The plugin needs the `VECTOR` type of MariaDB 11.7 or later. On other databases it shows a notice and WordPress keeps using its standard search.

### What happens when the AI provider is unavailable?

Search falls back to the standard WordPress search, and indexing is retried on the next save or Reindex. Search never returns an error page because of the provider.

### How do I tune the search results?

On **Settings > Squirrel Search**, the **Search** section sets two thresholds: the maximum distance of a post from the search term, and the maximum distance behind the best match. Suitable values depend on the model, so enter a search term in the preview to see the nearest posts with their distances before saving. Switching to another model resets the thresholds.

### What happens when I switch to another embedding model?

Embeddings from different models cannot be compared, so every post has to be embedded again: run **Reindex all posts** right after saving the new model. This sends all indexed posts to the AI provider again. The search thresholds and prefixes are reset, since they depend on the model.

If the new model has a different number of dimensions, Reindex recreates the embeddings table after you confirm it, which deletes all existing embeddings. Search results are unreliable until the reindex has finished.

### What are the query and document prefixes?

Some embedding models are trained with a text in front of search terms and documents, such as `query: ` and `passage: ` for E5 and Nemotron models, and are less accurate without it. Check your model's documentation and enter them exactly, including trailing spaces. Most models, including OpenAI's, need none. A changed document prefix requires a Reindex.

### Which posts are indexed?

Published posts of the post types selected on the settings page (all public, searchable post types by default). Password-protected posts are left out by default; this can be changed in the **Indexing** section.

### Can I reindex from the command line?

Yes, with WP-CLI:

```bash
wp squirrel-search reindex
wp squirrel-search reindex --post-type=post
wp squirrel-search reindex --force      # re-embed unchanged posts too
wp squirrel-search reindex --batch=100  # posts per batch (default: 50)
```

### Embedding requests to my local model time out. What can I do?

Requests use the WordPress AI Client's timeout (30 seconds by default). Local models that load on the first request may need longer. Raise it for embeddings only:

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

### What is removed when I delete the plugin?

The embeddings table and all of the plugin's settings.

## Changelog

### Unreleased

* Initial release.
