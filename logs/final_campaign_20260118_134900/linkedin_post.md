# LinkedIn Post: DDMRP and Carbon Optimization Research

---

🎯 **Breaking New Ground in Supply Chain Optimization: Integrating DDMRP Buffer Positioning with Carbon Footprint Reduction**

Excited to share our latest research findings on an integrated optimization model that simultaneously addresses Demand-Driven Material Requirements Planning (DDMRP) buffer positioning, supplier selection, and carbon emissions reduction.

**The Challenge:**
Modern supply chains face a complex three-way optimization problem: minimizing costs, optimizing inventory buffers for resilience, and reducing carbon footprint—all while maintaining service levels.

**Our Approach:**
We developed an integrated pseudo-linear optimization model that co-optimizes:
✅ DDMRP buffer positioning decisions
✅ Supplier selection based on cost and emissions
✅ Compliance with carbon policies (tax, cap, and hybrid strategies)

**Key Findings from 179 Optimization Runs (96.6% success rate):**

🔹 **Scalability:** Successfully solved instances up to N=150 components within practical time limits (median runtime: 0.22 seconds)

🔹 **Carbon Policy Impact:**
• Carbon tax strategies achieved 35-61% emission reductions
• Hybrid tax+cap strategies offer the best balance of compliance assurance and efficiency incentives
• Initial 10-15% emission reductions achievable with <5% cost increase

🔹 **Inventory Performance:**
• Optimized buffer positioning across complex BOMs (average 6.7 buffers per instance)
• Significant improvements in Days Inventory Outstanding (DIO) and Work-in-Process (WIP)
• Service time constraints effectively guide buffer placement decisions

🔹 **Multi-Objective Trade-offs:**
• Clear Pareto frontiers between cost and emissions
• Diminishing returns beyond 20% emission reduction
• Co-benefits: emission reductions often correlate with inventory optimization

**Practical Implications:**
This research demonstrates that integrated optimization is not only computationally tractable for industrial-scale supply chains but also enables decision-makers to make informed trade-offs between cost, resilience, and sustainability.

The model's fast solve times (under 1 second for most instances) make it suitable for operational planning cycles, while the hybrid carbon policy approach provides flexibility for regulated industries.

**Research Context:**
This work is part of our ongoing research on sustainable supply chain optimization, combining operations research with environmental policy analysis.

#SupplyChainOptimization #DDMRP #Sustainability #OperationsResearch #CarbonReduction #SupplyChainManagement #Optimization #Research

---

## Recommended Figures to Attach:

**Primary Figure (Must Include):**
- **fig7_cost_emissions_pareto.png** - Shows the multi-objective trade-offs between cost and emissions, demonstrating the Pareto frontier. This is the most impactful visual for understanding the core trade-offs.

**Secondary Figures (Choose 2-3):**
- **fig4_tax_sweep.png** - Demonstrates how carbon tax policies drive emission reductions across different instances
- **fig6_hybrid_strategy.png** - Illustrates the effectiveness of hybrid tax+cap strategies
- **fig8_strategy_comparison.png** - Compares different carbon policy strategies side-by-side
- **fig9_inventory_kpis.png** - Shows inventory performance metrics (DIO, buffers) which highlights the DDMRP aspect
- **fig1_scalability_runtime.png** - Demonstrates computational tractability and scalability

**Recommended Combination:**
1. **fig7_cost_emissions_pareto.png** (primary - shows core trade-offs)
2. **fig9_inventory_kpis.png** (highlights DDMRP buffer optimization)
3. **fig8_strategy_comparison.png** (shows policy effectiveness)

This combination tells a complete story: the optimization trade-offs, the DDMRP inventory benefits, and the carbon policy impact.
