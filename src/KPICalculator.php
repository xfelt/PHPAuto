<?php

/**
 * KPI Calculator for DDMRP Buffer Positioning and Carbon Footprint Model
 * 
 * Computes all KPIs required for the Journal of Cleaner Production article:
 * - Cost decomposition
 * - Service performance
 * - Carbon/environmental metrics
 * - Inventory metrics (WIP, DIO, ITR)
 * - DDMRP structural decisions
 * - Supplier selection metrics
 * - Computational performance
 */
class KPICalculator {
    
    private $baseline_emissions = [];
    private $baseline_costs = [];
    private $baseline_wip = [];
    private $baseline_dio = [];
    
    /**
     * Set baseline values for relative calculations
     */
    public function setBaseline(string $instanceId, array $baselineData): void {
        if (isset($baselineData['total_emissions'])) {
            $this->baseline_emissions[$instanceId] = (float)$baselineData['total_emissions'];
        }
        if (isset($baselineData['total_cost'])) {
            $this->baseline_costs[$instanceId] = (float)$baselineData['total_cost'];
        }
        if (isset($baselineData['WIP'])) {
            $this->baseline_wip[$instanceId] = (float)$baselineData['WIP'];
        }
        if (isset($baselineData['DIO'])) {
            $this->baseline_dio[$instanceId] = (float)$baselineData['DIO'];
        }
    }
    
    /**
     * Compute all KPIs from solver result
     */
    public function computeAllKPIs(array $result, array $runConfig, string $instanceId): array {
        $kpis = [
            // Run identification
            'run_id' => $runConfig['PREFIXE'] ?? 'unknown',
            'instance_id' => $instanceId,
            'bom_file' => $runConfig['_NODE_FILE_'] ?? '',
            'strategy' => $this->extractStrategy($runConfig),
            'model_type' => $runConfig['MODEL_TYPE'] ?? 'PLM',
            'service_time_promised' => $runConfig['_SERVICE_T_'] ?? 1,
            'suppliers_available' => $runConfig['_NBSUPP_'] ?? 10,
            'tax_rate' => $runConfig['_EMISTAXE_'] ?? 0.0,
            'cap_value' => $runConfig['_EMISCAP_'] ?? 2500000,
            
            // Cost KPIs
            'cost' => $this->computeCostKPIs($result, $runConfig),
            
            // Service KPIs
            'service' => $this->computeServiceKPIs($result, $runConfig),
            
            // Carbon KPIs
            'carbon' => $this->computeCarbonKPIs($result, $instanceId),
            
            // Inventory KPIs
            'inventory' => $this->computeInventoryKPIs($result, $instanceId),
            
            // DDMRP structural KPIs
            'ddmrp' => $this->computeDDMRPKPIs($result),
            
            // Supplier KPIs
            'supplier' => $this->computeSupplierKPIs($result),
            
            // Computational KPIs
            'computational' => $this->computeComputationalKPIs($result)
        ];
        
        return $kpis;
    }
    
    /**
     * Extract strategy from run config
     */
    private function extractStrategy(array $runConfig): string {
        $modelFile = $runConfig['MODEL_FILE'] ?? '';
        if (strpos($modelFile, 'Hybrid') !== false) return 'EMISHYBRID';
        if (strpos($modelFile, 'Cap') !== false) return 'EMISCAP';
        if (strpos($modelFile, 'Tax') !== false) return 'EMISTAXE';
        return 'UNKNOWN';
    }
    
