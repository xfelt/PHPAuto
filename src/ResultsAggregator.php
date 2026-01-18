<?php

/**
 * Comprehensive Results Aggregator for Scientific Article Preparation
 * 
 * This class aggregates results from all test scenarios (scalability, compliance pathways,
 * multi-objective optimization) and generates publication-ready summaries, tables, and statistics.
 */
class ResultsAggregator {
    
    private $logsDir;
    private $outputDir;
    
    public function __construct(string $logsDir, string $outputDir) {
        $this->logsDir = rtrim($logsDir, '/\\') . DIRECTORY_SEPARATOR;
        $this->outputDir = rtrim($outputDir, '/\\') . DIRECTORY_SEPARATOR;
        
        if (!is_dir($this->outputDir)) {
            mkdir($this->outputDir, 0755, true);
        }
    }
    
    /**
     * Aggregate all available test results
     */
    public function aggregateAllResults(): array {
        $results = [
            'scalability' => $this->aggregateScalabilityResults(),
            'compliance_pathways' => $this->aggregateCompliancePathwayResults(),
            'multi_objective' => $this->aggregateMultiObjectiveResults(),
            'standard_runs' => $this->aggregateStandardRunResults(),
            'metadata' => [
                'aggregation_date' => date('Y-m-d H:i:s'),
                'logs_directory' => $this->logsDir
            ]
        ];
        
        return $results;
    }
    
    /**
     * Aggregate scalability benchmark results
     */
    private function aggregateScalabilityResults(): array {
        $scalabilityDirs = glob($this->logsDir . 'scalability_*', GLOB_ONLYDIR);
        $allResults = [];
        
        foreach ($scalabilityDirs as $dir) {
            $csvFile = $dir . DIRECTORY_SEPARATOR . 'results_scalability_bom_sweep.csv';
            if (file_exists($csvFile)) {
                $results = $this->readCSV($csvFile);
                $timestamp = basename($dir);
                $allResults[$timestamp] = [
                    'timestamp' => $timestamp,
                    'results' => $results,
                    'summary' => $this->analyzeScalabilityResults($results)
                ];
            }
        }
        
        return $allResults;
    }
    
    /**
     * Analyze scalability results and compute statistics
     */
    private function analyzeScalabilityResults(array $results): array {
        if (empty($results)) {
            return [];
        }
        
        $validResults = array_filter($results, function($r) {
            return isset($r['status']) && $r['status'] === 'OPTIMAL';
        });
        
        if (empty($validResults)) {
            return ['error' => 'No optimal solutions found'];
        }
        
        $runtimes = array_column($validResults, 'runtime_sec');
        $emissions = array_column($validResults, 'total_emissions');
        $costs = array_column($validResults, 'total_cost');
        $bomSizes = array_column($validResults, 'N');
        $buffers = array_column($validResults, 'buffers_count');
        
        // Remove null values
        $runtimes = array_filter($runtimes, function($v) { return $v !== null && $v >= 0; });
        $emissions = array_filter($emissions, function($v) { return $v !== null && $v > 0; });
        $costs = array_filter($costs, function($v) { return $v !== null && $v > 0; });
        $buffers = array_filter($buffers, function($v) { return $v !== null; });
        
        return [
            'total_instances' => count($results),
            'optimal_solutions' => count($validResults),
            'runtime_stats' => [
                'min' => !empty($runtimes) ? min($runtimes) : null,
                'max' => !empty($runtimes) ? max($runtimes) : null,
                'mean' => !empty($runtimes) ? array_sum($runtimes) / count($runtimes) : null,
                'median' => !empty($runtimes) ? $this->median($runtimes) : null
            ],
            'emissions_stats' => [
                'min' => !empty($emissions) ? min($emissions) : null,
                'max' => !empty($emissions) ? max($emissions) : null,
                'mean' => !empty($emissions) ? array_sum($emissions) / count($emissions) : null,
                'median' => !empty($emissions) ? $this->median($emissions) : null
            ],
            'cost_stats' => [
                'min' => !empty($costs) ? min($costs) : null,
                'max' => !empty($costs) ? max($costs) : null,
                'mean' => !empty($costs) ? array_sum($costs) / count($costs) : null,
                'median' => !empty($costs) ? $this->median($costs) : null
            ],
            'bom_size_range' => [
                'min' => !empty($bomSizes) ? min($bomSizes) : null,
                'max' => !empty($bomSizes) ? max($bomSizes) : null
            ],
            'buffer_stats' => [
                'min' => !empty($buffers) ? min($buffers) : null,
                'max' => !empty($buffers) ? max($buffers) : null,
                'mean' => !empty($buffers) ? array_sum($buffers) / count($buffers) : null,
                'median' => !empty($buffers) ? $this->median($buffers) : null
            ],
            'scaling_analysis' => $this->analyzeScalingPattern($validResults)
        ];
    }
    
