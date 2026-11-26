# Status

Updated 2026-11-26.

- Version: 0.1.0, released on GitHub 2026-11-26 (v0.1.0, zip attached)
- Repository: github.com/orcnd/speedup-meta-tables (public), first commit 01a415d
- WordPress.org: 0.1.1 prepared for submission (listing images in `.wordpress-org/`); the owner uploads the zip

## Done

- Lab engine (round 8) ported into the plugin, with automatic observe, build, rebuild and drop.
- PHPCS (WordPress, PHPCompatibilityWP) clean.
- Lab checks on the plugin: 154 captured queries 0 differences; WordPress-level test 855 comparisons 0 differences;
  race test 0 stale rows. Benchmark in `benchmarks/`.

## Open

- Not tested: a live site, multisite, persistent object cache, MariaDB, other plugins filtering `query`.
- `repair` command added after the WordPress-level test; tested only on the lab.
- No DB version routine yet: base tables are created on activation and before each build.

## Next

- Try it on a real store with real traffic and see what it learns.
