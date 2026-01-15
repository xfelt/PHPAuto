# Multi-Objective Model Quick Start Guide

## Overview

This guide provides step-by-step instructions for running multi-objective optimization scenarios to generate Pareto fronts for Cost-DIO, Cost-WIP, and Cost-Emissions trade-offs.

## Prerequisites

1. IBM ILOG CPLEX Optimization Studio installed
2. PHP 7.4 or later
3. All required data files in the `data/` directory:
   - BOM files: `bom_supemis_X.csv`
   - Supplier list files: `supp_list_X.csv`
   - Supplier details files: `supp_details_supeco.csv` or `supp_details_supeco_grdCapacity.csv`

## Step 1: Configure Test Instances

Edit `config/multiObjConfig.csv` to define your test instances:

```csv
items,suppliers,service_times,model_type,max_capacity,num_pareto_points
5,10,1,PLM,0,10
13,10,1,NLM,0,10
```

**Column Descriptions:**
- `items`: Number of items in the BOM
- `suppliers`: Number of suppliers (10 or 20)
- `service_times`: Service time requirement
- `model_type`: PLM (Pseudo-Linear Model) or NLM (Non-Linear Model)
- `max_capacity`: 0 for default capacity, 1 for high capacity
- `num_pareto_points`: Number of points to generate in each Pareto front (recommended: 10-20)

## Step 2: Run Multi-Objective Optimization

Execute the main script:

```bash
cd src
php MultiObjectiveMain.php
```

## Step 3: Review Results

Results are saved in `logs/multiobj_YYYYMMDD_HHMMSS/` directory:

### Output Files

1. **`{PREFIXE}_ideal_nadir.json`**
   - Ideal and nadir points for all objectives
   - Used to determine epsilon value ranges

2. **`{PREFIXE}_pareto_cost_dio.csv`**
   - Cost-DIO Pareto front
   - Columns: Cost, DIO, WIP, Emissions, Epsilon, Prefix

3. **`{PREFIXE}_pareto_cost_wip.csv`**
   - Cost-WIP Pareto front
   - Columns: Cost, DIO, WIP, Emissions, Epsilon, Prefix

4. **`{PREFIXE}_pareto_cost_emissions.csv`**
   - Cost-Emissions Pareto front
   - Columns: Cost, DIO, WIP, Emissions, Epsilon, Prefix

5. **`{PREFIXE}_summary.json`**
   - Summary of the run
   - Number of points generated for each Pareto front

## Step 4: Analyze Pareto Fronts

### Using Excel or Python

1. Open the CSV files in Excel or import into Python
2. Create scatter plots:
   - X-axis: Cost
   - Y-axis: DIO, WIP, or Emissions (depending on the front)

### Example Python Analysis

```python
import pandas as pd
import matplotlib.pyplot as plt

# Load Pareto front
df = pd.read_csv('pareto_cost_dio.csv', sep=';')

# Plot
plt.figure(figsize=(10, 6))
plt.scatter(df['Cost'], df['DIO'], alpha=0.7)
plt.xlabel('Cost')
plt.ylabel('DIO (Days Inventory Outstanding)')
plt.title('Cost-DIO Pareto Front')
plt.grid(True)
plt.show()
```

## Interpreting Results

### Cost-DIO Pareto Front
- **Left side (low DIO)**: Fast delivery, higher cost
- **Right side (high DIO)**: Slower delivery, lower cost
- **Decision**: Choose based on service level requirements

### Cost-WIP Pareto Front
- **Left side (low WIP)**: Less inventory, higher cost (more frequent orders)
- **Right side (high WIP)**: More inventory, lower cost (bulk orders)
- **Decision**: Choose based on working capital constraints

### Cost-Emissions Pareto Front
- **Left side (low emissions)**: Green suppliers, higher cost
- **Right side (high emissions)**: Cheaper suppliers, more emissions
- **Decision**: Choose based on sustainability goals

## Troubleshooting

### Issue: "No output from CPLEX"
- **Solution**: Check that `oplrun` is accessible. Verify path in `config/settings.php`

### Issue: "BOM file not found"
- **Solution**: Ensure BOM files exist in `data/` directory with correct naming: `bom_supemis_{items}.csv`

### Issue: "Incomplete solution" warnings
- **Solution**: Some epsilon values may be too restrictive. The script will continue with valid solutions.

### Issue: Very few Pareto points generated
- **Solution**: 
  1. Check ideal/nadir points in JSON file
  2. Adjust epsilon ranges if needed
  3. Increase `num_pareto_points` in configuration

## Advanced Usage

### Custom Epsilon Ranges

Modify `MultiObjectiveRunner.php` to use custom epsilon ranges instead of ideal-nadir:

```php
// Instead of using ideal-nadir, use fixed ranges
$dioMin = 50;   // Minimum DIO
$dioMax = 200;  // Maximum DIO
$epsilonValues = self::generateEpsilonValues($dioMin, $dioMax, $numPoints);
```

### Adding More Objectives

To add additional objectives:

1. Update model files to include new objective expression
2. Add epsilon constraint parameter
3. Update `MultiObjectiveRunner.php` to generate new Pareto front
4. Update documentation

## Performance Considerations

- **Computation Time**: Each Pareto front requires solving `num_pareto_points` optimization problems
- **Total Time**: For 3 fronts × 10 points = 30 optimization problems per instance
- **Recommendation**: Start with fewer points (5-10) for testing, then increase for final analysis

## Next Steps

1. Review the comprehensive documentation: `MULTI_OBJECTIVE_MODEL.md`
2. Analyze Pareto fronts to identify preferred solutions
3. Apply decision criteria (budget, service level, sustainability)
4. Select final solution based on trade-offs
