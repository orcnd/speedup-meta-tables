"""Draws the benchmark charts as SVG from benchmarks/results/*.json.

python3 benchmarks/charts/make.py [results folder] [plugin arm name]

Each chart carries light and dark colors (prefers-color-scheme), so it reads on GitHub in both themes.
Colors: categorical slots 1 (blue, the plugin) and 2 (orange, WordPress alone) of a palette checked for color
vision deficiency; every value is also printed as text or listed in benchmarks/README.md.
"""
import json
import math
import os
import statistics
import sys

HERE = os.path.dirname(os.path.abspath(__file__))
RES = sys.argv[1] if len(sys.argv) > 1 else os.path.join(HERE, '..', 'results')
ARM = sys.argv[2] if len(sys.argv) > 2 else 'smt'
NAMES = json.load(open(os.path.join(HERE, 'scenarios.json')))

STYLE = """<style>
  .s { --bg: #ffffff; --ink: #191919; --ink2: #666666; --grid: #e4e4e4; --stock: #f47230; --plugin: #257de0; --ring: #ffffff; }
  @media (prefers-color-scheme: dark) {
    .s { --ink: #efefef; --ink2: #9c9c9c; --grid: #333333; --stock: #e76624; --plugin: #338ced; --ring: #0d1117; --bg: #0d1117; }
  }
  text { font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", Helvetica, Arial, sans-serif; fill: var(--ink); }
  .t2 { fill: var(--ink2); }
  .grid { stroke: var(--grid); stroke-width: 1; }
  .link { stroke: var(--ink2); stroke-width: 2; opacity: .35; }
  .stock { fill: var(--stock); stroke: var(--ring); stroke-width: 2; }
  .plugin { fill: var(--plugin); stroke: var(--ring); stroke-width: 2; }
  .bstock { fill: var(--stock); }
  .bplugin { fill: var(--plugin); }
  .bg { fill: var(--bg); }
</style>"""


def esc(s):
    return str(s).replace('&', '&amp;').replace('<', '&lt;').replace('>', '&gt;')


def ms(v):
    if v >= 1000:
        return f'{v / 1000:.1f} s'
    if v >= 10:
        return f'{v:.0f} ms'
    if v >= 1:
        return f'{v:.1f} ms'
    return f'{v:.2f} ms'


def tickfmt(v):
    return f'{v / 1000:g} s' if v >= 1000 else f'{v:g} ms'


def times(v):
    if v >= 100:
        return f'{v:,.0f}x'
    if v >= 10:
        return f'{v:.0f}x'
    return f'{v:.1f}x'


def legend(x, y):
    return (f'<circle class="stock" cx="{x}" cy="{y}" r="5"/><text x="{x + 10}" y="{y + 4}" font-size="12">WordPress alone</text>'
            f'<circle class="plugin" cx="{x + 130}" cy="{y}" r="5"/><text x="{x + 140}" y="{y + 4}" font-size="12">With SpeedUp Meta Tables</text>')


def dumbbell(rows, title_note, lo, hi, out):
    """rows: [(code, name, stock ms, plugin ms)] drawn on a log axis, slowest first."""
    rows = sorted(rows, key=lambda r: -r[2])
    left, right, top, rh = 300, 70, 62, 20
    width = 860
    plot = width - left - right
    height = top + rh * len(rows) + 34
    lmin, lmax = math.log10(lo), math.log10(hi)
    x = lambda v: left + plot * (math.log10(max(lo, min(hi, v))) - lmin) / (lmax - lmin)
    o = [f'<svg xmlns="http://www.w3.org/2000/svg" class="s" width="{width}" height="{height}" viewBox="0 0 {width} {height}" role="img" aria-label="{esc(title_note)}">', STYLE, f'<rect class="bg" width="{width}" height="{height}" rx="6"/>']
    o.append(legend(left, 18))
    o.append(f'<text x="8" y="22" font-size="12" class="t2">{esc(title_note)}</text>')
    tick = lo
    while tick <= hi * 1.0001:
        tx = x(tick)
        o.append(f'<line class="grid" x1="{tx:.1f}" x2="{tx:.1f}" y1="{top - 8}" y2="{height - 28}"/>')
        o.append(f'<text x="{tx:.1f}" y="{height - 12}" font-size="11" class="t2" text-anchor="middle">{tickfmt(tick)}</text>')
        tick *= 10
    o.append(f'<text x="{width - right + 8}" y="{top - 12}" font-size="11" class="t2">faster</text>')
    for i, (code, name, a, b) in enumerate(rows):
        y = top + i * rh + rh / 2
        o.append(f'<text x="8" y="{y + 4}" font-size="12"><tspan font-weight="600">{esc(code)}</tspan><tspan class="t2" dx="6">{esc(name[:42])}</tspan></text>')
        o.append(f'<line class="link" x1="{x(b):.1f}" x2="{x(a):.1f}" y1="{y}" y2="{y}"/>')
        o.append(f'<circle class="stock" cx="{x(a):.1f}" cy="{y}" r="5"><title>{esc(code)} WordPress alone: {ms(a)}</title></circle>')
        o.append(f'<circle class="plugin" cx="{x(b):.1f}" cy="{y}" r="5"><title>{esc(code)} with the plugin: {ms(b)}</title></circle>')
        o.append(f'<text x="{width - right + 8}" y="{y + 4}" font-size="12" class="t2">{times(a / b) if b > 0 else "-"}</text>')
    o.append('</svg>')
    open(out, 'w').write('\n'.join(o) + '\n')