    /**
     * Compute cost-related KPIs
     */
    private function computeCostKPIs(array $result, array $runConfig): array {
        $totalCostTS = $this->extractNumeric($result, ['TS', 'TotalCostTS']);
        $totalCostCS = $this->extractNumeric($result, ['CS', 'TotalCostCS']);
        $rawMCost = $this->extractNumeric($result, ['RawMCost']);
        $inventCost = $this->extractNumeric($result, ['InventCost']);
        $emisCost = $this->extractNumeric($result, ['EmisCost', 'TaxCost']);
        $objective = $this->extractNumeric($result, ['Result.fctObj', 'Result.Objective']);
        
        // If Result is an array, extract from it
        if (isset($result['Result']) && is_array($result['Result'])) {
            $objective = $result['Result']['fctObj'] ?? $result['Result']['Objective'] ?? $objective;
            $totalCostTS = $result['Result']['StCosts'] ?? $result['Result']['TotalCost'] ?? $totalCostTS;
        }
        
        // Get emissions and tax rate for calculating carbon cost
        $emissions = $this->extractNumeric($result, ['E', 'Emis']);
        $taxRate = (float)($runConfig['_EMISTAXE_'] ?? 0.0);
        
        // Calculate carbon cost if not directly available
        if ($emisCost === null && $emissions !== null && $taxRate > 0) {
            $emisCost = $taxRate * $emissions;
        }
        
        // If we have TS (total with tax) but not CS (cost without tax), calculate CS
        if ($totalCostCS === null && $totalCostTS !== null) {
            if ($emisCost !== null) {
                // CS = TS - EmisCost
                $totalCostCS = $totalCostTS - $emisCost;
            } elseif ($taxRate == 0) {
                // If no tax, TS = CS
                $totalCostCS = $totalCostTS;
            }
        }
        
        // Calculate carbon cost from the difference if both TS and CS are available
        if ($emisCost === null && $totalCostTS !== null && $totalCostCS !== null) {
            $emisCost = $totalCostTS - $totalCostCS;
        }
        
        return [
            'objective_value' => $objective,
            'total_cost_with_tax' => $totalCostTS,
            'total_cost_without_tax' => $totalCostCS,
            'procurement_cost' => $rawMCost,
            'inventory_holding_cost' => $inventCost,
            'carbon_cost' => $emisCost
        ];
    }
    
    /**
     * Compute service-related KPIs
     */
    private function computeServiceKPIs(array $result, array $runConfig): array {
        $promisedSvT = $runConfig['_SERVICE_T_'] ?? 1;
        $achievedSvT = null;
        
        // Extract a[0] which is the achieved service time at root node
        if (isset($result['A']) && is_array($result['A']) && count($result['A']) > 0) {
            $achievedSvT = (int)$result['A'][0];
        }
        
        $slack = ($achievedSvT !== null) ? ($promisedSvT - $achievedSvT) : null;
        $isBinding = ($slack !== null) ? ($slack <= 0) : null;
        
        return [
            'promised_service_time' => $promisedSvT,
            'achieved_service_time' => $achievedSvT,
            'service_constraint_slack' => $slack,
            'is_constraint_binding' => $isBinding
        ];
    }
    
    /**
     * Compute carbon/environmental KPIs
     */
    private function computeCarbonKPIs(array $result, string $instanceId): array {
        $totalEmissions = $this->extractNumeric($result, ['E', 'Emis', 'Result.emiss', 'Result.Emiss']);
        
        if (isset($result['Result']) && is_array($result['Result'])) {
            $totalEmissions = $result['Result']['emiss'] ?? $result['Result']['Emiss'] ?? $totalEmissions;
        }
        
        // Calculate emission reduction vs baseline
        $reductionAbs = null;
        $reductionPct = null;
        
        if ($totalEmissions !== null && isset($this->baseline_emissions[$instanceId])) {
            $baseline = $this->baseline_emissions[$instanceId];
            if ($baseline > 0) {
                $reductionAbs = $baseline - $totalEmissions;
                $reductionPct = ($reductionAbs / $baseline) * 100;
            }
        }
        
        return [
            'total_emissions' => $totalEmissions,
            'baseline_emissions' => $this->baseline_emissions[$instanceId] ?? null,
            'emission_reduction_absolute' => $reductionAbs,
            'emission_reduction_percent' => $reductionPct
        ];
    }
    
