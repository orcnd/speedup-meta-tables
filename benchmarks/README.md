# Benchmark: SpeedUp Meta Tables against WordPress alone

A WooCommerce store with 25,000 products, 49,154 variations, 48,522 customers and 60,120 orders, about 4 million
postmeta rows. 43 admin screens and jobs, each run with and without the plugin on the same site and data.
How it was set up and measured: [METHOD.md](METHOD.md). Raw numbers: [results/](results/).

## Summary

| | WordPress alone | With the plugin | |
|---|---|---|---|
| Meta SQL per screen, median (39 screens) | 99 ms | 0.66 ms | 149x |
| Meta SQL per screen, geometric mean | 163 ms | 1.0 ms | 160x |
| Meta SQL of all screens together | 58.1 s | 198 ms | |
| Whole page, median (43 screens) | 422 ms | 267 ms | |
| Pages slower than 1 s | 13 | 0 | |
| Pages served by 4 clients in 45 s | 87 | 277 | |
| Median / 95th percentile page under that load | 1.2 s / 6.7 s | 542 ms / 1.4 s | |

Per screen the gain ranged from 2.4x to 94,709x, median 155x.
27 of 43 pages got at least 20% faster as a whole; 0 got more than 10% slower.

## Correctness

| Check | Result |
|---|---|
| Every captured meta query the plugin answers, run both ways: full result set, total, page order | 154 queries, 0 differences |
| 40 rounds of product, order, customer and stock changes through WordPress, WooCommerce and direct SQL, then the same queries both ways | 855 comparisons, 0 differences (792 answered by the plugin) |
| 4 writers and 3 readers at once for 45 s, then every copied row and counter compared with a fresh copy | 0 stale rows or counters (10,419 write rounds, 7,869 reads) |
| Edge cases from code review: a kept total reaching another query, a write starting with a comment, a BINARY cast, a second wpdb object, repair | 6 of 6 passed |
| The page itself: item ids and total of each screen, both ways | same on 35 of 43 screens (see the note under the page table) |

## Meta SQL per screen

The meta queries each screen ran, replayed 15 times; median wall time including the plugin's own PHP time.

![Meta SQL time per screen](charts/queries.svg)

| Screen | | WordPress alone | With the plugin | Faster |
|---|---|---|---|---|
| P7 | Sort by shelf, products without a shelf included | 33.2 s | 0.35 ms | 94,709x |
| P15 | ERP sync, 100 products one by one | 7.0 s | 4.4 ms | 1,590x |
| P4 | Products without an EAN | 3.3 s | 8.5 ms | 384x |
| P3 | Products without an image | 3.1 s | 3.4 ms | 909x |
| P20 | Daily scheduled sale job | 1.9 s | 1.9 ms | 995x |
| U6 | Membership status counters | 1.5 s | 1.2 ms | 1,295x |
| P9 | Sale ends within 30 days | 1.2 s | 0.85 ms | 1,375x |
| U4 | Customers without a company name | 1.1 s | 0.59 ms | 1,802x |
| P19 | Low stock report | 885 ms | 1.2 ms | 715x |
| P10 | On sale and in stock | 862 ms | 0.94 ms | 920x |
| P16 | Export, 500 products | 782 ms | 0.94 ms | 834x |
| P17 | Open the product edit screen | 771 ms | 1.5 ms | 510x |
| P18 | Low stock counter | 443 ms | 0.66 ms | 672x |
| O1 | Order search | 309 ms | 126 ms | 2.4x |
| P14 | REST: price range | 222 ms | 2.9 ms | 76x |
| P6 | Sort by total sales | 166 ms | 0.48 ms | 348x |
| M1 | Search the media library | 144 ms | 1.5 ms | 96x |
| P5 | Sort by purchase cost | 137 ms | 0.45 ms | 305x |
| P12 | REST: out of stock | 102 ms | 0.55 ms | 184x |
| U2 | Search a customer on the order screen | 99 ms | 24 ms | 4.1x |
| P8 | Reorder list: supplier and stock range | 88 ms | 0.57 ms | 155x |
| L2 | Order search | 82 ms | 0.96 ms | 85x |
| P13 | REST: list of SKUs | 81 ms | 0.43 ms | 186x |
| U3 | Country + at least 5 orders | 78 ms | 0.52 ms | 150x |
| O2 | Find by part of a tracking number | 76 ms | 0.95 ms | 80x |
| P11 | Search by part of the ERP code | 76 ms | 1.9 ms | 41x |
| P2 | Three suppliers, in stock | 74 ms | 0.65 ms | 113x |
| L5 | Total spent by 5 customers | 73 ms | 0.46 ms | 158x |
| U5 | Sort by last activity | 58 ms | 0.37 ms | 157x |
| U1 | Filter users by role | 53 ms | 5.3 ms | 9.9x |
| O4 | Delivery date this week | 36 ms | 0.79 ms | 45x |
| L4 | Filter by where the order came from | 32 ms | 0.64 ms | 51x |
| L3 | Sort by order total | 31 ms | 0.33 ms | 95x |
| P1 | Filter by supplier | 22 ms | 0.46 ms | 47x |
| L1 | Orders of one customer | 12 ms | 0.30 ms | 41x |
| L6 | Find orders by email | 12 ms | 0.24 ms | 48x |
| C2 | Used coupons that expire this month | 5.9 ms | 0.35 ms | 17x |
| C1 | Filter coupons by type | 5.9 ms | 0.33 ms | 18x |
| O3 | Orders without a tracking number | 2.6 ms | 0.25 ms | 10x |

## Whole pages

Each screen loaded 7 times both ways, logged in as an administrator; median time of the whole request. PHP, the
admin screen itself and queries without meta are included, so the gain is smaller than in the SQL alone.

