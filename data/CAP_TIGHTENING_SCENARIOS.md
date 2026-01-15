# Cap Tightening Scenarios for Compliance Pathway Cost Projections

## Overview

The cap tightening scenarios feature enables systematic evaluation of compliance pathway cost projections by generating and analyzing multiple optimization runs with progressively tighter emissions caps. This allows organizations to understand how costs change as carbon emissions compliance requirements become stricter over time.

## Purpose

This feature addresses the need identified in test case expansion plan item #12:
- **Current State**: Cap limit strategy with customized ECap for every BOM instance
- **Proposed Expansion**: Progressive cap tightening scenarios
- **Expected Results**: Compliance pathway cost projections

## Key Features

1. **Automatic Scenario Generation**: Creates multiple scenarios with progressively tighter caps
2. **Multiple Generation Methods**: Supports percentage-based, fixed-step, and custom reduction patterns
3. **Cost Projection Analysis**: Analyzes how costs change as caps tighten
4. **Comprehensive Reporting**: Generates detailed reports and CSV exports for further analysis

## Configuration

### Enabling Cap Tightening Scenarios

Edit `config/capTighteningConfig.php` to enable the feature:

```php
return [
    'enabled' => true,  // Set to true to enable cap tightening
    'method' => 'progressive',  // Choose generation method
    // ... other configuration
];
```

### Generation Methods

#### 1. Progressive Method (Percentage-Based)

Reduces the cap by a fixed percentage each step:

```php
'progressive' => [
    'num_scenarios' => 5,           // Generate 5 scenarios
    'reduction_percentage' => 0.05,  // 5% reduction per scenario
    'min_cap' => null                // Optional minimum cap
]
```

**Example**: Starting with cap 2,500,000:
- Scenario 1: 2,500,000 (base)
- Scenario 2: 2,375,000 (5% reduction)
- Scenario 3: 2,256,250 (5% reduction)
- Scenario 4: 2,143,438 (5% reduction)
- Scenario 5: 2,036,266 (5% reduction)

#### 2. Fixed Step Method

Reduces the cap by a fixed amount each step:

```php
'fixed_step' => [
    'num_scenarios' => 5,           // Generate 5 scenarios
    'step_size' => 100000,          // Reduce by 100,000 each step
    'min_cap' => null                // Optional minimum cap
]
```

**Example**: Starting with cap 2,500,000:
- Scenario 1: 2,500,000
- Scenario 2: 2,400,000
- Scenario 3: 2,300,000
- Scenario 4: 2,200,000
- Scenario 5: 2,100,000

#### 3. Custom Method

Uses custom reduction percentages:

```php
'custom' => [
    'reduction_percentages' => [
        0.0,    // Base scenario (0% reduction)
        0.05,   // 5% reduction
        0.10,   // 10% reduction
        0.15,   // 15% reduction
        0.20    // 20% reduction
    ]
]
```

**Example**: Starting with cap 2,500,000:
- Scenario 1: 2,500,000 (0% reduction)
- Scenario 2: 2,375,000 (5% reduction)
- Scenario 3: 2,250,000 (10% reduction)
- Scenario 4: 2,125,000 (15% reduction)
- Scenario 5: 2,000,000 (20% reduction)

## Usage

### Basic Usage

1. **Configure Cap Tightening**: Edit `config/capTighteningConfig.php` and set `enabled` to `true`

2. **Run Main Script**: Execute the main script as usual:
   ```bash
   php src/Main.php
   ```

3. **Review Results**: The script will:
   - Automatically expand EMISCAP strategy rows with cap tightening scenarios
   - Run all scenarios
   - Generate analysis reports in the timestamped log folder

### Output Files

For each group of cap tightening scenarios, the following files are generated:

1. **Text Report** (`compliance_pathway_[group].txt`):
   - Summary statistics
   - Cost projection analysis
   - Emissions analysis
   - Detailed scenario breakdown

2. **CSV Export** (`compliance_pathway_[group].csv`):
   - Machine-readable format for further analysis
   - Includes all scenario metrics

## Analysis Output

### Summary Statistics

- Total scenarios analyzed
- Cap range (loosest to tightest)
- Cost range (minimum to maximum)
- Emissions range

### Cost Projection Analysis

- **Base Cap vs Tightest Cap**: Comparison of starting and ending caps
- **Total Cost Increase**: Absolute and percentage increase from base scenario
- **Average Marginal Cost**: Average cost per ton CO2 reduced between scenarios
- **Cost Elasticity**: Percentage cost increase per percentage cap reduction

### Emissions Analysis