    /**
     * Compute inventory KPIs (WIP, DIO, ITR)
     */
    private function computeInventoryKPIs(array $result, string $instanceId): array {
        // DIO = sum of decoupled lead times
        $dio = null;
        if (isset($result['DIO'])) {
            $dio = (float)$result['DIO'];
        } elseif (isset($result['A']) && is_array($result['A'])) {
            $dio = array_sum($result['A']);
        }
        
        // WIP from result
        $wip = $this->extractNumeric($result, ['WIP']);
        
        // Calculate improvements vs baseline
        $dioImprovementPct = null;
        $wipReductionPct = null;
        
        if ($dio !== null && isset($this->baseline_dio[$instanceId]) && $this->baseline_dio[$instanceId] > 0) {
            $dioImprovementPct = (($this->baseline_dio[$instanceId] - $dio) / $this->baseline_dio[$instanceId]) * 100;
        }
        
        if ($wip !== null && isset($this->baseline_wip[$instanceId]) && $this->baseline_wip[$instanceId] > 0) {
            $wipReductionPct = (($this->baseline_wip[$instanceId] - $wip) / $this->baseline_wip[$instanceId]) * 100;
        }
        
        // ITR estimation (if we have cost data)
        $itr = null;
        $totalCost = $this->extractNumeric($result, ['CS', 'TotalCostCS']);
        if ($totalCost !== null && $wip !== null && $wip > 0) {
            // ITR = COGS / Average Inventory (approximated)
            $itr = $totalCost / $wip;
        }
        
        return [
            'WIP_level' => $wip,
            'WIP_baseline' => $this->baseline_wip[$instanceId] ?? null,
            'WIP_reduction_percent' => $wipReductionPct,
            'DIO' => $dio,
            'DIO_baseline' => $this->baseline_dio[$instanceId] ?? null,
            'DIO_improvement_percent' => $dioImprovementPct,
            'ITR' => $itr
        ];
    }
    
    /**
     * Compute DDMRP structural KPIs
     */
    private function computeDDMRPKPIs(array $result): array {
        $bufferPositions = [];
        $bufferCount = 0;
        $decoupledLeadTimes = [];
        
        // Extract buffer positions from X array
        if (isset($result['X']) && is_array($result['X'])) {
            foreach ($result['X'] as $i => $val) {
                $decoupledLeadTimes[$i] = isset($result['A'][$i]) ? (int)$result['A'][$i] : 0;
                if ((int)$val == 1) {
                    $bufferPositions[] = $i;
                    $bufferCount++;
                }
            }
        }
        
        // Alternative: extract from DELIVER if X not available
        if ($bufferCount === 0 && isset($result['DELIVER']) && is_array($result['DELIVER'])) {
            $products = [];
            foreach ($result['DELIVER'] as $delivery) {
                if (preg_match('/P(\d+)/', $delivery, $matches)) {
                    $products[(int)$matches[1]] = true;
                }
            }
            $bufferPositions = array_keys($products);
            $bufferCount = count($bufferPositions);
        }
        
        // Calculate average decoupled lead time
        $avgDLT = 0;
        if (!empty($decoupledLeadTimes)) {
            $avgDLT = array_sum($decoupledLeadTimes) / count($decoupledLeadTimes);
        }
        
        return [
            'buffer_count' => $bufferCount,
            'buffer_positions' => $bufferPositions,
            'decoupled_lead_times' => $decoupledLeadTimes,
            'average_decoupled_lead_time' => $avgDLT
        ];
    }
    
    /**
     * Compute supplier selection KPIs
     */
    private function computeSupplierKPIs(array $result): array {
        $selectedSuppliers = [];
        $orderQuantities = [];
        $suppliersUsed = 0;
        
        // Extract from DELIVER array
        if (isset($result['DELIVER']) && is_array($result['DELIVER'])) {
            foreach ($result['DELIVER'] as $delivery) {
                // Format: S<supplier_id>=>P<product_id>
                if (preg_match('/S(\d+)=>P(\d+)/', $delivery, $matches)) {
                    $suppId = (int)$matches[1];
                    $prodId = (int)$matches[2];
                    
                    if (!isset($selectedSuppliers[$prodId])) {
                        $selectedSuppliers[$prodId] = [];
                    }
                    $selectedSuppliers[$prodId][] = $suppId;
                }
            }
            
            // Count unique suppliers used
            $allSuppliers = [];
            foreach ($selectedSuppliers as $suppliers) {
                foreach ($suppliers as $s) {
                    $allSuppliers[$s] = true;
                }
            }
            $suppliersUsed = count($allSuppliers);
        }
        
        return [
            'suppliers_used' => $suppliersUsed,
            'selected_suppliers_per_product' => $selectedSuppliers,
            'supplier_distribution' => $this->computeSupplierDistribution($selectedSuppliers)
        ];
    }
    
