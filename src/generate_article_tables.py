#!/usr/bin/env python3
"""
Generate publication-ready LaTeX table fragments for the Journal of Cleaner
Production article from a final-campaign consolidated_results.csv.

Usage:
    python generate_article_tables.py [results_dir]

If results_dir is omitted, the most recent logs/final_campaign_* directory is used.
Outputs .tex fragments into <results_dir>/tables_tex/.
"""
import sys
import glob
import os
import math
import re
import pandas as pd

# ---------------------------------------------------------------- helpers
def fmt_emis(x):
    """Format an emission value, converting the model's gCO2 to tonnes (1 t = 1e6 gCO2)."""
    try:
        x = float(x)
    except (TypeError, ValueError):
        return "--"
    if math.isnan(x):
        return "--"
    t = x / 1e6  # gCO2 -> tonnes
    if t >= 1000:
        return f"{t:,.0f}".replace(",", "\\,")
    if t >= 10:
        return f"{t:.1f}"
    return f"{t:.2f}"

def fmt_cost(x):
    try:
        x = float(x)
    except (TypeError, ValueError):
        return "--"
    if math.isnan(x):
        return "--"
    return f"{x:,.0f}".replace(",", "\\,")

def fmt_num(x, d=2):
    try:
        x = float(x)
        if math.isnan(x):
            return "--"
        return f"{x:.{d}f}"
    except (TypeError, ValueError):
        return "--"

def fmt_rate(x):
    try:
        x = float(x)
    except (TypeError, ValueError):
        return "--"
    if math.isnan(x):
        return "--"
    if abs(x - round(x)) < 0.05:
        return f"{x:,.0f}".replace(",", "\\,")
    return f"{x:,.1f}".replace(",", "\\,")

def emis_of(df_rows):
    return df_rows['total_emissions'].astype(float)

# ---------------------------------------------------------------- locate data
if len(sys.argv) > 1:
    results_dir = sys.argv[1]
else:
    base = os.path.join(os.path.dirname(__file__), '..', 'logs')
    cands = sorted(glob.glob(os.path.join(base, 'final_campaign_*')),
                   key=os.path.getmtime)
    if not cands:
        print("No final_campaign_* directory found.")
        sys.exit(1)
    results_dir = cands[-1]

results_dir = os.path.abspath(results_dir)
csv_path = os.path.join(results_dir, 'consolidated_results.csv')
df = pd.read_csv(csv_path)
out_dir = os.path.join(results_dir, 'tables_tex')
os.makedirs(out_dir, exist_ok=True)
print(f"Loaded {len(df)} rows from {csv_path}")
print(f"Writing LaTeX tables to {out_dir}")

def write(name, content):
    with open(os.path.join(out_dir, name), 'w', encoding='utf-8') as f:
        f.write(content)
    print("  wrote", name)

def n_of(instance_id):
    """Extract numeric BOM size from instance id like bom_50 / bom_ml4_30."""
    digits = ''.join(ch for ch in str(instance_id).split('_')[-1] if ch.isdigit())
    return int(digits) if digits else 0

def instance_sort_key(instance_id):
    """Sort regular BOMs numerically first, then structural benchmark families."""
    value = str(instance_id)
    regular = re.match(r'^bom_(\d+)$', value)
    if regular:
        return (0, int(regular.group(1)), value)
    multi_level = re.match(r'^bom_ml(\d+)_(\d+)$', value)
    if multi_level:
        return (1, int(multi_level.group(2)), int(multi_level.group(1)), value)
    parallel = re.match(r'^bom_par(\d+)$', value)
    if parallel:
        return (2, int(parallel.group(1)), value)
    return (9, n_of(value), value)

def comparison_admissible(rows):
    """Keep rows eligible to support behavioral comparisons."""
    if rows.empty:
        return rows
    if 'comparison_admissible' in rows.columns:
        values = rows['comparison_admissible'].astype(str).str.strip().str.lower()
        return rows[values.isin(['1', '1.0', 'true', 'yes'])].copy()
    status = rows['solver_status'].astype(str).str.strip().str.upper()
    if 'mip_gap' in rows.columns:
        gap = pd.to_numeric(rows['mip_gap'], errors='coerce')
    else:
        gap = pd.Series(float('nan'), index=rows.index)
    return rows[(status == 'OPTIMAL') | ((status == 'FEASIBLE') & (gap <= 1.0))].copy()

