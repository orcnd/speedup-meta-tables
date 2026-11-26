=== SpeedUp Meta Tables ===
Contributors: orcnd
Tags: performance, postmeta, woocommerce, meta query, database
Requires at least: 6.4
Tested up to: 7.1
Requires PHP: 8.0
Stable tag: 0.1.0
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Answers slow postmeta, usermeta and WooCommerce order meta queries from narrow tables it builds from the queries your site really runs.

== Description ==

Filtering or sorting by a custom field makes MySQL join the meta table once per field, often over millions of rows. On a large WooCommerce store an admin list filtered by two or three fields can take seconds.

SpeedUp Meta Tables watches the meta queries your site runs. When a query pattern keeps coming back on a type with at least 1,000 objects, it builds a narrow table for it: one row per object, one column per field the query uses, with the indexes that pattern needs. From then on it answers that query from the narrow table, with the same result.

What it does not do:

* It never changes how WordPress, WooCommerce or another plugin writes. Every write still goes to the meta tables. The plugin notes which objects changed and copies them into its tables right before the next read.
* It never changes another plugin's code or behavior. It works through the `query` filter of `wpdb` and a few actions.
* It never sends data anywhere.

When it is not sure, for example SQL it cannot read, a write it could not follow, or a table being rebuilt, the query goes to WordPress unchanged.

On the benchmark site (25,000 products, 48,000 customers, 60,000 orders) the meta SQL of 39 admin screens went from 99 ms to 0.66 ms at the median, and all 13 pages that took over a second dropped below one. The full results, the method and the scripts are in the GitHub repository.

= Requirements =

* MySQL 8.0.13 or newer. MariaDB is not supported yet.
* No database drop-in that spreads queries over several connections or another database (HyperDB, LudicrousDB, the SQLite integration). Query Monitor's drop-in is fine.
* On multisite, activate it per site; network activation is refused.
* The database user must be allowed to create, alter and drop tables.
* WP-Cron, or a system cron that calls it, for the hourly check.

== Installation ==

1. Upload the plugin and activate it.
2. Use the site as usual. The plugin records the meta queries it sees (one request in ten).
3. Within a few hours, Tools > SpeedUp Meta Tables lists the tables it built. You can also press "Build now", or run `wp speedup-meta build`.

== Frequently Asked Questions ==

= What happens to my data when I deactivate or delete the plugin? =

Nothing. The plugin's tables only hold copies. Deactivating drops the copies (they would go stale without the plugin watching writes); deleting also removes the plugin's options.

= Does it slow down writes? =

Slightly. After a write to a watched object, one small row is noted (about 0.03 ms). The next read that needs that object copies it first, which takes a few milliseconds.

= Another program writes to the database directly. =

The plugin only sees writes that go through WordPress' `wpdb`. After direct writes, run `wp speedup-meta repair`: it compares every copied object with the meta tables and fixes the differences (about two minutes for 267,000 objects on the test store). While it runs, reads go to WordPress. Until then, reads may return what the plugin copied last.

= Query Monitor shows different SQL. =

Yes. `$wpdb->last_query` and Query Monitor show the SQL the plugin ran instead. When several rows have the same sort value, their order can differ from WordPress' order, as it can between two MySQL versions.

= Can I turn it off for one request? =

Add a filter: `add_filter( 'speedup_meta_tables_enabled', '__return_false' );` from a plugin or a mu-plugin. Writes are still noted, so the tables stay correct.

== Changelog ==

= 0.1.0 =
* First release.