    /**
     * Analyze scaling patterns (runtime vs BOM size)
     */
    private function analyzeScalingPattern(array $results): array {
        $data = [];
        foreach ($results as $r) {
            if (isset($r['N']) && isset($r['runtime_sec']) && $r['runtime_sec'] >= 0) {
                $data[] = [
                    'N' => (int)$r['N'],
                    'runtime' => (float)$r['runtime_sec']
                ];
            }
        }
        
        if (empty($data)) {
            return [];
        }
        
        // Group by size ranges
        $small = array_filter($data, function($d) { return $d['N'] < 10; });
        $medium = array_filter($data, function($d) { return $d['N'] >= 10 && $d['N'] < 30; });
        $large = array_filter($data, function($d) { return $d['N'] >= 30; });
        
        return [
            'small_instances' => [
                'count' => count($small),
                'avg_runtime' => !empty($small) ? array_sum(array_column($small, 'runtime')) / count($small) : null
            ],
            'medium_instances' => [
                'count' => count($medium),
                'avg_runtime' => !empty($medium) ? array_sum(array_column($medium, 'runtime')) / count($medium) : null
            ],
            'large_instances' => [
                'count' => count($large),
                'avg_runtime' => !empty($large) ? array_sum(array_column($large, 'runtime')) / count($large) : null
            ]
        ];
    }
    
    /**
     * Aggregate compliance pathway results
     */
    private function aggregateCompliancePathwayResults(): array {
        // Look for cap tightening scenario results
        $capTighteningDirs = glob($this->logsDir . '*cap*', GLOB_ONLYDIR);
        $results = [];
        
        // This would need to be implemented based on how cap tightening results are stored
        // For now, return empty structure
        return $results;
    }
    
    /**
     * Aggregate multi-objective optimization results
     */
    private function aggregateMultiObjectiveResults(): array {
        $multiObjDirs = glob($this->logsDir . 'multiobj_*', GLOB_ONLYDIR);
        $allResults = [];
        
        foreach ($multiObjDirs as $dir) {
            $timestamp = basename($dir);
            $paretoFiles = glob($dir . DIRECTORY_SEPARATOR . '*_pareto_*.csv');
            $summaryFiles = glob($dir . DIRECTORY_SEPARATOR . '*_summary.json');
            $idealNadirFiles = glob($dir . DIRECTORY_SEPARATOR . '*_ideal_nadir.json');
            
            $runResults = [
                'timestamp' => $timestamp,
                'pareto_fronts' => [],
                'summaries' => [],
                'ideal_nadir' => []
            ];
            
            foreach ($paretoFiles as $file) {
                $type = $this->extractParetoType($file);
                if ($type) {
                    $runResults['pareto_fronts'][$type] = $this->readCSV($file);
                }
            }
            
            foreach ($summaryFiles as $file) {
                $content = file_get_contents($file);
                $runResults['summaries'][] = json_decode($content, true);
            }
            
            foreach ($idealNadirFiles as $file) {
                $content = file_get_contents($file);
                $runResults['ideal_nadir'][] = json_decode($content, true);
            }
            
            if (!empty($runResults['pareto_fronts']) || !empty($runResults['summaries'])) {
                $allResults[$timestamp] = $runResults;
            }
        }
        
        return $allResults;
    }
    
    /**
     * Extract Pareto front type from filename
     */
    private function extractParetoType(string $filename): ?string {
        if (strpos($filename, 'cost_dio') !== false) return 'cost_dio';
        if (strpos($filename, 'cost_wip') !== false) return 'cost_wip';
        if (strpos($filename, 'cost_emissions') !== false) return 'cost_emissions';
        return null;
    }
    
