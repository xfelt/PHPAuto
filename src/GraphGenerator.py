#!/usr/bin/env python3
"""
Graph Generator for DDMRP Buffer Positioning and Carbon Footprint Study
Generates publication-ready figures for Journal of Cleaner Production article

Usage:
    python GraphGenerator.py <results_directory>

Requires: pandas, matplotlib, seaborn, numpy
"""

import os
import sys
import json
import pandas as pd
import numpy as np
import matplotlib.pyplot as plt
import matplotlib.patches as mpatches
from matplotlib.lines import Line2D
import seaborn as sns
from pathlib import Path
from datetime import datetime

# Set publication-ready style
plt.style.use('seaborn-v0_8-whitegrid')
plt.rcParams.update({
    'font.family': 'serif',
    'font.serif': ['Times New Roman', 'DejaVu Serif'],
    'font.size': 10,
    'axes.labelsize': 11,
    'axes.titlesize': 12,
    'legend.fontsize': 9,
    'xtick.labelsize': 9,
    'ytick.labelsize': 9,
    'figure.dpi': 300,
    'savefig.dpi': 300,
    'savefig.bbox': 'tight',
    'savefig.pad_inches': 0.1
})

# Color palettes
STRATEGY_COLORS = {
    'EMISTAXE': '#2E86AB',    # Blue
    'EMISCAP': '#E94F37',      # Red
    'EMISHYBRID': '#A23B72',   # Purple
    'baseline': '#6B8E23'      # Olive
}

TOPOLOGY_COLORS = {
    'simple': '#3498db',
    'medium': '#2ecc71',
    'complex': '#e74c3c',
    'multi_level': '#9b59b6',
    'parallel': '#f39c12'
}


