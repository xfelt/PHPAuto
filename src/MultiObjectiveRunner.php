<?php

require_once __DIR__ . '/FileUtils.php';
require_once __DIR__ . '/CplexRunner.php';

/**
 * Multi-Objective Optimization Runner
 * Generates Pareto fronts for Cost-DIO, Cost-WIP, and Cost-Emissions
 */
class MultiObjectiveRunner {
    
    /**
     * Generate epsilon values for a given range
     * 
     * @param float $min Minimum value
     * @param float $max Maximum value
     * @param int $numPoints Number of points to generate
     * @return array Array of epsilon values
     */
    public static function generateEpsilonValues($min, $max, $numPoints = 10) {
        if ($numPoints < 2) {
            return [$max];
        }
        
        $values = [];
        $step = ($max - $min) / ($numPoints - 1);
        
        for ($i = 0; $i < $numPoints; $i++) {
            $values[] = $min + ($step * $i);
        }
        
        return $values;
    }
    
    /**
     * Find ideal and nadir points by optimizing each objective individually
     * 
     * @param array $baseRun Base run configuration
     * @param string $modelFile Model file path
     * @param string $workDir Working directory
     * @param string $oplRunPath Path to oplrun
     * @return array Array with ideal and nadir points
     */
    public static function findIdealNadirPoints($baseRun, $modelFile, $workDir, $oplRunPath) {
        $points = [
            'ideal' => ['Cost' => null, 'DIO' => null, 'WIP' => null, 'Emissions' => null],
            'nadir' => ['Cost' => null, 'DIO' => null, 'WIP' => null, 'Emissions' => null],
            'all_solutions' => []
        ];
        
        // Set very large epsilon values (effectively no constraint)
        $largeValue = 1e10;
        
        // Optimize each objective individually
        for ($obj = 1; $obj <= 4; $obj++) {
            $run = $baseRun;
            $run['_OBJ_PRIMARY_'] = $obj;
            $run['_EPSILON_DIO_'] = $largeValue;
            $run['_EPSILON_WIP_'] = $largeValue;
            $run['_EPSILON_EMIS_'] = $largeValue;
            
            $prefix = $run['PREFIXE'] . '_IDEAL_OBJ' . $obj;
            $run['PREFIXE'] = $prefix;
            
            try {
                $modifiedFile = FileUtils::applyDictionnary($modelFile, $run, $prefix, $workDir);
                $result = CplexRunner::run($modifiedFile, $oplRunPath);
                
                // Extract all objective values
                $cost = $result['TotalCost'] ?? $result['CS'] ?? null;
                $dio = $result['DIO'] ?? null;
                $wip = $result['WIP'] ?? null;
                $emis = $result['E'] ?? $result['Emiss'] ?? null;
                
                // Store solution
                $solution = [
                    'objective' => $obj,
                    'Cost' => $cost,
                    'DIO' => $dio,
                    'WIP' => $wip,
                    'Emissions' => $emis
                ];
                $points['all_solutions'][] = $solution;
                
                // Update ideal point (best value for the optimized objective)
                if ($obj == 1 && $cost !== null) {
                    $points['ideal']['Cost'] = $cost;
                } elseif ($obj == 2 && $dio !== null) {
                    $points['ideal']['DIO'] = $dio;
                } elseif ($obj == 3 && $wip !== null) {
                    $points['ideal']['WIP'] = $wip;
                } elseif ($obj == 4 && $emis !== null) {
                    $points['ideal']['Emissions'] = $emis;
                }
                
                // Update nadir point (worst value across all solutions)
                if ($cost !== null) {
                    if ($points['nadir']['Cost'] === null || $cost > $points['nadir']['Cost']) {
                        $points['nadir']['Cost'] = $cost;
                    }
                }
                if ($dio !== null) {
                    if ($points['nadir']['DIO'] === null || $dio > $points['nadir']['DIO']) {
                        $points['nadir']['DIO'] = $dio;
                    }
                }
                if ($wip !== null) {
                    if ($points['nadir']['WIP'] === null || $wip > $points['nadir']['WIP']) {
                        $points['nadir']['WIP'] = $wip;
                    }
                }
                if ($emis !== null) {
                    if ($points['nadir']['Emissions'] === null || $emis > $points['nadir']['Emissions']) {
                        $points['nadir']['Emissions'] = $emis;
                    }
                }
                
            } catch (Exception $e) {
                echo "Warning: Could not find ideal point for objective $obj: " . $e->getMessage() . "\n";
            }
        }
        
        return $points;
    }
    
