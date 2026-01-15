# Multi-Objective Model Implementation Summary

## Implementation Date
2025

## Overview

A comprehensive multi-objective optimization framework has been implemented to generate Pareto fronts for supplier selection scenarios. The system optimizes four key performance indicators (KPIs): **Cost**, **DIO (Days Inventory Outstanding)**, **WIP (Work In Process)**, and **Emissions**.

## Files Created

### Model Files
1. **`models/RUNS_SupEmis_MultiObj_PLM.mod`**
   - Pseudo-Linear Model (PLM) version
   - Uses linearization variables `y[i] = a[i] × x[i]`
   - Compatible with CPLEX solver

2. **`models/RUNS_SupEmis_MultiObj_NLM.mod`**
   - Non-Linear Model (NLM) version
   - Uses CP Optimizer
   - Handles non-linear expressions directly

### PHP Scripts
1. **`src/MultiObjectiveRunner.php`**
   - Core class for multi-objective optimization
   - Methods:
     - `findIdealNadirPoints()`: Finds ideal and nadir points for all objectives
     - `generateCostDIOPareto()`: Generates Cost-DIO Pareto front
     - `generateCostWIPPareto()`: Generates Cost-WIP Pareto front
     - `generateCostEmissionsPareto()`: Generates Cost-Emissions Pareto front
     - `exportParetoToCSV()`: Exports Pareto fronts to CSV format

2. **`src/MultiObjectiveMain.php`**
   - Main execution script
   - Reads configuration from `config/multiObjConfig.csv`
   - Orchestrates Pareto front generation
   - Saves results to timestamped log directory

### Configuration Files
1. **`config/multiObjConfig.csv`**
   - Test instance configuration
   - Defines items, suppliers, model types, and number of Pareto points

### Documentation Files
1. **`data/MULTI_OBJECTIVE_MODEL.md`**
   - Comprehensive model documentation
   - Explains objectives, constraints, and solution method
   - Describes differences from base model

2. **`data/MULTI_OBJECTIVE_QUICKSTART.md`**
   - Quick start guide
   - Step-by-step instructions
   - Troubleshooting tips

3. **`data/MULTI_OBJECTIVE_IMPLEMENTATION_SUMMARY.md`**
   - This file
   - Implementation overview

## Key Features

### 1. Multi-Objective Optimization
- Optimizes four KPIs simultaneously: Cost, DIO, WIP, Emissions
- Uses epsilon-constraint method for Pareto front generation
- Flexible primary objective selection

### 2. Pareto Front Generation
Generates three Pareto fronts:
- **Cost-DIO**: Trade-off between cost and inventory days
- **Cost-WIP**: Trade-off between cost and inventory value
- **Cost-Emissions**: Trade-off between cost and environmental impact

### 3. Automatic Ideal/Nadir Point Detection
- Automatically finds ideal (best) and nadir (worst) points
- Uses these to determine appropriate epsilon ranges
- Ensures Pareto fronts cover meaningful trade-off regions

### 4. Comprehensive Output
- JSON files for ideal/nadir points and summaries
- CSV files for each Pareto front
- Easy to import into analysis tools (Excel, Python, R)

## Model Differences from Base Model

### Base Model
- Single objective: `minimize TotalCostCS + dlts`
- Fixed trade-off between cost and lead times
- No explicit WIP calculation
- No Pareto front generation

### Multi-Objective Model
- Multiple objectives: Cost, DIO, WIP, Emissions
- Explicit WIP calculation based on inventory value
- Epsilon-constraint method for Pareto front generation
- Flexible analysis of trade-offs

## Usage Workflow

1. **Configure**: Edit `config/multiObjConfig.csv`
2. **Run**: Execute `php src/MultiObjectiveMain.php`
3. **Analyze**: Review Pareto fronts in `logs/multiobj_*/`
4. **Decide**: Select solution based on trade-offs

## Test Instances Prepared

The configuration file includes test instances for:
- Small instances: 5 items, 10 suppliers
- Medium instances: 13 items, 10 suppliers
- Both PLM and NLM model types

## Technical Details

### Epsilon-Constraint Method
1. Select primary objective (e.g., minimize Cost)
2. Constrain other objectives: `DIO ≤ epsilon_DIO`, `WIP ≤ epsilon_WIP`, `Emis ≤ epsilon_Emis`
3. Vary epsilon values from ideal to nadir
4. Solve optimization problem for each epsilon
5. Collect Pareto-optimal solutions

### WIP Calculation
- **PLM**: `WIP = Σ(i) [unit_price[i] × (1+Σ(j)(z[i][j]×su[i][j]×sup[j][2])) × rqtf[i] × y[i] × adup]`
- **NLM**: `WIP = Σ(i) [unit_price[i] × (1+Σ(j)(z[i][j]×su[i][j]×sup[j][2])) × rqtf[i] × a[i] × x[i] × adup]`

### DIO Calculation
- `DIO = Σ(i in N) a[i]` (sum of decoupled lead times)

## Performance Considerations

- **Computation Time**: Each Pareto front requires solving multiple optimization problems
- **Typical Run**: 3 fronts × 10 points = 30 optimization problems per instance
- **Recommendation**: Start with 5-10 points for testing, increase for final analysis

## Future Enhancements

Potential improvements:
1. Three-objective Pareto surfaces (e.g., Cost-DIO-WIP)
2. Interactive methods for decision maker preference elicitation
3. Automatic epsilon range refinement
4. Visualization tools for Pareto fronts
5. Integration with decision support systems

## Validation

The implementation:
- ✅ Follows the same structure as base models
- ✅ Maintains compatibility with existing data files
- ✅ Uses standard CPLEX/CP Optimizer syntax
- ✅ Provides comprehensive error handling
- ✅ Generates well-formatted output files

## Dependencies

- IBM ILOG CPLEX Optimization Studio
- PHP 7.4+
- Existing data files (BOM, supplier lists, supplier details)

## Contact

For questions or issues, refer to:
- `MULTI_OBJECTIVE_MODEL.md` for detailed model documentation
- `MULTI_OBJECTIVE_QUICKSTART.md` for usage instructions