def bars(panels, out):
    """panels: [(title, unit, stock value, plugin value, better)] as small side-by-side bar pairs."""
    pw, gap, top, h = 250, 30, 64, 150
    width = len(panels) * pw + (len(panels) - 1) * gap + 32
    height = top + h + 32
    o = [f'<svg xmlns="http://www.w3.org/2000/svg" class="s" width="{width}" height="{height}" viewBox="0 0 {width} {height}" role="img" aria-label="Load test">', STYLE, f'<rect class="bg" width="{width}" height="{height}" rx="6"/>', '<g transform="translate(16 8)">']
    o.append(legend(6, 14))
    for i, (title, unit, a, b, better) in enumerate(panels):
        x0 = i * (pw + gap)
        o.append(f'<text x="{x0}" y="46" font-size="13" font-weight="600">{esc(title)}</text>')
        o.append(f'<text x="{x0 + pw}" y="46" font-size="11" class="t2" text-anchor="end">{esc(better)}</text>')
        m = max(a, b) * 1.15
        base = top + h
        o.append(f'<line class="grid" x1="{x0}" x2="{x0 + pw}" y1="{base}" y2="{base}"/>')
        for j, (v, cls, label) in enumerate(((a, 'bstock', 'WordPress alone'), (b, 'bplugin', 'With the plugin'))):
            bw = 70
            bx = x0 + 30 + j * (bw + 50)
            bh = h * v / m
            o.append(f'<path class="{cls}" d="M{bx},{base} V{base - bh + 4:.1f} Q{bx},{base - bh:.1f} {bx + 4},{base - bh:.1f} H{bx + bw - 4} Q{bx + bw},{base - bh:.1f} {bx + bw},{base - bh + 4:.1f} V{base} Z"><title>{esc(label)}: {v:,.0f} {esc(unit)}</title></path>')
            o.append(f'<text x="{bx + bw / 2}" y="{base - bh - 6:.1f}" font-size="12" text-anchor="middle">{v:,.0f} {esc(unit)}</text>')
    o.append('</g></svg>')
    open(out, 'w').write('\n'.join(o) + '\n')


def main():
    summary = {}
    rp = json.load(open(os.path.join(RES, 'replay.json')))
    rows = []
    for k, x in rp.items():
        if 'stock' in x and ARM in x and x['stock']['q'] > 0:
            rows.append((k, NAMES.get(k, ['', k])[1], x['stock']['wall'], x[ARM]['wall']))
    dumbbell(rows, 'meta SQL time per screen, median of 15 runs, log scale', 0.1, 100000, os.path.join(HERE, 'queries.svg'))
    sp = [a / b for _, _, a, b in rows if b > 0]
    summary['queries'] = {
        'screens': len(rows),
        'stock_median_ms': statistics.median(r[2] for r in rows),
        'plugin_median_ms': statistics.median(r[3] for r in rows),
        'stock_geomean_ms': math.exp(statistics.mean(math.log(r[2]) for r in rows)),
        'plugin_geomean_ms': math.exp(statistics.mean(math.log(r[3]) for r in rows)),
        'stock_total_ms': sum(r[2] for r in rows),
        'plugin_total_ms': sum(r[3] for r in rows),
        'speedup_median': statistics.median(sp),
        'speedup_min': min(sp),
        'speedup_max': max(sp),
        'rows': rows,
    }
    pg_path = os.path.join(RES, 'pages.json')
    if os.path.exists(pg_path):
        pg = json.load(open(pg_path))
        prow = []
        for k, x in pg.items():
            if 'stock' in x and ARM in x and x['stock']['n']:
                prow.append((k, NAMES.get(k, ['', k])[1], x['stock']['wall'], x[ARM]['wall'], x.get('same', {}).get(ARM)))
        dumbbell([r[:4] for r in prow], 'whole page time, median of 7 loads, log scale', 10, 100000, os.path.join(HERE, 'pages.svg'))
        summary['pages'] = {
            'screens': len(prow),
            'stock_median_ms': statistics.median(r[2] for r in prow),
            'plugin_median_ms': statistics.median(r[3] for r in prow),
            'stock_over_1s': sum(1 for r in prow if r[2] > 1000),
            'plugin_over_1s': sum(1 for r in prow if r[3] > 1000),
            'faster_20': sum(1 for r in prow if r[3] < r[2] * 0.8),
            'slower_10': sum(1 for r in prow if r[3] > r[2] * 1.1),
            'same_result': sum(1 for r in prow if r[4]),
            'rows': prow,
        }
    ld_path = os.path.join(RES, 'load.json')
    if os.path.exists(ld_path):
        ld = json.load(open(ld_path))
        md = lambda arm, f: statistics.median(r[f] for r in ld[arm])
        bars([
            ('Pages served in 45 s', 'pages', md('stock', 'n'), md(ARM, 'n'), 'more is better'),
            ('Median page time', 'ms', md('stock', 'med'), md(ARM, 'med'), 'less is better'),
            ('95th percentile page time', 'ms', md('stock', 'p95'), md(ARM, 'p95'), 'less is better'),
        ], os.path.join(HERE, 'load.svg'))
        summary['load'] = {arm: {f: md(arm, f) for f in ('n', 'med', 'p95', 'err')} for arm in ('stock', ARM)}
    json.dump(summary, open(os.path.join(HERE, 'summary.json'), 'w'), indent=1)
    print(json.dumps({k: {a: b for a, b in v.items() if a != 'rows'} if isinstance(v, dict) and 'rows' in v else v for k, v in summary.items()}, indent=1))


main()