class GraphGenerator:
    def __init__(self, results_dir: str):
        self.results_dir = Path(results_dir)
        self.figures_dir = self.results_dir / 'figures'
        self.tables_dir = self.results_dir / 'tables'
        
        # Create figures directory if it doesn't exist
        self.figures_dir.mkdir(parents=True, exist_ok=True)
        
        # Load data
        self.consolidated_df = None
        self.load_data()
    
    def load_data(self):
        """Load all result CSV files"""
        # Try to load consolidated results
        consolidated_path = self.results_dir / 'consolidated_results.csv'
        if consolidated_path.exists():
            self.consolidated_df = pd.read_csv(consolidated_path)
            print(f"Loaded consolidated results: {len(self.consolidated_df)} rows")
        
        # Load experiment-specific data
        self.scalability_df = self._load_csv('scalability_results.csv')
        self.tax_sweep_df = self._load_csv('carbon_tax_sweep_results.csv')
        self.cap_sweep_df = self._load_csv('carbon_cap_sweep_results.csv')
        self.hybrid_df = self._load_csv('carbon_hybrid_results.csv')
        self.svt_df = self._load_csv('service_time_sensitivity_results.csv')
        self.topology_df = self._load_csv('topology_baseline_results.csv')
        self.nlm_comparison_df = self._load_csv('nlm_comparison_results.csv')
    
    def _load_csv(self, filename: str) -> pd.DataFrame:
        """Load CSV file if it exists"""
        filepath = self.tables_dir / filename
        if filepath.exists():
            df = pd.read_csv(filepath)
            print(f"Loaded {filename}: {len(df)} rows")
            return df
        return None
    
    def generate_all_figures(self):
        """Generate all publication figures"""
        print("\n=== Generating Publication Figures ===\n")
        
        # 1. Scalability plots
        if self.scalability_df is not None:
            self.plot_scalability_runtime()
            self.plot_scalability_emissions()
            self.plot_scalability_buffers()
        
        # 2. Carbon policy plots
        if self.tax_sweep_df is not None:
            self.plot_tax_sweep()
        
        if self.cap_sweep_df is not None:
            self.plot_cap_sweep()
        
        if self.hybrid_df is not None:
            self.plot_hybrid_strategy()
        
        # 3. Strategy comparison
        if self.consolidated_df is not None:
            self.plot_cost_emissions_pareto()
            self.plot_strategy_comparison()
        
        # 4. Inventory KPIs
        if self.consolidated_df is not None:
            self.plot_inventory_kpis()
        
        # 5. Service time sensitivity
        if self.svt_df is not None:
            self.plot_service_time_sensitivity()
        
        # 6. Topology analysis
        if self.topology_df is not None:
            self.plot_topology_comparison()
        
        # 7. PLM vs NLM comparison
        if self.nlm_comparison_df is not None:
            self.plot_plm_nlm_comparison()
        
        # 8. Load and plot Pareto fronts if available
        self.plot_pareto_fronts()
        
        print(f"\nAll figures saved to: {self.figures_dir}")
    
    def plot_scalability_runtime(self):
        """Figure 1: Runtime vs BOM Size"""
        df = self.scalability_df.copy()
        df = df[df['solver_status'] == 'OPTIMAL']
        
        if df.empty:
            print("No optimal solutions for scalability runtime plot")
            return
        
        fig, ax = plt.subplots(figsize=(8, 5))
        
        # Extract numeric BOM size from instance_id
        df['bom_size'] = df['instance_id'].str.extract(r'bom_(\d+)').astype(float)
        df = df.dropna(subset=['bom_size']).sort_values('bom_size')
        
        ax.scatter(df['bom_size'], df['runtime_sec'], c='#2E86AB', s=60, alpha=0.7, edgecolors='black', linewidths=0.5)
        ax.plot(df['bom_size'], df['runtime_sec'], c='#2E86AB', alpha=0.5, linestyle='--')
        
        ax.set_xlabel('BOM Size (number of components)')
        ax.set_ylabel('Computation Time (seconds)')
        ax.set_title('Computational Scalability of Integrated DDMRP Model')
        
        # Add phase annotations
        ax.axvline(x=10, color='gray', linestyle=':', alpha=0.5)
        ax.axvline(x=30, color='gray', linestyle=':', alpha=0.5)
        ax.text(5, ax.get_ylim()[1]*0.9, 'Small', ha='center', fontsize=9, color='gray')
        ax.text(20, ax.get_ylim()[1]*0.9, 'Medium', ha='center', fontsize=9, color='gray')
        ax.text(90, ax.get_ylim()[1]*0.9, 'Large', ha='center', fontsize=9, color='gray')
        
        ax.set_xlim(0, max(df['bom_size']) * 1.05)
        ax.set_ylim(0, max(df['runtime_sec']) * 1.1)
        
        plt.tight_layout()
        plt.savefig(self.figures_dir / 'fig1_scalability_runtime.png')
        plt.savefig(self.figures_dir / 'fig1_scalability_runtime.pdf')
        plt.close()
        print("Generated: fig1_scalability_runtime.png/pdf")
    
    def plot_scalability_emissions(self):
        """Figure 2: Baseline Emissions vs BOM Size"""
        df = self.scalability_df.copy()
        df = df[df['solver_status'] == 'OPTIMAL']
        
        if df.empty or 'total_emissions' not in df.columns:
            return
        
        fig, ax = plt.subplots(figsize=(8, 5))
        
        df['bom_size'] = df['instance_id'].str.extract(r'bom_(\d+)').astype(float)
        df = df.dropna(subset=['bom_size', 'total_emissions']).sort_values('bom_size')
        
        ax.bar(df['bom_size'].astype(str), df['total_emissions'] / 1e6, color='#E94F37', alpha=0.7, edgecolor='black')
        
        ax.set_xlabel('BOM Size (number of components)')
        ax.set_ylabel('Baseline Emissions (Million kg CO₂)')
        ax.set_title('Baseline Carbon Emissions by BOM Complexity')
        
        plt.xticks(rotation=45, ha='right')
        plt.tight_layout()
        plt.savefig(self.figures_dir / 'fig2_scalability_emissions.png')
        plt.savefig(self.figures_dir / 'fig2_scalability_emissions.pdf')
        plt.close()
        print("Generated: fig2_scalability_emissions.png/pdf")
    
    def plot_scalability_buffers(self):
        """Figure 3: Buffer Count vs BOM Size"""
        df = self.scalability_df.copy()
        df = df[df['solver_status'] == 'OPTIMAL']
        
        if df.empty or 'buffer_count' not in df.columns:
            return
        
        fig, ax = plt.subplots(figsize=(8, 5))
        
        df['bom_size'] = df['instance_id'].str.extract(r'bom_(\d+)').astype(float)
        df = df.dropna(subset=['bom_size', 'buffer_count']).sort_values('bom_size')
        
        ax.scatter(df['bom_size'], df['buffer_count'], c='#2ecc71', s=80, alpha=0.7, edgecolors='black', linewidths=0.5)
        
        # Add trend line
        z = np.polyfit(df['bom_size'], df['buffer_count'], 1)
        p = np.poly1d(z)
        ax.plot(df['bom_size'], p(df['bom_size']), "r--", alpha=0.5, label='Linear trend')
        
        ax.set_xlabel('BOM Size (number of components)')
        ax.set_ylabel('Number of Buffers Positioned')
        ax.set_title('DDMRP Buffer Positioning vs BOM Complexity')
        ax.legend()
        
        plt.tight_layout()
        plt.savefig(self.figures_dir / 'fig3_scalability_buffers.png')
        plt.savefig(self.figures_dir / 'fig3_scalability_buffers.pdf')
        plt.close()
        print("Generated: fig3_scalability_buffers.png/pdf")
    
    def plot_tax_sweep(self):
        """Figure 4: Emissions and Cost vs Carbon Tax Rate"""
        df = self.tax_sweep_df.copy()
        df = df[df['solver_status'] == 'OPTIMAL']
        
        if df.empty:
            return
        
        fig, axes = plt.subplots(1, 2, figsize=(12, 5))
        
        # Group by instance
        instances = df['instance_id'].unique()
        colors = plt.cm.tab10(np.linspace(0, 1, len(instances)))
        
        for i, instance in enumerate(instances):
            inst_df = df[df['instance_id'] == instance].sort_values('tax_rate')
            
            if len(inst_df) > 1:
                # Emissions plot
                axes[0].plot(inst_df['tax_rate'], inst_df['total_emissions'] / 1e6, 
                           marker='o', label=instance, color=colors[i], linewidth=2, markersize=6)
                
                # Cost plot
                axes[1].plot(inst_df['tax_rate'], inst_df['total_cost_with_tax'] / 1e3,
                           marker='s', label=instance, color=colors[i], linewidth=2, markersize=6)
        
        axes[0].set_xlabel('Carbon Tax Rate ($/unit)')
        axes[0].set_ylabel('Total Emissions (Million kg CO₂)')
        axes[0].set_title('Emissions Response to Carbon Tax')
        axes[0].legend(loc='best', fontsize=8)
        
        axes[1].set_xlabel('Carbon Tax Rate ($/unit)')
        axes[1].set_ylabel('Total Cost (Thousand $)')
        axes[1].set_title('Cost Impact of Carbon Tax')
        axes[1].legend(loc='best', fontsize=8)
        
        plt.tight_layout()
        plt.savefig(self.figures_dir / 'fig4_tax_sweep.png')
        plt.savefig(self.figures_dir / 'fig4_tax_sweep.pdf')
        plt.close()
        print("Generated: fig4_tax_sweep.png/pdf")
    
    def plot_cap_sweep(self):
        """Figure 5: Cost vs Emission Cap Tightening"""
        df = self.cap_sweep_df.copy()
        df = df[df['solver_status'] == 'OPTIMAL']
        
        if df.empty:
            return
        
        fig, ax = plt.subplots(figsize=(8, 5))
        
        instances = df['instance_id'].unique()
        colors = plt.cm.Set2(np.linspace(0, 1, len(instances)))
        
        for i, instance in enumerate(instances):
            inst_df = df[df['instance_id'] == instance].sort_values('cap_value', ascending=False)
            
            if len(inst_df) > 1 and 'emission_reduction_pct' in inst_df.columns:
                # Calculate cap percentage from baseline
                baseline_emis = inst_df['baseline_emissions'].iloc[0] if 'baseline_emissions' in inst_df.columns else inst_df['cap_value'].max()
                cap_pct = (inst_df['cap_value'] / baseline_emis) * 100
                
                ax.plot(cap_pct, inst_df['total_cost_without_tax'] / 1e3,
                       marker='o', label=instance, color=colors[i], linewidth=2, markersize=6)
        
        ax.set_xlabel('Emission Cap (% of baseline)')
        ax.set_ylabel('Total Cost (Thousand $)')
        ax.set_title('Cost of Emission Cap Compliance')
        ax.legend(loc='best', fontsize=8)
        ax.invert_xaxis()  # Lower cap = tighter constraint
        
        plt.tight_layout()
        plt.savefig(self.figures_dir / 'fig5_cap_sweep.png')
        plt.savefig(self.figures_dir / 'fig5_cap_sweep.pdf')
        plt.close()
        print("Generated: fig5_cap_sweep.png/pdf")
    
    def plot_hybrid_strategy(self):
        """Figure 6: Hybrid Tax+Cap Strategy Comparison"""
        df = self.hybrid_df.copy()
        df = df[df['solver_status'] == 'OPTIMAL']
        
        if df.empty:
            return
        
        fig, axes = plt.subplots(1, 2, figsize=(12, 5))
        
        # Create pivot for heatmap-style visualization
        instances = df['instance_id'].unique()
        
        for i, instance in enumerate(instances[:2]):  # First two instances for clarity
            inst_df = df[df['instance_id'] == instance]
            
            if len(inst_df) > 1:
                ax = axes[i] if len(instances) > 1 else axes
                
                # Scatter plot with color coding
                scatter = ax.scatter(inst_df['total_emissions'] / 1e6, 
                                   inst_df['total_cost_with_tax'] / 1e3,
                                   c=inst_df['tax_rate'], 
                                   s=100, 
                                   cmap='viridis',
                                   alpha=0.8,
                                   edgecolors='black')
                
                # Annotate points
                for _, row in inst_df.iterrows():
                    label = f"τ={row['tax_rate']:.2f}"
                    ax.annotate(label, (row['total_emissions']/1e6, row['total_cost_with_tax']/1e3),
                              fontsize=7, xytext=(5, 5), textcoords='offset points')
                
                ax.set_xlabel('Total Emissions (Million kg CO₂)')
                ax.set_ylabel('Total Cost (Thousand $)')
                ax.set_title(f'Hybrid Strategy Trade-offs: {instance}')
                
                plt.colorbar(scatter, ax=ax, label='Tax Rate')
        
        plt.tight_layout()
        plt.savefig(self.figures_dir / 'fig6_hybrid_strategy.png')
        plt.savefig(self.figures_dir / 'fig6_hybrid_strategy.pdf')
        plt.close()
        print("Generated: fig6_hybrid_strategy.png/pdf")
    
    def plot_cost_emissions_pareto(self):
        """Figure 7: Cost-Emissions Trade-off by Strategy"""
        df = self.consolidated_df.copy()
        df = df[df['solver_status'] == 'OPTIMAL']
        
        if df.empty or 'strategy' not in df.columns:
            return
        
        fig, ax = plt.subplots(figsize=(10, 6))
        
        strategies = df['strategy'].unique()
        
        for strategy in strategies:
            strat_df = df[df['strategy'] == strategy]
            color = STRATEGY_COLORS.get(strategy, '#333333')
            
            ax.scatter(strat_df['total_emissions'] / 1e6, 
                      strat_df['total_cost_without_tax'] / 1e3,
                      c=color, s=60, alpha=0.6, label=strategy,
                      edgecolors='black', linewidths=0.3)
        
        ax.set_xlabel('Total Emissions (Million kg CO₂)')
        ax.set_ylabel('Total Cost (Thousand $)')
        ax.set_title('Cost-Emissions Trade-offs by Carbon Policy Strategy')
        ax.legend(title='Strategy', loc='best')
        
        plt.tight_layout()
        plt.savefig(self.figures_dir / 'fig7_cost_emissions_pareto.png')
        plt.savefig(self.figures_dir / 'fig7_cost_emissions_pareto.pdf')
        plt.close()
        print("Generated: fig7_cost_emissions_pareto.png/pdf")
    
    def plot_strategy_comparison(self):
        """Figure 8: Strategy Comparison Box Plots"""
        df = self.consolidated_df.copy()
        df = df[df['solver_status'] == 'OPTIMAL']
        
        if df.empty or 'strategy' not in df.columns:
            return
        
        fig, axes = plt.subplots(1, 3, figsize=(14, 5))
        
        strategies = ['EMISTAXE', 'EMISCAP', 'EMISHYBRID']
        existing_strategies = [s for s in strategies if s in df['strategy'].values]
        
        if len(existing_strategies) < 2:
            return
        
        colors = [STRATEGY_COLORS.get(s, '#333333') for s in existing_strategies]
        
        # Cost comparison - use total_cost_with_tax first, fallback to total_cost_without_tax
        cost_data = []
        for s in existing_strategies:
            strat_df = df[df['strategy'] == s]
            # Try total_cost_with_tax first (contains data for EMISTAXE)
            costs = strat_df['total_cost_with_tax'].dropna()
            if costs.empty:
                # Fallback to total_cost_without_tax
                costs = strat_df['total_cost_without_tax'].dropna()
            cost_data.append(costs / 1e3)
        
        bp1 = axes[0].boxplot(cost_data, tick_labels=existing_strategies, patch_artist=True)
        for patch, color in zip(bp1['boxes'], colors):
            patch.set_facecolor(color)
            patch.set_alpha(0.7)
        axes[0].set_ylabel('Total Cost (Thousand $)')
        axes[0].set_title('Cost Distribution by Strategy')
        
        # Emissions comparison
        emis_data = [df[df['strategy'] == s]['total_emissions'].dropna() / 1e6 for s in existing_strategies]
        bp2 = axes[1].boxplot(emis_data, tick_labels=existing_strategies, patch_artist=True)
        for patch, color in zip(bp2['boxes'], colors):
            patch.set_facecolor(color)
            patch.set_alpha(0.7)
        axes[1].set_ylabel('Total Emissions (Million kg CO₂)')
        axes[1].set_title('Emissions Distribution by Strategy')
        
        # Buffer count comparison
        buffer_data = [df[df['strategy'] == s]['buffer_count'].dropna() for s in existing_strategies]
        bp3 = axes[2].boxplot(buffer_data, tick_labels=existing_strategies, patch_artist=True)
        for patch, color in zip(bp3['boxes'], colors):
            patch.set_facecolor(color)
            patch.set_alpha(0.7)
        axes[2].set_ylabel('Number of Buffers')
        axes[2].set_title('Buffer Positioning by Strategy')
        
        plt.tight_layout()
        plt.savefig(self.figures_dir / 'fig8_strategy_comparison.png')
        plt.savefig(self.figures_dir / 'fig8_strategy_comparison.pdf')
        plt.close()
        print("Generated: fig8_strategy_comparison.png/pdf")
    
    def plot_inventory_kpis(self):
        """Figure 9: Inventory KPIs (DIO, WIP) by Strategy"""
        df = self.consolidated_df.copy()
        df = df[df['solver_status'] == 'OPTIMAL']
        
        if df.empty or 'DIO' not in df.columns:
            return
        
        fig, axes = plt.subplots(1, 2, figsize=(12, 5))
        
        # DIO by strategy
        strategies = df['strategy'].unique()
        dio_means = [df[df['strategy'] == s]['DIO'].mean() for s in strategies]
        colors = [STRATEGY_COLORS.get(s, '#333333') for s in strategies]
        
        axes[0].bar(strategies, dio_means, color=colors, alpha=0.7, edgecolor='black')
        axes[0].set_ylabel('Days Inventory Outstanding (DIO)')
        axes[0].set_title('Average DIO by Carbon Policy Strategy')
        
        # DIO improvement (if available)
        if 'DIO_improvement_pct' in df.columns:
            dio_impr = [df[df['strategy'] == s]['DIO_improvement_pct'].mean() for s in strategies]
            dio_impr = [x if pd.notna(x) else 0 for x in dio_impr]
            axes[1].bar(strategies, dio_impr, color=colors, alpha=0.7, edgecolor='black')
            axes[1].set_ylabel('DIO Improvement (%)')
            axes[1].set_title('Average DIO Improvement vs Baseline')
            axes[1].axhline(y=0, color='gray', linestyle='--', alpha=0.5)
        
        plt.tight_layout()
        plt.savefig(self.figures_dir / 'fig9_inventory_kpis.png')
        plt.savefig(self.figures_dir / 'fig9_inventory_kpis.pdf')
        plt.close()
        print("Generated: fig9_inventory_kpis.png/pdf")
    
    def plot_service_time_sensitivity(self):
        """Figure 10: Service Time Sensitivity Analysis"""
        df = self.svt_df.copy()
        df = df[df['solver_status'] == 'OPTIMAL']
        
        if df.empty:
            return
        
        fig, axes = plt.subplots(1, 2, figsize=(12, 5))
        
        # Group by service time
        svt_groups = df.groupby('service_time_promised')
        
        svt_values = sorted(df['service_time_promised'].unique())
        
        # Buffer count vs service time
        buffer_means = [df[df['service_time_promised'] == svt]['buffer_count'].mean() for svt in svt_values]
        axes[0].bar([str(s) for s in svt_values], buffer_means, color='#3498db', alpha=0.7, edgecolor='black')
        axes[0].set_xlabel('Promised Service Time')
        axes[0].set_ylabel('Average Number of Buffers')
        axes[0].set_title('Buffer Positioning vs Service Time Constraint')
        
        # Cost vs service time
        cost_means = [df[df['service_time_promised'] == svt]['total_cost_without_tax'].mean() / 1e3 for svt in svt_values]
        axes[1].bar([str(s) for s in svt_values], cost_means, color='#e74c3c', alpha=0.7, edgecolor='black')
        axes[1].set_xlabel('Promised Service Time')
        axes[1].set_ylabel('Average Total Cost (Thousand $)')
        axes[1].set_title('Cost Impact of Service Time Constraint')
        
        plt.tight_layout()
        plt.savefig(self.figures_dir / 'fig10_service_time_sensitivity.png')
        plt.savefig(self.figures_dir / 'fig10_service_time_sensitivity.pdf')
        plt.close()
        print("Generated: fig10_service_time_sensitivity.png/pdf")
    
    def plot_topology_comparison(self):
        """Figure 11: Topology Comparison (ML vs PAR)"""
        df = self.topology_df.copy()
        df = df[df['solver_status'] == 'OPTIMAL']
        
        if df.empty:
            return
        
        fig, axes = plt.subplots(1, 2, figsize=(12, 5))
        
        # Identify topology from instance_id
        df['topology'] = df['instance_id'].apply(
            lambda x: 'Multi-Level' if 'ml' in x.lower() else ('Parallel' if 'par' in x.lower() else 'Standard')
        )
        
        topologies = df['topology'].unique()
        colors = ['#9b59b6', '#f39c12', '#3498db'][:len(topologies)]
        
        # Emissions by topology
        emis_data = [df[df['topology'] == t]['total_emissions'].dropna() / 1e6 for t in topologies]
        bp1 = axes[0].boxplot(emis_data, tick_labels=topologies, patch_artist=True)
        for patch, color in zip(bp1['boxes'], colors):
            patch.set_facecolor(color)
            patch.set_alpha(0.7)
        axes[0].set_ylabel('Total Emissions (Million kg CO₂)')
        axes[0].set_title('Emissions by BOM Topology')
        
        # Buffers by topology
        buffer_data = [df[df['topology'] == t]['buffer_count'].dropna() for t in topologies]
        bp2 = axes[1].boxplot(buffer_data, tick_labels=topologies, patch_artist=True)
        for patch, color in zip(bp2['boxes'], colors):
            patch.set_facecolor(color)
            patch.set_alpha(0.7)
        axes[1].set_ylabel('Number of Buffers')
        axes[1].set_title('Buffer Positioning by BOM Topology')
        
        plt.tight_layout()
        plt.savefig(self.figures_dir / 'fig11_topology_comparison.png')
        plt.savefig(self.figures_dir / 'fig11_topology_comparison.pdf')
        plt.close()
        print("Generated: fig11_topology_comparison.png/pdf")
    
    def plot_plm_nlm_comparison(self):
        """Figure 12: PLM vs NLM Model Comparison"""
        df = self.nlm_comparison_df.copy()
        df = df[df['solver_status'] == 'OPTIMAL']
        
        if df.empty or 'model_type' not in df.columns:
            return
        
        fig, axes = plt.subplots(1, 2, figsize=(12, 5))
        
        # Runtime comparison
        model_types = df['model_type'].unique()
        runtime_means = [df[df['model_type'] == mt]['runtime_sec'].mean() for mt in model_types]
        
        colors = ['#2E86AB', '#E94F37']
        axes[0].bar(model_types, runtime_means, color=colors[:len(model_types)], alpha=0.7, edgecolor='black')
        axes[0].set_ylabel('Average Runtime (seconds)')
        axes[0].set_title('Computation Time: PLM vs NLM')
        
        # Cost comparison
        cost_means = [df[df['model_type'] == mt]['total_cost_without_tax'].mean() / 1e3 for mt in model_types]
        axes[1].bar(model_types, cost_means, color=colors[:len(model_types)], alpha=0.7, edgecolor='black')
        axes[1].set_ylabel('Average Total Cost (Thousand $)')
        axes[1].set_title('Solution Quality: PLM vs NLM')
        
        plt.tight_layout()
        plt.savefig(self.figures_dir / 'fig12_plm_nlm_comparison.png')
        plt.savefig(self.figures_dir / 'fig12_plm_nlm_comparison.pdf')
        plt.close()
        print("Generated: fig12_plm_nlm_comparison.png/pdf")
    
    def plot_pareto_fronts(self):
        """Figure 13-15: Multi-objective Pareto Fronts"""
        pareto_dir = self.results_dir / 'pareto'
        
        if not pareto_dir.exists():
            print("No Pareto front data found")
            return
        
        # Find Pareto CSV files
        pareto_files = list(pareto_dir.glob('*_pareto.csv'))
        
        if not pareto_files:
            return
        
        # Group by front type
        cost_emis_files = [f for f in pareto_files if 'cost_emissions' in f.name]
        cost_dio_files = [f for f in pareto_files if 'cost_dio' in f.name]
        cost_wip_files = [f for f in pareto_files if 'cost_wip' in f.name]
        
        # Plot Cost-Emissions Pareto
        if cost_emis_files:
            fig, ax = plt.subplots(figsize=(8, 6))
            
            for f in cost_emis_files:
                df = pd.read_csv(f, sep=';')
                instance = f.stem.replace('_cost_emissions_pareto', '')
                
                if 'Cost' in df.columns and 'Emissions' in df.columns:
                    df = df.dropna(subset=['Cost', 'Emissions'])
                    ax.plot(df['Emissions'] / 1e6, df['Cost'] / 1e3, 
                           marker='o', label=instance, linewidth=2, markersize=6)
            
            ax.set_xlabel('Total Emissions (Million kg CO₂)')
            ax.set_ylabel('Total Cost (Thousand $)')
            ax.set_title('Cost-Emissions Pareto Front')
            ax.legend(loc='best')
            
            plt.tight_layout()
            plt.savefig(self.figures_dir / 'fig13_pareto_cost_emissions.png')
            plt.savefig(self.figures_dir / 'fig13_pareto_cost_emissions.pdf')
            plt.close()
            print("Generated: fig13_pareto_cost_emissions.png/pdf")
        
        # Plot Cost-DIO Pareto
        if cost_dio_files:
            fig, ax = plt.subplots(figsize=(8, 6))
            
            for f in cost_dio_files:
                df = pd.read_csv(f, sep=';')
                instance = f.stem.replace('_cost_dio_pareto', '')
                
                if 'Cost' in df.columns and 'DIO' in df.columns:
                    df = df.dropna(subset=['Cost', 'DIO'])
                    ax.plot(df['DIO'], df['Cost'] / 1e3, 
                           marker='s', label=instance, linewidth=2, markersize=6)
            
            ax.set_xlabel('Days Inventory Outstanding (DIO)')
            ax.set_ylabel('Total Cost (Thousand $)')
            ax.set_title('Cost-DIO Pareto Front')
            ax.legend(loc='best')
            
            plt.tight_layout()
            plt.savefig(self.figures_dir / 'fig14_pareto_cost_dio.png')
            plt.savefig(self.figures_dir / 'fig14_pareto_cost_dio.pdf')
            plt.close()
            print("Generated: fig14_pareto_cost_dio.png/pdf")


def main():
    if len(sys.argv) < 2:
        # Try to find most recent results directory
        logs_dir = Path(__file__).parent.parent / 'logs'
        campaign_dirs = list(logs_dir.glob('final_campaign_*'))
        
        if campaign_dirs:
            results_dir = max(campaign_dirs, key=lambda x: x.stat().st_mtime)
            print(f"Using most recent campaign: {results_dir}")
        else:
            print("Usage: python GraphGenerator.py <results_directory>")
            print("No campaign results found in logs directory")
            sys.exit(1)
    else:
        results_dir = Path(sys.argv[1])
    
    if not results_dir.exists():
        print(f"Results directory not found: {results_dir}")
        sys.exit(1)
    
    generator = GraphGenerator(str(results_dir))
    generator.generate_all_figures()


if __name__ == '__main__':
    main()
