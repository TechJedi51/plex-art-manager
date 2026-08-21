# Plex Art Manager

A self-hosted web app for auditing and filling in Plex artwork (poster, background,
square art, logo) across a movie library, with candidate image search (Fanart.tv /
TMDB), manual upload overrides, and a durable "needs review" worklist.

Pure PHP + SQLite. No Python, no Node build step, no external database server.

![PHP](https://img.shields.io/badge/PHP-8.1%2B-777bb4)
![License](https://img.shields.io/badge/license-MIT-blue)

## Features

- Audits Poster, Background, Square Art, and Logo against what Plex actually
  reports for each movie, and saves any that are missing or out of date
- Recognizes Plex's own local-asset naming conventions (not just a fixed
  filename), so it won't duplicate artwork you've already placed by hand
- Batch processing with live progress, dry-run mode, and resumable
  start/stop ranges for large libraries
- Candidate image search (Fanart.tv, TMDB) with a picker UI, plus manual
  upload for anything neither service has
- A "Needs Review" worklist for anything that failed, with per-movie or
  per-batch "Ignore" so the same gap doesn't keep resurfacing
- A Diagnostics page for tracing exactly what Plex reports vs. what the app
  can actually see/write on disk - the single most useful tool for debugging
  permission or path-mapping issues
- Built-in Help page with searchable documentation

## Quick Start (Docker)

```bash
git clone https://github.com/techjedi51/plex-art-manager.git
cd plex-art-manager
```

Edit `docker-compose.yml`:
- Set the `/path/to/your/movies` volume to your actual movie library's path
- Adjust `PUID`/`PGID` to match whatever already owns your media files (`id -u`
  / `id -g` on the account that owns them, if unsure)
- By default this pulls the pre-built image from GHCR (see
  [Pre-built images](#pre-built-images) below). If you'd rather build from
  source instead, swap the `image:` line for `build: .`

```bash
docker compose up -d
```

Visit `http://localhost:8080` (or whatever port you mapped), then:

1. **Settings** - enter your Plex URL/token (and Fanart.tv/TMDB API keys, if
   you want candidate image search)
2. **Movies → Sync Library** - pulls titles/paths in (fast, no downloads)
3. **Batch Process** - actually check/save artwork

If Plex reports a different path than the one this container sees for the
same movie (e.g. Plex runs on a different machine, or your media is mounted
at a different point here), set **Mapped Folders** in Settings to translate
between the two - see the in-app Help page for the exact format and an
example.

### Advanced: media on a different host

If Plex's media library lives on a different machine than the one running
this container, see the commented CIFS/SMB example at the bottom of
`docker-compose.yml`. This requires `cifs-utils` (or your distro's
equivalent) installed on the Docker **host** itself, since Docker's volume
driver shells out to `mount.cifs`.

## Pre-built images

Tagged releases are also published as multi-arch (amd64/arm64) images to
GitHub Container Registry:

```bash
docker pull ghcr.io/techjedi51/plex-art-manager:latest
```

This is what `docker-compose.yml` uses by default. If you'd rather build
from source instead (e.g. to test local changes), swap the `image:` line
for `build: .`.

## Alternative: bare-metal nginx + php-fpm

If you'd rather not containerize this, or you're already running nginx +
php-fpm for other sites and want to add this as one more:

### Requirements

- PHP 8.1+ with extensions: `pdo_sqlite`, `curl`, `fileinfo`, `gd` (optional -
  without it, thumbnails on the movie detail page are served unresized rather
  than resized/cached)
- nginx + php-fpm
- Network access to your Plex server, and outbound HTTPS to
  `webservice.fanart.tv` / `api.themoviedb.org` if you want candidate search
- Write access for the php-fpm user to `data/` and `cache/` inside this
  project, **and** to every movie folder Plex points at

### Install

```bash
cp -r plex-art-manager /path/to/webroot/plex-art-manager
cd /path/to/webroot/plex-art-manager

# php-fpm's user needs to write here - adjust the user/group to match your
# php-fpm pool config (commonly www-data on Linux, _www on macOS)
chown -R www-data:www-data data cache
chmod -R 770 data cache
```

### nginx

`data/` and `cache/` hold the SQLite database and rendered thumbnails and
must **never** be served directly:

```nginx
server {
    listen 443 ssl;
    server_name plexart.yourdomain.internal;

    root /path/to/webroot/plex-art-manager;
    index index.php;

    location ~ ^/(data|cache|includes|cli|docker)/ {
        deny all;
        return 404;
    }

    location / {
        try_files $uri $uri/ /index.php?$query_string;
    }

    location ~ \.php$ {
        fastcgi_pass unix:/var/run/php-fpm.sock;   # match your php-fpm pool socket/port
        fastcgi_index index.php;
        include fastcgi_params;
        fastcgi_param SCRIPT_FILENAME $document_root$fastcgi_script_name;
    }

    # ssl_certificate / ssl_certificate_key ...
}
```

Since Settings stores your Plex token and any API keys in plaintext in the
SQLite database, don't expose this publicly without some form of access
control in front of it - a reverse-proxy auth layer, an IP allowlist, or
keeping it reachable only over a VPN/private network are all reasonable
options depending on your setup.

### First run

1. Visit the site - `index.php` creates `data/app.sqlite` and the schema
   automatically on first load.
2. **Settings** - Plex URL/token, optionally Fanart.tv/TMDB keys.
3. **Movies → Sync Library** - pulls titles/paths in.
4. **Batch Process** - save/update artwork.

## Cron / unattended sweeps

`cli/process_batch_cli.php` reuses the exact same batch logic as the web UI:

```bash
php cli/process_batch_cli.php --library="Movies" --types=poster,art,square,logo
```

Example crontab (poster+background daily; square/logo weekly, since they're
slower - each requires one extra Plex API call per movie):

```cron
0 3 * * * php /path/to/plex-art-manager/cli/process_batch_cli.php --library="Movies" --types=poster,art >> /path/to/plex-art-manager/data/cron.log 2>&1
0 4 * * 0 php /path/to/plex-art-manager/cli/process_batch_cli.php --library="Movies" --types=square,logo >> /path/to/plex-art-manager/data/cron.log 2>&1
```

(Inside the Docker image, run this via `docker compose exec plex-art-manager php cli/process_batch_cli.php ...` from the host's own cron instead.)

## Known limitations / things worth knowing

- **Square art candidates will usually come back empty.** Neither Fanart.tv
  nor TMDB has a dedicated "square cover art" category for movies (it's
  mostly a music/artist concept on those services). Plex's own square art
  images typically come from a manual upload or a third-party tool. If you
  want real square-art candidate search, the provider interface
  (`getCandidates($tmdbId, $assetType)` in `includes/providers/`) is written
  so adding another source is a drop-in, not a rearchitecture.
- **Square/Logo lookups cost one extra Plex round-trip per movie** the first
  time, because Plex's library-listing endpoint doesn't include the full
  image list - only the per-item endpoint does. Poster/Background are
  already covered by that same call, so running all four together isn't 2x
  slower than poster+background alone, just slower than poster+background by
  itself if you split the runs.
- **The "kept existing" fallback** (when Plex has no art at all, or a
  download fails) can't distinguish "Plex genuinely has nothing" from "a
  transient error happened" - both end up keeping whatever local file is
  already there rather than reporting a failure. Check the asset history on
  a movie's detail page if you want to audit which case actually happened.
- Built and tested against **Movies** libraries. TV show libraries aren't
  wired up (Plex's per-episode file locations vs. per-show artwork make the
  "same folder as the media" assumption more complicated).

## Contributing

Issues and PRs welcome. The codebase is intentionally small and
dependency-light - see `includes/` for the core logic, `api/` for the HTTP
endpoints, and `assets/js/app.js` for the entire frontend (single file, no
build step).

## License

MIT
