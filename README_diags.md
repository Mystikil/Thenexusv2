# Nexus Startup Diagnostics

This fork introduces a set of diagnostic tools to help track down startup stalls that originate from the reputation and trading economy systems.

## CLI flags

* `--trace-startup`
  * Raises the log level to **DEBUG**.
  * Enables high resolution checkpoint logging via `StartupProbe`.
  * Starts the watchdog with a 5s stall threshold (10s by default).
  * Enables SQL tracing for the reputation/economy tables.
  * Turns on Lua load/ checkpoint tracing (via `data/lib/startup_trace.lua`).

When the flag is not provided the watchdog still runs (10s threshold) but SQL/Lua tracing remain quiet.

## Configuration feature flags

Add the following keys to your `config.lua` (default is `true`):

```lua
enableReputationSystem = true
enableEconomySystem = true
```

Setting either flag to `false` skips loading the corresponding Lua subsystems and clearly logs that the feature has been disabled. Disabling both systems is a useful sanity check when investigating new stalls.

## Logs and checkpoints

* C++ phase markers are emitted to `logs/server.log` with the prefix `PHASE`.
* When `--trace-startup` is active the watchdog writes `WATCHDOG` warnings when no progress is observed.
* SQL tracing uses the prefix `SQL[rep_eco]` and records duration, row counts, and a sanitized query preview.
* Lua load/checkpoint messages (`[LUA-LOAD]` / `[LUA-CKPT]`) go to the console and `logs/server.log` while tracing is enabled.

The primary log file lives in `logs/server.log`; the logger flushes immediately after each diagnostic message so the tail is always up to date.

## Quick sanity test

`data/scripts/_rep_eco_sanity.lua` runs during script loading and executes a trivial `SELECT COUNT(*)` against each reputation/economy table. Missing tables produce `[REP/ECO] table missing: …` followed by a hard failure so you can diagnose schema drift before the server hangs.

