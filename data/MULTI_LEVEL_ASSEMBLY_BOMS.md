# Multi-Level Assembly BOMs Documentation

## Overview

This document describes the multi-level assembly BOM (Bill of Materials) structures created specifically to report memory and runtime scalability for realistic complexity scenarios. These BOMs feature **4-6 assembly levels** with deep hierarchical structures, enabling comprehensive scalability analysis of optimization algorithms.

## Purpose

The multi-level assembly BOMs are designed to:

1. **Report Memory Scalability**: Test how memory consumption scales with increasing BOM depth and complexity
2. **Report Runtime Scalability**: Measure computational time growth as assembly levels increase
3. **Realistic Complexity Testing**: Provide BOMs with realistic manufacturing structures (4-6 levels deep)
4. **Algorithm Performance Analysis**: Enable comparison of algorithm performance across different depth levels
5. **Resource Planning**: Support capacity planning for production systems handling complex multi-level assemblies

## Structure Characteristics

### Key Features

1. **Multiple Assembly Levels**: Each BOM has 4-6 distinct assembly levels
2. **Deep Hierarchies**: Components are organized in deep tree structures
3. **Realistic Manufacturing**: Structures represent realistic multi-stage manufacturing processes
4. **Progressive Complexity**: BOMs range from moderate (30 components) to complex (70 components)
5. **Consistent Branching**: Each level maintains consistent branching patterns for scalability analysis

### Visual Representation

```
Multi-Level Assembly Structure (4 levels):
                   0 (Root - Final Assembly)
                  / \
                 /   \
                /     \
               /       \
              /         \
             /           \
            /             \
           /               \
          /                 \
         /                   \
        /                     \
       /                       \
      1                        2 (Level 1: Major Sub-assemblies)
     / \                      / \
    /   \                    /   \
   3     4                  5     6 (Level 2: Sub-components)
  / \   / \                / \   / \
 7  8  9  10             11 12 13 14 (Level 3: Parts)
/ \ / \ / \ / \          / \ / \ / \ / \
... ... ... ...         ... ... ... ... (Level 4: Raw Materials/Leaf Nodes)
```

## Available Multi-Level Assembly BOMs

### 1. bom_supemis_ml4_30.csv
- **Assembly Levels**: 4 levels
- **Total Components**: 30 nodes (0-30)
- **Structure**: 
  - Level 0: Root (1 node)
  - Level 1: Major sub-assemblies (2 nodes: 1, 2)
  - Level 2: Sub-components (4 nodes: 3-6)
  - Level 3: Parts (8 nodes: 7-14)
  - Level 4: Leaf nodes (15 nodes: 16-30)
- **Use Case**: Baseline 4-level assembly for scalability comparison
- **Scalability Focus**: Memory and runtime for moderate-depth structures

### 2. bom_supemis_ml5_45.csv
- **Assembly Levels**: 5 levels
- **Total Components**: 45 nodes (0-45)
- **Structure**:
  - Level 0: Root (1 node)
  - Level 1: Major sub-assemblies (2 nodes: 1, 2)
  - Level 2: Sub-components (4 nodes: 3-6)
  - Level 3: Parts (8 nodes: 7-14)
  - Level 4: Sub-parts (16 nodes: 15-30)
  - Level 5: Leaf nodes (15 nodes: 31-45)
- **Use Case**: 5-level assembly testing, deeper hierarchy
- **Scalability Focus**: Impact of additional assembly level on performance

### 3. bom_supemis_ml4_55.csv
- **Assembly Levels**: 4 levels
- **Total Components**: 55 nodes (0-55)
- **Structure**:
  - Level 0: Root (1 node)
  - Level 1: Major sub-assemblies (5 nodes: 1-5)
  - Level 2: Sub-components (10 nodes: 6-15)
  - Level 3: Parts (20 nodes: 16-35)
  - Level 4: Leaf nodes (20 nodes: 36-55)
- **Use Case**: Wide 4-level assembly with more components per level
- **Scalability Focus**: Impact of width (more components) vs. depth

