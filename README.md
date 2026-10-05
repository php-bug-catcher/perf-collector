# Bug Catcher performance collector

Per-request performance monitoring for any PHP application — wallclock, user and system CPU, peak
memory and HTTP status — with **no PHP extension, no daemon and no Composer dependencies** beyond
`ext-curl`.

It collects the way [phptop](https://github.com/bearstech/phptop) does: a hook loaded at the very
start of the request writes a JSON line per request to a local file, and a cron job rolls those
lines up into per-minute aggregates and ships them to a
[Bug Catcher](https://github.com/php-bug-catcher/bug-catcher) server. Nothing leaves the request.

```
collector.php hook ──▶ /var/log/bcperf.jsonl ──▶ bc-perf-aggregate ──HTTP──▶ Bug Catcher
  (one line/request)       (local only)            (cron, every minute)
```

## Install

```
composer require php-bug-catcher/perf-collector
```

### 1. The hook

There are two ways to load `hook/collector.php`, and they measure the same thing: the duration is
taken from `$_SERVER['REQUEST_TIME_FLOAT']`, so it covers the whole request either way and it does
not matter at which point the hook itself got its turn.

#### A. `auto_prepend_file` — when you can edit `php.ini`

```ini
auto_prepend_file = /path/to/vendor/php-bug-catcher/perf-collector/hook/collector.php
bcperf.log = /dev/shm/bcperf.jsonl
```

This is the better of the two where it is available: it covers **every** PHP entry point on the
machine, including the legacy scripts nobody is going to edit, and it survives a syntax error in
the application's front controller — the request still leaves a line, with its 500.

#### B. One `require` in your entry point — when you cannot

Shared hosting usually means no `php.ini` and no process environment either, so the hook takes its
configuration from a global in that case. Put both in one file at the root of the project:

```php
<?php
// bcperf.php
$GLOBALS['_bcperf_config'] = [
	'log'         => __DIR__ . '/var/bcperf.jsonl',
	'sample_rate' => 1,
	'cli'         => true,
];

require __DIR__ . '/vendor/php-bug-catcher/perf-collector/hook/collector.php';
```

and require that file on the first line of every entry point:

| Application | Where |
|---|---|
| Symfony | `public/index.php` and `bin/console`, straight after `<?php`, before `vendor/autoload_runtime.php` |
| Laravel | `public/index.php` and `artisan` |
| WordPress | `wp-config.php` |
| Anything else | the front controller, and any cron script that does not go through it |

```php
<?php
require __DIR__ . '/../bcperf.php';
```

Set `cli` only if you want console commands and cron jobs recorded — see
[what a command-line run is called](#what-a-command-line-run-is-called). The same `bcperf.php` can
later be pointed at by `auto_prepend_file` unchanged, if access to `php.ini` ever turns up.

**What this mode does not cover**, honestly:

- only the entry points you actually edited. A request that reaches some other `.php` file directly
  is not measured.
- a syntax error in the file you edited means the `require` never runs and the request leaves no
  line at all. Under `auto_prepend_file` it would.
- wherever `log` points has to be writable by both the web user and the CLI user, and must not sit
  under the document root. `/dev/shm` is usually not available on shared hosting, so a `var/`
  directory outside the web root is the realistic choice — it is slower, which
  [costs you](#overhead).

#### Settings

Three settings, three channels. The environment wins, then `php.ini`, then the inline array — so an
operator who does have env or `php.ini` can override what the application ships without touching
the application's code.

| Environment | `php.ini` | `$GLOBALS['_bcperf_config']` | Default | Meaning |
|---|---|---|---|---|
| `BCPERF_LOG` | `bcperf.log` | `log` | — (hook does nothing) | Log path. Accepts a `strftime` pattern for daily rotation, e.g. `/var/log/bcperf-%Y%m%d.jsonl`. |
| `BCPERF_SAMPLE_RATE` | `bcperf.sample_rate` | `sample_rate` | `1` | `1` records everything, `10` every tenth request. The rate is written into the line as `w`, so the aggregator scales the counts back up. |
| `BCPERF_CLI` | `bcperf.cli` | `cli` | `0` | Record CLI processes too. Off by default — cron noise. |

In the inline array the keys are the lower-case `php.ini` names without the prefix, and `true`,
`10` and `'10'` all mean what you would expect. Loading the hook twice records the request once,
so a `require` on a machine that already has an `auto_prepend_file` is safe.

### Is it working?

The one failure mode worth knowing about is a hook that is simply inert — a log path that is not
writable, or a `require` that never ran. It says nothing about it, on purpose: a monitoring hook
must not write to the monitored application's output or error log. So check it yourself:

```bash
curl -s -o /dev/null http://localhost/          # or one page load in a browser
tail -n1 var/bcperf.jsonl                       # a JSON line must have appeared
bc-perf-aggregate --log=var/bcperf.jsonl --endpoint=https://bugcatcher.example.com \
	--project=myapp --dry-run                   # what would be shipped
```

### What a command-line run is called

A request is named by its URI. A command has none, so it is named `/<script>/<job>`: the basename
of the script, plus the first argument that is not an option.

```
php C:\inetpub\wwwroot\cron\execute.php Cron\Money\SyncAllPayments -test 0
  ->  /execute.php/Cron/Money/SyncAllPayments
```

That argument is the whole point. `execute.php` is often the single entry point of a hundred cron
tasks, and without it they all aggregate into one path — a dashboard that cannot tell
`SyncAllPayments` from `ParseEmail` says nothing about either. The directory, the drive letter and
the slashes are dropped because the same script gets written `C:\app\execute.php` in one scheduled
task and `c:/app/execute.php` in the next, and as paths those are two rows for one job. Only the
*first* non-option argument is taken: `-isps 1,6` says how a task was asked to run, not which task
it was, and folding every argument in would mean a path per invocation.

A CLI worker that sets `REQUEST_URI` itself is believed over its own command line.

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
instead of being lost. Once the batch is accepted the log is emptied, so the machine keeps
nothing; anything the hook appended while the batch was in flight stays for the next run.

`bc-perf-aggregate --help` lists every option. The ones worth knowing about:

| Option | Default | Why you would set it |
|---|---|---|
| `--state-dir=PATH` | system temp directory | Where read cursors and the lock file live. |
| `--rules=PATH` | — | A JSON list of extra normalisation rules, applied before the shipped ones. Add `--no-default-rules` to use only yours. |
| `--max-bytes=N` | 16777216 | Most bytes read in one run. A backlog from a server outage drains over several runs rather than one request that can never succeed. |
| `--dry-run` | — | Read, group and report. Ship nothing, move nothing, delete nothing. |

Exit codes: `0` done — including when another run already holds the lock; `1` the batch did not
reach the server, so cron will hear about it; `2` the command line or the rules file is wrong and
retrying will not help.

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
| Entry path, request recorded, configured from the environment | **0.9 µs** |
| Entry path, request recorded, configured inline | **1.1 µs** |
| Shutdown, building the 227-byte line | **1.4 µs** |
| Shutdown, appending it to tmpfs | **~2 µs** |

The inline channel is the dearer of the two by the width of two lookups that miss — the environment
and `php.ini` are consulted before the array is.

The duration is measured from `REQUEST_TIME_FLOAT`, which is before PHP ran any of this, so unlike
in earlier versions the hook's own entry cost does fall inside the number it reports. At a
microsecond it is three orders of magnitude under the sampling noise of anything you are measuring.
What it does mean is that `d` now covers PHP's startup and the application's bootstrap as well, so
an installation upgrading from a version that measured from the hook will see its durations step up
once. The server's regression baseline is a rolling one and relearns them by itself.

CPU (`u`, `s`) is still measured from the hook onwards, because there is no `getrusage()` of the
past to subtract from. PHP's own startup therefore reads as wallclock the request spent waiting.
That is true of both installation modes equally.

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
