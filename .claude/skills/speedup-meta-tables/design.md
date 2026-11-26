# Design of SpeedUp Meta Tables

## In the product

One screen under Tools, built from core admin markup only: `wrap`, `widefat striped` tables, `button` /
`button-primary`, `notice` boxes. No own CSS or JavaScript, no colors of its own, no dashboard widget, no admin
notices outside its own page. Text says what the plugin does and what the owner can do; no marketing words.

## GitHub presentation

README.md opens with one sentence, the headline numbers and the query chart. Charts are SVG made by
`benchmarks/charts/make.py`, with their own background and colors for light and dark (orange = WordPress alone,
blue = with the plugin; slots 2 and 1 of a color-vision-checked palette). Every number in a chart is also in a table.
Limits and costs get as much room as the gains.

## Listing art

None yet. The plugin is distributed through GitHub releases, not WordPress.org.