def maybe_read_table_csv(name):
    path = os.path.join(results_dir, 'tables', name)
    if os.path.exists(path):
        return pd.read_csv(path)
    return pd.DataFrame()

# ================================================================ 1. SCALABILITY
scal = df[df['experiment'] == 'scalability'].copy()
if not scal.empty:
    scal['N'] = scal['instance_id'].map(n_of)
    scal = scal.sort_values('N')
    rows = []
    for _, r in scal.iterrows():
        rows.append(
            f"{int(r['N'])} & {int(r['buffer_count'])} & {fmt_num(r['DIO'],0)} & "
            f"{fmt_emis(r['total_emissions'])} & {fmt_num(r['runtime_sec'],3)} & "
            f"{r['solver_status']} \\\\"
        )
    body = "\n".join(rows)
    tex = (
        "\\begin{table}[!htbp]\\centering\n"
        "\\caption{Scalability of the pseudo-linear model across BOM sizes "
        "(baseline, zero carbon tax).}\\label{tab:scal}\n"
        "\\begin{tabular}{cccccc}\n\\toprule\n"
        "$N$ & Buffers & DIO & Emissions (t\\,CO$_2$) & Runtime (s) & Status\\\\\n"
        "\\midrule\n" + body + "\n\\bottomrule\n\\end{tabular}\n\\end{table}\n"
    )
    write('tab_scalability.tex', tex)

# ================================================================ 2. TAX SWEEP
tax = comparison_admissible(df[df['experiment'] == 'carbon_tax_sweep'].copy())
if not tax.empty:
    tax['tax_rate'] = tax['tax_rate'].astype(float)
    rates = sorted(tax['tax_rate'].unique())
    insts = sorted(tax['instance_id'].unique(), key=instance_sort_key)
    header = "Instance & " + " & ".join(f"${r:g}$" for r in rates) + " & Red.\\,\\%\\\\"
    rows = []
    for inst in insts:
        sub = tax[tax['instance_id'] == inst]
        base = sub[sub['tax_rate'] == 0.0]['total_emissions']
        base = float(base.iloc[0]) if len(base) else float('nan')
        cells = []
        red = float('nan')
        for r in rates:
            v = sub[sub['tax_rate'] == r]['total_emissions']
            if len(v):
                ev = float(v.iloc[0])
                cells.append(fmt_emis(ev))
                if r == rates[-1] and not math.isnan(base) and base > 0:
                    red = (base - ev) / base * 100
            else:
                cells.append("--")
        inst_esc = inst.replace('_', '\\_')
        rows.append(f"{inst_esc} & " + " & ".join(cells) +
                    f" & {fmt_num(red,1)} \\\\")
    body = "\n".join(rows)
    colspec = "l" + "c" * (len(rates) + 1)
    tex = (
        "\\begin{table*}[!htbp]\\centering\\scriptsize\n"
        "\\setlength{\\tabcolsep}{3pt}\n"
        "\\caption{Carbon tax sweep: total emissions (t\\,CO$_2$) per instance as the "
        "carbon price $EmisTax$ (EUR/tCO$_2$) increases; column headings report $EmisTax$ values.}"
        "\\label{tab:tax}\n"
        f"\\begin{{tabular}}{{{colspec}}}\n\\toprule\n" + header + "\n\\midrule\n" +
        body + "\n\\bottomrule\n\\end{tabular}\n\\end{table*}\n"
    )
    write('tab_tax_sweep.tex', tex)