![Whole page time per screen](charts/pages.svg)

| Screen | | WordPress alone | With the plugin | Same items |
|---|---|---|---|---|
| P7 | Sort by shelf, products without a shelf included | 32.5 s | 378 ms | yes |
| P15 | ERP sync, 100 products one by one | 6.8 s | 161 ms | yes |
| P20 | Daily scheduled sale job | 2.3 s | 178 ms | yes |
| P4 | Products without an EAN | 2.1 s | 338 ms | no, see below |
| U6 | Membership status counters | 2.0 s | 188 ms | yes |
| P3 | Products without an image | 2.0 s | 345 ms | yes |
| P9 | Sale ends within 30 days | 1.5 s | 351 ms | yes |
| U4 | Customers without a company name | 1.4 s | 234 ms | yes |
| P10 | On sale and in stock | 1.4 s | 341 ms | no, see below |
| P17 | Open the product edit screen | 1.1 s | 267 ms | yes |
| P16 | Export, 500 products | 1.1 s | 168 ms | yes |
| P19 | Low stock report | 1.0 s | 76 ms | no, see below |
| U2 | Search a customer on the order screen | 1.0 s | 991 ms | yes |
| K3 | Search products by name and SKU | 630 ms | 639 ms | yes |
| O1 | Order search | 557 ms | 354 ms | yes |
| P6 | Sort by total sales | 496 ms | 326 ms | no, see below |
| P18 | Low stock counter | 469 ms | 49 ms | yes |
| M1 | Search the media library | 455 ms | 282 ms | yes |
| P5 | Sort by purchase cost | 443 ms | 318 ms | no, see below |
| P8 | Reorder list: supplier and stock range | 442 ms | 392 ms | yes |
| P11 | Search by part of the ERP code | 431 ms | 375 ms | yes |
| O4 | Delivery date this week | 422 ms | 312 ms | no, see below |
| P2 | Three suppliers, in stock | 401 ms | 320 ms | no, see below |
| W1 | Update stock and cost of 40 products | 401 ms | 413 ms | yes |
| L2 | Order search | 394 ms | 334 ms | yes |
| O2 | Find by part of a tracking number | 363 ms | 275 ms | yes |
| K1 | Product list (no meta filter) | 350 ms | 341 ms | yes |
| P1 | Filter by supplier | 349 ms | 328 ms | yes |
| L1 | Orders of one customer | 300 ms | 274 ms | yes |
| L4 | Filter by where the order came from | 263 ms | 234 ms | yes |
| O3 | Orders without a tracking number | 259 ms | 263 ms | no, see below |
| U3 | Country + at least 5 orders | 259 ms | 175 ms | yes |
| L3 | Sort by order total | 258 ms | 233 ms | yes |
| U1 | Filter users by role | 258 ms | 202 ms | yes |
| P14 | REST: price range | 252 ms | 90 ms | yes |
| U5 | Sort by last activity | 244 ms | 188 ms | yes |
| C2 | Used coupons that expire this month | 228 ms | 224 ms | yes |
| L5 | Total spent by 5 customers | 219 ms | 135 ms | yes |
| C1 | Filter coupons by type | 217 ms | 210 ms | yes |
| K2 | Order list (HPOS) | 207 ms | 202 ms | yes |
| P12 | REST: out of stock | 177 ms | 83 ms | yes |
| L6 | Find orders by email | 142 ms | 137 ms | yes |
| P13 | REST: list of SKUs | 121 ms | 42 ms | yes |

"Same items" compares the item ids and the total on the page. Screens marked "no" differ for reasons outside the
plugin; each is explained in [results/pages-notes.md](results/pages-notes.md).

## Under load

4 clients loading random screens for 45 seconds, 3 rounds each way, PHP with 4 workers.

![Load test](charts/load.svg)

| | WordPress alone | With the plugin |
|---|---|---|
| Pages served (median of 3 rounds) | 87 | 277 |
| Median page time | 1.2 s | 542 ms |
| 95th percentile | 6.7 s | 1.4 s |
| Errors | 0 | 0 |

## What it costs

| | |
|---|---|
| A meta update through WordPress | 0.98 ms |
| The note the plugin adds after it | 0.044 ms |
| Copying 1 changed product before the next read (first time / again) | 8.597 ms / 5.303 ms |
| Copying 12 changed products before the next read (first time / again) | 40.105 ms / 16.336 ms |
| Copying 40 changed products before the next read (first time / again) | 63.513 ms / 38.52 ms |
| A read (screen P1) while 0 objects wait to be copied | 0.255 ms |
| A read (screen P1) while 1 objects wait to be copied | 15.92 ms |
| A read (screen P1) while 10 objects wait to be copied | 23.217 ms |
| A read (screen P1) while 50 objects wait to be copied | 44.654 ms |
| A read (screen P1) while 200 objects wait to be copied | 25.331 ms, sent to WordPress |
| Disk used by the plugin's tables | 245 MB (the meta tables themselves: 778 MB) |
| Building all tables | about 2 minutes (8 tables) |
| Repairing all posts after a write the plugin could not follow | about 50 s; reads of posts go to WordPress meanwhile |
| Loading the table definitions, once per request | about 0.3 ms (a 70 KB autoloaded option); included in every plugin time above |

## Limits of this benchmark

- One synthetic store on one laptop that was busy with other work during the run (see METHOD.md, "Noise").
- The custom fields the screens filter on were added for the test; their names and value spread are assumptions.
- The plugin learned from these same screens. On a real site it learns from real traffic, which is less tidy.
- MySQL only, no object cache, no other plugins that filter `query`, one WordPress and WooCommerce version.
