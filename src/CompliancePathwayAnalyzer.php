<?php

/**
 * Analyzer for compliance pathway cost projections from cap tightening scenarios.
 * 
 * This class aggregates and analyzes results from multiple cap tightening scenarios
 * to evaluate how costs change as emissions caps become progressively tighter.
 */
class CompliancePathwayAnalyzer {
    
    /**
     * Aggregate results from multiple cap tightening scenario runs.
     * 
     * @param array $results Array of result arrays, each containing:
     *   - 'prefix': Run prefix identifier
     *   - 'cap': Emissions cap value used
     *   - 'result': Parsed CPLEX result array
     * @return array Aggregated analysis with cost projections
     */
    public static function analyzeCompliancePathway(array $results): array {
        // Sort results by cap value (descending - loosest to tightest)
        usort($results, function($a, $b) {
            return $b['cap'] <=> $a['cap'];
        });
        
        $analysis = [
            'scenarios' => [],
            'cost_projection' => [],
            'emissions_analysis' => [],
            'summary' => []
        ];
        
        $baseScenario = null;
        $previousCost = null;
        
        foreach ($results as $index => $result) {
            $cap = $result['cap'];
            $resultData = $result['result'] ?? [];
            
            // Extract key metrics
            $totalCost = self::extractTotalCost($resultData);
            $emissions = self::extractEmissions($resultData);
            $serviceCost = self::extractServiceCost($resultData);
            $objective = self::extractObjective($resultData);
            
            $scenario = [
                'index' => $index + 1,
                'cap' => $cap,
                'total_cost' => $totalCost,
                'emissions' => $emissions,
                'service_cost' => $serviceCost,
                'objective' => $objective,
                'prefix' => $result['prefix'] ?? ''
            ];
            
            // Calculate cost increase from base scenario
            if ($baseScenario === null) {
                $baseScenario = $scenario;
                $scenario['cost_increase'] = 0;
                $scenario['cost_increase_percent'] = 0;
                $scenario['emissions_reduction'] = 0;
                $scenario['emissions_reduction_percent'] = 0;
            } else {
                $costIncrease = $totalCost - $baseScenario['total_cost'];
                $scenario['cost_increase'] = $costIncrease;
                $scenario['cost_increase_percent'] = $baseScenario['total_cost'] > 0 
                    ? ($costIncrease / $baseScenario['total_cost']) * 100 
                    : 0;
                
                $emissionsReduction = $baseScenario['emissions'] - $emissions;
                $scenario['emissions_reduction'] = $emissionsReduction;
                $scenario['emissions_reduction_percent'] = $baseScenario['emissions'] > 0 
                    ? ($emissionsReduction / $baseScenario['emissions']) * 100 
                    : 0;
            }
            
            // Calculate marginal cost per unit emissions reduction
            if ($previousCost !== null && $previousCost['emissions'] > $emissions) {
                $marginalCost = ($totalCost - $previousCost['total_cost']) / 
                               ($previousCost['emissions'] - $emissions);
                $scenario['marginal_cost_per_ton_co2'] = $marginalCost;
            } else {
                $scenario['marginal_cost_per_ton_co2'] = null;
            }
            
            $analysis['scenarios'][] = $scenario;
            $previousCost = $scenario;
        }
        
        // Generate cost projection summary
        $analysis['cost_projection'] = self::generateCostProjection($analysis['scenarios']);
        
        // Generate emissions analysis
        $analysis['emissions_analysis'] = self::generateEmissionsAnalysis($analysis['scenarios']);
        
        // Generate overall summary
        $analysis['summary'] = self::generateSummary($analysis);
        
        return $analysis;
    }
    
    /**
     * Extract total cost from CPLEX result.
     */
    private static function extractTotalCost(array $resultData): float {
        // Try to get TotalCostCS first (cost without tax)
        if (isset($resultData['CS'])) {
            return (float)$resultData['CS'];
        }
        if (isset($resultData['TS'])) {
            return (float)$resultData['TS'];
        }
        if (isset($resultData['Result']['ServiceCost'])) {
            return (float)$resultData['Result']['ServiceCost'];
        }
        if (isset($resultData['Result']['Objective'])) {
            return (float)$resultData['Result']['Objective'];
        }
        return 0.0;
    }
    
    /**
     * Extract emissions from CPLEX result.
     */
    private static function extractEmissions(array $resultData): float {
        if (isset($resultData['E'])) {
            return (float)$resultData['E'];
        }
        if (isset($resultData['Result']['Emissions'])) {
            return (float)$resultData['Result']['Emissions'];
        }
        return 0.0;
    }
    
    /**
     * Extract service cost from CPLEX result.
     */
    private static function extractServiceCost(array $resultData): float {
        if (isset($resultData['Result']['ServiceCost'])) {
            return (float)$resultData['Result']['ServiceCost'];
        }
        return 0.0;
    }
    
    /**
     * Extract objective value from CPLEX result.
     */
    private static function extractObjective(array $resultData): float {
        if (isset($resultData['Result']['Objective'])) {
            return (float)$resultData['Result']['Objective'];
        }
        return 0.0;
    }
    