# ================================================================ 2B. PRICE THRESHOLD
threshold = maybe_read_table_csv('carbon_price_threshold_results.csv')
if not threshold.empty:
    rows = []
    for _, r in threshold.sort_values('instance_id', key=lambda s: s.map(instance_sort_key)).iterrows():
        inst = str(r['instance_id']).replace('_', '\\_')
        switched = str(r.get('switched_within_max', '0')).strip() in ['1', '1.0', 'true', 'True']
        if switched:
            interval = (
                fmt_rate(r.get('threshold_lower_eur_per_tco2'))
                + "--"
                + fmt_rate(r.get('threshold_upper_eur_per_tco2'))
            )
        else:
            interval = "$>" + fmt_rate(r.get('max_probe_rate')) + "$"
        delta_cost = fmt_cost(r.get('delta_cost_without_tax'))
        try:
            emis_reduction = -float(r.get('delta_emissions_gco2')) / 1e6
        except (TypeError, ValueError):
            emis_reduction = float('nan')
        rows.append(
            f"{inst} & {interval} & {delta_cost} & {fmt_num(emis_reduction, 2)} \\\\"
        )
    body = "\n".join(rows)
    tex = (
        "\\begin{table*}[!htbp]\\centering\\scriptsize\n"
        "\\setlength{\\tabcolsep}{4pt}\n"
        "\\caption{Exploratory carbon-price switching-threshold diagnostic. The interval reports "
        "the first $EmisTax$ range, in EUR/tCO$_2$, where the price-only operating point differs "
        "from the no-price solution; values above observed policy levels are stress-test diagnostics, "
        "not proposed statutory taxes.}\\label{tab:pricethreshold}\n"
        "\\begin{tabular}{lccc}\n\\toprule\n"
        "Instance & Switching interval & $\\Delta$ cost & Emission reduction (t\\,CO$_2$)\\\\\n"
        "\\midrule\n" + body + "\n\\bottomrule\n\\end{tabular}\n\\end{table*}\n"
    )
    write('tab_price_threshold.tex', tex)

# ================================================================ 3. CAP SWEEP
cap = comparison_admissible(df[df['experiment'] == 'carbon_cap_sweep'].copy())
if not cap.empty:
    # cap level expressed as % of baseline; recover from cap_value vs baseline_emissions
    insts = sorted(cap['instance_id'].unique(), key=instance_sort_key)
    # Determine cap percentages present (round cap_value/baseline)
    def cap_pct(row):
        try:
            b = float(row['baseline_emissions'])
            c = float(row['cap_value'])
            if b > 0:
                return round(c / b * 100)
        except (TypeError, ValueError):
            return None
        return None
    cap['cap_pct'] = cap.apply(cap_pct, axis=1)
    pcts = sorted([p for p in cap['cap_pct'].dropna().unique()], reverse=True)
    if pcts:
        header = "Instance & " + " & ".join(f"{int(p)}\\%" for p in pcts) + "\\\\"
        rows = []
        for inst in insts:
            sub = cap[cap['instance_id'] == inst]
            cells = []
            for p in pcts:
                v = sub[sub['cap_pct'] == p]['total_cost_with_tax']
                cells.append(fmt_cost(v.iloc[0]) if len(v) else "--")
            inst_esc = inst.replace('_', '\\_')
            rows.append(f"{inst_esc} & " + " & ".join(cells) + " \\\\")
        body = "\n".join(rows)
        colspec = "l" + "c" * len(pcts)
        tex = (
            "\\begin{table*}[!htbp]\\centering\\scriptsize\n"
            "\\setlength{\\tabcolsep}{3pt}\n"
            "\\caption{Carbon cap sweep: total cost per instance as the emission cap "
            "tightens from 100\\% to 70\\% of the baseline emissions.}\\label{tab:cap}\n"
            f"\\begin{{tabular}}{{{colspec}}}\n\\toprule\n" + header + "\n\\midrule\n" +
            body + "\n\\bottomrule\n\\end{tabular}\n\\end{table*}\n"
        )
        write('tab_cap_sweep.tex', tex)

