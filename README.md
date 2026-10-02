# Bug Catcher performance collector

Per-request performance monitoring for any PHP application — wallclock, user and system CPU, peak
memory and HTTP status — with **no PHP extension, no daemon and no Composer dependencies** beyond
`ext-curl`.

It collects the way [phptop](https://github.com/bearstech/phptop) does: one `auto_prepend_file`
line in `php.ini` writes a JSON line per request to a local file, and a cron job rolls those lines
up into per-minute aggregates and ships them to a
[Bug Catcher](https://github.com/php-bug-catcher/bug-catcher) server. Nothing leaves the request.

```
auto_prepend_file hook ──▶ /var/log/bcperf.jsonl ──▶ bc-perf-aggregate ──HTTP──▶ Bug Catcher
     (one line/request)        (local only)            (cron, every minute)
```

## Install

```
composer require php-bug-catcher/perf-collector
```

### 1. The hook

Point `auto_prepend_file` at `hook/collector.php` and tell it where to write:

```ini
auto_prepend_file = /path/to/vendor/php-bug-catcher/perf-collector/hook/collector.php
```

The hook is configured from the environment or from `php.ini`, because it runs before any
autoloader and has no container to read:

| Setting | Default | Meaning |
|---|---|---|
| `BCPERF_LOG` | — (hook does nothing) | Log path. Accepts a `strftime` pattern for daily rotation, e.g. `/var/log/bcperf-%Y%m%d.jsonl`. |
| `BCPERF_SAMPLE_RATE` | `1` | `1` records everything, `10` every tenth request. The rate is written into the line as `w`, so the aggregator scales the counts back up. |
| `BCPERF_CLI` | `0` | Record CLI processes too. Off by default — cron noise. |

Either form works; the environment wins:

```ini
php_value bcperf.log /var/log/bcperf.jsonl
```

### 2. The aggregator

```cron
* * * * *  bc-perf-aggregate --log=/var/log/bcperf.jsonl --endpoint=https://bugcatcher.example.com --project=myapp
```

It reads from the stored offset, groups by `(minute, host, normalised path)`, POSTs the batch to
`/api/perf_buckets` and only then advances the cursor — a failed ship is retried by the next run
instead of being lost.

## Why a line per request is cheap

The request only measures and appends; everything expensive — path normalisation, histograms,
grouping, JSON parsing, HTTP — happens in the cron job. Measured overhead is in the README section
[Overhead](#overhead) and asserted by `tests/Hook/CollectorHookBenchTest.php`.

## Overhead

To be filled in with measured numbers once the hook lands.

## License

MIT.
