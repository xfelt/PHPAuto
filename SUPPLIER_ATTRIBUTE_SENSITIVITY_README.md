# Supplier Attribute Sensitivity Benchmark

## Overview

This benchmark evaluates how enriching supplier characterization beyond cost and emissions (e.g., quality, reliability, lead-time variability proxies) affects:
- Supplier selection
- Sourcing concentration/diversification
- Total cost
- Baseline emissions stability

Under a cost-dominant optimization framework, before introducing explicit multi-objective or policy constraints.

## Execution

Run the benchmark using:

```bash
php src/SupplierAttributeSensitivityBenchmark.php
```

## Configuration

The benchmark is configured with:
- **Model**: `RUNS_SupEmis_Cplex_PLM_Tax.mod` (with variants)
- **Carbon tax**: 0 (baseline emissions)
- **Emission cap**: None (inactive)
- **Time limit**: 1800 seconds (30 minutes) per run
- **Selected BOMs**: 
  - `bom_supemis_13.csv` (medium-size)
  - `bom_supemis_50.csv` (larger, optional robustness)

## Variants Tested

### Variant A: Baseline
- Uses only: unit cost, emission factor, capacity, nominal lead time
- This is the reference case

### Variant B: Enriched Attributes (Neutral Weight)
- Additional attributes: `quality_score`, `reliability_score`, `lead_time_variability_index`
- Attributes are read and stored but **not used in the objective**
- Purpose: Verify that extending the data structure alone does not distort results

### Variant C1: Soft Attribute Influence (Low Penalty)
- Same enriched attributes as Variant B
- Introduces soft penalties: `effective_cost = base_cost + α₁·(1 − quality_score) + α₁·(1 − reliability)`
- Penalty level: α₁ = 0.1

### Variant C2: Soft Attribute Influence (Moderate Penalty)
- Same enriched attributes as Variant B
- Introduces soft penalties with higher intensity
- Penalty level: α₂ = 0.5

## Output Files

The benchmark generates output in `logs/supplier_attributes/<timestamp>/`:

1. **results_supplier_attribute_sensitivity.csv**
   - Comprehensive results table with all KPIs
   - Columns: bom_id, supplier_variant, penalty_level, total_cost, total_emissions, suppliers_used, avg_quality, avg_reliability, supplier_concentration_index, delta_cost_vs_baseline, delta_emissions_vs_baseline, supplier_switch_rate, emissions_per_unit_cost, runtime_sec

2. **results_supplier_attribute_summary.md**
   - Markdown summary report with:
     - Overview of variants tested
     - Comparison tables across variants
     - Key findings per BOM
     - Overall conclusions

3. **run_<bom>_<variant>.log**
   - Detailed CPLEX output for each run
   - Contains full solution details, supplier selections, etc.

## KPIs Extracted

### Decision KPIs
- `number_of_suppliers_selected`: Count of unique suppliers used
- `supplier_concentration_index`: Top-3 supplier share (0-1)
- `average_quality_score`: Weighted by flow volume
- `average_reliability_score`: Weighted by flow volume

### Cost & Inventory KPIs
- `total_cost`: Total system cost
- `procurement_cost`: Raw material procurement cost
- `inventory_cost`: Inventory holding cost (if separable)

### Sustainability KPIs
- `total_CO2_emissions`: Total carbon emissions
- `emissions_per_unit_cost`: Emissions efficiency metric

### Stability KPIs
- `delta_cost_vs_baseline`: Cost change vs Variant A
- `delta_emissions_vs_baseline`: Emissions change vs Variant A
- `supplier_switch_rate`: Fraction of products that switched suppliers vs baseline

## Interpretation Rules

1. **Variant A (Baseline)**: Treat as the structural-economic baseline
2. **Variant B (Enriched Neutral)**: Model robustness check (data enrichment only)
3. **Variant C (Penalty-Based)**: Behavioral sensitivity test, not a policy scenario
   - Do not interpret attribute penalties as regulations
   - They are proxies for operational preferences

## Expected Contribution

This scenario allows you to argue that:
> "Extending supplier characterization beyond price and emissions alters sourcing structure and resilience properties, while leaving baseline emissions relatively stable until explicit preference penalties are introduced."

This positions the scenario as:
- A bridge between pure optimization and realistic decision-making
- A justification for later multi-criteria or ESG-aware extensions
- A robustness check against model oversimplification

## Model Modifications

The benchmark automatically modifies the CPLEX model file for variants B, C1, and C2:

1. **Extended supplier data structure**: `sup[S][1..4]` → `sup[S][1..7]`
2. **Additional attribute reading**: Reads columns 5-7 from supplier details CSV
3. **Penalty terms (C1/C2 only)**: Adds quality and reliability penalty expressions to the objective

## Notes

- The benchmark uses the existing `supp_details_supeco.csv` file which already contains the enriched attributes
- Each run may take up to 30 minutes (time limit), so the full benchmark may take several hours
- Results are automatically aggregated and summarized in the output files