# ================================================================ 4. HYBRID
hyb = df[df['experiment'] == 'carbon_hybrid'].copy()
if not hyb.empty:
    rows = []
    hyb['_instance_sort_key'] = hyb['instance_id'].map(instance_sort_key)
    for _, r in hyb.sort_values(['_instance_sort_key', 'tax_rate', 'cap_value']).iterrows():
        cap_level = str(r.get('cap_level', '')).strip().lower()
        cap_display = "No cap" if cap_level == "none" else fmt_emis(r['cap_value'])
        status = str(r.get('solver_status', 'UNKNOWN')).strip().upper().replace('_', '\\_')
        buffer_count = fmt_num(r.get('buffer_count'), 0)
        rows.append(
            f"{str(r['instance_id']).replace('_',chr(92)+'_')} & {fmt_num(r['tax_rate'],2)} & "
            f"{cap_display} & {fmt_emis(r['total_emissions'])} & "
            f"{fmt_cost(r['total_cost_with_tax'])} & {buffer_count} & {status} \\\\"
        )
    body = "\n".join(rows)
    tex = (
        "\\begingroup\\scriptsize\n"
        "\\setlength{\\tabcolsep}{3pt}\n"
        "\\begin{longtable}{lcccccc}\n"
        "\\caption{Full results of the hybrid strategy across all tested instances, for every "
        "combination of carbon price $EmisTax$ (currency/tCO$_2$) and emission cap.}\\label{tab:hybridfull}\\\\\n"
        "\\toprule\n"
        "Instance & $EmisTax$ & Cap level & Emissions (t\\,CO$_2$) & Cost & Buffers & Status\\\\\n"
        "\\midrule\n\\endfirsthead\n"
        "\\toprule\n"
        "Instance & $EmisTax$ & Cap level & Emissions (t\\,CO$_2$) & Cost & Buffers & Status\\\\\n"
        "\\midrule\n\\endhead\n"
        "\\midrule\n\\multicolumn{7}{r}{Continued on next page}\\\\\n\\endfoot\n"
        "\\bottomrule\n\\endlastfoot\n"
        + body + "\n\\end{longtable}\n\\endgroup\n"
    )
    write('tab_hybrid.tex', tex)

# ================================================================ 4B. DECISION STABILITY
stability = maybe_read_table_csv('decision_stability_summary.csv')
if not stability.empty:
    rows = []
    source_labels = {
        'carbon_tax_sweep': 'tax',
        'carbon_cap_sweep': 'cap',
        'carbon_hybrid': 'hybrid',
    }
    stability['_instance_sort_key'] = stability['instance_id'].map(instance_sort_key)
    for _, r in stability.sort_values(['_instance_sort_key', 'source_experiment', 'tax_rate']).iterrows():
        source = source_labels.get(str(r['source_experiment']), str(r['source_experiment']))
        rows.append(
            f"{str(r['instance_id']).replace('_',chr(92)+'_')} & "
            f"{source.replace('_',chr(92)+'_')} & "
            f"{fmt_num(r.get('tax_rate'), 2)} & {str(r.get('cap_level', '--')).replace('%', chr(92)+'%')} & "
            f"{fmt_num(r.get('minimum_buffer_jaccard_similarity'), 2)} & "
            f"{fmt_num(r.get('minimum_supplier_jaccard_similarity'), 2)} & "
            f"{fmt_num(r.get('maximum_allocation_l1_normalized'), 2)} \\\\"
        )
    body = "\n".join(rows)
    tex = (
        "\\begin{table*}[!htbp]\\centering\\scriptsize\n"
        "\\setlength{\\tabcolsep}{3pt}\n"
        "\\caption{Near-optimal decision-degeneracy diagnostic probes. Each row summarizes extremal alternatives "
        "whose original objective remains within 1\\% of the proven optimum. Source abbreviations are tax, cap and hybrid; no binary stability "
        "class is assigned.}\\label{tab:stability}\n"
        "\\begin{tabular}{llccccc}\n\\toprule\n"
        "Instance & Source & Tax & Cap & Buf. J & Sup. J & Alloc. L1\\\\\n"
        "\\midrule\n" + body + "\n\\bottomrule\n\\end{tabular}\n\\end{table*}\n"
    )
    write('tab_decision_stability.tex', tex)

