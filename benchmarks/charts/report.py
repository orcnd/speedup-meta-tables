"""Writes benchmarks/README.md from benchmarks/results/ and charts/summary.json (run make.py first).

python3 benchmarks/charts/report.py
"""
import json
import os
import re

HERE = os.path.dirname(os.path.abspath(__file__))
B = os.path.join(HERE, '..')
R = os.path.join(B, 'results')
S = json.load(open(os.path.join(HERE, 'summary.json')))
NAMES = json.load(open(os.path.join(HERE, 'scenarios.json')))
ARM = 'smt'


def ms(v):
    if v >= 1000:
        return f'{v / 1000:,.1f} s'
    if v >= 10:
        return f'{v:,.0f} ms'
    if v >= 1:
        return f'{v:.1f} ms'
    return f'{v:.2f} ms'


def x(a, b):
    v = a / b if b else 0
    return f'{v:,.0f}x' if v >= 10 else f'{v:.1f}x'


def read(name):
    p = os.path.join(R, name)
    return open(p).read() if os.path.exists(p) else ''


q = S['queries']
p = S['pages']
ld = S['load']
wr = json.load(open(os.path.join(R, 'writes.json')))
verify = read('verify.txt')
vm = re.search(r'(\d+) queries answered, (\d+) differences', verify)
wpt = re.search(r'(\d+) comparisons, (\d+) differences, (\d+) answered', read('wptest.txt'))
race = re.search(r'(\d+) s: (\d+) write rounds, (\d+) reads \((\d+) sent to meta table\), write median ([\d.]+) ms p99 ([\d.]+) ms.*stale rows or counters (\d+)', read('race.txt'))
disk = {}
for line in read('disk.txt').splitlines()[1:]:
    t, mb = line.split('\t')
    disk[t] = float(mb)
plugin_mb = sum(v for k, v in disk.items() if 'speedup_meta_' in k)
meta_mb = sum(v for k, v in disk.items() if k.endswith(('postmeta', 'usermeta', 'orders_meta')))

o = []
w = o.append
w('# Benchmark: SpeedUp Meta Tables against WordPress alone')
w('')
w('A WooCommerce store with 25,000 products, 49,154 variations, 48,522 customers and 60,120 orders, about 4 million')
w('postmeta rows. 43 admin screens and jobs, each run with and without the plugin on the same site and data.')
w('How it was set up and measured: [METHOD.md](METHOD.md). Raw numbers: [results/](results/).')
w('')
w('## Summary')
w('')
w('| | WordPress alone | With the plugin | |')
w('|---|---|---|---|')
w(f"| Meta SQL per screen, median ({q['screens']} screens) | {ms(q['stock_median_ms'])} | {ms(q['plugin_median_ms'])} | {x(q['stock_median_ms'], q['plugin_median_ms'])} |")
w(f"| Meta SQL per screen, geometric mean | {ms(q['stock_geomean_ms'])} | {ms(q['plugin_geomean_ms'])} | {x(q['stock_geomean_ms'], q['plugin_geomean_ms'])} |")
w(f"| Meta SQL of all screens together | {ms(q['stock_total_ms'])} | {ms(q['plugin_total_ms'])} | |")
w(f"| Whole page, median ({p['screens']} screens) | {ms(p['stock_median_ms'])} | {ms(p['plugin_median_ms'])} | |")
w(f"| Pages slower than 1 s | {p['stock_over_1s']} | {p['plugin_over_1s']} | |")
w(f"| Pages served by 4 clients in 45 s | {ld['stock']['n']:.0f} | {ld[ARM]['n']:.0f} | |")
w(f"| Median / 95th percentile page under that load | {ms(ld['stock']['med'])} / {ms(ld['stock']['p95'])} | {ms(ld[ARM]['med'])} / {ms(ld[ARM]['p95'])} | |")
w('')
w(f"Per screen the gain ranged from {x(q['speedup_min'], 1)} to {x(q['speedup_max'], 1)}, median {x(q['speedup_median'], 1)}.")
w(f"{p['faster_20']} of {p['screens']} pages got at least 20% faster as a whole; {p['slower_10']} got more than 10% slower.")
w('')
w('## Correctness')
w('')
w('| Check | Result |')
w('|---|---|')
if vm:
    w(f'| Every captured meta query the plugin answers, run both ways: full result set, total, page order | {vm.group(1)} queries, {vm.group(2)} differences |')
if wpt:
    w(f'| 40 rounds of product, order, customer and stock changes through WordPress, WooCommerce and direct SQL, then the same queries both ways | {wpt.group(1)} comparisons, {wpt.group(2)} differences ({wpt.group(3)} answered by the plugin) |')
