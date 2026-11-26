# Pages whose item list differed

`harness/e2e.py` takes the item ids and the "N items" total from each loaded page and compares them between the two
runs. On 8 of 43 screens the ids on the page differed while the total was the same:

| Screen | Sorted by | Total both ways |
|---|---|---|
| P2 Three suppliers, in stock | post date | 1,050 |
| P4 Products without an EAN | post date | 4,012 |
| P5 Sort by purchase cost | purchase cost | 22,552 |
| P6 Sort by total sales | total sales | 24,991 |
| P10 On sale and in stock | post date | 5,748 |
| P19 Low stock report | stock quantity | 664 |
| O3 Orders without a tracking number | order date | 122 |
| O4 Delivery date this week | order date | 365 |

All 8 sort on a value that many items share: the product dump gives thousands of products the same post date, many
products have the same cost or sales count, and the copied orders share dates. MySQL returns rows with equal sort
values in whatever order its plan reads them, and WordPress does not add a tie-breaker, so the first 20 of them can
differ between two query plans, or between two MySQL versions, without either being wrong.

The SQL behind these screens is checked row by row in `verify.txt`: full result set, total, and the sort values of
the page in order. It finds no difference there, which is what you would expect when only ties are ordered
differently.