    /**
     * Aggregate standard run results
     */
    private function aggregateStandardRunResults(): array {
        // Look for standard result log files
        $resultFiles = glob($this->logsDir . '*_result.log');
        $results = [];
        
        foreach ($resultFiles as $file) {
            // Parse result log files if needed
            // This would require parsing the log format
        }
        
        return $results;
    }
    
    /**
     * Generate publication-ready summary report
     */
    public function generatePublicationSummary(array $aggregatedResults): string {
        $report = "# Comprehensive Results Summary for Scientific Article\n\n";
        $report .= "Generated: " . date('Y-m-d H:i:s') . "\n\n";
        
        $report .= "## 1. Scalability Analysis\n\n";
        $scalability = $aggregatedResults['scalability'] ?? [];
        if (!empty($scalability)) {
            $latest = end($scalability);
            $summary = $latest['summary'] ?? [];
            
            if (!empty($summary)) {
                $report .= "### Key Findings\n\n";
                $report .= sprintf("- Total instances tested: %d\n", $summary['total_instances'] ?? 0);
                $report .= sprintf("- Optimal solutions found: %d\n", $summary['optimal_solutions'] ?? 0);
                $report .= sprintf("- BOM size range: %d - %d items\n", 
                    $summary['bom_size_range']['min'] ?? 0,
                    $summary['bom_size_range']['max'] ?? 0
                );
                
                if (isset($summary['runtime_stats']['mean'])) {
                    $report .= sprintf("- Average runtime: %.3f seconds\n", $summary['runtime_stats']['mean']);
                }
                
                if (isset($summary['scaling_analysis'])) {
                    $scaling = $summary['scaling_analysis'];
                    $report .= "\n### Scaling Patterns\n\n";
                    if (isset($scaling['small_instances']['avg_runtime'])) {
                        $report .= sprintf("- Small instances (<10 items): %.3f s average\n", 
                            $scaling['small_instances']['avg_runtime']);
                    }
                    if (isset($scaling['medium_instances']['avg_runtime'])) {
                        $report .= sprintf("- Medium instances (10-29 items): %.3f s average\n", 
                            $scaling['medium_instances']['avg_runtime']);
                    }
                    if (isset($scaling['large_instances']['avg_runtime'])) {
                        $report .= sprintf("- Large instances (≥30 items): %.3f s average\n", 
                            $scaling['large_instances']['avg_runtime']);
                    }
                }
            }
        }
        
        $report .= "\n## 2. Multi-Objective Optimization Results\n\n";
        $multiObj = $aggregatedResults['multi_objective'] ?? [];
        if (!empty($multiObj)) {
            $report .= sprintf("- Total multi-objective runs: %d\n", count($multiObj));
            foreach ($multiObj as $timestamp => $run) {
                $report .= sprintf("\n### Run: %s\n", $timestamp);
                $report .= sprintf("- Pareto fronts generated: %d\n", count($run['pareto_fronts'] ?? []));
                foreach ($run['pareto_fronts'] as $type => $front) {
                    $report .= sprintf("  - %s: %d points\n", str_replace('_', '-', $type), count($front));
                }
            }
        }
        
        return $report;
    }
    