    /**
     * Generate cost projection analysis.
     */
    private static function generateCostProjection(array $scenarios): array {
        if (empty($scenarios)) {
            return [];
        }
        
        $baseScenario = $scenarios[0];
        $tightestScenario = end($scenarios);
        
        return [
            'base_cap' => $baseScenario['cap'],
            'tightest_cap' => $tightestScenario['cap'],
            'base_cost' => $baseScenario['total_cost'],
            'tightest_cost' => $tightestScenario['total_cost'],
            'total_cost_increase' => $tightestScenario['cost_increase'],
            'total_cost_increase_percent' => $tightestScenario['cost_increase_percent'],
            'average_marginal_cost' => self::calculateAverageMarginalCost($scenarios),
            'cost_elasticity' => self::calculateCostElasticity($scenarios)
        ];
    }
    
    /**
     * Generate emissions analysis.
     */
    private static function generateEmissionsAnalysis(array $scenarios): array {
        if (empty($scenarios)) {
            return [];
        }
        
        $baseScenario = $scenarios[0];
        $tightestScenario = end($scenarios);
        
        return [
            'base_emissions' => $baseScenario['emissions'],
            'tightest_emissions' => $tightestScenario['emissions'],
            'total_emissions_reduction' => $tightestScenario['emissions_reduction'],
            'total_emissions_reduction_percent' => $tightestScenario['emissions_reduction_percent'],
            'average_cost_per_ton_reduced' => self::calculateAverageCostPerTon($scenarios)
        ];
    }
    
    /**
     * Calculate average marginal cost per ton CO2 reduced.
     */
    private static function calculateAverageMarginalCost(array $scenarios): ?float {
        $marginalCosts = array_filter(
            array_column($scenarios, 'marginal_cost_per_ton_co2'),
            function($value) { return $value !== null; }
        );
        
        if (empty($marginalCosts)) {
            return null;
        }
        
        return array_sum($marginalCosts) / count($marginalCosts);
    }
    
    /**
     * Calculate average cost per ton CO2 reduced across all scenarios.
     */
    private static function calculateAverageCostPerTon(array $scenarios): ?float {
        if (empty($scenarios)) {
            return null;
        }
        
        $baseScenario = $scenarios[0];
        $tightestScenario = end($scenarios);
        
        $totalCostIncrease = $tightestScenario['cost_increase'];
        $totalEmissionsReduction = $tightestScenario['emissions_reduction'];
        
        if ($totalEmissionsReduction <= 0) {
            return null;
        }
        
        return $totalCostIncrease / $totalEmissionsReduction;
    }
    
    /**
     * Calculate cost elasticity (percentage cost increase per percentage cap reduction).
     */
    private static function calculateCostElasticity(array $scenarios): ?float {
        if (count($scenarios) < 2) {
            return null;
        }
        
        $baseScenario = $scenarios[0];
        $tightestScenario = end($scenarios);
        
        $capReductionPercent = (($baseScenario['cap'] - $tightestScenario['cap']) / $baseScenario['cap']) * 100;
        $costIncreasePercent = $tightestScenario['cost_increase_percent'];
        
        if ($capReductionPercent <= 0) {
            return null;
        }
        
        return $costIncreasePercent / $capReductionPercent;
    }
    
    /**
     * Generate overall summary statistics.
     */
    private static function generateSummary(array $analysis): array {
        $scenarios = $analysis['scenarios'];
        $costProjection = $analysis['cost_projection'];
        $emissionsAnalysis = $analysis['emissions_analysis'];
        
        return [
            'total_scenarios' => count($scenarios),
            'cap_range' => [
                'loosest' => $scenarios[0]['cap'] ?? 0,
                'tightest' => end($scenarios)['cap'] ?? 0
            ],
            'cost_range' => [
                'minimum' => min(array_column($scenarios, 'total_cost')),
                'maximum' => max(array_column($scenarios, 'total_cost'))
            ],
            'emissions_range' => [
                'maximum' => max(array_column($scenarios, 'emissions')),
                'minimum' => min(array_column($scenarios, 'emissions'))
            ],
            'average_marginal_cost_per_ton' => $costProjection['average_marginal_cost'] ?? null,
            'total_cost_per_ton_reduced' => $emissionsAnalysis['average_cost_per_ton_reduced'] ?? null,
            'cost_elasticity' => $costProjection['cost_elasticity'] ?? null
        ];
    }
    
    /**
     * Export analysis to CSV format for further analysis.
     * 
     * @param array $analysis Analysis results from analyzeCompliancePathway
     * @param string $outputFile Path to output CSV file
     * @return bool Success status
     */
    public static function exportToCSV(array $analysis, string $outputFile): bool {
        $scenarios = $analysis['scenarios'] ?? [];
        
        if (empty($scenarios)) {
            return false;
        }
        
        $handle = fopen($outputFile, 'w');
        if ($handle === false) {
            return false;
        }
        
        // Write header
        fputcsv($handle, [
            'Scenario',
            'Cap',
            'Total_Cost',
            'Emissions',
            'Cost_Increase',
            'Cost_Increase_Percent',
            'Emissions_Reduction',
            'Emissions_Reduction_Percent',
            'Marginal_Cost_Per_Ton_CO2',
            'Prefix'
        ]);
        
        // Write data rows
        foreach ($scenarios as $scenario) {
            fputcsv($handle, [
                $scenario['index'],
                $scenario['cap'],
                $scenario['total_cost'],
                $scenario['emissions'],
                $scenario['cost_increase'],
                $scenario['cost_increase_percent'],
                $scenario['emissions_reduction'],
                $scenario['emissions_reduction_percent'],
                $scenario['marginal_cost_per_ton_co2'] ?? '',
                $scenario['prefix']
            ]);
        }
        
        fclose($handle);
        return true;
    }
    
