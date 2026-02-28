/**
 * Tooltip content (from tooltips.json)
 */
export default {
  tooltips: [
    { id: "cost_metric", element: "Total Cost metric card", text: "Total cost = procurement + buffer holding. Lower is better, but watch emissions and reliability." },
    { id: "emissions_metric", element: "Emissions metric card", text: "Total CO₂ from selected suppliers. Cheaper suppliers often emit more. Buffers don't affect emissions directly." },
    { id: "reliability_metric", element: "Reliability metric card", text: "Average supplier reliability. Higher = fewer disruptions. Buffers boost this by 2.5% each." },
    { id: "lead_time_metric", element: "Lead Time metric card", text: "Average lead time across suppliers. Each buffer cuts this by ~1.2 days but adds $3,500 holding cost." },
    { id: "buffer_positioning", element: "Buffer checkboxes", text: "Buffers decouple variability: they reduce lead time and boost reliability, but increase holding cost." },
    { id: "optimization_score", element: "Optimization Score box", text: "Composite score: 40% cost efficiency, 40% emissions reduction, 20% reliability. Target 700+ for 4 stars." },
    { id: "scenario_picker", element: "Scenario buttons (header)", text: "Each scenario has different target thresholds and supplier pools. Test your optimization strategy across all three." },
    { id: "star_rating", element: "Star display", text: "★★★★★ = Expert (850+), ★★★★ = Proficient (700+), ★★★ = Competent (550+), ★★ = Developing (400+), ★ = Needs work." }
  ]
};