    /**
     * Compute supplier distribution statistics
     */
    private function computeSupplierDistribution(array $selectedSuppliers): array {
        if (empty($selectedSuppliers)) {
            return [];
        }
        
        $supplierCounts = [];
        foreach ($selectedSuppliers as $suppliers) {
            foreach ($suppliers as $s) {
                if (!isset($supplierCounts[$s])) {
                    $supplierCounts[$s] = 0;
                }
                $supplierCounts[$s]++;
            }
        }
        
        return $supplierCounts;
    }
    
    /**
     * Compute computational performance KPIs
     */
    private function computeComputationalKPIs(array $result): array {
        $runtime = -1;
        $status = 'UNKNOWN';
        $mipGap = null;
        
        // Extract runtime
        if (isset($result['CplexRunTime'])) {
            $runtimeStr = $result['CplexRunTime'];
            // CPLEX format: "Total (root+branch&cut) = 0.16 sec"
            if (preg_match('/([\d,\.]+)\s*sec/', $runtimeStr, $matches)) {
                $runtime = (float)str_replace(',', '.', $matches[1]);
            }
            // CP Optimizer format: "CP Time = 0,16"
            elseif (preg_match('/CP Time\s*=\s*([\d,\.]+)/', $runtimeStr, $matches)) {
                $runtime = (float)str_replace(',', '.', $matches[1]);
            }
            // Just a number
            elseif (is_numeric(str_replace(',', '.', $runtimeStr))) {
                $runtime = (float)str_replace(',', '.', $runtimeStr);
            }
        }
        
        // Determine status
        if (isset($result['status'])) {
            $status = $result['status'];
        } elseif (isset($result['_is_infeasible']) || 
                  (isset($result['_raw_output']) && preg_match('/Infeasibility|no solution/i', $result['_raw_output']))) {
            $status = 'INFEASIBLE';
        } elseif (isset($result['E']) || isset($result['TS']) || isset($result['Result'])) {
            $status = 'OPTIMAL';
        } elseif ($runtime >= 1795) { // Near time limit
            $status = 'TIMEOUT';
        }
        
        return [
            'solver_status' => $status,
            'runtime_sec' => $runtime,
            'mip_gap' => $mipGap
        ];
    }
    
    /**
     * Helper to extract numeric value from result
     */
    private function extractNumeric(array $result, array $keys): ?float {
        foreach ($keys as $key) {
            // Handle nested keys (e.g., "Result.fctObj")
            $parts = explode('.', $key);
            $value = $result;
            
            foreach ($parts as $part) {
                if (is_array($value) && isset($value[$part])) {
                    $value = $value[$part];
                } else {
                    $value = null;
                    break;
                }
            }
            
            if ($value !== null && is_numeric($value)) {
                return (float)$value;
            }
        }
        
        return null;
    }
    