### 4. bom_supemis_ml5_65.csv
- **Assembly Levels**: 5 levels
- **Total Components**: 65 nodes (0-65)
- **Structure**:
  - Level 0: Root (1 node)
  - Level 1: Major sub-assemblies (5 nodes: 1-5)
  - Level 2: Sub-components (10 nodes: 6-15)
  - Level 3: Parts (20 nodes: 16-35)
  - Level 4: Sub-parts (20 nodes: 36-55)
  - Level 5: Leaf nodes (10 nodes: 56-65)
- **Use Case**: Large 5-level assembly combining depth and width
- **Scalability Focus**: Maximum realistic complexity for 5-level structures

### 5. bom_supemis_ml6_70.csv
- **Assembly Levels**: 6 levels (maximum depth)
- **Total Components**: 70 nodes (0-70)
- **Structure**:
  - Level 0: Root (1 node)
  - Level 1: Major sub-assemblies (2 nodes: 1, 2)
  - Level 2: Sub-assemblies (4 nodes: 3-6)
  - Level 3: Sub-components (8 nodes: 7-14)
  - Level 4: Parts (16 nodes: 15-30)
  - Level 5: Sub-parts (16 nodes: 31-46)
  - Level 6: Leaf nodes (24 nodes: 47-70)
- **Use Case**: Maximum depth assembly structure
- **Scalability Focus**: Extreme depth testing, memory and runtime limits

## Scalability Analysis

### Memory Scalability

Multi-level assembly structures impact memory consumption through:

1. **Tree Traversal**: Deeper trees require more memory for traversal structures
2. **State Space**: Each assembly level adds to the decision state space
3. **Buffer Storage**: Multiple levels require buffer state tracking at each level
4. **Supplier Mapping**: Deep hierarchies multiply supplier assignment combinations

**Expected Memory Growth**:
- Linear growth with number of nodes
- Exponential growth with depth (due to state space combinations)
- Polynomial growth with width × depth

### Runtime Scalability

Computational time scales with:

1. **Depth Impact**: Each additional level multiplies decision points
2. **Width Impact**: More components per level increase coordination complexity
3. **Assembly Coordination**: Multiple levels require synchronization calculations
4. **Optimization Complexity**: Deeper structures increase search space

**Expected Runtime Growth**:
- Polynomial with number of nodes: O(n^k) where k depends on algorithm
- Exponential with depth for exact algorithms
- Linear with depth for heuristic algorithms (with good pruning)

### Comparison Matrix

| BOM Type | Levels | Nodes | Width Factor | Depth Factor | Memory Complexity | Runtime Complexity |
|----------|--------|-------|--------------|--------------|-------------------|-------------------|
| ml4_30 | 4 | 30 | Moderate | Moderate | Baseline | Baseline |
| ml5_45 | 5 | 45 | Moderate | High | +50% nodes, +1 level | Higher |
| ml4_55 | 4 | 55 | High | Moderate | +83% nodes, same depth | Moderate increase |
| ml5_65 | 5 | 65 | High | High | +117% nodes, +1 level | High |
| ml6_70 | 6 | 70 | Moderate | Very High | +133% nodes, +2 levels | Very High |

## Data Structure

### BOM File Format
Each BOM file follows the standard format:
- **ind**: Node index (0 = root, 1-N = assembly nodes)
- **t_process**: Process type (2-6, indicating assembly complexity)
- **parent**: Parent node (hierarchical relationship)
- **unit_price**: Unit price (decreases with depth, realistic manufacturing)
- **rqtf**: Required quantity factor (typically 1 for assembly levels)
- **aih_cost**: Average inventory holding cost (0,25)
- **var_factor**: Variability factor (0,5)
- **lt_factor**: Lead time factor (0,8)
- **cycle**: Cycle time (1)
- **minOrder**: Minimum order quantity (0)
- **facility_emis**: Facility emissions (decreases with depth)
- **inventory_emis**: Inventory emissions (decreases with depth)
- **trsp_emis**: Transportation emissions (1500)

### Supplier List Format
Each supplier list file (supp_list_mlN_X.csv) contains:
- **nb_nodes**: Maximum node index
- **nb_suppliers**: Number of suppliers (10)
- **id_nodes**: List of leaf nodes (nodes with no children) that have supplier assignments
- **list_suppliers**: Available suppliers for each leaf node (1-20)

