<?php

/**
 * Utility class for generating cap tightening scenarios for compliance pathway cost projections.
 * 
 * This class generates progressive cap tightening scenarios where emissions caps are
 * gradually reduced to evaluate how costs change as compliance requirements become stricter.
 */
class CapTighteningScenarioGenerator {
    
    /**
     * Generate progressive cap values from a base cap with specified reduction parameters.
     * 
     * @param int $baseCap The starting emissions cap value
     * @param int $numScenarios Number of scenarios to generate
     * @param float $reductionPercentage Percentage reduction per scenario (e.g., 0.05 for 5%)
     * @param int|null $minCap Optional minimum cap value to stop at
     * @return array Array of cap values in descending order (tightest last)
     */
    public static function generateProgressiveCaps(
        int $baseCap,
        int $numScenarios = 5,
        float $reductionPercentage = 0.05,
        ?int $minCap = null
    ): array {
        $caps = [];
        $currentCap = $baseCap;
        
        for ($i = 0; $i < $numScenarios; $i++) {
            $caps[] = (int)round($currentCap);
            
            // Calculate next cap
            $reduction = $currentCap * $reductionPercentage;
            $currentCap = $currentCap - $reduction;
            
            // Stop if we've reached minimum cap
            if ($minCap !== null && $currentCap <= $minCap) {
                // Add the minimum cap if we haven't reached numScenarios yet
                if (count($caps) < $numScenarios) {
                    $caps[] = $minCap;
                }
                break;
            }
        }
        
        return $caps;
    }
    
    /**
     * Generate cap values using fixed step reduction.
     * 
     * @param int $baseCap The starting emissions cap value
     * @param int $numScenarios Number of scenarios to generate
     * @param int $stepSize Fixed reduction amount per scenario
     * @param int|null $minCap Optional minimum cap value to stop at
     * @return array Array of cap values in descending order
     */
    public static function generateFixedStepCaps(
        int $baseCap,
        int $numScenarios = 5,
        int $stepSize = 100000,
        ?int $minCap = null
    ): array {
        $caps = [];
        $currentCap = $baseCap;
        
        for ($i = 0; $i < $numScenarios; $i++) {
            $caps[] = (int)$currentCap;
            
            $currentCap = $currentCap - $stepSize;
            
            // Stop if we've reached minimum cap
            if ($minCap !== null && $currentCap <= $minCap) {
                if (count($caps) < $numScenarios) {
                    $caps[] = $minCap;
                }
                break;
            }
        }
        
        return $caps;
    }
    
    /**
     * Generate cap values from a custom list of reduction percentages.
     * 
     * @param int $baseCap The starting emissions cap value
     * @param array $reductionPercentages Array of reduction percentages (e.g., [0.0, 0.05, 0.10, 0.15, 0.20])
     * @return array Array of cap values
     */
    public static function generateCustomCaps(
        int $baseCap,
        array $reductionPercentages
    ): array {
        $caps = [];
        
        foreach ($reductionPercentages as $reduction) {
            $cap = $baseCap * (1 - $reduction);
            $caps[] = (int)round($cap);
        }
        
        return $caps;
    }
    
    /**
     * Expand base configuration rows to include cap tightening scenarios.
     * 
     * @param array $baseConfigRow A single row from baseConfig.csv
     * @param array $capTighteningConfig Configuration for cap tightening:
     *   - 'method': 'progressive' | 'fixed_step' | 'custom'
     *   - 'num_scenarios': int (for progressive/fixed_step)
     *   - 'reduction_percentage': float (for progressive)
     *   - 'step_size': int (for fixed_step)
     *   - 'reduction_percentages': array (for custom)
     *   - 'min_cap': int|null (optional minimum cap)
     * @return array Array of expanded configuration rows
     */
    public static function expandConfigWithCapTightening(
        array $baseConfigRow,
        array $capTighteningConfig
    ): array {
        // Only expand EMISCAP strategy rows
        if (strtoupper($baseConfigRow['strategy']) !== 'EMISCAP') {
            return [$baseConfigRow]; // Return original if not EMISCAP
        }
        
        $method = $capTighteningConfig['method'] ?? 'progressive';
        $baseCapValues = explode(',', $baseConfigRow['strategy_values']);
        $expandedRows = [];
        
        foreach ($baseCapValues as $baseCapStr) {
            $baseCap = (int)trim($baseCapStr);
            
            // Generate cap scenarios based on method
            switch ($method) {
                case 'progressive':
                    $caps = self::generateProgressiveCaps(
                        $baseCap,
                        $capTighteningConfig['num_scenarios'] ?? 5,
                        $capTighteningConfig['reduction_percentage'] ?? 0.05,
                        $capTighteningConfig['min_cap'] ?? null
                    );
                    break;
                    
                case 'fixed_step':
                    $caps = self::generateFixedStepCaps(
                        $baseCap,
                        $capTighteningConfig['num_scenarios'] ?? 5,
                        $capTighteningConfig['step_size'] ?? 100000,
                        $capTighteningConfig['min_cap'] ?? null
                    );
                    break;
                    
                case 'custom':
                    $caps = self::generateCustomCaps(
                        $baseCap,
                        $capTighteningConfig['reduction_percentages'] ?? [0.0, 0.05, 0.10, 0.15, 0.20]
                    );
                    break;
                    
                default:
                    throw new Exception("Unknown cap tightening method: $method");
            }
            
            // Create expanded rows for each cap scenario
            foreach ($caps as $cap) {
                $expandedRow = $baseConfigRow;
                $expandedRow['strategy_values'] = (string)$cap;
                $expandedRow['_cap_scenario_index'] = array_search($cap, $caps);
                $expandedRow['_base_cap'] = $baseCap;
                $expandedRows[] = $expandedRow;
            }
        }
        
        return $expandedRows;
    }
    
    /**
     * Generate a summary of cap tightening scenarios for documentation.
     * 
     * @param int $baseCap Base cap value
     * @param array $caps Generated cap values
     * @return string Formatted summary
     */
    public static function generateScenarioSummary(int $baseCap, array $caps): string {
        $summary = "Cap Tightening Scenarios Summary\n";
        $summary .= "Base Cap: " . number_format($baseCap) . "\n";
        $summary .= "Number of Scenarios: " . count($caps) . "\n\n";
        $summary .= "Scenario Breakdown:\n";
        $summary .= str_repeat("-", 60) . "\n";
        
        foreach ($caps as $index => $cap) {
            $reduction = $baseCap > 0 ? (($baseCap - $cap) / $baseCap) * 100 : 0;
            $summary .= sprintf(
                "Scenario %d: %s (%.2f%% reduction from base)\n",
                $index + 1,
                number_format($cap),
                $reduction
            );
        }
        
        return $summary;
    }
}
