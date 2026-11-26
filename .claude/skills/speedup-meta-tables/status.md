# Status

Updated 2026-11-26.

- Version: 0.1.0, not released
- Repository: not created (planned: github.com/orcnd/speedup-meta-tables, public)
- WordPress.org: not submitted (GitHub releases only for now)

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
