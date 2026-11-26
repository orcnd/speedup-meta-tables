---
name: speedup-meta-tables
description: Use when working on SpeedUp Meta Tables in any way - changing its code or readme, fixing a bug, adding a feature, testing it, drawing its banner, icon or screenshots, or releasing it to WordPress.org and GitHub.
---

# SpeedUp Meta Tables

A WordPress plugin that answers slow postmeta and usermeta queries from narrow tables it builds and drops on its own, without changing how WordPress or any other plugin writes data.

## Files next to this one

| File | Read it when |
|---|---|
| `working-rules.md` | Always (loaded through CLAUDE.md): approvals, commits, text, verification |
| `status.md` | Always (loaded through CLAUDE.md): where things stand, what comes next |
| `design.md` | Before touching the UI, the readme text or any image |
| `wordpress-org.md` | Before a release or anything that goes to WordPress.org |

## Layout

| Path | Holds |
|---|---|
| `speedup-meta-tables.php` | Header, autoloader, activation hooks |
| `includes/class-speedup-meta-tables.php` | Requirements, base tables, option names |
| `includes/class-speedup-meta-tables-interceptor.php` | The `query` filter: answers reads, notes writes |
| `includes/class-speedup-meta-tables-observer.php` | Records the shape of unanswered meta queries |
| `includes/class-speedup-meta-tables-planner.php` | Cron, build, drain, drop |
| `includes/class-speedup-meta-tables-engine.php` | Builds tables, compiles queries, refreshes objects |
| `includes/class-speedup-meta-tables-sql.php` | Parser for WordPress' meta SQL |
| `includes/class-speedup-meta-tables-raw.php` | Known hand-written WooCommerce statements |
| `benchmarks/` | Results (README.md), method (METHOD.md), harness, charts (`charts/make.py`) |
| `readme.txt` | Directory listing; must pass the readme validator |
| `phpcs.xml.dist` | WordPress Coding Standards + PHPCompatibilityWP; must be clean |
| `checklist.md` | One table per release, closed with evidence |
| `bin/build-zip.sh` | Builds `dist/speedup-meta-tables.zip` with only the files the plugin needs |
| `bin/svn-stage.sh` | Prepares the SVN folder, never commits |

The lab site the benchmark ran on lives outside this repository (`software-factory/lab`); `benchmarks/METHOD.md`
describes it.

## Decisions

- Hard rule from the owner: nothing may require changing WordPress, WooCommerce or another plugin, and no plugin's
  behavior may change. Reads may be intercepted through hooks; writes always go to the meta tables untouched.
- Horizontal tables were chosen over an object store (lab comparison, 2026-11-25).
- Table names are prefixed (`{prefix}speedup_meta_table_N`, registry `{prefix}speedup_meta_tables`) instead of the
  bare `performance_table_N` of the plan, so they cannot collide with another plugin.
- The plugin's own SQL runs on `$wpdb->dbh` directly: going through `wpdb` would overwrite `last_query`,
  `insert_id` and `num_rows` of the site's code. Background refreshes run on a second connection (a second `wpdb`) so
  they never join or end a transaction of the site.
- Nothing may run on the site's connection between a page query and its `FOUND_ROWS()` (a bug of this kind was found
  by `wptest.php`: reloading options there reset the total).
- The registry is an autoloaded option (about 70 KB on the lab data) so a request pays no query for it; the version
  row in the queue table tells a request when it changed.
- Writes are noted even when reads are paused or switched off by the filter, so the tables never go stale silently.
- While a build runs, reads go to WordPress and every write is noted; the build waits 60 s first so requests that
  loaded the old registry finish.
- Search terms and values are never stored in the observed shapes; only empty strings and range numbers stay.
- MariaDB and database drop-ins are refused (untested), not guessed at.