    /**
     * Generate Pareto front for Cost vs DIO
     * 
     * @param array $baseRun Base run configuration
     * @param string $modelFile Model file path
     * @param string $workDir Working directory
     * @param string $oplRunPath Path to oplrun
     * @param array $idealNadir Ideal and nadir points
     * @param int $numPoints Number of points in Pareto front
     * @return array Array of Pareto points
     */
    public static function generateCostDIOPareto($baseRun, $modelFile, $workDir, $oplRunPath, $idealNadir, $numPoints = 10) {
        $paretoPoints = [];
        
        if ($idealNadir['ideal']['DIO'] === null || $idealNadir['nadir']['DIO'] === null) {
            echo "Warning: DIO ideal/nadir points not found. Skipping Cost-DIO Pareto front.\n";
            return $paretoPoints;
        }
        
        $dioMin = $idealNadir['ideal']['DIO'];
        $dioMax = $idealNadir['nadir']['DIO'] * 1.2; // Add 20% margin
        
        $epsilonValues = self::generateEpsilonValues($dioMin, $dioMax, $numPoints);
        $largeValue = 1e10;
        
        foreach ($epsilonValues as $idx => $epsilonDIO) {
            $run = $baseRun;
            $run['_OBJ_PRIMARY_'] = 1; // Minimize cost
            $run['_EPSILON_DIO_'] = $epsilonDIO;
            $run['_EPSILON_WIP_'] = $largeValue; // No constraint on WIP
            $run['_EPSILON_EMIS_'] = $largeValue; // No constraint on emissions
            
            $prefix = $baseRun['PREFIXE'] . '_CDIO_' . $idx;
            $run['PREFIXE'] = $prefix;
            
            try {
                $modifiedFile = FileUtils::applyDictionnary($modelFile, $run, $prefix, $workDir);
                $result = CplexRunner::run($modifiedFile, $oplRunPath);
                
                $cost = $result['TotalCost'] ?? $result['CS'] ?? null;
                $dio = $result['DIO'] ?? null;
                $wip = $result['WIP'] ?? null;
                $emis = $result['E'] ?? $result['Emiss'] ?? null;
                
                // Check if solution is valid (at least cost and the constrained objective should be present)
                if ($cost !== null && $dio !== null) {
                    $paretoPoints[] = [
                        'Cost' => $cost,
                        'DIO' => $dio,
                        'WIP' => $wip,
                        'Emissions' => $emis,
                        'epsilon_DIO' => $epsilonDIO,
                        'prefix' => $prefix
                    ];
                } else {
                    echo "Warning: Incomplete solution for epsilon_DIO=$epsilonDIO (Cost=$cost, DIO=$dio)\n";
                }
            } catch (Exception $e) {
                echo "Warning: Could not solve for epsilon_DIO=$epsilonDIO: " . $e->getMessage() . "\n";
            }
        }
        
        return $paretoPoints;
    }
    
    /**
     * Generate Pareto front for Cost vs WIP
     */
    public static function generateCostWIPPareto($baseRun, $modelFile, $workDir, $oplRunPath, $idealNadir, $numPoints = 10) {
        $paretoPoints = [];
        
        if ($idealNadir['ideal']['WIP'] === null || $idealNadir['nadir']['WIP'] === null) {
            echo "Warning: WIP ideal/nadir points not found. Skipping Cost-WIP Pareto front.\n";
            return $paretoPoints;
        }
        
        $wipMin = $idealNadir['ideal']['WIP'];
        $wipMax = $idealNadir['nadir']['WIP'] * 1.2; // Add 20% margin
        
        $epsilonValues = self::generateEpsilonValues($wipMin, $wipMax, $numPoints);
        $largeValue = 1e10;
        
        foreach ($epsilonValues as $idx => $epsilonWIP) {
            $run = $baseRun;
            $run['_OBJ_PRIMARY_'] = 1; // Minimize cost
            $run['_EPSILON_DIO_'] = $largeValue; // No constraint on DIO
            $run['_EPSILON_WIP_'] = $epsilonWIP;
            $run['_EPSILON_EMIS_'] = $largeValue; // No constraint on emissions
            
            $prefix = $baseRun['PREFIXE'] . '_CWIP_' . $idx;
            $run['PREFIXE'] = $prefix;
            
            try {
                $modifiedFile = FileUtils::applyDictionnary($modelFile, $run, $prefix, $workDir);
                $result = CplexRunner::run($modifiedFile, $oplRunPath);
                
                $cost = $result['TotalCost'] ?? $result['CS'] ?? null;
                $dio = $result['DIO'] ?? null;
                $wip = $result['WIP'] ?? null;
                $emis = $result['E'] ?? $result['Emiss'] ?? null;
                
                // Check if solution is valid
                if ($cost !== null && $wip !== null) {
                    $paretoPoints[] = [
                        'Cost' => $cost,
                        'DIO' => $dio,
                        'WIP' => $wip,
                        'Emissions' => $emis,
                        'epsilon_WIP' => $epsilonWIP,
                        'prefix' => $prefix
                    ];
                } else {
                    echo "Warning: Incomplete solution for epsilon_WIP=$epsilonWIP (Cost=$cost, WIP=$wip)\n";
                }
            } catch (Exception $e) {
                echo "Warning: Could not solve for epsilon_WIP=$epsilonWIP: " . $e->getMessage() . "\n";
            }
        }
        
        return $paretoPoints;
    }
    
