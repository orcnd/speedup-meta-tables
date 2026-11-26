# How the benchmark was run

Results: [README.md](README.md). This page is for checking them or running them again.

## The site

| | |
|---|---|
| WordPress | 7.1.5 |
| WooCommerce | 11.3.1, orders in HPOS tables (12,000 older orders also in the posts table) |
| Database | MySQL 26.10, InnoDB buffer pool 384 MB, binary log off |
| PHP | 8.2, opcache on, the built-in PHP web server (`harness/router.php`) |
| Object cache | none |
| Machine | a MacBook running other work at the same time (see "Noise" below) |

Data:

| Object | Count |
|---|---|
| Products | 25,000 |
| Variations | 49,154 |
| Customers (users) | 48,522 |
| Orders in HPOS tables | 48,120 |
| Orders in the posts table | 12,000 |
| Coupons | 4,000 |
| Media files | 30,101 |
| Other posts (a "listing" type) | 50,000 |
| postmeta rows | about 4 million |
| usermeta rows | 1.4 million |

How it was made, in this order:

1. Products and variations: the dump from
   [woocommerce/test-products-database](https://github.com/woocommerce/test-products-database).
2. Customers and orders: [WC Smooth Generator](https://github.com/woocommerce/wc-smooth-generator) 1.3.0,
   `wp wc generate customers 60` and `wp wc generate orders 120 --status=completed`.
3. [dataset/scale.sql](dataset/scale.sql): 400 copies of those customers and orders, reviews, media, coupons and older
   orders; [dataset/scale2.sql](dataset/scale2.sql): the listing posts' fields.
4. [dataset/veri.sql](dataset/veri.sql): the fields that admin filters in the scenarios use (supplier, purchase cost,
   ERP code, EAN, shelf, tracking number, delivery date). Their names are assumptions; real stores name them differently.

The 50,000 listing posts were imported from a JSON file that is not in this repository, and some steps were run by
hand, so the data cannot be rebuilt with one command. The scripts above are the ones that were used.

## The 43 screens

[charts/scenarios.json](charts/scenarios.json) lists them; [harness/senaryo.py](harness/senaryo.py) has the request of
each. They are WooCommerce admin screens and jobs a store team uses: product lists filtered or sorted by a custom field,
REST product filters, coupon and customer lists, order search in both order stores, the low stock counter, the daily
scheduled sale job, and a bulk stock update. The last column of `scenarios.json` says where each one comes from:
"complaint" means people reported this screen as slow in public forums or issue trackers, "core code" means
WordPress or WooCommerce builds this query itself, "assumed" means a plausible store task without a public report.
Three control screens (K1 to K3) have no meta filter and should not change.

The filters come from [harness/mu-plugins/lab-panel.php](harness/mu-plugins/lab-panel.php), which adds them to the
admin lists the way a store's own code or a plugin would.

## How the plugin got its tables

The plugin was not told which queries to expect. It was installed, every screen was opened twice with the plugin
recording every request (`harness/learn.py`), and then `wp speedup-meta build` built the tables from what it had
recorded. On a live site the same happens with one request in ten and the hourly check; the lab only lowers the
number of sightings needed from 50 to 2 (`harness/mu-plugins/lab-smt.php`).

It built 8 tables in about two minutes: products, products with variations, customers, orders in both stores, coupons,
media and refunds.

## What was measured

| File | What | How |
|---|---|---|
| `results/verify.txt` | Same results | Every captured meta query the plugin answers is run both ways; full result set, `FOUND_ROWS()` total and page order are compared. Where page order differs only between rows with equal sort values, it counts as the same. |
| `results/replay.json` | Meta SQL time per screen | The meta queries each screen ran (`data/capture.json`, `data/plan.json`) replayed 15 times through WordPress' SQL and through the plugin, in random order per round; median wall time, including the plugin's own PHP time. Screens over 5 s with WordPress alone were run fewer times. |
| `results/pages.json` | Whole page time | Each screen loaded 7 times with and without the plugin, alternating, logged in as an administrator; median. The page's item ids and total are compared both ways. |
| `results/load.json` | Throughput | 4 clients load random screens for 45 seconds, 3 rounds per arm, arms alternating, PHP with 4 workers. |
| `results/writes.json` | Cost on writes | A meta update and the note after it; a read while 0 to 200 objects wait; refreshing 1, 12 and 40 products. |
| `results/race.txt` | Concurrency | 4 writers and 3 readers for 45 s; afterwards every row and counter of the touched products is compared with a fresh copy. |
| `results/edge.txt` | Edge cases from code review | The total of an empty answered page must not reach a later `FOUND_ROWS()`; a write starting with a comment is noted; a `BINARY` cast is left to WordPress; a second `wpdb` object is never rewritten; `repair` leaves no object unchecked. |
| `results/wptest.txt` | Correctness after real work | 40 rounds of product, order, customer and stock changes through WordPress, WooCommerce and direct SQL; after each, 21 queries with and without the plugin. |

"WordPress alone" and "with the plugin" ran on the same site, the same data and the same process; a request header
switched the plugin's reads on or off (`lab-smt.php`). Writes were always noted, so the tables stayed current for the
next run.

## Noise

The machine ran other work during the run: load average between 5 and 15 and 8 to 10 GB of swap in use
(`results/run.log` has the readings before and after). Absolute times are therefore higher than on a quiet server.
Both arms ran interleaved under the same conditions, so the comparison holds, but read single numbers loosely.

## Running it again

You need the lab site above, WP-CLI and MySQL root access without a password on the database socket (for CPU
counters). Then:

```sh
LAB_DIR=/path/to/lab benchmarks/harness/final.sh     # every check and measurement, about two hours
python3 benchmarks/charts/make.py                    # charts and charts/summary.json
```

`LAB_DIR` holds WordPress in `www/`, the PHP router, `cookie.txt` with an administrator's login cookie, `dl/wp-cli.phar`
and the `q` MySQL shortcut. The scripts in `harness/` are lab tools: short, dense and written for this one site.
