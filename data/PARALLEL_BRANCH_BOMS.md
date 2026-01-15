# Parallel Branch BOMs Documentation

## Overview

This document describes the parallel branch BOM (Bill of Materials) structures created specifically to quantify the impact of structure on buffer positioning. These BOMs feature **hierarchical parallelism** where multiple branches from the root node have different structural characteristics, allowing testing of buffer placement strategies across varied parallel paths.

## Purpose

The parallel branch BOMs are designed to stress test three critical aspects of supply chain structure:

1. **Different Depth-Width Combinations**: Test "wide" parents with many children versus fewer children but deeper sub-branches
2. **Strongly Unbalanced Branches**: One branch with many levels and long lead time versus another very short/fast branch, testing how the model arbitrates buffer placement and supplier choice across unequal parallel paths
3. **Parallel Delivered vs. Manufactured Branches**: One branch entirely delivered materials (direct from suppliers) versus another with several manufacturing stages, to see the interaction of supplier selection and positioning on competing branches

## Structure Characteristics

### Key Features

1. **Root Node (0)**: Always the assembly/final product node with parent = -1
2. **Parallel Branches**: Multiple branches originate from the root (parent = 0)
3. **Hierarchical Structure**: Each branch can have its own sub-components and depth
4. **Structural Variations**: Branches differ in:
   - **Width**: Number of direct children per parent
   - **Depth**: Number of levels in the branch
   - **Type**: Delivered (direct from suppliers) vs. Manufactured (with sub-components)

### Visual Representation

```
Standard Hierarchical BOM:          Parallel Branch BOM (par2):
        0                             0
       / \                           / \
      1   2                         1   9
     / \                           /|\
    3   4                         2 3 4
                                  /| |\
                                 5 6 7 8
                                 
Branch 1: Deep (4 levels)        Branch 9: Shallow (1 level, delivered)
```

## Available Parallel Branch BOMs

### 1. bom_supemis_par2.csv
- **Structure**: 2 parallel branches with extreme imbalance
- **Branch 1**: Deep manufacturing branch (4 levels: 1→2,3→4,5,6,7→8)
  - Node 1 → Nodes 2,3 → Nodes 4,5,6,7 → Node 8
  - Represents complex manufacturing with multiple stages
- **Branch 9**: Shallow delivered branch (1 level: 9)
  - Node 9 directly from root
  - Represents direct material delivery
- **Test Focus**: Unbalanced branches (deep vs. shallow), delivered vs. manufactured
- **Nodes**: 0 (root), 1-9 (10 total nodes)

### 2. bom_supemis_par3.csv
- **Structure**: 2 parallel branches with width-depth contrast
- **Branch 1**: Wide parent branch (6 direct children: 2,3,4,5,6)
  - Node 1 → Nodes 2,3,4,5,6 (all direct children)
  - Represents wide assembly with many components
- **Branch 7**: Deep manufacturing branch (4 levels: 7→8→9→10→11)
  - Node 7 → Node 8 → Node 9 → Node 10 → Node 11
  - Represents deep manufacturing chain
- **Test Focus**: Width vs. depth combinations, unbalanced branches
- **Nodes**: 0 (root), 1-11 (12 total nodes)

### 3. bom_supemis_par4.csv
- **Structure**: 4 parallel branches mixing delivered and manufactured
- **Branches 1,2,3**: Delivered branches (direct from root, no sub-components)
  - Nodes 1, 2, 3 directly from root
  - Represent direct material deliveries
- **Branch 4**: Manufactured branch (3 levels: 4→5,6→7,8,9,10)
  - Node 4 → Nodes 5,6 → Nodes 7,8,9,10
  - Represents manufacturing with sub-assemblies
- **Test Focus**: Delivered vs. manufactured branches, parallel competition
- **Nodes**: 0 (root), 1-10 (11 total nodes)

### 4. bom_supemis_par5.csv
- **Structure**: 3 parallel branches with combined patterns
- **Branch 1**: Very wide parent (6 direct children: 2,3,4,5,6)
  - Node 1 → Nodes 2,3,4,5,6 (wide assembly)