**Note**: Only leaf nodes (final procurement points) have direct supplier assignments, representing raw materials or purchased components at the deepest level.

## Usage for Scalability Testing

### Memory Profiling

1. **Baseline Measurement**: Start with ml4_30 to establish baseline memory usage
2. **Depth Impact**: Compare ml4_30 vs. ml5_45 vs. ml6_70 (same width, increasing depth)
3. **Width Impact**: Compare ml4_30 vs. ml4_55 (same depth, increasing width)
4. **Combined Impact**: Analyze ml5_65 (both depth and width)

### Runtime Profiling

1. **Execution Time**: Measure total execution time for each BOM
2. **Per-Level Time**: Profile time spent at each assembly level
3. **Algorithm Comparison**: Compare different algorithms across BOM sizes
4. **Scaling Analysis**: Calculate scaling factors (linear, polynomial, exponential)

### Performance Metrics

Key metrics to track:
- **Memory Usage**: Peak memory, average memory, memory per node
- **Runtime**: Total time, time per node, time per level
- **Scalability Factor**: How performance degrades with size/depth
- **Algorithm Efficiency**: Comparison of different optimization approaches

## Research Applications

These BOMs enable research into:

1. **Scalability Analysis**: How algorithms scale with BOM complexity
2. **Memory Optimization**: Strategies for reducing memory footprint
3. **Runtime Optimization**: Techniques for improving computational speed
4. **Algorithm Selection**: Choosing appropriate algorithms for different BOM sizes
5. **Resource Planning**: Capacity planning for production systems
6. **Performance Benchmarking**: Standardized tests for algorithm comparison

## Comparison with Other BOM Types

| BOM Category | Depth Range | Typical Size | Use Case |
|--------------|-------------|--------------|----------|
| Simple BOMs | 2-3 levels | 2-10 nodes | Basic functionality testing |
| Medium BOMs | 3-4 levels | 13-35 nodes | Standard production scenarios |
| Complex BOMs | 3-4 levels | 40-150 nodes | Large-scale production |
| **Multi-Level Assembly** | **4-6 levels** | **30-70 nodes** | **Scalability testing** |
| Parallel Branch | 1-4 levels | 10-25 nodes | Structural pattern testing |

## Notes

- All BOMs maintain realistic manufacturing hierarchies
- Prices and emissions decrease with depth (realistic cost structure)
- Each level represents a distinct assembly stage
- Leaf nodes are procurement points (supplier assignments)
- Structures are balanced for consistent scalability analysis
- These BOMs complement other BOM types for comprehensive testing

## File Naming Convention

- BOM files: `bom_supemis_mlN_X.csv` where:
  - N = number of assembly levels (4, 5, or 6)
  - X = total number of nodes
- Supplier lists: `supp_list_mlN_X.csv` (matching BOM file)

## Integration with Main System

These BOMs can be used in the same way as standard BOMs:

```php
// In baseConfig.csv or configuration
items: 30  // For ml4_30 (adjust based on max node index)
_NODE_FILE_: bom_supemis_ml4_30.csv
_NODE_SUPP_FILE_: supp_list_ml4_30.csv
```

## Expected Scalability Results

### Memory Scaling
- **ml4_30**: Baseline memory usage
- **ml5_45**: ~1.5x memory (50% more nodes, 1 more level)
- **ml4_55**: ~1.8x memory (83% more nodes, same depth)
- **ml5_65**: ~2.2x memory (117% more nodes, 1 more level)
- **ml6_70**: ~2.3x memory (133% more nodes, 2 more levels)

### Runtime Scaling
- **ml4_30**: Baseline runtime
- **ml5_45**: ~2-3x runtime (depth impact)
- **ml4_55**: ~1.5-2x runtime (width impact)
- **ml5_65**: ~3-4x runtime (combined impact)
- **ml6_70**: ~4-6x runtime (maximum depth impact)

*Note: Actual scaling depends on algorithm type and implementation*

## Future Extensions

Potential enhancements:
- Asymmetric multi-level structures (different depths per branch)
- Mixed assembly types (some branches deeper than others)
- Dynamic depth structures (configurable assembly levels)
- Multi-product structures (shared components across products)
