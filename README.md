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

Each one can also be written into `php.ini` in lower case under a `bcperf.` prefix — the
environment wins when both are set:

```ini
bcperf.log = /dev/shm/bcperf.jsonl
bcperf.sample_rate = 1
bcperf.cli = 0
```

One request becomes one line:

```json
{"t":1759400000.123,"d":0.438,"u":0.4,"s":0.012,"m":31457280,"c":200,"sv":"https",
 "h":"www.site.com","p":"/feed/","q":"page=2","x":"GET","i":20789,"w":1,"n":"web-01"}
```

An application that can tell you more assigns numbers to `$GLOBALS['_bcperf_extra']` — a Doctrine
query count, WordPress' `$wpdb`, a custom timer — and they are written out under `e` and summed
per bucket by the aggregator. `php-bug-catcher/perf-collector-bundle` does exactly that for
Doctrine, and is the reference for doing it yourself.

### 2. The aggregator

```cron
* * * * *  bc-perf-aggregate --log=/var/log/bcperf.jsonl --endpoint=https://bugcatcher.example.com --project=myapp
```

It reads from the stored offset, groups by `(minute, host, normalised path)`, POSTs the batch to
`/api/perf_buckets` and only then advances the cursor — a failed ship is retried by the next run
instead of being lost.

## Overhead

The request only measures and appends. Everything expensive — path normalisation, histograms,
grouping, JSON parsing, HTTP — happens in the cron job, and nothing in the hook touches the
network. The budget is **100 µs on the way in and 200 µs in shutdown**, and
`tests/Hook/CollectorHookBenchTest.php` fails the build if it is exceeded.

Measured on PHP 8.5, no Xdebug, one core of a laptop:

| Phase | Cost |
|---|---|
| CLI process, not recording (the `php bin/console` case) | **0.10 µs** |
| Entry path, request not sampled | **0.29 µs** |
| Entry path, request recorded | **0.83 µs** |
| Shutdown, building the 227-byte line | **1.4 µs** |
| Shutdown, appending it to tmpfs | **~2 µs** |

The hook measures from its own start, so it never appears in the numbers it reports — these are
what it costs you.

**Put the log on a fast filesystem.** The append is the only part whose cost is not ours: the same
write that takes 2 µs on tmpfs took ~350 µs on a journalling filesystem on the same machine, which
is three orders of magnitude and the only figure here big enough to notice. `tmpfs` is the right
default — the aggregator drains and truncates the file every minute, so nothing is meant to
survive a reboot anyway.

```ini
; /etc/php.d/bcperf.ini
auto_prepend_file = /path/to/vendor/php-bug-catcher/perf-collector/hook/collector.php
bcperf.log = /dev/shm/bcperf.jsonl
```

## License

MIT.