- **Branch 7**: Deep manufacturing (5 levels: 7→8,9→10,11,12,13→14,15→16,17,18)
  - Node 7 → Nodes 8,9 → Nodes 10,11,12,13 → Nodes 14,15 → Nodes 16,17,18
  - Deep manufacturing chain
- **Branches 16,17,18**: Delivered branches (direct from root)
  - Nodes 16, 17, 18 directly from root
- **Test Focus**: All patterns combined (width, depth, delivered vs. manufactured)
- **Nodes**: 0 (root), 1-18 (19 total nodes)

### 5. bom_supemis_par6.csv
- **Structure**: 3 parallel branches with maximum complexity
- **Branch 1**: Extremely wide parent (8 direct children: 2,3,4,5,6,7,8)
  - Node 1 → Nodes 2,3,4,5,6,7,8 (very wide assembly)
- **Branch 9**: Very deep manufacturing (6 levels: 9→10,11→12,13,14,15→16,17,18→19,20)
  - Node 9 → Nodes 10,11 → Nodes 12,13,14,15 → Nodes 16,17,18 → Nodes 19,20
  - Deepest manufacturing chain
- **Branches 21,22,23,24**: Delivered branches (direct from root)
  - Nodes 21, 22, 23, 24 directly from root
- **Test Focus**: Maximum complexity with all patterns
- **Nodes**: 0 (root), 1-24 (25 total nodes)

## Buffer Positioning Analysis

### Why These Structures Matter

1. **Synchronization Challenges**: 
   - Deep branches have longer lead times than shallow branches
   - Model must decide where to place buffers to synchronize parallel paths
   - Unbalanced branches create timing mismatches

2. **Supplier Selection Impact**:
   - Delivered branches: Direct supplier selection at root level
   - Manufactured branches: Supplier selection at multiple levels
   - Competition between branches for optimal supplier allocation

3. **Buffer Placement Strategies**:
   - **At root**: Synchronize all branches before final assembly
   - **At branch level**: Handle variability within each branch independently
   - **Mixed strategy**: Different buffers for different branch types

4. **Width vs. Depth Trade-offs**:
   - Wide branches: More components to coordinate, but shorter paths
   - Deep branches: Fewer components per level, but longer paths
   - Different buffer requirements for each structure

### Expected Analysis Outcomes

When analyzing these parallel branch BOMs:

- **Buffer Positioning**: 
  - Deep branches may need buffers at intermediate levels
  - Shallow/delivered branches may only need buffers at root
  - Unbalanced branches require strategic buffer placement to prevent bottlenecks

- **Supplier Selection**:
  - Delivered branches: Direct supplier optimization
  - Manufactured branches: Multi-level supplier optimization
  - Competition between branches for preferred suppliers

- **Lead Time Management**:
  - Deep branches create longer total lead times
  - Model must balance fast vs. slow branches
  - Buffer placement compensates for lead time differences

- **Algorithm Performance**:
  - Different computational complexity for wide vs. deep structures
  - Parallel branches increase decision complexity
  - Unbalanced structures test optimization robustness

## Data Structure

### BOM File Format
Each BOM file follows the standard format:
- **ind**: Node index (0 = root, 1-N = branch nodes)
- **t_process**: Process type (2 = assembly/manufacturing, higher = more complex)
- **parent**: Parent node (0 for root children, node index for sub-components)
- **unit_price**: Unit price (varies by node)
- **rqtf**: Required quantity factor
- **aih_cost**: Average inventory holding cost (0.25)
- **var_factor**: Variability factor (0.5)
- **lt_factor**: Lead time factor (0.8)
- **cycle**: Cycle time (1)
- **minOrder**: Minimum order quantity (0)
- **facility_emis**: Facility emissions
- **inventory_emis**: Inventory emissions
- **trsp_emis**: Transportation emissions (1500)

