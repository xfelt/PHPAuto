# Numerical experiment data and results

Dataset supporting the article **“Carbon-price inertia and cap-driven compliance in joint DDMRP strategic buffer positioning and supplier selection and allocation.”**

## Scope

This package contains only:

- raw numerical inputs used to define the benchmark instances;
- supplier eligibility and supplier-parameter inputs;
- the environmental, policy, service, and computational conditions applied in the experiments;
- structured numerical outputs and raw solver-output logs.

It deliberately excludes source code, optimization model files, executable files, manuscript files, and figures.

The instances are calibrated deterministic benchmarks. They are not observations from firms, human participants, or field experiments, and the scenarios are not Monte Carlo simulations.

## Directory structure

- `inputs/bom_instances/`: 33 BOM input tables.
- `inputs/supplier_eligibility/`: supplier eligibility tables corresponding to the BOM instances.
- `inputs/supplier_parameters/`: standard- and high-capacity supplier input tables.
- `inputs/conditions/`: experiment design, instance index, environmental assumptions, and computational environment.
- `outputs/main_campaign/consolidated_results.csv`: 301 run-level results from the corrected main campaign.
- `outputs/main_campaign/tabular_results/`: experiment-specific outputs for scalability, topology, carbon price, emissions cap, hybrid price-and-cap, service time, price-switching thresholds, decision degeneracy, and the campaign PLM/NLM diagnostic.
- `outputs/main_campaign/pareto_results/`: cost–emissions and cost–DIO Pareto points and ideal/nadir values.
- `outputs/main_campaign/raw_solver_outputs/`: 409 raw solver-output logs, including decision vectors and quantities.
- `outputs/formulation_validation/plm_validation_results.csv`: focused formulation-validation results comprising 21 PLM/NLM equivalence pairs and 15 PLM stress cases.
- `outputs/formulation_validation/raw_solver_outputs/`: 69 raw outputs supporting the focused validation, including baselines, paired runs, and stress runs.

## Campaign provenance

The main results come from the corrected campaign started on 5 June 2026 at 06:13:05 and completed at 06:43:11. Its automated post-run validation passed: 301 planned consolidated rows were realized, 409 runner solver calls were recorded, all expected identifiers were present without duplicates, 60 Pareto points were produced, and 108 conditional decision-degeneracy probes were retained.

The focused formulation-validation campaign is separate from the main campaign. Use `outputs/formulation_validation/plm_validation_results.csv` for the article’s reported 21 equivalence pairs, 16 jointly optimal matches, five equal time-limited incumbents, and 15 stress cases. The main campaign’s `nlm_comparison_results.csv` is retained as part of the complete campaign output but is not the source of that focused 21-pair summary.

## Units and interpretation

- Monetary values are constant 2023 EUR.
- Carbon-price inputs are EUR per tonne of CO2.
- Emissions in raw CSV files and logs are grams of CO2 unless a column explicitly states otherwise; divide by 1,000,000 for tonnes of CO2.
- Runtime is in seconds.
- Service time, decoupled lead time, and DIO are in days.
- A blank CSV field means that the metric was not applicable or not retained for that run.
- `OPTIMAL` indicates a proven optimum; `FEASIBLE` indicates a time-limited incumbent.
- Comparative conclusions use proven-optimal results and feasible incumbents with a final optimality gap no greater than 1%.

## Identifiers

Regular instances use `bom_N`. Structural files use `bom_mlL_N` for multi-level instances and `bom_parB` for parallel-branch instances. In the manuscript, a parallel instance may be displayed with its node count appended; for example, output identifier `bom_par4` corresponds to manuscript label `bom_par4_10`.

## File formats

Most experiment outputs are comma-separated CSV files. BOM, supplier, and Pareto input/output tables use semicolon separators. JSON files are UTF-8 metadata or numerical output records. Raw `.log` files contain solver outputs only; no optimization model source is included.

See `DATA_DICTIONARY.md` for field definitions.
