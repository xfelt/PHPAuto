<?php

/**
 * Main script to generate publication-ready data for scientific article
 * 
 * This script aggregates all test results and generates:
 * - Comprehensive summary reports
 * - LaTeX tables
 * - CSV files for statistical analysis
 * - JSON data for further processing
 */

require_once __DIR__ . '/ResultsAggregator.php';
require_once __DIR__ . '/StatisticalAnalyzer.php';

// Load configuration
$config = include __DIR__ . '/../config/settings.php';
$logsDir = $config['LOGS_DIR'];

// Create output directory for article data
$outputDir = __DIR__ . '/../article_data/' . date('Ymd_His') . DIRECTORY_SEPARATOR;
if (!is_dir($outputDir)) {
    mkdir($outputDir, 0755, true);
}

echo "=== Article Data Generator ===\n\n";
echo "Logs directory: $logsDir\n";
echo "Output directory: $outputDir\n\n";

// Initialize aggregator
$aggregator = new ResultsAggregator($logsDir, $outputDir);

// Aggregate all results
echo "Aggregating all test results...\n";
$allResults = $aggregator->aggregateAllResults();

echo "Found:\n";
echo "  - Scalability test runs: " . count($allResults['scalability'] ?? []) . "\n";
echo "  - Multi-objective runs: " . count($allResults['multi_objective'] ?? []) . "\n";
echo "  - Compliance pathway runs: " . count($allResults['compliance_pathways'] ?? []) . "\n";
echo "  - Standard runs: " . count($allResults['standard_runs'] ?? []) . "\n\n";

// Generate publication summary
echo "Generating publication summary...\n";
$summary = $aggregator->generatePublicationSummary($allResults);
file_put_contents($outputDir . 'publication_summary.md', $summary);
echo "  ✓ Saved: publication_summary.md\n";

// Generate LaTeX tables
echo "Generating LaTeX tables...\n";
$latexTable = $aggregator->generateLaTeXScalabilityTable($allResults);
file_put_contents($outputDir . 'latex_scalability_table.tex', $latexTable);
echo "  ✓ Saved: latex_scalability_table.tex\n";

// Generate CSV files for analysis
echo "Generating CSV files...\n";
$aggregator->generateCSVTable($allResults, 'scalability_data.csv');
echo "  ✓ Saved: scalability_data.csv\n";

// Export full JSON data
echo "Exporting JSON data...\n";
$aggregator->exportToJSON($allResults, 'all_results.json');
echo "  ✓ Saved: all_results.json\n";

// Generate KPI summary based on enhanced_kpi_framework
echo "Generating KPI summary...\n";
$kpiSummary = generateKPISummary($allResults, $outputDir);
file_put_contents($outputDir . 'kpi_summary.md', $kpiSummary);
echo "  ✓ Saved: kpi_summary.md\n";

// Generate statistical analysis
echo "Generating statistical analysis...\n";
$statAnalyzer = new StatisticalAnalyzer($outputDir);
$statAnalysis = $statAnalyzer->analyzeScalability($allResults['scalability'] ?? []);
if (!empty($statAnalysis)) {
    $statReport = $statAnalyzer->generateStatisticalReport($statAnalysis);
    file_put_contents($outputDir . 'statistical_analysis.md', $statReport);
    echo "  ✓ Saved: statistical_analysis.md\n";
    
    $latexStats = $statAnalyzer->generateStatisticalTable($statAnalysis);
    file_put_contents($outputDir . 'latex_statistical_table.tex', $latexStats);
    echo "  ✓ Saved: latex_statistical_table.tex\n";
    
    $statAnalyzer->generateVisualizationData($allResults['scalability'] ?? [], 'visualization_data.csv');
    echo "  ✓ Saved: visualization_data.csv\n";
}

echo "\n=== Generation Complete ===\n";
echo "All files saved to: $outputDir\n";
echo "\nFiles generated:\n";
echo "  - publication_summary.md: Comprehensive summary for article\n";
echo "  - kpi_summary.md: KPI analysis based on framework\n";
echo "  - statistical_analysis.md: Detailed statistical report\n";
echo "  - latex_scalability_table.tex: LaTeX table for scalability results\n";
echo "  - latex_statistical_table.tex: LaTeX table for statistics\n";
echo "  - scalability_data.csv: Raw data for analysis\n";
echo "  - visualization_data.csv: Data formatted for plotting\n";
echo "  - all_results.json: Complete aggregated results\n";

