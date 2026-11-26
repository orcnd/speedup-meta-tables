# SpeedUp Meta Tables

A WordPress plugin that answers slow `postmeta`, `usermeta` and WooCommerce order meta queries from narrow tables it
builds on its own, from the queries your site really runs. It never changes how anything writes data.

On a test store with 25,000 products, 48,000 customers and 60,000 orders, the meta SQL behind 39 WooCommerce admin
screens went from 99 ms to 0.66 ms at the median, and every page that took more than a second
dropped below one. Same results, checked row by row.

![Meta SQL time per admin screen, WordPress alone and with the plugin](benchmarks/charts/queries.svg)

**[Download the plugin](https://github.com/orcnd/speedup-meta-tables/releases/latest)** ·
[Benchmark results](benchmarks/README.md) · [How the benchmark was run](benchmarks/METHOD.md)

## The problem

WordPress keeps custom fields as rows: one row per object, field and value. A product list filtered by supplier and
sorted by cost asks MySQL to join the meta table once per field, over every row of every product. With a few million
meta rows, screens like "products without an image", "sort orders by total" or the low stock counter take seconds.

Indexes on the meta table help with some of these queries and not with others: a `NOT EXISTS`, a numeric sort over a
text column or a `LIKE '%term%'` still reads most of the table.

## What the plugin does

1. **It watches.** For one request in ten, every meta query that it cannot answer yet is reduced to its shape: which
   keys, which operators, which sort, which post type. Values and search terms are not kept.
2. **It builds.** Once an hour it looks at the shapes. For a shape it has seen often, on a type with at least 1,000
   objects, it builds `speedup_meta_table_N`: one row per object, one column per key and cast, and the indexes that
   shape needs. Counters, text search tables and a few flag columns are added where the queries call for them.
3. **It answers.** In the `query` filter of `wpdb` it recognizes the same shape again and rewrites the SQL to read the
   narrow table. Anything it does not fully understand goes to WordPress unchanged.
4. **It follows writes without touching them.** Every write still goes to the meta tables. The plugin notes which
   objects changed, in the same transaction, and copies them into its tables right before the next read that needs
   them. A write it cannot follow (for example an `UPDATE` with a `JOIN`) marks that object kind as unknown: reads of
   that kind go to WordPress until a background job has compared and repaired the copies.
5. **It cleans up.** Tables whose queries have not been seen for 30 days are dropped at the next rebuild.

![How reads, writes and the background job fit together](benchmarks/charts/how-it-works.svg)

It understands what `WP_Query`, `WP_User_Query` and `wc_get_orders()` generate, and these hand-written WooCommerce
statements: the low stock counter and report, the scheduled sale job, customer totals, the custom field list of the
product editor, and order search in both order stores.

## Results

| | WordPress alone | With the plugin |
|---|---|---|
| Meta SQL per screen, median of 39 screens | 99 ms | 0.66 ms |
| Meta SQL per screen, geometric mean | 163 ms | 1.0 ms |
| Whole page, median of 43 screens | 422 ms | 267 ms |
| Pages slower than 1 second | 13 | 0 |
| Pages served by 4 clients in 45 s | 87 | 277 |
| Same results as WordPress | | 154 queries and 855 WordPress-level comparisons, 0 differences |
| Extra disk | | 245 MB |

The fastest gains are the queries WordPress cannot index well: `NOT EXISTS`, sorts on numeric fields, counters.
Screens without a meta filter do not change. Every screen, every chart and the method are in
[benchmarks/](benchmarks/README.md).

## Install

1. Download `speedup-meta-tables.zip` from the [latest release](https://github.com/orcnd/speedup-meta-tables/releases/latest).
2. Plugins > Add New > Upload Plugin, then activate.
3. Use the site as usual. Tools > SpeedUp Meta Tables shows what it learned and built. "Build now" (or
   `wp speedup-meta build`) builds without waiting for the hourly check.

Requirements:

- WordPress 6.4 or newer, PHP 8.0 or newer.
- MySQL 8.0.13 or newer. On MariaDB the plugin stays idle; it has not been tested there.
- No database drop-in that spreads queries over several connections or another database (HyperDB, LudicrousDB, the
  SQLite integration). The plugin stays idle with one. Query Monitor's drop-in is fine.
- Single sites, or per-site activation on multisite. Network activation is refused.
- The database user may create, alter and drop tables.
- WP-Cron running, or a system cron calling it.

## What it costs

- **Disk.** The narrow tables take room: 245 MB on the test store, next to 778 MB of meta tables.
- **Writes.** After each write to a watched object, one row is noted (about 0.04 ms). The next read that needs
  that object copies it first: about 5 ms for one product, 16 ms for twelve.
- **Writes it cannot follow.** An `UPDATE ... JOIN`, a `LOAD DATA` or similar marks the whole object kind as
  unknown. Reads of that kind go to WordPress until the repair job has run: about 50 seconds for all posts on the test
  store.
- **Building.** Building all tables took about two minutes on the test store. During a build reads go to WordPress.
- **Every request** that reads meta loads the table definitions once, an autoloaded option of about 70 KB: roughly
  0.3 ms. It is part of the times above.
- **Writes outside WordPress.** Programs that write to MySQL directly are not seen. Run `wp speedup-meta repair` after
  them: it compared all 267,000 copied objects of the test store in about two minutes.
- **Writes inside a transaction.** When the site's own code opens a transaction and updates rows by a condition other
  than their id, the plugin cannot look up the rows without changing what that transaction sees. It marks the object
  kind as unknown instead, so reads of that kind go to WordPress until the repair job has run.
- **A second connection.** Copying changed objects runs on a connection of its own, opened by the first read that
  needs it. On a site that sends reads to a replica, that connection must reach the primary.
- **Different SQL in your tools.** Query Monitor and `$wpdb->last_query` show the plugin's SQL. Rows with the same sort
  value can come back in a different order than WordPress would return them, as they can between two MySQL versions.
- **No retry.** If the rewritten SQL fails (say, someone dropped a plugin table by hand), WordPress gets the error;
  the plugin does not run the original query again.
- **Learning takes traffic.** A small site, or a type with fewer than 1,000 objects, gets no tables at all.

## When not to use it

- Your meta queries are already fast. Measure first: Query Monitor shows the slow ones.
- You are on MariaDB, use a database drop-in or a host that does not allow `CREATE TABLE`.
- Other programs write to the meta tables directly and often, without going through WordPress.

## Not tested yet

A live site, multisite with several active sites, a persistent object cache (Redis, Memcached), other WordPress and WooCommerce versions,
other plugins that filter `query`, and write rates above the 45-second race test. Reports from real sites are welcome
in the issues.

## Controls

| | |
|---|---|
| `wp speedup-meta status` | State, tables, the current plan |
| `wp speedup-meta build [--wait=60]` | Build the tables of the current plan now |
| `wp speedup-meta drain` | Copy objects waiting in the queue, repair unknown kinds |
| `wp speedup-meta repair` | Compare every copy with the meta tables, after writes outside WordPress |
| `wp speedup-meta drop` | Drop every table; the learned shapes stay |
| `speedup_meta_tables_enabled` | Filter: `false` sends this request's reads to WordPress (writes are still noted) |
| `speedup_meta_tables_sample_rate` | Filter: record one request in N (default 10) |
| `speedup_meta_tables_min_hits` | Filter: sightings a shape needs before it gets a table (default 50) |
| `speedup_meta_tables_min_objects` | Filter: objects a type needs (default 1,000) |
| `speedup_meta_tables_keep_days` | Filter: days a shape stays after it was last seen (default 30) |
| `speedup_meta_tables_build_wait` | Filter: seconds a build waits after stopping reads (default 60) |

Set the filters from a plugin or a mu-plugin; the theme loads too late for some of them.

## Development

The code follows the WordPress Coding Standards (`phpcs.xml.dist`, clean with WPCS 3 and PHPCompatibilityWP), and
WordPress.org's Plugin Check finds no errors or warnings in the release zip (all categories, experimental checks included).
`bin/build-zip.sh` builds the release zip. The benchmark harness and its lab are described in
[benchmarks/METHOD.md](benchmarks/METHOD.md).

## License

GPL-2.0-or-later. See [LICENSE](LICENSE).
