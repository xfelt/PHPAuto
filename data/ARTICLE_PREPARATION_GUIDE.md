# Scientific Article Preparation Guide

This guide explains how to use the results aggregation and analysis tools to prepare data for your scientific article.

## Overview

After running all your tests and collecting KPIs, you can use the automated tools to:
1. Aggregate all test results from different scenarios
2. Generate statistical summaries and analyses
3. Create publication-ready tables (LaTeX format)
4. Export data for visualization and further analysis

## Quick Start

### Step 1: Run the Article Data Generator

```bash
cd src
php ArticleDataGenerator.php
```

This will:
- Scan all log directories for test results
- Aggregate scalability, multi-objective, and compliance pathway results
- Generate publication-ready summaries and tables
- Export data in multiple formats (CSV, JSON, LaTeX, Markdown)

### Step 2: Review Generated Files

All output files are saved in `article_data/YYYYMMDD_HHMMSS/` directory:

#### Summary Files
- **`publication_summary.md`**: High-level summary of all findings
- **`kpi_summary.md`**: Detailed KPI analysis based on your framework
- **`statistical_analysis.md`**: Statistical analysis with correlations and regressions

#### LaTeX Tables (for article)
- **`latex_scalability_table.tex`**: Table showing scalability results
- **`latex_statistical_table.tex`**: Table with descriptive statistics

#### Data Files (for analysis/visualization)
- **`scalability_data.csv`**: Complete scalability test data
- **`visualization_data.csv`**: Filtered data ready for plotting
- **`all_results.json`**: Complete aggregated results in JSON format

## Using the Generated Data

### For LaTeX Articles

1. Copy the `.tex` table files into your LaTeX document
2. Adjust formatting if needed (column widths, captions, etc.)
3. The tables are ready to use with standard LaTeX packages

Example:
```latex
\input{latex_scalability_table.tex}
```

### For Statistical Analysis

1. Import `visualization_data.csv` into your preferred tool:
   - **Python**: Use pandas to load and analyze
   - **R**: Use `read.csv()` or `readr::read_csv()`
   - **Excel**: Open directly for pivot tables and charts
   - **MATLAB**: Use `readtable()`

2. Use `statistical_analysis.md` as a reference for:
   - Correlation coefficients
   - Regression equations
   - Scaling interpretations

### For Visualizations

The `visualization_data.csv` file contains:
- BOM sizes (x-axis for scaling plots)
- Runtime, emissions, cost (y-axis options)
- Status and phase (for filtering)

Common visualizations:
- **Scaling plots**: BOM Size vs Runtime (log scale recommended)
- **Trade-off plots**: Emissions vs Cost
- **Distribution plots**: Histograms of runtime/emissions/cost

## KPI Framework Integration

The generated summaries align with your enhanced KPI framework:

### 1. Work-In-Process (WIP) Reduction
- Buffer counts from scalability tests
- Inventory levels from multi-objective results

### 2. Days Inventory Outstanding (DIO)
- Available in multi-objective Pareto fronts
- Calculated from buffer positioning decisions

### 3. Capital Immobilization
- Total cost metrics from all test scenarios
- Cost increases from compliance pathway analysis

### 4. Inventory Turnover Ratio
- Derived from buffer allocation and flow equations
- Available in multi-objective model outputs

### 5. Sustainable ROI (S-ROI)
- Economic returns: Cost savings from optimization
- Environmental returns: Emission reductions
- Can be calculated from aggregated results

### 6. Service Level & Lead Time
- Service time constraints from model results
- Available in standard run outputs

### 7. Carbon Emissions
- Total CO₂ emissions from all scenarios
- Emissions reduction percentages from compliance pathways

## Customizing the Analysis

### Adding New Result Types

To aggregate additional result types:

1. Edit `src/ResultsAggregator.php`
2. Add a new aggregation method (similar to `aggregateScalabilityResults()`)
3. Update `aggregateAllResults()` to include the new method
4. Add corresponding summary generation in `ArticleDataGenerator.php`

### Modifying Statistical Analysis

To customize statistical analysis:

1. Edit `src/StatisticalAnalyzer.php`
2. Add new analysis methods (e.g., ANOVA, time series)
3. Update report generation to include new analyses

### Custom Table Formats

To generate tables in different formats:

1. Add new methods to `ResultsAggregator.php` or `StatisticalAnalyzer.php`
2. Follow the pattern of existing `generateLaTeX*Table()` methods
3. Call from `ArticleDataGenerator.php`

## Best Practices

### Before Running Analysis

1. **Ensure all tests are complete**: The aggregator will only process available results
2. **Check log directories**: Verify that result files are in expected locations
3. **Review data quality**: Check for any obvious errors in log files

### For Article Writing

1. **Start with summaries**: Use `publication_summary.md` as a starting point
2. **Reference statistics**: Use `statistical_analysis.md` for quantitative findings
3. **Use tables strategically**: Include LaTeX tables for key comparisons
4. **Cite data sources**: Reference the specific test runs and configurations used

### Data Validation

1. **Cross-check numbers**: Verify aggregated statistics match individual results
2. **Check for outliers**: Review statistical reports for unusual values
3. **Validate correlations**: Ensure correlation coefficients make sense contextually

## Troubleshooting

### No Results Found

- Check that log directories exist and contain result files
- Verify file naming conventions match expected patterns
- Ensure result files are in correct format (CSV, JSON, etc.)

### Missing Data in Tables

- Some metrics may not be available for all test scenarios
- Check individual result files to see what data is available
- Modify aggregation logic if needed to handle missing data

### Statistical Analysis Errors

- Ensure sufficient data points (minimum 3 for correlations)
- Check for null or invalid values in source data
- Review data filtering logic in `StatisticalAnalyzer.php`

## Example Workflow

1. **Run all tests**:
   ```bash
   php src/Main.php
   php src/MultiObjectiveMain.php
   php src/ScalabilityBenchmark.php
   ```

2. **Generate article data**:
   ```bash
   php src/ArticleDataGenerator.php
   ```

3. **Review summaries**:
   - Open `article_data/YYYYMMDD_HHMMSS/publication_summary.md`
   - Check `statistical_analysis.md` for key findings

4. **Create visualizations**:
   - Import `visualization_data.csv` into Python/R/Excel
   - Create plots based on statistical insights

5. **Write article**:
   - Use LaTeX tables directly
   - Reference statistical findings
   - Include key KPIs from summaries

## Support

For questions or issues:
- Review the source code comments in the aggregator classes
- Check individual result files to understand data structure
- Modify the analysis scripts as needed for your specific requirements
