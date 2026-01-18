<?php

/**
 * Statistical Analyzer for Publication-Ready Analysis
 * 
 * Generates statistical summaries, correlation analyses, and visualization-ready data
 * for scientific article preparation.
 */
class StatisticalAnalyzer {
    
    private $outputDir;
    
    public function __construct(string $outputDir) {
        $this->outputDir = rtrim($outputDir, '/\\') . DIRECTORY_SEPARATOR;
    }
    
    /**
     * Analyze scalability data and generate statistical report
     */
    public function analyzeScalability(array $scalabilityResults): array {
        $analysis = [];
        
        foreach ($scalabilityResults as $timestamp => $data) {
            $results = $data['results'] ?? [];
            if (empty($results)) continue;
            
            $optimal = array_filter($results, function($r) {
                return isset($r['status']) && $r['status'] === 'OPTIMAL';
            });
            
            if (empty($optimal)) continue;
            
            $analysis[$timestamp] = [
                'descriptive_stats' => $this->computeDescriptiveStats($optimal),
                'correlations' => $this->computeCorrelations($optimal),
                'regression_analysis' => $this->computeRegression($optimal),
                'performance_by_size' => $this->analyzeByBOMSize($optimal)
            ];
        }
        
        return $analysis;
    }
    
    /**
     * Compute descriptive statistics
     */
    private function computeDescriptiveStats(array $results): array {
        $runtimes = array_filter(array_column($results, 'runtime_sec'), function($v) {
            return $v !== null && $v >= 0;
        });
        $emissions = array_filter(array_column($results, 'total_emissions'), function($v) {
            return $v !== null && $v > 0;
        });
        $costs = array_filter(array_column($results, 'total_cost'), function($v) {
            return $v !== null && $v > 0;
        });
        $bomSizes = array_column($results, 'N');
        
        $stats = [];
        
        foreach ([
            'runtime' => $runtimes,
            'emissions' => $emissions,
            'cost' => $costs,
            'bom_size' => $bomSizes
        ] as $name => $values) {
            if (empty($values)) continue;
            
            $values = array_map('floatval', $values);
            sort($values);
            
            $stats[$name] = [
                'count' => count($values),
                'mean' => array_sum($values) / count($values),
                'median' => $this->median($values),
                'std_dev' => $this->standardDeviation($values),
                'min' => min($values),
                'max' => max($values),
                'q1' => $this->percentile($values, 25),
                'q3' => $this->percentile($values, 75),
                'iqr' => $this->percentile($values, 75) - $this->percentile($values, 25)
            ];
        }
        
        return $stats;
    }
    
    /**
     * Compute correlations between variables
     */
    private function computeCorrelations(array $results): array {
        $data = [];
        foreach ($results as $r) {
            if (isset($r['N']) && isset($r['runtime_sec']) && $r['runtime_sec'] >= 0 &&
                isset($r['total_emissions']) && $r['total_emissions'] > 0 &&
                isset($r['total_cost']) && $r['total_cost'] > 0) {
                $data[] = [
                    'N' => (float)$r['N'],
                    'runtime' => (float)$r['runtime_sec'],
                    'emissions' => (float)$r['total_emissions'],
                    'cost' => (float)$r['total_cost']
                ];
            }
        }
        
        if (count($data) < 3) {
            return [];
        }
        
        return [
            'N_vs_runtime' => $this->pearsonCorrelation(
                array_column($data, 'N'),
                array_column($data, 'runtime')
            ),
            'N_vs_emissions' => $this->pearsonCorrelation(
                array_column($data, 'N'),
                array_column($data, 'emissions')
            ),
            'N_vs_cost' => $this->pearsonCorrelation(
                array_column($data, 'N'),
                array_column($data, 'cost')
            ),
            'emissions_vs_cost' => $this->pearsonCorrelation(
                array_column($data, 'emissions'),
                array_column($data, 'cost')
            )
        ];
    }
    