- **Base Emissions vs Tightest Emissions**: Comparison of emissions levels
- **Total Emissions Reduction**: Absolute and percentage reduction
- **Average Cost per Ton Reduced**: Overall cost efficiency of emissions reduction

### Detailed Scenarios Table

For each scenario:
- Cap value
- Total cost
- Emissions level
- Cost increase (absolute and percentage)
- Emissions reduction (absolute and percentage)
- Marginal cost per ton CO2 (for transitions)

## Example Analysis Report

```
======================================================================
COMPLIANCE PATHWAY COST PROJECTION ANALYSIS
======================================================================

SUMMARY
----------------------------------------------------------------------
Total Scenarios Analyzed: 5
Cap Range: 2,500,000 to 2,036,266
Cost Range: 51,555.00 to 52,340.00

COST PROJECTION
----------------------------------------------------------------------
Base Cap: 2,500,000
Tightest Cap: 2,036,266
Base Cost: 51,555.00
Tightest Cost: 52,340.00
Total Cost Increase: 785.00 (1.52%)
Average Marginal Cost per Ton CO2: 1.65

EMISSIONS ANALYSIS
----------------------------------------------------------------------
Base Emissions: 2,253,700.00
Tightest Emissions: 2,036,266.00
Total Emissions Reduction: 217,434.00 (9.65%)
Average Cost per Ton CO2 Reduced: 0.36

DETAILED SCENARIOS
----------------------------------------------------------------------
Scenario   Cap              Total Cost      Emissions       Cost Increase %
1          2,500,000        51,555.00       2,253,700.00    0.00
2          2,375,000        51,890.00       2,240,200.00    0.65
3          2,256,250        52,120.00       2,220,100.00    1.10
4          2,143,438        52,280.00       2,100,500.00    1.41
5          2,036,266        52,340.00       2,036,266.00    1.52
```

## Integration with Existing Workflow

The cap tightening feature integrates seamlessly with the existing workflow:

1. **Base Configuration**: Uses `baseConfig.csv` as before
2. **Automatic Expansion**: EMISCAP strategy rows are automatically expanded
3. **Model Execution**: Each scenario runs through the normal CPLEX execution pipeline
4. **Result Logging**: Individual results are logged as usual
5. **Post-Processing**: Analysis is performed after all runs complete

## Customization

### Per-BOM Customization

The feature respects the existing BOM instance structure. Each BOM instance (defined by items, suppliers, service_times, model_type) gets its own set of cap tightening scenarios.

### Scenario Grouping

Results are automatically grouped by:
- Number of items
- Number of suppliers
- Service times
- Model type (PLM/NLM)

This allows for comparative analysis across different BOM configurations.

## Best Practices

1. **Start with Base Cap**: Use a cap value that represents current or near-term compliance requirements
2. **Choose Appropriate Reduction**: 
   - For short-term projections (1-2 years): Use smaller reductions (2-5%)
   - For long-term projections (5-10 years): Use larger reductions (10-20%)
3. **Set Minimum Cap**: Use `min_cap` to prevent scenarios from becoming infeasible
4. **Review Marginal Costs**: Pay attention to scenarios where marginal costs spike significantly
5. **Compare Across BOMs**: Use the grouping feature to compare cost projections across different BOM structures

## Technical Details

### Classes

- **CapTighteningScenarioGenerator**: Generates cap scenarios using various methods
- **CompliancePathwayAnalyzer**: Analyzes results and generates reports

### File Locations

- Configuration: `config/capTighteningConfig.php`
- Generator: `src/CapTighteningScenarioGenerator.php`
- Analyzer: `src/CompliancePathwayAnalyzer.php`
- Reports: `logs/[timestamp]/compliance_pathway_*.txt` and `*.csv`

## Troubleshooting

### No Scenarios Generated

- Check that `enabled` is set to `true` in `capTighteningConfig.php`
- Verify that baseConfig.csv contains EMISCAP strategy rows
- Check that the method configuration is correct

### Infeasible Scenarios

- Some scenarios may become infeasible if caps are too tight
- Set a `min_cap` value to prevent this
- Review error logs for specific infeasibility messages

### Missing Analysis Reports

- Ensure that `generate_report` is set to `true` in analysis configuration
- Check that at least one EMISCAP run completed successfully
- Verify file write permissions in the logs directory

## Future Enhancements

Potential future improvements:
- Visualization of cost projection curves
- Sensitivity analysis for reduction percentages
- Integration with external compliance databases
- Multi-objective optimization considering cost and emissions trade-offs
- Scenario comparison across different BOM structures

## References

- Test Case Expansion Plan: Item #12 - Carbon Strategy Scenarios
- Base Configuration: `config/baseConfig.csv`
- Main Script: `src/Main.php`
