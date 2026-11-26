# Checklist: 0.1.0 (first release, GitHub only)

Close an item only with evidence (URL, what was visible, which check was clean).

| # | Item | Page / command | Status | Evidence |
|---|---|---|---|---|
| 1 | Coding standards | `phpcs` with `phpcs.xml.dist` (WPCS 3.x, PHPCompatibilityWP 8.0-) | done | 0 errors, 0 warnings, 2026-11-26 |
| 2 | Plugin Check | `wp plugin check speedup-meta-tables --include-experimental` on the dist folder | done | "No errors found", 2026-11-26 |
| 3 | Same results | `benchmarks/results/verify.txt` | done | 154 queries, 0 differences |
| 4 | WordPress-level work | `benchmarks/results/wptest.txt` | done | 855 comparisons, 0 differences |
| 5 | Concurrency | `benchmarks/results/race.txt` | done | 0 stale rows or counters |
| 6 | Review edge cases | `benchmarks/results/edge.txt` | done | 6 of 6 passed |
| 7 | Benchmark | `benchmarks/harness/final.sh` | done | results/ and README.md, 2026-11-26 |
| 8 | Slop check on texts | `.claude/slop/slop_check.py` | done | clean |
| 9 | Activate, deactivate, uninstall | Lab site, WP-CLI (`uninstall --skip-delete`) | done | Deactivate dropped the built tables, reactivate came back in "Learning" with the 43 learned patterns, uninstall left no table or option. Not tried on a fresh Playground site. |
| 10 | Real site | Learn from real traffic | open | Not done |

# Checklist: 0.1.1 (WordPress.org submission)

| # | Item | Page / command | Status | Evidence |
|---|---|---|---|---|
| 1 | Registry out of `wp_options` SQL | `grep "options (option_name"` | done | none left; option written only through `update_option()` |
| 2 | Coding standards | `phpcs` | done | 0 errors, 0 warnings |
| 3 | Readme validator | https://wordpress.org/plugins/developers/readme-validator/ | done | no errors or warnings; notes: tag "meta query" rare (changed to "custom fields"), no Upgrade Notice, no donate link |
| 4 | Listing images | `.wordpress-org/` | done | banner 772x250 and 1544x500, icon 128 and 256, 2 screenshots from the lab admin page |
| 5 | Plugin Check | `wp plugin check speedup-meta-tables --include-experimental` on dist | done | "No errors found", 2026-11-26 |
| 6 | Lab checks on 0.1.1 (fresh install, learned and built from scratch) | verify, edge, wptest, race | done | 154 queries 0 differences; 6 of 6 edge cases; 855 comparisons 0 differences; 0 stale rows. The timing benchmark in `benchmarks/` was not repeated (0.1.0). |
| 7 | Upload | https://wordpress.org/plugins/developers/add/ | open | the owner uploads `dist/speedup-meta-tables.zip` |
