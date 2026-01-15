# Multi-Objective Optimization Model Documentation

## Overview

This document describes the multi-objective optimization model for supplier selection with emissions constraints. The model extends the base single-objective models to simultaneously optimize multiple Key Performance Indicators (KPIs): **Cost**, **DIO (Days Inventory Outstanding)**, **WIP (Work In Process)**, and **Emissions**.

## Objectives

### 1. Cost (TotalCostCS)
The total cost includes:
- **RawMCost**: Raw material costs from suppliers
  ```
  RawMCost = Σ(i in N) [unit_price[i] × Σ(j in S) (q[i][j] × sup[j][2])]
  ```
- **InventCost**: Inventory holding costs
  - For PLM: `InventCost = adup × Σ(i in N) [aih_cost[i] × (1.5+var_factor[i]) × lt_factor[i] × unit_price[i] × (1+Σ(j in S)(z[i][j]×su[i][j]×sup[j][2])) × rqtf[i] × y[i]]`
  - For NLM: `InventCost = adup × Σ(i in N) [aih_cost[i] × (1.5+var_factor[i]) × lt_factor[i] × unit_price[i] × (1+Σ(j in S)(z[i][j]×su[i][j]×sup[j][2])) × rqtf[i] × a[i] × x[i]]`

**TotalCostCS = RawMCost + InventCost**

### 2. DIO (Days Inventory Outstanding)
DIO represents the total time inventory is held across all nodes in the supply chain. It is calculated as the sum of decoupled lead times:
```
DIO = Σ(i in N) a[i]
```
where `a[i]` is the decoupled lead time for node `i`.

**Lower DIO** indicates faster inventory turnover and reduced working capital requirements.

### 3. WIP (Work In Process)
WIP represents the total value of inventory held in the system. It is calculated as:
- For PLM: `WIP = Σ(i in N) [unit_price[i] × (1+Σ(j in S)(z[i][j]×su[i][j]×sup[j][2])) × rqtf[i] × y[i] × adup]`
- For NLM: `WIP = Σ(i in N) [unit_price[i] × (1+Σ(j in S)(z[i][j]×su[i][j]×sup[j][2])) × rqtf[i] × a[i] × x[i] × adup]`

**Lower WIP** indicates reduced inventory investment and improved cash flow.

### 4. Emissions
Total emissions include:
- **Emis_supp**: Emissions from suppliers and transportation
  ```
  Emis_supp = Σ(i in N) [Σ(j in S) (q[i][j] × sup[j][4])]
  ```
- **Emis_facility**: Facility emissions when buffers are active
- **Emis_inventory**: Inventory-related emissions
- **Emis_transport**: Transportation emissions

**Total Emissions = Emis_supp + Emis_facility + Emis_inventory + Emis_transport**

## Model Formulation

### Decision Variables
- `x[i]`: Binary variable indicating if buffer is ON/OFF at node `i`
- `a[i]`: Integer variable for decoupled lead time at node `i`
- `y[i]`: Integer variable for linearization (PLM only): `y[i] = a[i] × x[i]`
- `z[i][j]`: Binary variable indicating if supplier `j` is chosen for node `i`
- `q[i][j]`: Integer variable for order quantity from supplier `j` for node `i`

### Constraints

1. **Lead Time Constraints**: Ensure proper sequencing and supplier lead times
2. **Service Time Constraint**: `a[0] ≤ service_t`
3. **Buffer Linearization** (PLM only): `y[i] = a[i] × x[i]`
4. **Supplier Selection**: At least one supplier must be selected for each leaf node
5. **Demand Satisfaction**: `Σ(j in S) q[i][j] = adup × rqtf[i]` for leaf nodes
6. **Emissions Cap**: `Emis ≤ EmisCap`
7. **Capacity Constraints**: `q[i][j] ≤ z[i][j] × sup[j][3]`
8. **Epsilon Constraints** (Multi-objective):
   - `DIO ≤ epsilon_DIO`
   - `WIP ≤ epsilon_WIP`
   - `Emis ≤ epsilon_Emis`

### Objective Function

The model uses the **epsilon-constraint method** for Pareto front generation:

1. **Primary Objective**: Minimize one of the four objectives (Cost, DIO, WIP, or Emissions)
2. **Epsilon Constraints**: Constrain the other objectives to be ≤ epsilon values

```
Minimize: PrimaryObj
Subject to:
  DIO ≤ epsilon_DIO
  WIP ≤ epsilon_WIP
  Emis ≤ epsilon_Emis
  [All other constraints]
```

## Differences from Base Model

### Base Model
- **Single Objective**: Minimizes `TotalCostCS + dlts` (weighted sum)
- **No explicit DIO/WIP tracking**: DIO (`dlts`) is included in objective but not separately tracked
- **No WIP calculation**: WIP is not explicitly calculated or optimized
- **Fixed trade-off**: Uses a fixed weight between cost and lead times

### Multi-Objective Model
- **Multiple Objectives**: Explicitly optimizes Cost, DIO, WIP, and Emissions separately
- **Pareto Front Generation**: Uses epsilon-constraint method to generate trade-off curves
- **Explicit KPI Tracking**: All KPIs are calculated and tracked independently
- **Flexible Analysis**: Enables analysis of trade-offs between different objectives

