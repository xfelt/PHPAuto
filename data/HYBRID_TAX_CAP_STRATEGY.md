# Hybrid Tax + Cap Emissions Strategy

## Overview

The Hybrid Tax + Cap strategy combines both emissions tax and emissions cap mechanisms to provide a comprehensive approach for evaluating the cost of carbon reduction ($/ton CO2 saved). This hybrid approach allows for simultaneous consideration of:

1. **Emissions Tax**: Applied in the objective function, creating a financial incentive to reduce emissions
2. **Emissions Cap**: Applied as a hard constraint, ensuring emissions do not exceed a specified limit

## Model Implementation

### Strategy Components

#### Emissions Tax (Objective Function)
- **Parameter**: `_EMISTAXE_` (float)
- **Usage**: Multiplied by total emissions to calculate `EmisCost = EmisTax * Emis`
- **Effect**: Included in the objective function: `minimize TotalCostTS + dlts`
  - Where `TotalCostTS = EmisCost + TotalCostCS`
  - `TotalCostCS = RawMCost + InventCost` (costs without tax)

#### Emissions Cap (Constraint)
- **Parameter**: `_EMISCAP_` (int)
- **Usage**: Hard constraint: `Emis <= EmisCap`
- **Effect**: Ensures total emissions do not exceed the specified cap

### Model Files

Two model files support the hybrid strategy:

1. **PLM (CPLEX Linear Programming)**: `RUNS_SupEmis_Cplex_PLM_Hybrid.mod`
2. **NLM (CP Optimizer)**: `RUNS_SupEmis_CP_NLM_Hybrid.mod`

Both models implement:
- Tax cost in the objective function
- Cap constraint on emissions
- Enhanced output including both `TotalCostTS` (with tax) and `TotalCostCS` (without tax) for comparison

## Configuration Format

### baseConfig.csv Format

For hybrid strategy configurations, use the `EMISHYBRID` strategy type with strategy values in the format `tax:cap`:

```csv
items,suppliers,service_times,strategy,model_type,strategy_values,max_capacity
5,10,1,EMISHYBRID,PLM,"0.01:2500000,0.02:2200000",0
5,20,1,EMISHYBRID,NLM,"0.01:2500000,0.015:2300000",1
```

### Strategy Values Format

- **Separator**: Use colon (`:`) to separate tax and cap values within each pair
- **Multiple pairs**: Separate multiple tax:cap pairs with commas
- **Example**: `"0.01:2500000,0.02:2200000"` creates two runs:
  - Run 1: Tax = 0.01, Cap = 2500000
  - Run 2: Tax = 0.02, Cap = 2200000

### Parameter Details

- **Tax value** (`_EMISTAXE_`): Float representing cost per unit of emissions (e.g., 0.01 = $0.01 per unit)
- **Cap value** (`_EMISCAP_`): Integer representing maximum allowed emissions

## Output and Results

### Result Structure

The hybrid model outputs include:

- `#Result <fct_obj, tot_cst, tot_ldt, Emiss>`: Objective value, total cost (with tax), total lead time, emissions
- `#TS`: Total cost including tax (`TotalCostTS`)
- `#CS`: Cost without tax (`TotalCostCS`) - for comparison
- `#TaxCost`: Tax cost component (`EmisCost`)
- `#E`: Total emissions
- `#Cap`: Emissions cap value used
- `#Tax`: Tax rate used

### Cost of Carbon Reduction Calculation

To calculate the cost per ton of CO2 saved:

1. **Baseline emissions**: Run a baseline scenario (e.g., no constraints or high cap)
2. **Hybrid scenario emissions**: Run with hybrid tax + cap
3. **Emissions reduction**: `Baseline_Emissions - Hybrid_Emissions`
4. **Cost increase**: `Hybrid_TotalCostTS - Baseline_TotalCostCS`
5. **Cost per ton**: `Cost_Increase / Emissions_Reduction`

Example:
- Baseline: 2,500,000 units emissions, $1,000,000 cost
- Hybrid (0.01:2200000): 2,200,000 units emissions, $1,050,000 cost
- Reduction: 300,000 units
- Cost increase: $50,000
- Cost per unit saved: $50,000 / 300,000 = $0.167 per unit

## Comparison with Other Strategies

| Strategy | Tax in Objective | Cap Constraint | Use Case |
|----------|------------------|----------------|----------|
| **EMISCAP** | No (default 0.01) | Yes | Hard limit on emissions |
| **EMISTAXE** | Yes | No (default 2500000) | Financial incentive only |
| **EMISHYBRID** | Yes | Yes | Combined approach for comprehensive evaluation |

## Advantages of Hybrid Approach

1. **Dual Mechanism**: Combines financial incentives (tax) with hard limits (cap)
2. **Flexibility**: Allows exploration of different tax/cap combinations
3. **Cost Evaluation**: Enables calculation of cost-effectiveness of emissions reduction
4. **Policy Analysis**: Supports evaluation of different policy combinations

## Example Use Cases

### Scenario 1: Moderate Tax with Strict Cap
```
Strategy: EMISHYBRID
Values: "0.01:2200000"
```
- Low tax rate provides moderate financial incentive
- Strict cap ensures emissions stay below threshold

### Scenario 2: High Tax with Lenient Cap
```
Strategy: EMISHYBRID
Values: "0.05:2500000"
```
- High tax rate creates strong financial incentive
- Lenient cap allows flexibility if tax is effective

### Scenario 3: Progressive Policy
```
Strategy: EMISHYBRID
Values: "0.01:2500000,0.02:2400000,0.03:2300000"
```
- Multiple runs showing progressive tightening of both tax and cap
- Useful for analyzing policy phase-in strategies

## Notes

- The hybrid strategy requires both tax and cap values to be specified
- Tax values are typically in the range 0.01-0.10 (representing cost per emission unit)
- Cap values depend on the scale of your supply chain (typically 1,000,000 - 5,000,000+)
- Results include both cost components for detailed analysis