    /**
     * Generate Pareto front for Cost vs Emissions
     */
    public static function generateCostEmissionsPareto($baseRun, $modelFile, $workDir, $oplRunPath, $idealNadir, $numPoints = 10) {
        $paretoPoints = [];
        
        if ($idealNadir['ideal']['Emissions'] === null || $idealNadir['nadir']['Emissions'] === null) {
            echo "Warning: Emissions ideal/nadir points not found. Skipping Cost-Emissions Pareto front.\n";
            return $paretoPoints;
        }
        
        $emisMin = $idealNadir['ideal']['Emissions'];
        $emisMax = $idealNadir['nadir']['Emissions'] * 1.2; // Add 20% margin
        
        $epsilonValues = self::generateEpsilonValues($emisMin, $emisMax, $numPoints);
        $largeValue = 1e10;
        
        foreach ($epsilonValues as $idx => $epsilonEmis) {
            $run = $baseRun;
            $run['_OBJ_PRIMARY_'] = 1; // Minimize cost
            $run['_EPSILON_DIO_'] = $largeValue; // No constraint on DIO
            $run['_EPSILON_WIP_'] = $largeValue; // No constraint on WIP
            $run['_EPSILON_EMIS_'] = $epsilonEmis;
            
            $prefix = $baseRun['PREFIXE'] . '_CEMIS_' . $idx;
            $run['PREFIXE'] = $prefix;
            
            try {
                $modifiedFile = FileUtils::applyDictionnary($modelFile, $run, $prefix, $workDir);
                $result = CplexRunner::run($modifiedFile, $oplRunPath);
                
                $cost = $result['TotalCost'] ?? $result['CS'] ?? null;
                $dio = $result['DIO'] ?? null;
                $wip = $result['WIP'] ?? null;
                $emis = $result['E'] ?? $result['Emiss'] ?? null;
                
                // Check if solution is valid
                if ($cost !== null && $emis !== null) {
                    $paretoPoints[] = [
                        'Cost' => $cost,
                        'DIO' => $dio,
                        'WIP' => $wip,
                        'Emissions' => $emis,
                        'epsilon_Emis' => $epsilonEmis,
                        'prefix' => $prefix
                    ];
                } else {
                    echo "Warning: Incomplete solution for epsilon_Emis=$epsilonEmis (Cost=$cost, Emissions=$emis)\n";
                }
            } catch (Exception $e) {
                echo "Warning: Could not solve for epsilon_Emis=$epsilonEmis: " . $e->getMessage() . "\n";
            }
        }
        
        return $paretoPoints;
    }
    
    /**
     * Export Pareto front to CSV
     */
    public static function exportParetoToCSV($paretoPoints, $filename, $frontName) {
        if (empty($paretoPoints)) {
            return false;
        }
        
        $fp = fopen($filename, 'w');
        if (!$fp) {
            return false;
        }
        
        // Write header
        fputcsv($fp, ['Cost', 'DIO', 'WIP', 'Emissions', 'Epsilon', 'Prefix'], ';');
        
        // Write data
        foreach ($paretoPoints as $point) {
            $epsilon = $point['epsilon_DIO'] ?? $point['epsilon_WIP'] ?? $point['epsilon_Emis'] ?? '';
            fputcsv($fp, [
                $point['Cost'] ?? '',
                $point['DIO'] ?? '',
                $point['WIP'] ?? '',
                $point['Emissions'] ?? '',
                $epsilon,
                $point['prefix'] ?? ''
            ], ';');
        }
        
        fclose($fp);
        return true;
    }
}