### Key Additions

1. **WIP Calculation**: Added explicit WIP calculation based on inventory value
2. **Epsilon Constraints**: Added constraints to control secondary objectives
3. **Primary Objective Selection**: Parameter `obj_primary` allows selecting which objective to minimize
4. **Extended Output**: Results include all four KPIs (Cost, DIO, WIP, Emissions)

## Pareto Front Generation

The model generates three Pareto fronts:

### 1. Cost-DIO Pareto Front
- **Primary Objective**: Minimize Cost
- **Constraint**: `DIO ≤ epsilon_DIO` (varied)
- **Result**: Trade-off curve showing how cost increases as DIO decreases

### 2. Cost-WIP Pareto Front
- **Primary Objective**: Minimize Cost
- **Constraint**: `WIP ≤ epsilon_WIP` (varied)
- **Result**: Trade-off curve showing how cost increases as WIP decreases

### 3. Cost-Emissions Pareto Front
- **Primary Objective**: Minimize Cost
- **Constraint**: `Emis ≤ epsilon_Emis` (varied)
- **Result**: Trade-off curve showing how cost increases as emissions decrease

## Solution Method

### Epsilon-Constraint Method

1. **Step 1: Find Ideal and Nadir Points**
   - Optimize each objective individually to find:
     - **Ideal Point**: Best value for each objective
     - **Nadir Point**: Worst value when optimizing other objectives

2. **Step 2: Generate Epsilon Values**
   - Create a range from ideal to nadir (with margin)
   - Generate `num_pareto_points` evenly spaced epsilon values

3. **Step 3: Solve for Each Epsilon**
   - For each epsilon value:
     - Set primary objective (e.g., minimize Cost)
     - Set epsilon constraints for other objectives
     - Solve the optimization problem
     - Record the solution point

4. **Step 4: Collect Pareto Points**
   - Filter dominated solutions
   - Export Pareto front to CSV

## Usage

### Configuration File

Create a CSV file `config/multiObjConfig.csv` with the following columns:
- `items`: Number of items in BOM
- `suppliers`: Number of suppliers (10 or 20)
- `service_times`: Service time requirement
- `model_type`: PLM or NLM
- `max_capacity`: 0 for default capacity, 1 for high capacity
- `num_pareto_points`: Number of points in Pareto front (e.g., 10)

### Running the Model

```bash
php src/MultiObjectiveMain.php
```

### Output Files

For each run, the following files are generated in the logs directory:

1. `{PREFIXE}_ideal_nadir.json`: Ideal and nadir points for all objectives
2. `{PREFIXE}_pareto_cost_dio.csv`: Cost-DIO Pareto front
3. `{PREFIXE}_pareto_cost_wip.csv`: Cost-WIP Pareto front
4. `{PREFIXE}_pareto_cost_emissions.csv`: Cost-Emissions Pareto front
5. `{PREFIXE}_summary.json`: Summary of results

### CSV Format

Each Pareto front CSV contains:
- `Cost`: Total cost (TotalCostCS)
- `DIO`: Days Inventory Outstanding
- `WIP`: Work In Process (inventory value)
- `Emissions`: Total emissions
- `Epsilon`: Epsilon value used for constraint
- `Prefix`: Run identifier

## Interpretation

### Pareto Front Analysis

1. **Cost-DIO Trade-off**:
   - Points on the left (low DIO) have higher cost (faster delivery, more expensive)
   - Points on the right (high DIO) have lower cost (slower delivery, cheaper)
   - Decision makers can choose the balance based on service level requirements

2. **Cost-WIP Trade-off**:
   - Points on the left (low WIP) have higher cost (less inventory, more frequent orders)
   - Points on the right (high WIP) have lower cost (more inventory, bulk orders)
   - Decision makers can choose based on working capital constraints

3. **Cost-Emissions Trade-off**:
   - Points on the left (low emissions) have higher cost (green suppliers, more expensive)
   - Points on the right (high emissions) have lower cost (cheaper suppliers, more emissions)
   - Decision makers can choose based on sustainability goals

### Selecting Solutions

1. **Identify Non-Dominated Solutions**: Solutions where no other solution is better in all objectives
2. **Apply Decision Criteria**: 
   - Budget constraints → filter by maximum cost
   - Service level requirements → filter by maximum DIO
   - Working capital limits → filter by maximum WIP
   - Sustainability targets → filter by maximum emissions
3. **Choose Final Solution**: Select the solution that best balances all criteria

## Model Files

- **PLM Version**: `models/RUNS_SupEmis_MultiObj_PLM.mod`
- **NLM Version**: `models/RUNS_SupEmis_MultiObj_NLM.mod`

## Limitations and Future Work

1. **Computational Complexity**: Generating Pareto fronts requires solving multiple optimization problems
2. **Epsilon Selection**: The method assumes appropriate epsilon ranges; may need adjustment for different problem instances
3. **Three-Objective Pareto Fronts**: Currently generates two-objective fronts; could be extended to three-objective surfaces
4. **Interactive Methods**: Could implement interactive methods for decision maker preference elicitation

## References

- Epsilon-constraint method for multi-objective optimization
- Pareto optimality in supply chain optimization
- KPI-based decision making in operations management
