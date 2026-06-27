# Data dictionary

## BOM input tables

The semicolon-separated `bom_supemis_*.csv` files contain one row per BOM node.

- `ind`: node identifier.
- `t_process`: processing time.
- `parent`: parent/successor node identifier; `-1` marks the root boundary.
- `unit_price`: unit economic value.
- `rqtf`: required quantity factor.
- `aih_cost`: annual inventory holding-cost rate.
- `var_factor`: variability factor used in buffer sizing.
- `lt_factor`: lead-time factor.
- `cycle`: activity-cycle factor.
- `minOrder`: minimum-order parameter.
- `facility_emis`: facility-related emissions coefficient.
- `inventory_emis`: inventory/warehousing emissions coefficient.
- `trsp_emis`: internal-transport emissions coefficient.

## Supplier eligibility tables

The semicolon-separated `supp_list_*.csv` files declare the node and supplier dimensions, then list eligible supplier identifiers for externally supplied nodes.

- `nb_nodes`: BOM node count.
- `nb_suppliers`: configured supplier dimension.
- `id_nodes`: delivered-material node identifier.
- `list_suppliers`: comma-separated eligible supplier identifiers.

## Supplier parameter tables

The semicolon-separated supplier files contain:

- `id_supp`: supplier identifier.
- `delay`: delivery lead time.
- `price`: price multiplier.
- `capacity`: supplier capacity.
- `emissions`: supplier-side emissions coefficient, including inbound delivery within the modeled boundary.
- `quality_score`, `reliability`, `lead_time_variance`: retained supplier attributes. They are present in the raw input table but are not active objectives in the experiments reported by this article.

The standard-capacity table is used for instances with fewer than 25 nodes; the high-capacity table is used for instances with 25 or more nodes.

## Main campaign results

`consolidated_results.csv` and most files in `tabular_results/` share these principal fields:

- `experiment`, `run_id`, `instance_id`, `bom_file`: provenance identifiers.
- `strategy`: `EMISTAXE` (price), `EMISCAP` (cap), or `EMISHYBRID` (combined price and cap).
- `model_type`: PLM or NLM formulation used for the numerical run.
- `service_time_promised`: service-time condition.
- `suppliers_available`: configured number of suppliers.
- `tax_rate`: carbon price in EUR/tCO2.
- `cap_value`, `cap_level`: absolute and relative emissions-cap conditions.
- `objective_value`: solver objective value.
- `total_cost_with_tax`, `total_cost_without_tax`, `procurement_cost`, `inventory_holding_cost`, `carbon_cost`: cost measures in constant 2023 EUR.
- `achieved_service_time`, `service_constraint_binding`: service outcome.
- `total_emissions`, `baseline_emissions`: gCO2.
- `emission_reduction_pct`: reduction relative to the lexicographic baseline.
- `WIP`: work-in-process quantity.
- `DIO`: days inventory outstanding, calculated as the sum of decoupled lead times.
- `ITR`: inventory turnover ratio.
- `buffer_count`, `avg_decoupled_lead_time`, `suppliers_used`: structural decision summaries.
- `solver_status`, `runtime_sec`, `mip_gap`: computational results.
- `comparison_admissible`, `comparison_exclusion_reason`: eligibility for behavioral comparison.

The price-threshold table reports bisection intervals and before/after costs and emissions. Decision-degeneracy tables report Jaccard similarities for buffer and supplier selections plus normalized L1 deviations for allocations.

## Pareto outputs

Pareto CSV files are semicolon-separated and contain `Cost`, `DIO`, `WIP`, `Emissions`, `Epsilon`, and the run `Prefix`. Emissions are in gCO2.

## Raw solver outputs

Raw logs retain the solver termination status, runtime, objective, costs, emissions, and available decision arrays. Common arrays are:

- `A`: decoupled lead times by node.
- `X`: binary buffer-position decisions.
- `Z`: binary supplier-selection decisions.
- `Q`: supplier allocation quantities.

## Focused formulation validation

`plm_validation_results.csv` distinguishes `equivalence` and `stress` rows. Equivalence rows report PLM/NLM status, objectives, absolute objective difference, runtime, NLM gap, and decision-vector agreement. Stress rows report the PLM status, objective, and runtime for larger instances.