# ================================================================ 6. PARETO FRONTS
def pareto_table(front, xcol, xfmt, xhead, caption, label, fname):
    pdir = os.path.join(results_dir, 'pareto')
    files = sorted(glob.glob(os.path.join(pdir, '*_' + front + '_pareto.csv')),
                   key=lambda f: instance_sort_key(os.path.basename(f).replace('_' + front + '_pareto.csv', '')))
    if not files:
        return
    rows = []
    for f in files:
        inst = os.path.basename(f).replace('_' + front + '_pareto.csv', '')
        try:
            pf = pd.read_csv(f, sep=';')
        except Exception:
            continue
        if pf.empty:
            continue
        rows.append("\\multicolumn{3}{l}{\\textit{" + inst.replace('_', '\\_') + "}} \\\\")
        seen = set()
        for _, r in pf.iterrows():
            key = (round(float(r[xcol]), 3), round(float(r['Cost']), 1))
            if key in seen:
                continue
            seen.add(key)
            rows.append(" & " + xfmt(r[xcol]) + " & " + fmt_cost(r['Cost']) + " \\\\")
        rows.append("\\midrule")
    if rows and rows[-1] == "\\midrule":
        rows = rows[:-1]
    body = "\n".join(rows)
    tex = (
        "\\begin{table}[!htbp]\\centering\\small\n"
        "\\caption{" + caption + "}\\label{" + label + "}\n"
        "\\begin{tabular}{lcc}\n\\toprule\n"
        "Instance & " + xhead + " & Cost\\\\\n"
        "\\midrule\n" + body + "\n\\bottomrule\n\\end{tabular}\n\\end{table}\n"
    )
    write(fname, tex)

pareto_table('cost_emissions', 'Emissions', fmt_emis, 'Emissions (t\\,CO$_2$)',
             'Cost--emissions Pareto points obtained by the $\\varepsilon$-constraint method.',
             'tab:paretoemis', 'tab_pareto_emis.tex')
pareto_table('cost_dio', 'DIO', lambda x: f"{float(x):.0f}", 'DIO (days)',
             'Cost--DIO Pareto points obtained by the $\\varepsilon$-constraint method.',
             'tab:paretodio', 'tab_pareto_dio.tex')

# ================================================================ 5. PLM vs NLM
nlm = comparison_admissible(df[df['experiment'] == 'nlm_comparison'].copy())
if not nlm.empty:
    insts = sorted(nlm['instance_id'].unique(), key=instance_sort_key)
    rows = []
    for inst in insts:
        for strat in sorted(nlm[nlm['instance_id'] == inst]['strategy'].unique()):
            sub = nlm[(nlm['instance_id'] == inst) & (nlm['strategy'] == strat)]
            plm = sub[sub['model_type'] == 'PLM']
            nl = sub[sub['model_type'] == 'NLM']
            def g(d, col):
                return d[col].iloc[0] if len(d) else float('nan')
            rows.append(
                f"{inst.replace('_',chr(92)+'_')} & {strat} & "
                f"{fmt_cost(g(plm,'total_cost_with_tax'))} & {fmt_num(g(plm,'runtime_sec'),2)} & "
                f"{fmt_cost(g(nl,'total_cost_with_tax'))} & {fmt_num(g(nl,'runtime_sec'),2)} \\\\"
            )
    body = "\n".join(rows)
    tex = (
        "\\begin{table}[!htbp]\\centering\\small\n"
        "\\caption{Pseudo-linear (PLM) versus non-linear (NLM) model: cost and runtime. "
        "The NLM solve time is bounded to 300\\,s.}\\label{tab:plmnlm}\n"
        "\\begin{tabular}{llcccc}\n\\toprule\n"
        " & & \\multicolumn{2}{c}{PLM} & \\multicolumn{2}{c}{NLM}\\\\\n"
        "\\cmidrule(lr){3-4}\\cmidrule(lr){5-6}\n"
        "Instance & Strategy & Cost & RT (s) & Cost & RT (s)\\\\\n"
        "\\midrule\n" + body + "\n\\bottomrule\n\\end{tabular}\n\\end{table}\n"
    )
    write('tab_plm_nlm.tex', tex)

print("Done.")
