# Final Comprehensive Test Campaign Plan

- Generated at: 2026-06-05 06:13:05
- Solver time limit: 300s per run
- This plan is generated before solver execution.

## Planned Counts

- scalability (enabled): 23 reported rows, 23 solver calls (native staticLex cost/emissions baseline)
- topology_baseline (enabled): 10 reported rows, 10 solver calls (native staticLex cost/emissions baseline)
- carbon_tax_sweep (enabled): 30 reported rows, 30 solver calls
- carbon_cap_sweep (enabled): 42 reported rows, 42 solver calls
- carbon_hybrid (enabled): 160 reported rows, 160 solver calls
- service_time_sensitivity (enabled): 24 reported rows, 24 solver calls
- multi_objective (enabled): 60 reported rows, 72 solver calls (Pareto CSV points; WIP front skipped)
- nlm_comparison (enabled): 12 reported rows, 12 solver calls
- decision_stability (enabled): 108 reported rows, 108 solver calls (maximum; only proven-optimal anchors are probed)

## Totals

- Consolidated reported rows: 301
- Solver calls counted by campaign runner: 409
- Additional multi-objective solver calls: 72
- Maximum conditional decision-degeneracy probe calls: 108
- Maximum total solver calls: 481

## Baseline Coverage

- Baseline-producing instances planned: bom_2, bom_3, bom_4, bom_5, bom_6, bom_7, bom_8, bom_10, bom_13, bom_15, bom_20, bom_25, bom_26, bom_30, bom_35, bom_40, bom_50, bom_60, bom_80, bom_90, bom_100, bom_123, bom_150, bom_ml4_30, bom_ml4_55, bom_ml5_45, bom_ml5_65, bom_ml6_70, bom_par2, bom_par3, bom_par4, bom_par5, bom_par6
- Baseline prerequisites: satisfied by planned scalability/topology phases
