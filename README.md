<p align="center"><img width="500" src="art/logo.png"></p>

<p align="center">
<a href="https://packagist.org/packages/fernandoebert/valet-linux-plus"><img src="https://poser.pugx.org/fernandoebert/valet-linux-plus/downloads.svg" alt="Total Downloads"></a>
<a href="https://packagist.org/packages/fernandoebert/valet-linux-plus"><img src="https://poser.pugx.org/fernandoebert/valet-linux-plus/v/stable.svg" alt="Latest Stable Version"></a>
<a href="https://packagist.org/packages/fernandoebert/valet-linux-plus"><img src="https://poser.pugx.org/fernandoebert/valet-linux-plus/license.svg" alt="License"></a>
</p>

## Introduction

Valet *Linux+* is an advanced development environment for Linux minimalists. No Vagrant, no `/etc/hosts` file. You can even share your sites publicly using local tunnels. _Yeah, we like it too._

Valet *Linux+* configures your system to always run Nginx in the background when your machine starts. Then, using [DnsMasq](https://en.wikipedia.org/wiki/Dnsmasq), Valet proxies all requests on the `*.test` domain to point to sites installed on your local machine.

In other words, a blazing fast PHP development environment that uses roughly 7mb of RAM. Valet *Linux+* isn't a complete replacement for Vagrant or Homestead, but provides a great alternative if you want flexible basics, prefer extreme speed, or are working on a machine with a limited amount of RAM.

## About this fork

This is a maintained fork of [genesisweb/valet-linux-plus](https://github.com/genesisweb/valet-linux-plus), published on Packagist as [`fernandoebert/valet-linux-plus`](https://packagist.org/packages/fernandoebert/valet-linux-plus). Issues and pull requests should be opened at [FernandoEbert/valet-linux-plus](https://github.com/FernandoEbert/valet-linux-plus).

Documentation website: **[valet-linux-plus.ebertsystem.com.br](https://valet-linux-plus.ebertsystem.com.br/)** (temporary URL, a dedicated domain is coming soon).

### Installation

```bash
composer global require fernandoebert/valet-linux-plus
valet install
```

### Migrating from `genesisweb/valet-linux-plus`

Both packages cannot be installed side by side, so the migration swaps one for the other.

**What is preserved**

- Your Valet configuration in `~/.config/valet`: parked paths, links, certificate authority, isolated PHP versions and database credentials.
- Your databases. `valet install` does not reinstall or reconfigure MySQL/MariaDB when it is already installed and its credentials are stored in `~/.config/valet/config.json`.
- Your installed PHP versions and extensions.

**Why more than `remove` + `require` is needed**

Site Nginx configs in `~/.config/valet/Nginx` (secured, isolated and proxied sites) store the absolute path to `server.php` inside the Composer vendor directory. `valet install` rewrites those files from their current content, so the old `genesisweb/valet-linux-plus` path would remain and the sites would break. The commands below update those paths, keep the ngrok binary and re-link the `valet` and `php` executables.

Your `.test` sites are unavailable between steps 2 and 5.

```bash
# 1. Resolve paths and back up the site configs
VENDOR_DIR="$(composer global config vendor-dir --absolute 2>/dev/null)"
OLD_PATH="$VENDOR_DIR/genesisweb/valet-linux-plus"
NEW_PATH="$VENDOR_DIR/fernandoebert/valet-linux-plus"
cp -a ~/.config/valet/Nginx ~/.config/valet/Nginx.bak
[ -f "$OLD_PATH/bin/ngrok" ] && cp "$OLD_PATH/bin/ngrok" /tmp/valet-ngrok

# 2. Swap the packages
composer global remove genesisweb/valet-linux-plus
composer global require fernandoebert/valet-linux-plus

# 3. Point the site configs to the new server.php
find ~/.config/valet/Nginx -type f ! -name '.keep' \
    -exec sed -i 's#/genesisweb/valet-linux-plus/#/fernandoebert/valet-linux-plus/#g' {} +

# 4. Restore the ngrok binary (only used by `valet share`)
[ -f /tmp/valet-ngrok ] && mkdir -p "$NEW_PATH/bin" && mv /tmp/valet-ngrok "$NEW_PATH/bin/ngrok"

# 5. Reinstall Valet (asks for sudo).
#    Use the full path: /usr/local/bin/valet still points to the removed package.
#    Answer "yes" when asked to link valet's php binary.
hash -r
"$NEW_PATH/valet" install

# 6. Verify
valet --version
readlink -f /usr/local/bin/valet /usr/local/bin/php
grep -rl genesisweb ~/.config/valet/Nginx /etc/nginx/sites-available   # expect no output
```

When everything works, remove the backup: `rm -rf ~/.config/valet/Nginx.bak`.

**What `valet install` changes**

- Regenerates `/etc/nginx/nginx.conf`, `/etc/nginx/fastcgi_params`, `/etc/nginx/sites-available/valet.conf` and the PHP-FPM `valet.conf` pool from the package stubs (backups are kept for `nginx.conf` and `fastcgi_params`).
- Re-issues the certificates of secured sites, signed by the same local CA, so browsers keep trusting them.
- Re-creates the `/usr/local/bin/valet` and `/usr/local/bin/php` symlinks.
- Restarts Nginx, PHP-FPM and DnsMasq.

**Rollback**

```bash
VENDOR_DIR="$(composer global config vendor-dir --absolute 2>/dev/null)"
rm -rf ~/.config/valet/Nginx && mv ~/.config/valet/Nginx.bak ~/.config/valet/Nginx
composer global remove fernandoebert/valet-linux-plus
composer global require genesisweb/valet-linux-plus
hash -r
"$VENDOR_DIR/genesisweb/valet-linux-plus/valet" install
```

### Per-project Nginx config

A site can ship its own Nginx server block inside the project, at `<project>/nginx/<site>.<tld>.conf`, and keep it across `valet secure`, `unsecure`, `isolate`, `unisolate` and `domain`.

```bash
cd ~/projects/myapp           # a linked or parked site
valet nginx:publish           # writes nginx/myapp.test.conf from Valet's default config for the site
# edit nginx/myapp.test.conf
valet nginx                   # activates it: renders, validates with `nginx -t` (rolls back on failure) and restarts Nginx
valet nginx:reset             # back to Valet's default config (the project file is kept)
```

`nginx:publish` picks the stub matching the site state (secured and/or isolated) and accepts `--force` to overwrite an existing file. All commands accept the site name as argument (default: current directory).

The template keeps Valet placeholders, so it can be versioned and works on any machine:

| Placeholder | Value |
|---|---|
| `VALET_SITE` | Site domain, e.g. `myapp.test` |
| `VALET_PROJECT_PATH` | Project root |
| `VALET_HOME_PATH` | `~/.config/valet` |
| `VALET_SERVER_PATH` / `VALET_STATIC_PREFIX` | Valet's `server.php` and static files prefix |
| `VALET_CERT` / `VALET_KEY` | TLS certificate and key (site must be secured) |
| `VALET_FPM_SOCKET_FILE` / `VALET_ISOLATED_PHP_VERSION` | PHP-FPM socket and version (isolated version when the site is isolated) |
| `VALET_HTTP_PORT` / `VALET_HTTPS_PORT` / `VALET_REDIRECT_PORT` | Configured ports (`VALET_REDIRECT_PORT` is `:port` when HTTPS is not 443) |

Example: serve the project front controller directly instead of Valet's `server.php` (replace `root`, `location /` and `location ~ \.php$` of the published file):

```nginx
root VALET_PROJECT_PATH;
index index.php;

location / {
    try_files $uri $uri/ /index.php?route=$uri&$args;
}

location ~ \.php$ {
    try_files $uri =404;
    fastcgi_split_path_info ^(.+\.php)(/.+)$;
    fastcgi_pass "unix:VALET_FPM_SOCKET_FILE";
    include fastcgi_params;
    fastcgi_param SCRIPT_FILENAME $document_root$fastcgi_script_name;
}
```

## Official Documentation

Documentation for this fork can be found on the [Valet Linux+ website](https://valet-linux-plus.ebertsystem.com.br/).

> [!NOTE]
> The current URL (`valet-linux-plus.ebertsystem.com.br`) is temporary. A dedicated domain for the project will be acquired soon, and this link will be updated accordingly.
>
> The original documentation website (valetlinux.plus) is no longer available.

## License

Laravel Valet is open-sourced software licensed under the [MIT license](http://opensource.org/licenses/MIT)