    /**
     * Flatten KPIs for CSV export
     */
    public function flattenKPIs(array $kpis): array {
        $flat = [
            'run_id' => $kpis['run_id'],
            'instance_id' => $kpis['instance_id'],
            'bom_file' => $kpis['bom_file'],
            'strategy' => $kpis['strategy'],
            'model_type' => $kpis['model_type'],
            'service_time_promised' => $kpis['service_time_promised'],
            'suppliers_available' => $kpis['suppliers_available'],
            'tax_rate' => $kpis['tax_rate'],
            'cap_value' => $kpis['cap_value'],
            
            // Cost
            'objective_value' => $kpis['cost']['objective_value'],
            'total_cost_with_tax' => $kpis['cost']['total_cost_with_tax'],
            'total_cost_without_tax' => $kpis['cost']['total_cost_without_tax'],
            'procurement_cost' => $kpis['cost']['procurement_cost'],
            'inventory_holding_cost' => $kpis['cost']['inventory_holding_cost'],
            'carbon_cost' => $kpis['cost']['carbon_cost'],
            
            // Service
            'achieved_service_time' => $kpis['service']['achieved_service_time'],
            'service_constraint_binding' => $kpis['service']['is_constraint_binding'] ? 1 : 0,
            
            // Carbon
            'total_emissions' => $kpis['carbon']['total_emissions'],
            'baseline_emissions' => $kpis['carbon']['baseline_emissions'],
            'emission_reduction_pct' => $kpis['carbon']['emission_reduction_percent'],
            
            // Inventory
            'WIP' => $kpis['inventory']['WIP_level'],
            'WIP_reduction_pct' => $kpis['inventory']['WIP_reduction_percent'],
            'DIO' => $kpis['inventory']['DIO'],
            'DIO_improvement_pct' => $kpis['inventory']['DIO_improvement_percent'],
            'ITR' => $kpis['inventory']['ITR'],
            
            // DDMRP
            'buffer_count' => $kpis['ddmrp']['buffer_count'],
            'avg_decoupled_lead_time' => $kpis['ddmrp']['average_decoupled_lead_time'],
            
            // Supplier
            'suppliers_used' => $kpis['supplier']['suppliers_used'],
            
            // Computational
            'solver_status' => $kpis['computational']['solver_status'],
            'runtime_sec' => $kpis['computational']['runtime_sec'],
            'mip_gap' => $kpis['computational']['mip_gap']
        ];
        
        return $flat;
    }
    
    /**
     * Get CSV headers for flattened KPIs
     */
    public static function getCSVHeaders(): array {
        return [
            'run_id', 'instance_id', 'bom_file', 'strategy', 'model_type',
            'service_time_promised', 'suppliers_available', 'tax_rate', 'cap_value',
            'objective_value', 'total_cost_with_tax', 'total_cost_without_tax',
            'procurement_cost', 'inventory_holding_cost', 'carbon_cost',
            'achieved_service_time', 'service_constraint_binding',
            'total_emissions', 'baseline_emissions', 'emission_reduction_pct',
            'WIP', 'WIP_reduction_pct', 'DIO', 'DIO_improvement_pct', 'ITR',
            'buffer_count', 'avg_decoupled_lead_time',
            'suppliers_used',
            'solver_status', 'runtime_sec', 'mip_gap'
        ];
    }
    
    /**
     * Calculate S-ROI (Sustainable Return on Investment)
     */
    public function calculateSROI(array $kpis, array $sroiParams = []): array {
        $economicReturns = 0;
        $environmentalReturns = 0;
        $socialReturns = 0;
        
        // Economic returns: cost savings vs baseline
        if (isset($kpis['cost']['total_cost_without_tax']) && 
            isset($this->baseline_costs[$kpis['instance_id']])) {
            $costSaving = $this->baseline_costs[$kpis['instance_id']] - $kpis['cost']['total_cost_without_tax'];
            $economicReturns = $costSaving;
        }
        
        // Environmental returns: monetized emission reduction
        $carbonPrice = $sroiParams['carbon_price_per_ton'] ?? 50; // $/ton CO2
        $emissionReduction = $kpis['carbon']['emission_reduction_absolute'] ?? 0;
        if ($emissionReduction > 0) {
            // Convert emission units to tons if needed (assuming kg CO2)
            $emissionReductionTons = $emissionReduction / 1000;
            $environmentalReturns = $emissionReductionTons * $carbonPrice;
        }
        
        // Social returns (placeholder - would need additional data)
        // Could include: labor practice improvements, supply chain transparency, etc.
        $socialReturns = 0;
        
        // Implementation investment (placeholder)
        $investment = $sroiParams['implementation_cost'] ?? 100000;
        
        // Calculate S-ROI
        $totalReturns = $economicReturns + $environmentalReturns + $socialReturns;
        $sroi = ($investment > 0) ? ($totalReturns / $investment) : null;
        
        return [
            'economic_returns' => $economicReturns,
            'environmental_returns' => $environmentalReturns,
            'social_returns' => $socialReturns,
            'total_returns' => $totalReturns,
            'investment' => $investment,
            'sroi_ratio' => $sroi,
            'assumptions' => [
                'carbon_price_per_ton' => $carbonPrice,
                'implementation_cost' => $investment
            ]
        ];
    }
}
