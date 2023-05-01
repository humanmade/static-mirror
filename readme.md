# Static Mirror

A static mirror is a complete copy of the whole website or a single URL, saved as fully operational HTML pages. The mirrors are ultimately created via `wget` requests to the requested URL(s) and saved to the `/mirrors` subdirectory of the uploads directory.

The following static mirrors are generated:

* A mirror of the entire site once a day via the `static_mirror_create_mirror` recurring WP-Cron event
* A mirror of the affected URL whenever a page is updated, via an immediately-scheduled `static_mirror_create_mirror_for_url` WP-Cron event that's added via the `save_post` and `set_object_terms` hooks (with de-duplication)

## Constants

- `SM_NO_CHECK_CERT`: Skip checking SSL certificates by `wget` if true, useful for local development
- `SM_TTL`: How long to keep mirrors for in seconds

## WP-CLI Commands

The following WP-CLI commands are available:

* ```
  wp static-mirror create-mirror [--changelog=<changelog>]
  ```
* ```
  wp static-mirror list [--showposts=<showposts>] [--paged=<paged>] [--format=<format>] [--fields=<fields>]
  ```

* ```
  wp static-mirror delete-expired
  ```
