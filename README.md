# Otaria Search

Contributors: Toro_Unit  
Donate link: https://www.paypal.me/torounit  
Tags: search, semantic search, vector search, ai, mariadb  
Requires at least: 7.2  
Tested up to: 7.2  
Stable tag: 0.3.0  
Requires PHP: 8.2  
License: GPLv2 or later  
License URI: https://www.gnu.org/licenses/gpl-2.0.html  

Semantic and hybrid search for WordPress: finds posts by meaning, with embeddings stored in MariaDB's VECTOR column, alongside keyword matches.

## Description

Otaria Search replaces the default WordPress search with semantic search: a search for "cat" also finds posts that only say "kitten" or "feline". By default, the results are combined with the standard keyword search (hybrid search), so exact terms such as product codes and names are still found.

Embeddings are created through the AI Client embedding API of WordPress 7.2, with any AI provider plugin that supports them, such as AI Provider for OpenAI or AI Provider for LM Studio. They are stored in your site's own MariaDB database, so no external vector database is needed.

GitHub: [https://github.com/torounit/otaria-search](https://github.com/torounit/otaria-search)

### Features

* Indexes posts automatically when they are published, updated or deleted
* Search thresholds per model, with a preview of the nearest posts and their distances
* Choose which post types are indexed
* Query and document prefixes for models that need them
* WP-CLI command to reindex all posts

### Requirements

* **MariaDB 11.7 or later** (11.8 LTS recommended). MySQL has no `VECTOR` type and is not supported: the plugin shows a notice and WordPress keeps using its standard search.
* WordPress 7.2 or later.
* An AI provider plugin that supports embedding generation.

### External services

To create embeddings, this plugin sends the following to the AI provider configured in WordPress (through the WordPress AI Client):

* The title and text of published posts, when they are indexed
* The search terms entered by visitors, when they search

Password-protected posts are not sent by default. Which service receives the data, and under which terms, depends on the AI provider plugin you use; a local provider such as LM Studio keeps it on your own machine.

## Installation

1. Install and activate the plugin. The embeddings table is created on activation.
2. Configure an AI provider plugin that supports embeddings under **Settings > Connectors**.
3. On **Settings > Otaria Search**, select an embedding model and save it.
4. Click **Reindex all posts** to index the existing posts. New and updated posts are indexed automatically.

## Frequently Asked Questions

### What happens when the AI provider is unavailable?

Search falls back to the standard WordPress search, and indexing is retried on the next save or Reindex.

### How do I tune the search results?

In the **Search** section of **Settings > Otaria Search**, set the maximum distance from the search term and the maximum distance behind the best match. Suitable values depend on the model, so use the preview to see the nearest posts and their distances before saving.

### What happens when I switch to another embedding model?

Embeddings from different models cannot be compared, so run **Reindex all posts** right after saving the new model. This sends all indexed posts to the AI provider again. The search thresholds and prefixes are reset, since they depend on the model.

If the new model has a different number of dimensions, Reindex recreates the embeddings table after you confirm it, which deletes all existing embeddings. Search results are unreliable until the reindex has finished.

### What are the query and document prefixes?

Some embedding models, such as E5 and Nemotron, expect a text in front of search terms and documents (`query: ` and `passage: `) and are less accurate without it. Enter them exactly as your model's documentation says, including trailing spaces. Most models, including OpenAI's, need none. A changed document prefix requires a Reindex.

### Which posts are indexed?

Published posts of the post types selected on the settings page (by default, all public, searchable post types). Password-protected posts are left out unless you change this in the **Indexing** section.

### Can I reindex from the command line?

Yes, with WP-CLI:

```bash
wp otaria-search reindex
wp otaria-search reindex --post-type=post
wp otaria-search reindex --force      # re-embed unchanged posts too
wp otaria-search reindex --batch=100  # posts per batch (default: 50)
```

### What is removed when I delete the plugin?

The embeddings table and all of the plugin's settings.

## Changelog

### Unreleased

* The default maximum distance behind the best match is now 0.10 (was 0.25), so fewer unrelated posts trail the results. Saved values are unchanged; hybrid search still adds the posts that contain the search term.

### 0.3.0

* Initial release.
