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

Source: `art/art.html` next to this file (open it over http; `?2` renders the 1544x500 banner, `?half` the 128 icon).
Concept: the meta table is a tall pile of thin key/value rows; the plugin turns the fields a query needs into one
short, wide table. Left of the banner the name and one sentence, right the grey pile, an arrow and the blue table.
Icon: the blue table alone. Colors: background #0f1b2d, blue #338ced (the plugin's color in the charts), greys for
meta rows. No lightning bolts, rockets or speedometers. Screenshots come from the lab site with the plugin's real
tables and patterns.
