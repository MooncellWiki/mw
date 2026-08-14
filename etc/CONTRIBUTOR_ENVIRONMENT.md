# PRTS contributor environment

## PHP 8.1 environment

The verified development runtime is PHP **8.1.33**. Contributors may use any PHP version
manager or installation method as long as the active CLI runtime and extensions match the
requirements below. Verify the active runtime before installing dependencies or running
MediaWiki:

```bash
which php
php -v
php --ini
```

The verified PHP installation provides these application-relevant extensions:

```text
apcu bcmath bz2 calendar ctype curl dom excimer fileinfo gd gettext iconv intl
luasandbox mbstring memcached mysqli mysqlnd openssl pcntl pdo_mysql posix
readline redis session shmop soap sockets sysvmsg sysvsem sysvshm xml xmlreader
xmlrpc xmlwriter xsl zip zlib Zend OPcache
```

In particular, MediaWiki and the enabled extensions require `mysqli`, `pdo_mysql`, `intl`,
`gd`, `mbstring`, `xml`, `zip`, `apcu`, `memcached`, `redis`, `excimer`, and `luasandbox`.
Scribunto will not work without `luasandbox`.

Verify the active runtime:

```bash
php -m
php -r '
$required = [
    "mysqli", "pdo_mysql", "intl", "gd", "mbstring", "xml", "zip", "apcu",
    "memcached", "redis", "excimer", "luasandbox", "Zend OPcache"
];
foreach ($required as $extension) {
    printf("%-16s %s\n", $extension, extension_loaded($extension) ? "OK" : "MISSING");
}
'
```

The current CLI defaults include `memory_limit=128M`, `upload_max_filesize=2M`,
`post_max_size=8M`, `opcache.enable=1`, and `opcache.enable_cli=0`. For the built-in
development server, start PHP with CLI OPcache enabled and source timestamp validation:

```bash
php -d opcache.enable_cli=1 \
    -d opcache.validate_timestamps=1 \
    -d opcache.revalidate_freq=0 \
    -S 0.0.0.0:8080
```

Do not pass a router script. Install PHP dependencies after activating a compatible PHP
8.1 runtime:

```bash
composer install
```

## Local configuration

Copy `etc/config.example.php` to `etc/config.php` and `etc/post-config.example.php` to
`etc/post-config.php`. The defaults expect MySQL on `127.0.0.1:3307`, database `ak`, table
prefix `ak`, and local-only account `akdev` / `akdev`. Environment variables documented in
the example override those values.

After the database is available, run the schema updater before opening the site:

```bash
php maintenance/update.php --quick --skip-external-dependencies
```

Semantic MediaWiki creates `etc/.smw.json` during this update. The `etc` directory must be
writable by the user running the command. `.smw.json` is generated runtime state and remains
ignored by Git.

The example disables production CDN, Redis, Memcached, Google/Baidu analytics, and advertising.
It retains the `static.prts.wiki` frontend imports and the Aliyun OSS/AWS-compatible backend.
Provide OSS credentials through `PRTS_OSS_ACCESS_KEY_ID` and
`PRTS_OSS_ACCESS_KEY_SECRET`. Region, bucket, public domain, hash levels, and endpoint are
already set to the PRTS values in the example. Never add real credentials to a contributor
package.

## Restore format

The release is a compressed full Percona XtraBackup 8.0 directory. Decompress and prepare it
with the same XtraBackup 8.0 image, then copy it into an empty MySQL 8.0.43 datadir. The
published release must include checksums and its audit report.

For a tar release, extract it into an empty `backup` directory, then run:

```bash
docker run --rm -v "$PWD/backup:/backup" percona/percona-xtrabackup:8.0 \
  xtrabackup --decompress --remove-original --target-dir=/backup --parallel=4
docker run --rm -v "$PWD/backup:/backup" percona/percona-xtrabackup:8.0 \
  xtrabackup --prepare --target-dir=/backup
```

Copy the prepared files into an empty datadir, make them owned by the MySQL container user
(UID/GID 999 in the official image), and start `mysql:8.0.43`. The local credentials are
`root` / `akdev` and `akdev` / `akdev`; change them if the instance is exposed beyond localhost.