### Supplier List Format
Each supplier list file (supp_list_parN.csv) contains:
- **nb_nodes**: Maximum node index
- **nb_suppliers**: Number of suppliers (10)
- **id_nodes**: List of leaf nodes (nodes with no children) that have supplier assignments
- **list_suppliers**: Available suppliers for each leaf node (1-20)

**Note**: Only leaf nodes (nodes with no children) have direct supplier assignments, as they represent the final procurement points in each branch.

## Usage

### File Naming Convention
- BOM files: `bom_supemis_parN.csv` where N = 2-6 (test scenario number)
- Supplier lists: `supp_list_parN.csv` where N = 2-6

### Integration with Main System

These BOMs can be used in the same way as standard BOMs:
1. Reference the BOM file in configuration: `bom_supemis_parN.csv`
2. Reference the supplier list: `supp_list_parN.csv`
3. Run standard optimization algorithms
4. Compare results with standard hierarchical BOM structures

### Example Configuration

```php
// In baseConfig.csv or configuration
items: 9  // For par2 (adjust based on max node index)
_NODE_FILE_: bom_supemis_par2.csv
_NODE_SUPP_FILE_: supp_list_par2.csv
```

## Comparison Matrix

| BOM Type | Branches | Max Depth | Width | Delivered | Manufactured | Test Focus |
|----------|----------|-----------|-------|-----------|--------------|------------|
| par2 | 2 | 4 levels | 2-3 children | 1 branch | 1 branch | Unbalanced, delivered vs. manufactured |
| par3 | 2 | 4 levels | 6 children | 0 branches | 2 branches | Width vs. depth |
| par4 | 4 | 3 levels | 2-4 children | 3 branches | 1 branch | Delivered vs. manufactured |
| par5 | 3 | 5 levels | 6 children | 3 branches | 2 branches | All patterns combined |
| par6 | 3 | 6 levels | 8 children | 4 branches | 2 branches | Maximum complexity |

## Structural Patterns Tested

### Pattern 1: Depth-Width Combinations
- **Wide Shallow**: Many children, few levels (Branch 1 in par3, par5, par6)
- **Narrow Deep**: Few children per level, many levels (Branch 7 in par3, Branch 9 in par6)
- **Impact**: Different buffer requirements and coordination complexity

### Pattern 2: Unbalanced Branches
- **Deep Branch**: 4-6 levels with long lead times (Branch 1 in par2, Branch 7 in par3)
- **Shallow Branch**: 1 level with short lead times (Branch 9 in par2, Branches 16-18 in par5)
- **Impact**: Synchronization challenges, buffer placement to balance timing

### Pattern 3: Delivered vs. Manufactured
- **Delivered Branches**: Direct from suppliers, no sub-components (Branches 1-3 in par4, Branches 16-18 in par5)
- **Manufactured Branches**: Multiple manufacturing stages (Branch 4 in par4, Branch 7 in par3)
- **Impact**: Different supplier selection strategies, competition for resources

## Research Applications

These BOMs enable research into:

1. **Buffer Positioning Optimization**: Where to place buffers in parallel structures
2. **Supplier Selection**: How to allocate suppliers across competing branches
3. **Lead Time Synchronization**: Managing timing differences between branches
4. **Structural Impact Analysis**: Quantifying how structure affects optimization outcomes
5. **Algorithm Robustness**: Testing optimization algorithms on varied structures

## Notes

- All branches converge at the root node (0)
- Each branch maintains hierarchical structure within itself
- Leaf nodes (nodes with no children) are the procurement points with supplier assignments
- The structures are intentionally varied to isolate specific structural impacts
- These BOMs complement standard hierarchical BOMs for comprehensive structural analysis

## Future Extensions

Potential enhancements:
- Asymmetric parallel branches (different characteristics per branch)
- Mixed structures (some branches hierarchical, some parallel sub-branches)
- Dynamic structures (branches that can be delivered or manufactured based on conditions)
- Multi-root structures (multiple final products with shared components)