if race:
    w(f'| 4 writers and 3 readers at once for {race.group(1)} s, then every copied row and counter compared with a fresh copy | {race.group(7)} stale rows or counters ({int(race.group(2)):,} write rounds, {int(race.group(3)):,} reads) |')
edge = read('edge.txt')
if edge:
    w(f"| Edge cases from code review: a kept total reaching another query, a write starting with a comment, a BINARY cast, a second wpdb object, repair | {sum(1 for l in edge.splitlines() if l.startswith('ok'))} of {sum(1 for l in edge.splitlines() if l.startswith(('ok', 'FAIL')))} passed |")
w(f"| The page itself: item ids and total of each screen, both ways | same on {p['same_result']} of {p['screens']} screens (see the note under the page table) |")
w('')
w('## Meta SQL per screen')
w('')
w('The meta queries each screen ran, replayed 15 times; median wall time including the plugin\'s own PHP time.')
w('')
w('![Meta SQL time per screen](charts/queries.svg)')
w('')
w('| Screen | | WordPress alone | With the plugin | Faster |')
w('|---|---|---|---|---|')
for code, name, a, b in sorted(q['rows'], key=lambda r: -r[2]):
    w(f'| {code} | {name} | {ms(a)} | {ms(b)} | {x(a, b)} |')
w('')
w('## Whole pages')
w('')
w('Each screen loaded 7 times both ways, logged in as an administrator; median time of the whole request. PHP, the')
w('admin screen itself and queries without meta are included, so the gain is smaller than in the SQL alone.')
w('')
w('![Whole page time per screen](charts/pages.svg)')
w('')
w('| Screen | | WordPress alone | With the plugin | Same items |')
w('|---|---|---|---|---|')
for code, name, a, b, same in sorted(p['rows'], key=lambda r: -r[2]):
    w(f"| {code} | {name} | {ms(a)} | {ms(b)} | {'yes' if same else 'no, see below'} |")
w('')
w('"Same items" compares the item ids and the total on the page. Screens marked "no" differ for reasons outside the')
w('plugin; each is explained in [results/pages-notes.md](results/pages-notes.md).')
w('')
w('## Under load')
w('')
w('4 clients loading random screens for 45 seconds, 3 rounds each way, PHP with 4 workers.')
w('')
w('![Load test](charts/load.svg)')
w('')
w('| | WordPress alone | With the plugin |')
w('|---|---|---|')
w(f"| Pages served (median of 3 rounds) | {ld['stock']['n']:.0f} | {ld[ARM]['n']:.0f} |")
w(f"| Median page time | {ms(ld['stock']['med'])} | {ms(ld[ARM]['med'])} |")
w(f"| 95th percentile | {ms(ld['stock']['p95'])} | {ms(ld[ARM]['p95'])} |")
w(f"| Errors | {ld['stock']['err']:.0f} | {ld[ARM]['err']:.0f} |")
w('')
w('## What it costs')
w('')
w('| | |')
w('|---|---|')
w(f"| A meta update through WordPress | {wr['meta_update_ms']} ms |")
w(f"| The note the plugin adds after it | {wr['note_ms']} ms |")
for n, v in wr['refresh'].items():
    w(f"| Copying {n} changed product{'s' if n != '1' else ''} before the next read (first time / again) | {v['first']} ms / {v['warm']} ms |")
for n, v in wr['read_with_waiting'].items():
    w(f"| A read (screen P1) while {n} objects wait to be copied | {v['ms']} ms{'' if v['answered'] else ', sent to WordPress'} |")
if disk:
    w(f'| Disk used by the plugin\'s tables | {plugin_mb:,.0f} MB (the meta tables themselves: {meta_mb:,.0f} MB) |')
w('| Building all tables | about 2 minutes (8 tables) |')
w('| Repairing all posts after a write the plugin could not follow | about 50 s; reads of posts go to WordPress meanwhile |')
w('| Loading the table definitions, once per request | about 0.3 ms (a 70 KB autoloaded option); included in every plugin time above |')
w('')
w('## Limits of this benchmark')
w('')
w('- One synthetic store on one laptop that was busy with other work during the run (see METHOD.md, "Noise").')
w('- The custom fields the screens filter on were added for the test; their names and value spread are assumptions.')
w('- The plugin learned from these same screens. On a real site it learns from real traffic, which is less tidy.')
w('- MySQL only, no object cache, no other plugins that filter `query`, one WordPress and WooCommerce version.')
w('')
open(os.path.join(B, 'README.md'), 'w').write('\n'.join(o))
print('wrote benchmarks/README.md')