    /**
     * Compute regression analysis (runtime vs BOM size)
     */
    private function computeRegression(array $results): array {
        $data = [];
        foreach ($results as $r) {
            if (isset($r['N']) && isset($r['runtime_sec']) && $r['runtime_sec'] >= 0) {
                $data[] = [
                    'x' => (float)$r['N'],
                    'y' => (float)$r['runtime_sec']
                ];
            }
        }
        
        if (count($data) < 3) {
            return [];
        }
        
        $x = array_column($data, 'x');
        $y = array_column($data, 'y');
        
        $n = count($data);
        $sumX = array_sum($x);
        $sumY = array_sum($y);
        $sumXY = 0;
        $sumX2 = 0;
        
        for ($i = 0; $i < $n; $i++) {
            $sumXY += $x[$i] * $y[$i];
            $sumX2 += $x[$i] * $x[$i];
        }
        
        $slope = ($n * $sumXY - $sumX * $sumY) / ($n * $sumX2 - $sumX * $sumX);
        $intercept = ($sumY - $slope * $sumX) / $n;
        
        // Calculate R-squared
        $yMean = $sumY / $n;
        $ssTotal = 0;
        $ssResidual = 0;
        
        for ($i = 0; $i < $n; $i++) {
            $yPred = $slope * $x[$i] + $intercept;
            $ssTotal += pow($y[$i] - $yMean, 2);
            $ssResidual += pow($y[$i] - $yPred, 2);
        }
        
        $rSquared = 1 - ($ssResidual / $ssTotal);
        
        return [
            'slope' => $slope,
            'intercept' => $intercept,
            'r_squared' => $rSquared,
            'equation' => sprintf("y = %.6f * x + %.6f", $slope, $intercept),
            'interpretation' => $this->interpretScaling($slope, $rSquared)
        ];
    }
    
    /**
     * Analyze performance by BOM size categories
     */
    private function analyzeByBOMSize(array $results): array {
        $categories = [
            'small' => ['min' => 0, 'max' => 9],
            'medium' => ['min' => 10, 'max' => 29],
            'large' => ['min' => 30, 'max' => 999]
        ];
        
        $analysis = [];
        
        foreach ($categories as $name => $range) {
            $categoryResults = array_filter($results, function($r) use ($range) {
                $n = (int)($r['N'] ?? 0);
                return $n >= $range['min'] && $n <= $range['max'];
            });
            
            if (empty($categoryResults)) continue;
            
            $runtimes = array_filter(array_column($categoryResults, 'runtime_sec'), function($v) {
                return $v !== null && $v >= 0;
            });
            $emissions = array_filter(array_column($categoryResults, 'total_emissions'), function($v) {
                return $v !== null && $v > 0;
            });
            
            $analysis[$name] = [
                'count' => count($categoryResults),
                'avg_runtime' => !empty($runtimes) ? array_sum($runtimes) / count($runtimes) : null,
                'avg_emissions' => !empty($emissions) ? array_sum($emissions) / count($emissions) : null,
                'size_range' => $range
            ];
        }
        
        return $analysis;
    }
    
    /**
     * Generate LaTeX table with statistical summary
     */
    public function generateStatisticalTable(array $analysis): string {
        if (empty($analysis)) {
            return "% No statistical analysis available\n";
        }
        
        $latest = end($analysis);
        $stats = $latest['descriptive_stats'] ?? [];
        
        if (empty($stats)) {
            return "% No statistical data available\n";
        }
        
        $latex = "\\begin{table}[h]\n";
        $latex .= "\\centering\n";
        $latex .= "\\caption{Descriptive Statistics for Key Performance Indicators}\n";
        $latex .= "\\label{tab:statistics}\n";
        $latex .= "\\begin{tabular}{l c c c c c}\n";
        $latex .= "\\hline\n";
        $latex .= "Metric & Mean & Median & Std. Dev. & Min & Max \\\\\n";
        $latex .= "\\hline\n";
        
        foreach (['runtime', 'emissions', 'cost'] as $metric) {
            if (!isset($stats[$metric])) continue;
            
            $s = $stats[$metric];
            $label = ucfirst($metric);
            if ($metric === 'runtime') $label = 'Runtime (s)';
            if ($metric === 'emissions') $label = 'Emissions (CO₂)';
            if ($metric === 'cost') $label = 'Cost';
            
            $latex .= sprintf("%s & %.3f & %.3f & %.3f & %.3f & %.3f \\\\\n",
                $label,
                $s['mean'],
                $s['median'],
                $s['std_dev'],
                $s['min'],
                $s['max']
            );
        }
        
        $latex .= "\\hline\n";
        $latex .= "\\end{tabular}\n";
        $latex .= "\\end{table}\n";
        
        return $latex;
    }
    
