# Structural Baseline Benchmark Summary

Generated: 2026-01-16 14:40:49

## Configuration

- Model: RUNS_SupEmis_Cplex_PLM_Tax.mod
- Carbon Tax: 0 (baseline emissions)
- Emission Cap: None (inactive)
- Time Limit: 1800 seconds (30 minutes)
- Service Time: 1
- Suppliers: 10

## Key Findings

### Multi-Level Structural BOMs (ML)

- **Depth M=4:** Average emissions = 17,026,391.80 (range: 13,603,260.00 - 20,449,523.60)
- **Depth M=5:** Average emissions = 25,832,748.60 (range: 21,728,460.00 - 29,937,037.20)
- **Depth M=6:** Average emissions = 32,567,175.00 (range: 32,567,175.00 - 32,567,175.00)

### Realistic Topology BOMs (PAR)

- **Emission Statistics:**
  - Average: 12,314,837.00
  - Range: 5,939,600.00 - 18,894,145.00
  - Std Dev: 5,379,474.81

### Buffer Positioning Analysis

- **Average number of buffers:** 7.60
- **Buffer count range:** 3 - 18

### Infeasibility Patterns

- **No infeasible instances**

## Interpretation

All emissions computed here represent baseline emissions (E₀):
- E₀(M, N) for multi-level structural cases
- E₀(i) for realistic topology cases

These values serve as reference points for:
- Emission cap tightening scenarios
- Hybrid tax + cap scenarios
- Marginal abatement cost analysis

**Key Insight:** Before introducing any environmental regulation, emission levels are primarily driven by BOM topology and depth, even under identical cost-optimal sourcing rules.