    /**
     * Generate LaTeX table for scalability results
     */
    public function generateLaTeXScalabilityTable(array $aggregatedResults): string {
        $scalability = $aggregatedResults['scalability'] ?? [];
        if (empty($scalability)) {
            return "% No scalability results available\n";
        }
        
        $latest = end($scalability);
        $results = $latest['results'] ?? [];
        
        if (empty($results)) {
            return "% No scalability results available\n";
        }
        
        // Filter optimal solutions and select representative samples
        $optimal = array_filter($results, function($r) {
            return isset($r['status']) && $r['status'] === 'OPTIMAL';
        });
        
        // Select representative samples (small, medium, large)
        $samples = [];
        $bomSizes = array_unique(array_column($optimal, 'N'));
        sort($bomSizes);
        
        // Select first, middle, and last few
        $selectedSizes = [];
        if (count($bomSizes) <= 10) {
            $selectedSizes = $bomSizes;
        } else {
            $selectedSizes = array_merge(
                array_slice($bomSizes, 0, 3),
                array_slice($bomSizes, floor(count($bomSizes)/2) - 1, 3),
                array_slice($bomSizes, -3)
            );
        }
        
        foreach ($optimal as $r) {
            if (in_array($r['N'], $selectedSizes)) {
                $samples[] = $r;
            }
        }
        
        $latex = "\\begin{table}[h]\n";
        $latex .= "\\centering\n";
        $latex .= "\\caption{Scalability Analysis Results}\n";
        $latex .= "\\label{tab:scalability}\n";
        $latex .= "\\begin{tabular}{c c c c c}\n";
        $latex .= "\\hline\n";
        $latex .= "BOM Size & Runtime (s) & Emissions & Cost & Buffers \\\\\n";
        $latex .= "\\hline\n";
        
        foreach ($samples as $r) {
            $latex .= sprintf("%d & %.3f & %s & %s & %s \\\\\n",
                $r['N'],
                $r['runtime_sec'] ?? 0,
                $this->formatNumber($r['total_emissions'] ?? 0),
                $this->formatNumber($r['total_cost'] ?? 0),
                $r['buffers_count'] ?? '-'
            );
        }
        
        $latex .= "\\hline\n";
        $latex .= "\\end{tabular}\n";
        $latex .= "\\end{table}\n";
        
        return $latex;
    }
    
    /**
     * Generate CSV table for Excel/statistical analysis
     */
    public function generateCSVTable(array $aggregatedResults, string $filename): bool {
        $scalability = $aggregatedResults['scalability'] ?? [];
        if (empty($scalability)) {
            return false;
        }
        
        $latest = end($scalability);
        $results = $latest['results'] ?? [];
        
        if (empty($results)) {
            return false;
        }
        
        $file = fopen($this->outputDir . $filename, 'w');
        if ($file === false) {
            return false;
        }
        
        // Write header
        fputcsv($file, [
            'BOM_Size', 'Phase', 'Status', 'Runtime_sec', 'Objective_Value',
            'Total_Emissions', 'Total_Cost', 'Buffers_Count'
        ]);
        
        // Write data
        foreach ($results as $r) {
            fputcsv($file, [
                $r['N'] ?? '',
                $r['phase'] ?? '',
                $r['status'] ?? '',
                $r['runtime_sec'] ?? '',
                $r['objective_value'] ?? '',
                $r['total_emissions'] ?? '',
                $r['total_cost'] ?? '',
                $r['buffers_count'] ?? ''
            ]);
        }
        
        fclose($file);
        return true;
    }
    
    /**
     * Export all aggregated results to JSON
     */
    public function exportToJSON(array $aggregatedResults, string $filename): bool {
        $json = json_encode($aggregatedResults, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
        return file_put_contents($this->outputDir . $filename, $json) !== false;
    }
    
    // Helper methods
    
    private function readCSV(string $file): array {
        $results = [];
        if (!file_exists($file)) {
            return $results;
        }
        
        $handle = fopen($file, 'r');
        if ($handle === false) {
            return $results;
        }
        
        $headers = fgetcsv($handle);
        if ($headers === false) {
            fclose($handle);
            return $results;
        }
        
        while (($row = fgetcsv($handle)) !== false) {
            $result = [];
            foreach ($headers as $index => $header) {
                $result[trim($header)] = isset($row[$index]) ? trim($row[$index]) : null;
            }
            $results[] = $result;
        }
        
        fclose($handle);
        return $results;
    }
    
    private function median(array $values): float {
        sort($values);
        $count = count($values);
        $middle = floor(($count - 1) / 2);
        
        if ($count % 2) {
            return $values[$middle];
        } else {
            return ($values[$middle] + $values[$middle + 1]) / 2;
        }
    }
    
    private function formatNumber($value, int $decimals = 2): string {
        if ($value === null || $value === '') {
            return '-';
        }
        
        $num = (float)$value;
        if ($num >= 1000000) {
            return sprintf("%.2e", $num);
        } else {
            return number_format($num, $decimals);
        }
    }
}