    /**
     * Generate visualization-ready CSV data
     */
    public function generateVisualizationData(array $scalabilityResults, string $filename): bool {
        if (empty($scalabilityResults)) {
            return false;
        }
        
        $latest = end($scalabilityResults);
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
            'BOM_Size', 'Runtime_sec', 'Total_Emissions', 'Total_Cost', 
            'Buffers_Count', 'Status', 'Phase'
        ]);
        
        // Write data (only optimal solutions)
        foreach ($results as $r) {
            if (isset($r['status']) && $r['status'] === 'OPTIMAL') {
                fputcsv($file, [
                    $r['N'] ?? '',
                    $r['runtime_sec'] ?? '',
                    $r['total_emissions'] ?? '',
                    $r['total_cost'] ?? '',
                    $r['buffers_count'] ?? '',
                    $r['status'] ?? '',
                    $r['phase'] ?? ''
                ]);
            }
        }
        
        fclose($file);
        return true;
    }
    
    /**
     * Generate statistical report in Markdown
     */
    public function generateStatisticalReport(array $analysis): string {
        $report = "# Statistical Analysis Report\n\n";
        $report .= "Generated: " . date('Y-m-d H:i:s') . "\n\n";
        
        foreach ($analysis as $timestamp => $data) {
            $report .= "## Analysis for Run: $timestamp\n\n";
            
            // Descriptive Statistics
            $stats = $data['descriptive_stats'] ?? [];
            if (!empty($stats)) {
                $report .= "### Descriptive Statistics\n\n";
                $report .= "| Metric | Count | Mean | Median | Std. Dev. | Min | Max |\n";
                $report .= "|--------|-------|------|--------|-----------|-----|-----|\n";
                
                foreach (['runtime', 'emissions', 'cost'] as $metric) {
                    if (!isset($stats[$metric])) continue;
                    $s = $stats[$metric];
                    $report .= sprintf("| %s | %d | %.3f | %.3f | %.3f | %.3f | %.3f |\n",
                        ucfirst($metric),
                        $s['count'],
                        $s['mean'],
                        $s['median'],
                        $s['std_dev'],
                        $s['min'],
                        $s['max']
                    );
                }
                $report .= "\n";
            }
            
            // Correlations
            $correlations = $data['correlations'] ?? [];
            if (!empty($correlations)) {
                $report .= "### Correlation Analysis\n\n";
                foreach ($correlations as $pair => $value) {
                    $report .= sprintf("- **%s**: %.4f\n", str_replace('_', ' vs ', $pair), $value);
                }
                $report .= "\n";
            }
            
            // Regression
            $regression = $data['regression_analysis'] ?? [];
            if (!empty($regression)) {
                $report .= "### Regression Analysis (Runtime vs BOM Size)\n\n";
                $report .= sprintf("- **Equation**: %s\n", $regression['equation'] ?? 'N/A');
                $report .= sprintf("- **R²**: %.4f\n", $regression['r_squared'] ?? 0);
                if (isset($regression['interpretation'])) {
                    $report .= sprintf("- **Interpretation**: %s\n", $regression['interpretation']);
                }
                $report .= "\n";
            }
        }
        
        return $report;
    }
    
    // Helper methods
    
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
    
    private function standardDeviation(array $values): float {
        $count = count($values);
        if ($count < 2) return 0;
        
        $mean = array_sum($values) / $count;
        $variance = 0;
        
        foreach ($values as $value) {
            $variance += pow($value - $mean, 2);
        }
        
        return sqrt($variance / ($count - 1));
    }
    
    private function percentile(array $values, float $percentile): float {
        sort($values);
        $index = ($percentile / 100) * (count($values) - 1);
        $lower = floor($index);
        $upper = ceil($index);
        
        if ($lower == $upper) {
            return $values[$lower];
        }
        
        $weight = $index - $lower;
        return $values[$lower] * (1 - $weight) + $values[$upper] * $weight;
    }
    
    private function pearsonCorrelation(array $x, array $y): ?float {
        if (count($x) !== count($y) || count($x) < 2) {
            return null;
        }
        
        $n = count($x);
        $sumX = array_sum($x);
        $sumY = array_sum($y);
        $sumXY = 0;
        $sumX2 = 0;
        $sumY2 = 0;
        
        for ($i = 0; $i < $n; $i++) {
            $sumXY += $x[$i] * $y[$i];
            $sumX2 += $x[$i] * $x[$i];
            $sumY2 += $y[$i] * $y[$i];
        }
        
        $numerator = $n * $sumXY - $sumX * $sumY;
        $denominator = sqrt(($n * $sumX2 - $sumX * $sumX) * ($n * $sumY2 - $sumY * $sumY));
        
        if ($denominator == 0) {
            return null;
        }
        
        return $numerator / $denominator;
    }
    
    private function interpretScaling(float $slope, float $rSquared): string {
        if ($slope < 0.001) {
            return "Constant time complexity (O(1))";
        } elseif ($slope < 0.1) {
            return "Near-linear scaling (O(n))";
        } elseif ($slope < 1) {
            return "Sub-quadratic scaling";
        } else {
            return "Quadratic or higher complexity";
        }
    }
}
