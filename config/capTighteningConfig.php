<?php

/**
 * Configuration for cap tightening scenarios.
 * 
 * This file defines how cap tightening scenarios should be generated for
 * compliance pathway cost projection analysis.
 * 
 * Usage:
 * - Set 'enabled' to true to enable cap tightening scenario generation
 * - Choose a method: 'progressive', 'fixed_step', or 'custom'
 * - Configure parameters based on the selected method
 */

return [
    // Enable/disable cap tightening scenario generation
    'enabled' => false,
    
    // Method for generating cap scenarios:
    // - 'progressive': Reduce cap by a percentage each step
    // - 'fixed_step': Reduce cap by a fixed amount each step
    // - 'custom': Use custom reduction percentages
    'method' => 'progressive',
    
    // Configuration for 'progressive' method
    'progressive' => [
        'num_scenarios' => 5,           // Number of scenarios to generate
        'reduction_percentage' => 0.05,  // 5% reduction per scenario
        'min_cap' => null                // Optional minimum cap (null = no limit)
    ],
    
    // Configuration for 'fixed_step' method
    'fixed_step' => [
        'num_scenarios' => 5,           // Number of scenarios to generate
        'step_size' => 100000,          // Fixed reduction amount per scenario
        'min_cap' => null                // Optional minimum cap (null = no limit)
    ],
    
    // Configuration for 'custom' method
    'custom' => [
        'reduction_percentages' => [     // Array of reduction percentages from base
            0.0,    // Base scenario (0% reduction)
            0.05,   // 5% reduction
            0.10,   // 10% reduction
            0.15,   // 15% reduction
            0.20    // 20% reduction
        ]
    ],
    
    // Analysis options
    'analysis' => [
        'generate_report' => true,       // Generate text report
        'export_csv' => true,            // Export results to CSV
        'report_file' => 'compliance_pathway_analysis.txt',
        'csv_file' => 'compliance_pathway_analysis.csv'
    ]
];