/**
 * Generate KPI summary based on the enhanced KPI framework
 */
function generateKPISummary(array $allResults, string $outputDir): string {
    $summary = "# KPI Analysis Summary\n\n";
    $summary .= "Generated: " . date('Y-m-d H:i:s') . "\n\n";
    
    $summary .= "## KPI Categories Analyzed\n\n";
    
    // Scalability KPIs
    $scalability = $allResults['scalability'] ?? [];
    if (!empty($scalability)) {
        $summary .= "### 1. Computational Performance\n\n";
        $latest = end($scalability);
        $stats = $latest['summary']['runtime_stats'] ?? [];
        
        if (!empty($stats)) {
            $summary .= "- **Average Runtime**: " . number_format($stats['mean'] ?? 0, 3) . " seconds\n";
            $summary .= "- **Min Runtime**: " . number_format($stats['min'] ?? 0, 3) . " seconds\n";
            $summary .= "- **Max Runtime**: " . number_format($stats['max'] ?? 0, 3) . " seconds\n";
            $summary .= "- **Median Runtime**: " . number_format($stats['median'] ?? 0, 3) . " seconds\n\n";
        }
    }
    
    // Emissions KPIs
    if (!empty($scalability)) {
        $summary .= "### 2. Carbon Emissions\n\n";
        $latest = end($scalability);
        $emissionsStats = $latest['summary']['emissions_stats'] ?? [];
        
        if (!empty($emissionsStats)) {
            $summary .= "- **Average Emissions**: " . number_format($emissionsStats['mean'] ?? 0, 2) . " CO₂\n";
            $summary .= "- **Min Emissions**: " . number_format($emissionsStats['min'] ?? 0, 2) . " CO₂\n";
            $summary .= "- **Max Emissions**: " . number_format($emissionsStats['max'] ?? 0, 2) . " CO₂\n\n";
        }
    }
    
    // Cost KPIs
    if (!empty($scalability)) {
        $summary .= "### 3. Total Cost\n\n";
        $latest = end($scalability);
        $costStats = $latest['summary']['cost_stats'] ?? [];
        
        if (!empty($costStats)) {
            $summary .= "- **Average Cost**: " . number_format($costStats['mean'] ?? 0, 2) . "\n";
            $summary .= "- **Min Cost**: " . number_format($costStats['min'] ?? 0, 2) . "\n";
            $summary .= "- **Max Cost**: " . number_format($costStats['max'] ?? 0, 2) . "\n\n";
        }
    }
    
    // Buffer/WIP KPIs
    if (!empty($scalability)) {
        $summary .= "### 4. Inventory Management (WIP/Buffers)\n\n";
        $latest = end($scalability);
        $bufferStats = $latest['summary']['buffer_stats'] ?? [];
        
        if (!empty($bufferStats)) {
            $summary .= "- **Average Buffer Count**: " . number_format($bufferStats['mean'] ?? 0, 2) . "\n";
            $summary .= "- **Min Buffers**: " . ($bufferStats['min'] ?? '-') . "\n";
            $summary .= "- **Max Buffers**: " . ($bufferStats['max'] ?? '-') . "\n\n";
        }
    }
    
    // Multi-objective KPIs
    $multiObj = $allResults['multi_objective'] ?? [];
    if (!empty($multiObj)) {
        $summary .= "### 5. Multi-Objective Trade-offs\n\n";
        $summary .= "Pareto fronts generated for:\n";
        foreach ($multiObj as $timestamp => $run) {
            $summary .= "- **Run " . $timestamp . "**:\n";
            foreach ($run['pareto_fronts'] ?? [] as $type => $front) {
                $summary .= "  - " . str_replace('_', '-', ucwords($type, '_')) . ": " . count($front) . " points\n";
            }
        }
        $summary .= "\n";
    }
    
    $summary .= "## Notes for Article\n\n";
    $summary .= "- All KPIs are calculated from optimal solutions only\n";
    $summary .= "- Runtime includes both root node and branch-and-cut phases\n";
    $summary .= "- Emissions are total CO₂ equivalent across the supply chain\n";
    $summary .= "- Buffer counts represent decoupling points in the supply network\n";
    
    return $summary;
}
