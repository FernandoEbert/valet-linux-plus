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

Your existing configuration in `~/.valet` (parked paths, links, certificates, isolated PHP versions) is preserved.

```bash
composer global remove genesisweb/valet-linux-plus
composer global require fernandoebert/valet-linux-plus
valet install
```

`valet install` re-creates the `/usr/local/bin/valet` symlink pointing to the new package. Both packages cannot be installed side by side.

To go back to the original package, run the same steps swapping the package names.

## Official Documentation

Documentation for Valet can be found on the [Valet Linux website](https://valetlinux.plus/).

## License

Laravel Valet is open-sourced software licensed under the [MIT license](http://opensource.org/licenses/MIT)
