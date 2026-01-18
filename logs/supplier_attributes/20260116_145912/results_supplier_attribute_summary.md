# Supplier Attribute Sensitivity Analysis Summary

Generated: 2026-01-16 14:59:15

## Overview

This analysis evaluates the impact of enriching supplier characterization
beyond cost and emissions on sourcing decisions and performance metrics.

## Variants Tested

- **Variant A (Baseline)**: Cost and emissions only
- **Variant B (Enriched Neutral)**: Additional attributes read but not used in objective
- **Variant C1 (Low Penalty)**: Enriched attributes with low penalty (α=0.1)
- **Variant C2 (Moderate Penalty)**: Enriched attributes with moderate penalty (α=0.5)

## BOM: 13

### Baseline (Variant A)

- Total Cost: 116448
- Total Emissions: 29495946
- Suppliers Used: 8
- Concentration Index: 0.53333333333333

### Comparison Across Variants

| Variant | Total Cost | Δ Cost vs Baseline | Total Emissions | Δ Emissions vs Baseline | Suppliers Used | Concentration | Avg Quality | Avg Reliability |
|---------|------------|---------------------|-----------------|------------------------|----------------|---------------|-------------|-----------------|
| A | 116448 | N/A | 29495946 | N/A | 8 | 0.533 | N/A | N/A |
| B | 116448 | 0.00 | 29495946 | 0.00 | 8 | 0.533 | 0.789 | 0.857 |
| C1 (C1) | 117367.29 | 919.29 | 29496546 | 600.00 | 8 | 0.500 | 0.794 | 0.862 |
| C2 (C2) | 121044.45 | 4596.45 | 29496546 | 600.00 | 8 | 0.533 | 0.789 | 0.857 |

### Key Findings

- **Data enrichment (Variant B) does not materially change supplier selection**
- **Penalty intensity significantly affects cost**: C2 increases cost by 4596.45 vs C1's 919.29
- **Emissions show variation across variants**

## BOM: 50

### Baseline (Variant A)

- Total Cost: N/A
- Total Emissions: N/A
- Suppliers Used: 0
- Concentration Index: N/A

### Comparison Across Variants

| Variant | Total Cost | Δ Cost vs Baseline | Total Emissions | Δ Emissions vs Baseline | Suppliers Used | Concentration | Avg Quality | Avg Reliability |
|---------|------------|---------------------|-----------------|------------------------|----------------|---------------|-------------|-----------------|
| A | N/A | N/A | N/A | N/A | 0 | N/A | N/A | N/A |
| B | N/A | N/A | N/A | N/A | 0 | N/A | N/A | N/A |
| C1 (C1) | N/A | N/A | N/A | N/A | 0 | N/A | N/A | N/A |
| C2 (C2) | N/A | N/A | N/A | N/A | 0 | N/A | N/A | N/A |

### Key Findings

- **Data enrichment (Variant B) does not materially change supplier selection**
- **Baseline emissions remain relatively stable across variants**

## Overall Conclusions

1. **Model Robustness**: Variant B tests whether data structure extension alone distorts results.
2. **Behavioral Sensitivity**: Variants C1 and C2 test how penalty-based preferences affect decisions.
3. **Emission Stability**: Baseline emissions should remain stable until explicit penalties are introduced.
4. **Decision Impact**: Attribute enrichment alters sourcing structure and resilience properties.

