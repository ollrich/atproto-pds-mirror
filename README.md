# ATProto PDS Mirror

<p align="center">
  <img src="image.png" alt="Mirroring posts from one AT Protocol PDS to another" width="420">
</p>

<p align="center">
  <a href="#"><img src="https://img.shields.io/badge/PHP-8.0%2B-777BB4?logo=php&logoColor=white" alt="PHP 8.0+"></a>
  <a href="LICENSE"><img src="https://img.shields.io/badge/license-MIT-green" alt="MIT License"></a>
</p>

Mirror top-level posts from one [AT Protocol](https://atproto.com) PDS to another. Built for the `eurosky.social` → `wsocial.eu` use case, but works with any pair of ATProto PDS instances.

## Why

If you run accounts on more than one AT Protocol instance, you probably don't want to manually cross-post everything. This script watches a source PDS on a schedule and recreates new posts automatically on a target PDS. Replies and reposts are ignored — only your own top-level posts get mirrored.

## How it works

The script runs as a PHP cron job on shared hosting (developed for [All-Inkl](https://all-inkl.com), works on any host with PHP 8.x, MySQL and cURL).

Each run does the following:

1. **Authenticate** on both PDSes via `com.atproto.server.createSession`
2. **Fetch** the most recent posts from the source PDS via `com.atproto.repo.listRecords`
3. **Filter**: replies are skipped, only top-level posts pass through
4. **Deduplicate** against the MySQL table `mirror_posts` to check whether a post was already mirrored
5. **Re-upload embeds**: if a post contains images, videos or link-card thumbnails, the blob is fetched from the source PDS (`com.atproto.sync.getBlob`), uploaded to the target PDS (`com.atproto.repo.uploadBlob`), and the references in the record are swapped out
6. **Create** the post on the target PDS via `com.atproto.repo.createRecord`
7. **Log** the result in MySQL

Supported embed types: images, videos, external links (with thumbnail), and record-with-media (e.g. quote posts that also have images).

## Files

| File | Purpose |
|------|---------|
| `mirror.php` | Main script, invoked by cron |
| `config.example.php` | Configuration template — copy to `config.php` |
| `seed.php` | One-off script: marks all existing posts as already seeded |
| `test.php` | Connection test for the database and both PDSes |
| `.htaccess` | Blocks `config.php`, logs and helper scripts over HTTP |

`config.php` and `mirror.log` are git-ignored and never committed.

## Requirements

- PHP 8.0+ with the cURL extension
- A MySQL database
- App Passwords on both PDS instances
- The **PDS URL** of the target server (not necessarily the same as the web URL — verifiable via the DID document on [plc.directory](https://plc.directory))

## Setup

**1. Upload the files** to a directory on your web server, e.g. `/www/htdocs/user/mirror/`.

**2. Create the configuration:**

```bash
cp config.example.php config.php
```

In `config.php`, fill in: PDS URLs, handles, App Passwords and MySQL credentials.

> **Tip:** You can find the target server's PDS URL via its DID document:
>
> ```bash
> curl -s https://plc.directory/did:plc:YOUR_DID | python3 -m json.tool
> ```
>
> The value under `service[0].serviceEndpoint` is the PDS URL.

**3. Test the connection:**

```bash
php test.php
```

Checks the DB connection, table creation and authentication against both PDSes.

**4. Seed existing posts:**

```bash
php seed.php
```

Records all of your existing posts in the database *without* mirroring them. This step prevents the first cron run from copying your entire post history to the target PDS.

**5. Set up a cron job** (every 5 minutes):

```
*/5 * * * * php /www/htdocs/user/mirror/mirror.php
```

If your host runs cron as an HTTP request, point it at the script's URL instead. The bundled `.htaccess` keeps `mirror.php` reachable over HTTP while blocking sensitive files.

## Logging

The script writes to `mirror.log` in the script directory. When run from the CLI it also prints to stdout. Each run logs authentication, processed posts and any errors.

## Database

The `mirror_posts` table is created automatically on the first run. Structure:

| Column | Meaning |
|--------|---------|
| `source_uri` | AT-URI of the original post |
| `source_cid` | Content hash of the original post |
| `target_uri` | AT-URI of the mirrored post (`NULL` for seeded entries) |
| `target_cid` | Content hash of the mirrored post |
| `created_at` | Creation timestamp of the original post |
| `mirrored_at` | Timestamp of the mirroring |

Seeded posts (from `seed.php`) are identifiable by `target_uri = NULL`.

## Limitations

- No real-time mirroring — the delay equals the cron interval
- Quote posts are mirrored, but the embedded reference still points to the original post on the source PDS (not to a local copy)
- Deleted posts on the source are not automatically deleted on the target
- The script assumes the target PDS exposes the standard XRPC endpoints

## License

[MIT](LICENSE) © Oliver Eichhof
