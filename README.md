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

## Official Documentation

Documentation for Valet can be found on the [Valet Linux website](https://valetlinux.plus/).

> [!NOTE]
> The original documentation website (valetlinux.plus) is currently unavailable. A new documentation website for this fork will be available soon.

## License

Laravel Valet is open-sourced software licensed under the [MIT license](http://opensource.org/licenses/MIT)