    /**
     * Generate a formatted report from the analysis.
     * 
     * @param array $analysis Analysis results
     * @return string Formatted report text
     */
    public static function generateReport(array $analysis): string {
        $report = "=" . str_repeat("=", 70) . "=\n";
        $report .= "COMPLIANCE PATHWAY COST PROJECTION ANALYSIS\n";
        $report .= "=" . str_repeat("=", 70) . "=\n\n";
        
        $summary = $analysis['summary'] ?? [];
        $costProjection = $analysis['cost_projection'] ?? [];
        $emissionsAnalysis = $analysis['emissions_analysis'] ?? [];
        
        // Summary section
        $report .= "SUMMARY\n";
        $report .= str_repeat("-", 72) . "\n";
        $report .= sprintf("Total Scenarios Analyzed: %d\n", $summary['total_scenarios'] ?? 0);
        $report .= sprintf("Cap Range: %s to %s\n", 
            number_format($summary['cap_range']['loosest'] ?? 0),
            number_format($summary['cap_range']['tightest'] ?? 0)
        );
        $report .= sprintf("Cost Range: %s to %s\n",
            number_format($summary['cost_range']['minimum'] ?? 0, 2),
            number_format($summary['cost_range']['maximum'] ?? 0, 2)
        );
        $report .= "\n";
        
        // Cost Projection section
        $report .= "COST PROJECTION\n";
        $report .= str_repeat("-", 72) . "\n";
        if (!empty($costProjection)) {
            $report .= sprintf("Base Cap: %s\n", number_format($costProjection['base_cap'] ?? 0));
            $report .= sprintf("Tightest Cap: %s\n", number_format($costProjection['tightest_cap'] ?? 0));
            $report .= sprintf("Base Cost: %s\n", number_format($costProjection['base_cost'] ?? 0, 2));
            $report .= sprintf("Tightest Cost: %s\n", number_format($costProjection['tightest_cost'] ?? 0, 2));
            $report .= sprintf("Total Cost Increase: %s (%.2f%%)\n",
                number_format($costProjection['total_cost_increase'] ?? 0, 2),
                $costProjection['total_cost_increase_percent'] ?? 0
            );
            if ($costProjection['average_marginal_cost'] !== null) {
                $report .= sprintf("Average Marginal Cost per Ton CO2: %s\n",
                    number_format($costProjection['average_marginal_cost'], 2)
                );
            }
            if ($costProjection['cost_elasticity'] !== null) {
                $report .= sprintf("Cost Elasticity: %.2f\n", $costProjection['cost_elasticity']);
            }
        }
        $report .= "\n";
        
        // Emissions Analysis section
        $report .= "EMISSIONS ANALYSIS\n";
        $report .= str_repeat("-", 72) . "\n";
        if (!empty($emissionsAnalysis)) {
            $report .= sprintf("Base Emissions: %s\n", number_format($emissionsAnalysis['base_emissions'] ?? 0, 2));
            $report .= sprintf("Tightest Emissions: %s\n", number_format($emissionsAnalysis['tightest_emissions'] ?? 0, 2));
            $report .= sprintf("Total Emissions Reduction: %s (%.2f%%)\n",
                number_format($emissionsAnalysis['total_emissions_reduction'] ?? 0, 2),
                $emissionsAnalysis['total_emissions_reduction_percent'] ?? 0
            );
            if ($emissionsAnalysis['average_cost_per_ton_reduced'] !== null) {
                $report .= sprintf("Average Cost per Ton CO2 Reduced: %s\n",
                    number_format($emissionsAnalysis['average_cost_per_ton_reduced'], 2)
                );
            }
        }
        $report .= "\n";
        
        // Detailed scenarios table
        $report .= "DETAILED SCENARIOS\n";
        $report .= str_repeat("-", 72) . "\n";
        $report .= sprintf("%-10s %-15s %-15s %-15s %-15s\n",
            "Scenario", "Cap", "Total Cost", "Emissions", "Cost Increase %"
        );
        $report .= str_repeat("-", 72) . "\n";
        
        foreach ($analysis['scenarios'] ?? [] as $scenario) {
            $report .= sprintf("%-10d %-15s %-15s %-15s %-15.2f\n",
                $scenario['index'],
                number_format($scenario['cap']),
                number_format($scenario['total_cost'], 2),
                number_format($scenario['emissions'], 2),
                $scenario['cost_increase_percent']
            );
        }
        
        return $report;
    }
}
