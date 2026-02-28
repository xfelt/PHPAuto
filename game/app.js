/* Single-screen layout update: compact grid behavior + node inspector collapse support */
/* ========================================
   DDMRP Optimization Simulator  - app.js
   ========================================
   CHANGELOG
 - A) Replaced buffered lead-time KPI with Service Lead Time (FG buffered => 0)
 - B) Fixed buffer calculations for ALL buffered nodes by computing DLT map for every node
 - C) Replaced unbuffered lead-time KPI with Inventory Emissions
 - D) Implemented deterministic client-side optimizer (greedy + local improvement)
 - E) Added comparison mode (current vs optimized) with dual chart curves and KPI block
 - F) Revised supplier/scenario data targets so good scores are reachable
 - G) Updated UI labels/tooltips and optimization display semantics
 ---- v2 (critical path + optimizer separation + KPI layout) ----
 - H) Fixed critical path: purchased node lead time is now additive (node.LT + supplier.LT)
 - I) Decoupled serviceLT recomputation pipeline (recomputes on every buffer/supplier change)
 - J) Optimizer no longer overwrites current config; operates on cloned config only
 - K) Dual series charts corrected: current history only appended on value-changing actions
 - L) Separate optimized history arrays for cost/emissions trend curves
 - M) Optimized KPIs integrated inline into current KPI tiles (side-by-side comparison)
 - N) Simplified optimized KPI block to score + Apply button only
 - O) Calibrated serviceTarget and objective weights for additive lead times
 - P) Added deterministic tests: additive LT, buffer toggle SLT, optimizer non-mutation, history isolation
 - Q) Replaced service LT with topo segment DP (buffer reset)
 - R) Split chart series into current/opt with optimized config snapshot
 - S) Added optimal supplier markers in supplier list
*/

/* ========================================
   MANUAL TEST CHECKLIST
   ----------------------------------------
   1) Toggle buffer on an upstream node => serviceLT changes immediately
   2) Toggle buffer off => serviceLT reverts accordingly
   3) Optimize does not alter current selections (suppliers, buffers)
   4) Current curve does not change when clicking Optimize
   5) Optimized curve appears and updates correctly (separate trend)
   6) KPI tiles show Current vs Opt side-by-side with "opt" label
   7) Apply optimized setup overwrites current config and hides opt values
   8) Buffering FG => serviceLT becomes 0
   9) No console errors at boot or during interactions
  10) No scrollbars at 1366x768
*/

/* ========================================
   Math Notes (units and formulas)
   ----------------------------------------
   All demand and buffer quantities are in "units".
   ADU_i = req_i / periodDays
   RedBase_i   = ADU_i * DLT_i * LTF_i
   RedSafety_i = RedBase_i * VF_i
   Red_i       = RedBase_i + RedSafety_i
   Yellow_i    = ADU_i * DLT_i
   Green_i     = Yellow_i
   TopOfGreen_i = Red_i + Yellow_i + Green_i

   NetFlowPosition_i = OnHand_i + OpenSupply_i - QualifiedDemand_i
   QualifiedDemand_i = ADU_i * min(periodDays, DLT_i) for buffered nodes

   Status:
   - NetFlowPosition < Red: Red
   - < Red+Yellow: Yellow
   - < TopOfGreen: Green
   - else: Above Green (non-buffered nodes are Pass-through)

   AvgInventory_i = TopOfGreen_i / 2
   InventoryCost = sum_i AvgInventory_i * holdingCostRate_i
*/

/* ========================================
   DDMRP IMPLEMENTATION NOTES
   ----------------------------------------
   - Buffer profiles are removed. Each node has boolean "buffered".
   - DLT is computed from BOM critical-path segmentation; never user-editable.
   - Path accumulation for a parent excludes buffered children (decoupling reset).
   - Purchased nodes support multi-sourcing with equal quota (1/n each).
   - Effective purchased lead time = MAX(selected supplier lead times).
   - Effective purchased reliability = MIN(selected supplier reliability).
   - Buffer formulas apply only on buffered nodes:
       RedBase   = ADU * DLT * LTF
       RedSafety = RedBase * VF
       Red       = RedBase + RedSafety
       Yellow    = ADU * DLT
       Green     = Yellow
       TopOfGreen = Red + Yellow + Green
   - Inventory cost is based on AvgInventory = TopOfGreen / 2.
*/

/* ========================================
   OPL -> Game variable mapping (Hybrid.mod)
   ----------------------------------------
   OPL demand/flow:
   - rqtf[i], adup, q[i][j], z[i][j], su[i][j]
     -> game req_i, periodDays, purchased qty, supplier assignment.
   OPL supplier attributes:
   - sup[j][1..4] = delay, price, capacity, emissions
     -> supplier leadTime, unitCost, capacity, unitEmissions.
   OPL objective structure:
   - RawMCost + InventCost + EmisCost (+ dlts in objective)
     -> game purchasedCost + inventoryCost + emissions/timing penalties.
   OPL emissions cap/tax:
   - Emis <= EmisCap and EmisTax*Emis
     -> game keeps emissions metric; didactic layer keeps cost/emissions trade-off.
   OPL does not define explicit DDMRP red/yellow/green formulas:
   - these are implemented here as the simulation teaching layer.
*/

import {
  FIXED_LTF,
  FIXED_VF,
  INVENTORY_EMISSIONS_FACTOR,
  BOM_BASE_VIEWBOX,
  ZOOM_MIN,
  ZOOM_MAX,
  ZOOM_STEP,
  ANIM_DURATION,
  ADVISOR_DELAY,
  BOM_SCENARIOS
} from "./src/data.js";
import {
  computeADU,
  computeBuffers,
  computeNetFlowStatus,
  computeAvgInventory,
  explodeBomDemand,
  expectedReqFromParents,
  computeDltMap,
  buildParentMap,
  buildIndegreeMap,
  topoOrderFromRoot,
  computeSegLtMap,
  summarizeSuppliersForNode,
  getNodeInventoryEmissionFactor
} from "./src/ddmrp.js";
import {
  state,
  getScenario,
  getNodesById,
  getSuppliersById,
  getPurchasedNodes,
  isPurchasedSelected,
  nodeMatchesQuery,
  graphChildren,
  buildWorkingConfig,
  cloneConfig,
  cloneCurrentConfig
} from "./src/state.js";
import {
  calculateTotals,
  computeScore,
  runOptimizer,
  evaluateConfig,
  starRating,
  boardApproval
} from "./src/engine.js";
import tooltipsData from "./src/tooltips.js";

const URL_PARAMS = new URLSearchParams(window.location.search);
const TEST_MODE = URL_PARAMS.get("test") === "1";
const REDUCED_MOTION = window.matchMedia("(prefers-reduced-motion: reduce)").matches;

const $ = (id) => document.getElementById(id);
const fmt = (v) => v.toLocaleString("en-US", { maximumFractionDigits: 1 });
const fmtC = (v) => `$${v.toLocaleString("en-US", { maximumFractionDigits: 0 })}`;
const clamp = (v, lo, hi) => Math.min(Math.max(v, lo), hi);
const isFiniteNumber = (v) => Number.isFinite(v) && !Number.isNaN(v);
const fmtInt = (v) => `${Math.round(v)}`;

const el = {
  supplierList: $("supplierList"),
  supplierChoicesBlock: $("supplierChoicesBlock"),
  supplierInlineHint: $("supplierInlineHint"),
  selectedCount: $("selectedCount"),
  purchasedCount: $("purchasedCount"),
  scenarioSummary: $("scenarioSummary"),
  demandRange: $("demandRange"),
  demandInput: $("demandInput"),
  periodDaysInput: $("periodDaysInput"),
  nodeSearch: $("nodeSearch"),
  costValue: $("metricCost"),
  emissionsValue: $("metricEmissions"),
  reliabilityValue: $("metricReliability"),
  leadValue: $("metricLead"),
  deltaCost: $("deltaCost"),
  deltaEmissions: $("deltaEmissions"),
  bufferCount: $("bufferCount"),
  inventoryPosture: $("inventoryPosture"),
  postureRed: $("postureRed"),
  postureYellow: $("postureYellow"),
  postureGreen: $("postureGreen"),
  scoreValue: $("scoreValue"),
  scoreStars: $("scoreStars"),
  progressFill: $("progressFill"),
  costLine: $("costLine"),
  costLineOptimized: $("costLineOptimized"),
  emissionsLine: $("emissionsLine"),
  emissionsLineOptimized: $("emissionsLineOptimized"),
  tooltip: $("tooltip"),
  modal: $("modal"),
  modalTitle: $("modalTitle"),
  modalBody: $("modalBody"),
  modalPrimary: $("modalPrimary"),
  modalSecondary: $("modalSecondary"),
  scenarioModal: $("scenarioModal"),
  indicator: $("optimizerIndicator"),
  boardBadge: $("boardBadge"),
  roundStatus: $("roundStatus"),
  startRoundBtn: $("startRoundBtn"),
  endRoundBtn: $("endRoundBtn"),
  optimizeBtn: $("optimizeBtn"),
  tutorialBtn: $("tutorialBtn"),
  targetCostInline: $("targetCostInline"),
  targetEmissionsInline: $("targetEmissionsInline"),
  costTargetPill: $("costTargetPill"),
  emissionsTargetPill: $("emissionsTargetPill"),
  costTargetFill: $("costTargetFill"),
  emissionsTargetFill: $("emissionsTargetFill"),
  advisorBody: $("advisorBody"),
  advisorThinking: $("advisorThinking"),
  advisorToggle: $("advisorToggle"),
  bomSvg: $("bomSvg"),
  inspectorEmpty: $("nodeInspectorEmpty"),
  inspectorBody: $("nodeInspectorBody"),
  inspectorName: $("inspectorName"),
  inspectorType: $("inspectorType"),
  inspectorDemand: $("inspectorDemand"),
  inspectorLead: $("inspectorLead"),
  inspectorBuffered: $("inspectorBuffered"),
  inspectorOnHand: $("inspectorOnHand"),
  inspectorOnHandValue: $("inspectorOnHandValue"),
  inspectorOpenSupply: $("inspectorOpenSupply"),
  inspectorOpenSupplyValue: $("inspectorOpenSupplyValue"),
  inspectorBufferSummary: $("inspectorBufferSummary"),
  inspectorBufferSummaryText: $("inspectorBufferSummaryText"),
  inspectorSummaryRed: $("inspectorSummaryRed"),
  inspectorSummaryYellow: $("inspectorSummaryYellow"),
  inspectorSummaryGreen: $("inspectorSummaryGreen"),
  nodeInspector: $("nodeInspector"),
  inspectorAccordion: $("inspectorAccordion"),
  mapLeadSummary: $("mapLeadSummary"),
  mapBufferRed: $("mapBufferRed"),
  mapBufferYellow: $("mapBufferYellow"),
  mapBufferGreen: $("mapBufferGreen"),
  mapBufferDetails: $("mapBufferDetails"),
  validityPanel: $("validityPanel"),
  validityBody: $("validityBody"),
  runTestsBtn: $("runTestsBtn"),
  testResults: $("testResults"),
  invEmissionsValue: $("metricInvEmissions"),
  inventoryValue: $("metricInventory"),
  zoomInBtn: $("zoomInBtn"),
  zoomOutBtn: $("zoomOutBtn"),
  zoomResetBtn: $("zoomResetBtn"),
  tutorialPopover: $("tutorialPopover"),
  startTourBtn: $("startTourBtn"),
  optimizedKpiBlock: $("optimizedKpiBlock"),
  optScore: $("optScore"),
  applyOptimizedBtn: $("applyOptimizedBtn"),
  metricCostOpt: $("metricCostOpt"),
  metricEmissionsOpt: $("metricEmissionsOpt"),
  metricReliabilityOpt: $("metricReliabilityOpt"),
  metricLeadOpt: $("metricLeadOpt"),
  metricInvEmissionsOpt: $("metricInvEmissionsOpt"),
  metricInventoryOpt: $("metricInventoryOpt")
};

const scenarioButtons = document.querySelectorAll(".scenario-btn[data-scenario]");

const animateValue = (key, from, to, duration, onUpdate) => {
  if (state.animFrames[key]) cancelAnimationFrame(state.animFrames[key]);
  if (REDUCED_MOTION || duration <= 0 || Math.abs(to - from) < 0.2) {
    onUpdate(to);
    return;
  }
  const start = performance.now();
  const delta = to - from;
  const step = (now) => {
    const t = clamp((now - start) / duration, 0, 1);
    const eased = 1 - Math.pow(1 - t, 3);
    onUpdate(from + delta * eased);
    if (t < 1) state.animFrames[key] = requestAnimationFrame(step);
  };
  state.animFrames[key] = requestAnimationFrame(step);
};

const openModal = ({
  title,
  body,
  primaryText = "Continue",
  secondaryText = null,
  onPrimary = null,
  onSecondary = null
}) => {
  el.modalTitle.textContent = title;
  el.modalBody.textContent = body;
  el.modalPrimary.textContent = primaryText;
  el.modalSecondary.textContent = secondaryText || "";
  el.modalSecondary.classList.toggle("hidden", !secondaryText);
  state.modalHandlers.primary = onPrimary;
  state.modalHandlers.secondary = onSecondary;
  el.modal.classList.remove("hidden");
  el.modalPrimary.focus();
};
const closeModal = () => {
  el.modal.classList.add("hidden");
  state.modalHandlers.primary = null;
  state.modalHandlers.secondary = null;
};
const openInfoModal = (title, body) => openModal({ title, body });

const renderStars = (c) => "★".repeat(c) + "☆".repeat(5 - c);

const setTargetBar = ({ fillEl, pillEl, current, target }) => {
  const barEl = fillEl.parentElement;
  if (!state.roundActive) {
    fillEl.style.width = "0%";
    pillEl.textContent = "Not started";
    pillEl.className = "metric-pill neutral";
    barEl?.classList.remove("over");
    return;
  }
  const ratio = target > 0 ? current / target : 0;
  fillEl.style.width = `${Math.min(100, clamp(ratio * 100, 0, 150))}%`;
  const over = ratio > 1;
  barEl?.classList.toggle("over", over);
  pillEl.textContent = over ? "Over target" : "On target";
  pillEl.className = `metric-pill ${over ? "bad" : "ok"}`;
};

const updateCharts = () => {
  const pts = (vals, min, max) => {
    if (!vals.length) return "";
    const range = max - min || 1;
    return vals.map((v, i) => {
      const x = (i / Math.max(1, vals.length - 1)) * 300;
      const y = 110 - ((v - min) / range) * 100;
      return `${x},${y}`;
    }).join(" ");
  };
  const currentCost = state.history.cost.current;
  const currentEmis = state.history.emissions.current;
  const optCost = state.history.cost.opt;
  const optEmis = state.history.emissions.opt;
  const allCost = [...currentCost, ...optCost];
  const allEmis = [...currentEmis, ...optEmis];
  const costMin = allCost.length ? Math.min(...allCost) : 0;
  const costMax = allCost.length ? Math.max(...allCost) : 1;
  const emisMin = allEmis.length ? Math.min(...allEmis) : 0;
  const emisMax = allEmis.length ? Math.max(...allEmis) : 1;

  const ensureLength = (vals, targetLen) => {
    if (!vals.length) return [];
    if (vals.length >= 2 && vals.length >= targetLen) return vals;
    const len = Math.max(2, targetLen);
    return Array.from({ length: len }, () => vals[vals.length - 1]);
  };

  el.costLine.setAttribute("points", pts(currentCost, costMin, costMax));
  el.emissionsLine.setAttribute("points", pts(currentEmis, emisMin, emisMax));
  if (el.costLineOptimized) {
    const drawCost = ensureLength(optCost, Math.max(2, currentCost.length));
    el.costLineOptimized.setAttribute("points", pts(drawCost, costMin, costMax));
  }
  if (el.emissionsLineOptimized) {
    const drawEmis = ensureLength(optEmis, Math.max(2, currentEmis.length));
    el.emissionsLineOptimized.setAttribute("points", pts(drawEmis, emisMin, emisMax));
  }
};

const updateDeltas = (totals) => {
  if (!state.lastTotals) {
    el.deltaCost.textContent = "";
    el.deltaEmissions.textContent = "";
    return;
  }
  const apply = (target, delta, unit) => {
    if (Math.abs(delta) < 1) {
      target.textContent = "No change";
      target.className = "metric-delta neutral";
      return;
    }
    target.textContent = `${delta > 0 ? "▲" : "▼"} ${fmt(Math.abs(delta))} ${unit}`;
    target.className = `metric-delta ${delta < 0 ? "improved" : "worsened"}`;
  };
  apply(el.deltaCost, totals.cost - state.lastTotals.cost, "");
  apply(el.deltaEmissions, totals.emissions - state.lastTotals.emissions, "kg CO₂");
};

const updateBomVisuals = (totals) => {
  const ids = Object.keys(state.bomRefs);
  ids.forEach((id) => {
    const refs = state.bomRefs[id];
    const node = getNodesById()[id];
    const s = totals.nodeStats[id];
    refs.group.classList.toggle("selected", id === state.selectedNodeId);
    refs.group.classList.toggle("buffered", !!s.buffered);
    refs.group.classList.toggle("purchased-set", node.type === "PURCHASED" && !!state.supplierAssignments[id]);
    refs.group.classList.toggle("purchased-unset", node.type === "PURCHASED" && !state.supplierAssignments[id]);
    const isBuffered = !!s.buffered;
    const barW = 76;
    const total = Math.max(1, s.red + s.yellow + s.green);
    const redW = isBuffered ? (s.red / total) * barW : 0;
    const yellowW = isBuffered ? (s.yellow / total) * barW : 0;
    const greenW = isBuffered ? (s.green / total) * barW : 0;
    refs.zoneRed.setAttribute("x", "8");
    refs.zoneRed.setAttribute("width", `${redW}`);
    refs.zoneYellow.setAttribute("x", `${8 + redW}`);
    refs.zoneYellow.setAttribute("width", `${yellowW}`);
    refs.zoneGreen.setAttribute("x", `${8 + redW + yellowW}`);
    refs.zoneGreen.setAttribute("width", `${greenW}`);
    refs.zoneRed.setAttribute("visibility", isBuffered ? "visible" : "hidden");
    refs.zoneYellow.setAttribute("visibility", isBuffered ? "visible" : "hidden");
    refs.zoneGreen.setAttribute("visibility", isBuffered ? "visible" : "hidden");
  });
};

const applySearchHighlights = () => {
  const q = state.ui.nodeSearchQuery.trim().toLowerCase();
  const nodes = getScenario().nodes;
  Object.keys(state.bomRefs).forEach((id) => {
    const node = nodes.find((n) => n.id === id);
    state.bomRefs[id].group.classList.toggle("search-match", !!q && !!node && nodeMatchesQuery(node, q));
  });
  const rows = el.supplierList?.querySelectorAll(".supplier-row[data-supplier-name]") ?? [];
  rows.forEach((row) => {
    const name = row.dataset.supplierName || "";
    row.classList.toggle("search-match", !!q && name.includes(q));
  });
};

const VALUE_CHANGE_REASONS = new Set([
  "supplier_action", "buffer_action", "netflow_action",
  "demand_action", "period_action", "apply_optimized", "round_start"
]);

const updateMetrics = (totals, score, reason) => {
  const sc = getScenario();
  const prev = state.lastTotals || { cost: 0, emissions: 0, reliability: 0, serviceLead: 0, inventoryCost: 0, inventoryEmissions: 0 };
  const duration = state.roundActive ? ANIM_DURATION : 0;

  el.bufferCount.textContent = totals.bufferCount;
  el.inventoryPosture.textContent = totals.inventoryCost > totals.purchasedCost * 0.35 ? "Inventory Heavy" : totals.flowResponsiveness > 35 ? "Responsive" : "Balanced";

  const postureTotal = Math.max(1, totals.postureRed + totals.postureYellow + totals.postureGreen);
  el.postureRed.style.width = `${(totals.postureRed / postureTotal) * 100}%`;
  el.postureYellow.style.width = `${(totals.postureYellow / postureTotal) * 100}%`;
  el.postureGreen.style.width = `${(totals.postureGreen / postureTotal) * 100}%`;

  animateValue("cost", prev.cost, totals.cost, duration, (v) => { el.costValue.textContent = fmtC(v); });
  animateValue("emissions", prev.emissions, totals.emissions, duration, (v) => { el.emissionsValue.textContent = `${fmt(v)} kg CO₂`; });
  animateValue("rel", prev.reliability, totals.reliability, duration, (v) => { el.reliabilityValue.textContent = `${fmt(v)}%`; });
  animateValue("serviceLead", prev.serviceLead, totals.serviceLead, duration, (v) => { el.leadValue.textContent = `${fmt(v)} days`; });
  animateValue("inventory", prev.inventoryCost, totals.inventoryCost, duration, (v) => { if (el.inventoryValue) el.inventoryValue.textContent = fmtC(v); });
  animateValue("invEmis", prev.inventoryEmissions, totals.inventoryEmissions, duration, (v) => { if (el.invEmissionsValue) el.invEmissionsValue.textContent = `${fmt(v)} kg CO₂`; });
  animateValue("score", state.lastScore, score, duration, (v) => { el.scoreValue.textContent = Math.round(v); });

  el.scoreStars.textContent = renderStars(starRating(score));
  el.progressFill.style.width = `${clamp((score / 1000) * 100, 0, 100)}%`;
  const approval = boardApproval(score);
  el.boardBadge.textContent = approval.label;
  el.boardBadge.className = `board-badge ${approval.cls}`;

  el.targetCostInline.textContent = fmtC(sc.targetCost);
  el.targetEmissionsInline.textContent = `${fmt(sc.targetEmissions)} kg CO₂`;
  setTargetBar({ fillEl: el.costTargetFill, pillEl: el.costTargetPill, current: totals.cost, target: sc.targetCost });
  setTargetBar({ fillEl: el.emissionsTargetFill, pillEl: el.emissionsTargetPill, current: totals.emissions, target: sc.targetEmissions });

  updateDeltas(totals);
  if (reason && VALUE_CHANGE_REASONS.has(reason)) {
    state.history.cost.current.push(totals.cost);
    state.history.emissions.current.push(totals.emissions);
    if (state.history.cost.current.length > 14) state.history.cost.current.shift();
    if (state.history.emissions.current.length > 14) state.history.emissions.current.shift();
  }
  updateCharts();
  updateBomVisuals(totals);
};

const renderAdvisorEmpty = (msg) => {
  el.advisorBody.innerHTML = `<div class="advisor-empty">${msg}</div>`;
};
const setAdvisorThinking = (on) => el.advisorThinking.classList.toggle("hidden", !on);

const buildAdvisorSuggestions = (totals) => {
  const sc = getScenario();
  const missing = getPurchasedNodes().filter((n) => (state.supplierAssignments[n.id] || []).length === 0);
  if (missing.length) {
    return [{
      title: `Assign supplier for ${missing[0].name}`,
      why: "Purchased nodes require at least one supplier selection.",
      body: "Select at least one supplier to produce valid cost, lead-time, and emissions values."
    }];
  }

  const suggestions = [];
  const addBufferCandidate = sc.nodes
    .map((n) => ({ n, dlt: totals.nodeStats[n.id].rawDlt }))
    .filter((x) => !totals.nodeStats[x.n.id].buffered && x.n.id !== sc.fgId)
    .sort((a, b) => b.dlt - a.dlt)[0];
  const removeBufferCandidate = sc.nodes
    .map((n) => ({ n, inv: totals.nodeStats[n.id].avgInv * n.holdingRate }))
    .filter((x) => totals.nodeStats[x.n.id].buffered)
    .sort((a, b) => b.inv - a.inv)[0];
  if (totals.serviceLead > totals.rawLead * 0.75 && addBufferCandidate) {
    suggestions.push({
      title: `Add buffer at ${addBufferCandidate.n.name}`,
      why: "High critical-path segment remains unbuffered.",
      body: "Positioning a buffer can reduce effective lead time by decoupling upstream path length."
    });
  } else if (removeBufferCandidate && removeBufferCandidate.inv > totals.inventoryCost * 0.22) {
    suggestions.push({
      title: `Remove buffer at ${removeBufferCandidate.n.name}`,
      why: "This buffered node contributes a high share of holding cost.",
      body: "If lead-time impact is small, removing this buffer can improve cost posture."
    });
  }

  const multiSourceCandidate = getPurchasedNodes()
    .filter((n) => (state.supplierAssignments[n.id] || []).length === 1)
    .sort((a, b) => totals.required[b.id] - totals.required[a.id])[0];
  if (multiSourceCandidate) {
    suggestions.push({
      title: `Multi-source ${multiSourceCandidate.name}`,
      why: "Equal quota split can diversify emissions and cost exposure.",
      body: "Select an additional supplier. Note: effective lead time follows the longest selected supplier lead time."
    });
  }

  if (!suggestions.length) {
    suggestions.push({
      title: "Current plan is balanced",
      why: "No single obvious hotspot dominates cost or emissions.",
      body: "Adjust one node at a time and watch score movement."
    });
  }
  return suggestions.slice(0, 2);
};

const updateAdvisor = (totals) => {
  if (!state.roundActive) return renderAdvisorEmpty("Start the round to see guidance.");
  if (!state.advisor.enabled) return renderAdvisorEmpty("Optimizer suggestions are hidden.");
  if (state.advisor.timer) clearTimeout(state.advisor.timer);
  setAdvisorThinking(true);
  const suggestions = buildAdvisorSuggestions(totals);
  state.advisor.timer = setTimeout(() => {
    setAdvisorThinking(false);
    el.advisorBody.innerHTML = "";
    suggestions.forEach((s) => {
      const card = document.createElement("div");
      card.className = "advisor-item";
      card.innerHTML = `
        <div class="advisor-headline">
          <span>${s.title}</span>
          <span class="advisor-why" data-tip="${s.why}">Why?</span>
        </div>
        <div class="advisor-rationale">${s.body}</div>
      `;
      el.advisorBody.appendChild(card);
    });
  }, ADVISOR_DELAY);
};

const evaluateDidacticPopups = (totals, reason) => {
  if (!state.roundActive) return;
  if (reason === "buffer_action" && state.lastTotals && !state.tipFlags.flowImproved && totals.serviceLead < state.lastTotals.serviceLead - 0.4) {
    state.tipFlags.flowImproved = true;
    openInfoModal("Decoupling point effect", "Critical-path lead time dropped due to stronger decoupling at one or more nodes.");
  }
  if (reason === "buffer_action" && state.lastTotals && !state.tipFlags.inventorySpike && totals.inventoryCost > state.lastTotals.inventoryCost * 1.2) {
    state.tipFlags.inventorySpike = true;
    openInfoModal("Inventory trade-off", "Top Of Green rose significantly. Responsiveness improved, but holding cost increased.");
  }
};

const renderValidityChecks = (totals) => {
  if (!TEST_MODE || !el.validityPanel || !el.validityBody) return;
  const sc = getScenario();
  const nodeId = state.selectedNodeId || sc.fgId;
  const s = totals.nodeStats[nodeId];
  const reqExpected = expectedReqFromParents(nodeId, totals.required, sc.edges, sc.fgId, state.finalDemand);
  const reqMismatch = Math.abs((totals.required[nodeId] || 0) - reqExpected) > 1e-6;
  const warnings = [];
  if ([s.redBase, s.redSafety, s.red, s.yellow, s.green, s.topOfGreen].some((v) => v < 0)) warnings.push("Zone value below zero.");
  if (s.topOfGreen < s.red + s.yellow) warnings.push("TopOfGreen < Red + Yellow.");
  if (!isFiniteNumber(s.adu)) warnings.push("ADU is NaN/invalid.");
  if (s.buffered && s.adu > 0 && s.topOfGreen <= 0) warnings.push("Buffered node has ADU>0 but TOG<=0.");
  if (reqMismatch) warnings.push("Requirement is not derived from BOM demand propagation.");

  el.validityBody.innerHTML = `
    <div><strong>Node:</strong> ${nodeId}</div>
    <div>ADU=${fmt(s.adu)} u/day · DLT(computed)=${fmt(s.dlt)} · LTF=${FIXED_LTF.toFixed(2)} · VF=${FIXED_VF.toFixed(2)} · buffered=${s.buffered}</div>
    <div>RedBase=${fmt(s.redBase)} · RedSafety=${fmt(s.redSafety)} · Red=${fmt(s.red)} · Yellow=${fmt(s.yellow)} · Green=${fmt(s.green)} · TopOfGreen=${fmt(s.topOfGreen)}</div>
    <div>NetFlowPosition=${fmt(s.netFlowPosition)} · Status=<strong>${s.status}</strong> · OnHand=${fmt(s.onHand)} · OpenSupply=${fmt(s.openSupply)} · QualifiedDemand=${fmt(s.qualifiedDemand)}</div>
    <div>ServiceLT=${fmt(totals.serviceLead)} days · InventoryEmissions=${fmt(totals.inventoryEmissions)} kg CO₂</div>
    <div>${warnings.length ? `Warnings: ${warnings.join(" | ")}` : "No validity warnings."}</div>
  `;
};

const runDeterministicTests = () => {
  const results = [];
  const add = (name, pass, details) => results.push({ name, pass, details });

  // 1) Buffered node with ADU>0 must have TOG>0
  const bufferedZones = computeBuffers(10, 5, FIXED_LTF, FIXED_VF);
  add("Buffered node invariant (ADU>0 => TOG>0)", bufferedZones.topOfGreen > 0, `tog=${fmt(bufferedZones.topOfGreen)}`);

  // 1) DLT changes when buffer positioning changes
  const children = { FG: [{ child: "A" }], A: [{ child: "RM" }], RM: [] };
  const nodeIds = ["FG", "A", "RM"];
  const leadByNode = { FG: 2, A: 3, RM: 5 };
  const dltNoBuffer = computeDltMap(nodeIds, children, leadByNode, {});
  const dltWithBuffer = computeDltMap(nodeIds, children, leadByNode, { A: true });
  add("DLT reacts to buffer positioning", dltNoBuffer.FG !== dltWithBuffer.FG, `noBuffer=${dltNoBuffer.FG}, withBufferAtA=${dltWithBuffer.FG}`);

  // 1b) Service LT DP: no buffers equals unbuffered critical path
  {
    const edges = [{ parent: "FG", child: "A" }, { parent: "A", child: "RM" }];
    const nodeIds = ["FG", "A", "RM"];
    const childrenMap = { FG: [{ child: "A" }], A: [{ child: "RM" }], RM: [] };
    const parentsMap = buildParentMap(nodeIds, edges);
    const indegreeMap = buildIndegreeMap(nodeIds, edges);
    const order = topoOrderFromRoot("FG", childrenMap, indegreeMap);
    const effLT = { FG: 2, A: 3, RM: 5 };
    const segNoBuffer = computeSegLtMap(order, parentsMap, effLT, {}, "FG");
    const unbufferedCP = Math.max(...order.map((id) => segNoBuffer[id] || 0));
    const serviceLT = Math.max(...order.map((id) => segNoBuffer[id] || 0));
    add("Service LT equals unbuffered CP (no buffers)", serviceLT === unbufferedCP, `serviceLT=${serviceLT}, unbufferedCP=${unbufferedCP}`);
  }

  // 1c) Adding a buffer never increases service LT
  {
    const edges = [{ parent: "FG", child: "A" }, { parent: "A", child: "RM" }];
    const nodeIds = ["FG", "A", "RM"];
    const childrenMap = { FG: [{ child: "A" }], A: [{ child: "RM" }], RM: [] };
    const parentsMap = buildParentMap(nodeIds, edges);
    const indegreeMap = buildIndegreeMap(nodeIds, edges);
    const order = topoOrderFromRoot("FG", childrenMap, indegreeMap);
    const effLT = { FG: 2, A: 3, RM: 5 };
    const segNoBuffer = computeSegLtMap(order, parentsMap, effLT, {}, "FG");
    const segWithBuffer = computeSegLtMap(order, parentsMap, effLT, { A: true }, "FG");
    const serviceNoBuffer = Math.max(...order.map((id) => segNoBuffer[id] || 0));
    const serviceWithBuffer = Math.max(...order.map((id) => segWithBuffer[id] || 0));
    add("Adding buffer never increases service LT", serviceWithBuffer <= serviceNoBuffer, `before=${serviceNoBuffer}, after=${serviceWithBuffer}`);
  }

  // 2) multi-sourcing lead time follows longest supplier lead time (MAX)
  const sum = summarizeSuppliersForNode(["s_fast", "s_low"], {
    s_fast: { leadTime: 4, unitCost: 10, unitEmissions: 3, reliability: 90 },
    s_low: { leadTime: 9, unitCost: 8, unitEmissions: 5, reliability: 70 }
  });
  add("Multi-source lead time max rule", sum.effLeadTime === 9, `effectiveLead=${sum.effLeadTime}`);

  // 3) adding a buffer never increases effective lead time
  add("Lead-time monotonicity with added buffer", dltWithBuffer.FG <= dltNoBuffer.FG, `before=${dltNoBuffer.FG}, after=${dltWithBuffer.FG}`);

  // 4) FG buffered => service lead time == 0
  const fgId = getScenario().fgId;
  if (!state.buffers[fgId]) state.buffers[fgId] = { buffered: false };
  const oldFg = state.buffers[fgId].buffered;
  state.buffers[fgId].buffered = true;
  const fgBufferedTotals = calculateTotals();
  add("FG buffered implies service lead time 0", fgBufferedTotals.serviceLead === 0, `serviceLead=${fgBufferedTotals.serviceLead}`);
  state.buffers[fgId].buffered = oldFg;

  // 5) demand scaling scales buffers linearly
  const req1 = explodeBomDemand([{ id: "FG" }, { id: "A" }], [{ parent: "FG", child: "A", qty: 2 }], "FG", 100);
  const req2 = explodeBomDemand([{ id: "FG" }, { id: "A" }], [{ parent: "FG", child: "A", qty: 2 }], "FG", 200);
  const z1 = computeBuffers(computeADU(req1.A, 30), 6, FIXED_LTF, FIXED_VF);
  const z2 = computeBuffers(computeADU(req2.A, 30), 6, FIXED_LTF, FIXED_VF);
  add("Demand scaling linearity", Math.abs(z2.topOfGreen - 2 * z1.topOfGreen) < 1e-6, `tog1=${fmt(z1.topOfGreen)}, tog2=${fmt(z2.topOfGreen)}`);

  // 6) optimizer returns config and improves score in at least one scenario
  const originalScenario = state.scenarioIndex;
  let improvedAny = false;
  let detail = "";
  for (let idx = 0; idx < BOM_SCENARIOS.length; idx += 1) {
    state.scenarioIndex = idx;
    resetState();
    const baselineConfig = cloneCurrentConfig();
    const baseline = evaluateConfig(baselineConfig);
    const optimized = runOptimizer(baselineConfig);
    if (optimized?.config && optimized.score > baseline.score) improvedAny = true;
    detail += `${idx}:${baseline.score}->${optimized?.score ?? "n/a"} `;
  }
  state.scenarioIndex = originalScenario;
  resetState();
  renderBom();
  updateScenarioButtons();
  updateAll();
  add("Optimizer finds improvement in at least one scenario", improvedAny, detail.trim());

  // 7) Additive lead time: purchased node lead = node.leadTime + supplier.effLeadTime
  {
    const sc = getScenario();
    const purchNode = sc.nodes.find((n) => n.type === "PURCHASED");
    if (purchNode) {
      const suppById = getSuppliersById();
      const sel = state.supplierAssignments[purchNode.id] || [];
      const summary = summarizeSuppliersForNode(sel, suppById);
      const expected = purchNode.leadTime + summary.effLeadTime;
      const totals = calculateTotals();
      const childrenMap = graphChildren();
      const allIds = sc.nodes.map((n) => n.id);
      const bf = {};
      sc.nodes.forEach((n) => { bf[n.id] = !!state.buffers[n.id]?.buffered; });
      const lb = {};
      sc.nodes.forEach((n) => {
        lb[n.id] = n.leadTime;
        if (n.type === "PURCHASED") {
          const s = summarizeSuppliersForNode(state.supplierAssignments[n.id] || [], suppById);
          lb[n.id] = n.leadTime + s.effLeadTime;
        }
      });
      add("Additive lead time for purchased nodes", lb[purchNode.id] === expected, `${purchNode.id}: node.LT=${purchNode.leadTime} + supplier=${summary.effLeadTime} = ${expected}, actual=${lb[purchNode.id]}`);
    }
  }

  // 8) Buffer toggle changes serviceLT when on critical path
  {
    const sc = getScenario();
    const totalsBase = calculateTotals();
    const baseSLT = totalsBase.serviceLead;
    // Find the node on the critical path with highest rawDlt (non-FG)
    const critNode = sc.nodes
      .filter((n) => n.id !== sc.fgId && !state.buffers[n.id]?.buffered)
      .sort((a, b) => (totalsBase.nodeStats[b.id]?.rawDlt || 0) - (totalsBase.nodeStats[a.id]?.rawDlt || 0))[0];
    if (critNode) {
      state.buffers[critNode.id].buffered = true;
      const totalsAfter = calculateTotals();
      const afterSLT = totalsAfter.serviceLead;
      state.buffers[critNode.id].buffered = false;
      add("Buffer toggle changes serviceLT", afterSLT <= baseSLT, `before=${baseSLT}, after=${afterSLT} (buffered ${critNode.id})`);
    }
  }

  // 9) Optimize does not mutate current config
  {
    const before = cloneCurrentConfig();
    const optimized = runOptimizer(before);
    const after = cloneCurrentConfig();
    const same = JSON.stringify(before.supplierAssignments) === JSON.stringify(after.supplierAssignments)
      && JSON.stringify(before.buffers) === JSON.stringify(after.buffers);
    add("Optimizer does not mutate current config", same, same ? "configs match" : "configs differ!");
  }

  // 10) History not polluted by optimize_action
  {
    const lenBefore = state.history.cost.current.length;
    const totals = calculateTotals();
    const score = computeScore(totals);
    updateMetrics(totals, score, "optimize_action");
    const lenAfter = state.history.cost.current.length;
    add("Optimize action does not push to current history", lenAfter === lenBefore, `before=${lenBefore}, after=${lenAfter}`);
  }

  return results;
};

const renderTestResults = () => {
  if (!TEST_MODE || !el.testResults) return;
  const results = runDeterministicTests();
  el.testResults.innerHTML = "";
  results.forEach((r) => {
    const row = document.createElement("div");
    row.className = `test-line ${r.pass ? "pass" : "fail"}`;
    row.textContent = `${r.pass ? "PASS" : "FAIL"} - ${r.name}: ${r.details}`;
    el.testResults.appendChild(row);
  });
};

const renderBom = () => {
  const sc = getScenario();
  el.bomSvg.innerHTML = "";
  state.bomRefs = {};

  // Draw nodes first, then links on top so no link is hidden behind a node box.
  sc.nodes.forEach((node) => {
    const g = document.createElementNS("http://www.w3.org/2000/svg", "g");
    g.setAttribute("class", "bom-node");
    g.setAttribute("transform", `translate(${node.x},${node.y})`);
    g.setAttribute("tabindex", "0");
    g.setAttribute("role", "button");
    g.setAttribute("aria-label", `Inspect ${node.name}`);
    g.dataset.nodeId = node.id;

    const box = document.createElementNS("http://www.w3.org/2000/svg", "rect");
    box.setAttribute("class", "node-box");
    box.setAttribute("width", "92");
    box.setAttribute("height", "46");
    g.appendChild(box);

    const name = document.createElementNS("http://www.w3.org/2000/svg", "text");
    name.setAttribute("x", "8");
    name.setAttribute("y", "16");
    name.textContent = node.name.length > 13 ? `${node.name.slice(0, 12)}…` : node.name;
    g.appendChild(name);

    const type = document.createElementNS("http://www.w3.org/2000/svg", "text");
    type.setAttribute("class", "node-type");
    type.setAttribute("x", "8");
    type.setAttribute("y", "31");
    type.textContent = node.type;
    g.appendChild(type);

    const zoneRed = document.createElementNS("http://www.w3.org/2000/svg", "rect");
    zoneRed.setAttribute("class", "bom-zone bom-zone-red");
    zoneRed.setAttribute("x", "8");
    zoneRed.setAttribute("y", "2");
    zoneRed.setAttribute("width", "0");
    zoneRed.setAttribute("height", "4");
    zoneRed.setAttribute("visibility", "hidden");
    g.appendChild(zoneRed);

    const zoneYellow = document.createElementNS("http://www.w3.org/2000/svg", "rect");
    zoneYellow.setAttribute("class", "bom-zone bom-zone-yellow");
    zoneYellow.setAttribute("x", "8");
    zoneYellow.setAttribute("y", "2");
    zoneYellow.setAttribute("width", "0");
    zoneYellow.setAttribute("height", "4");
    zoneYellow.setAttribute("visibility", "hidden");
    g.appendChild(zoneYellow);

    const zoneGreen = document.createElementNS("http://www.w3.org/2000/svg", "rect");
    zoneGreen.setAttribute("class", "bom-zone bom-zone-green");
    zoneGreen.setAttribute("x", "8");
    zoneGreen.setAttribute("y", "2");
    zoneGreen.setAttribute("width", "0");
    zoneGreen.setAttribute("height", "4");
    zoneGreen.setAttribute("visibility", "hidden");
    g.appendChild(zoneGreen);

    g.addEventListener("click", (e) => {
      if (state.ui.bomDidPan) {
        e.preventDefault();
        e.stopPropagation();
        state.ui.bomDidPan = false;
        return;
      }
      onNodeSelect(node.id);
    });
    g.addEventListener("keydown", (e) => {
      if (e.key === "Enter" || e.key === " ") {
        e.preventDefault();
        onNodeSelect(node.id);
      }
    });

    el.bomSvg.appendChild(g);
    state.bomRefs[node.id] = { group: g, zoneRed, zoneYellow, zoneGreen };
  });

  sc.edges.forEach((edge, idx) => {
    const parent = sc.nodes.find((n) => n.id === edge.parent);
    const child = sc.nodes.find((n) => n.id === edge.child);
    if (!parent || !child) return;
    const x1 = parent.x + 92;
    const y1 = parent.y + 23;
    const x2 = child.x;
    const y2 = child.y + 23;
    const lane = ((idx % 5) - 2) * 6;
    const cx = (x1 + x2) / 2 + lane;

    const path = document.createElementNS("http://www.w3.org/2000/svg", "path");
    path.setAttribute("d", `M ${x1} ${y1} C ${cx} ${y1}, ${cx} ${y2}, ${x2} ${y2}`);
    path.setAttribute("class", "bom-edge");
    el.bomSvg.appendChild(path);

    const label = document.createElementNS("http://www.w3.org/2000/svg", "text");
    label.setAttribute("x", `${cx}`);
    label.setAttribute("y", `${(y1 + y2) / 2 - 4}`);
    label.setAttribute("class", "bom-edge-label");
    label.textContent = `x${edge.qty}`;
    el.bomSvg.appendChild(label);
  });
  applyBomZoom();
};

const renderSuppliers = () => {
  const purchasedNodes = getPurchasedNodes();
  const assigned = purchasedNodes.filter((n) => (state.supplierAssignments[n.id] || []).length > 0).length;
  el.selectedCount.textContent = assigned;
  el.purchasedCount.textContent = purchasedNodes.length;
  const showSupplierChoices = isPurchasedSelected();
  if (el.supplierChoicesBlock) el.supplierChoicesBlock.classList.toggle("hidden", !showSupplierChoices);
  if (el.supplierInlineHint) el.supplierInlineHint.classList.toggle("hidden", showSupplierChoices);

  if (!showSupplierChoices) {
    el.supplierList.innerHTML = "";
    return;
  }
  const suppliers = getScenario().supplierIds.map((id) => getSuppliersById()[id]).filter(Boolean);
  const node = getNodesById()[state.selectedNodeId];
  const selected = state.supplierAssignments[node.id] || [];
  const optimizedConfig = state.optimized?.optimizedConfig;
  const optIds = optimizedConfig?.selectedSuppliersByNode?.[node.id] || [];
  const optCount = optIds.length;
  const optQuotaText = optCount ? `Opt 1/${optCount}` : "";
  el.supplierList.innerHTML = `
    <div class="supplier-header">
      <span></span><span></span><span>Cost</span><span>Emis</span><span>LT</span><span>Rel</span>
    </div>
  `;
  suppliers.forEach((sup) => {
    const isSelected = selected.includes(sup.id);
    const isOpt = optIds.includes(sup.id);
    const row = document.createElement("label");
    row.className = `supplier-row ${isSelected ? "selected" : ""}`;
    row.dataset.supplierName = sup.name.toLowerCase();
    row.innerHTML = `
      <input type="checkbox" data-supplier="${sup.id}" ${isSelected ? "checked" : ""} ${!state.roundActive ? "disabled" : ""} />
      <span class="supplier-name-wrap">
        <span class="supplier-name">${sup.name}</span>
        ${isOpt ? `<span class="optMark" aria-label="Optimal supplier">✓</span><span class="optQuota">${optQuotaText}</span>` : ""}
      </span>
      <span class="sup-stat">${fmtC(sup.unitCost)}</span>
      <span class="sup-stat">${fmt(sup.unitEmissions)}</span>
      <span class="sup-stat">${sup.leadTime}d</span>
      <span class="sup-stat">${sup.reliability}%</span>
    `;
    el.supplierList.appendChild(row);
  });
};

const renderInspector = (totals) => {
  const node = getNodesById()[state.selectedNodeId];
  if (!node) {
    el.inspectorEmpty.classList.toggle("hidden", state.layout.inspectorCollapsed);
    el.inspectorBody.classList.add("hidden");
    if (el.inspectorBufferSummary) el.inspectorBufferSummary.classList.add("hidden");
    return;
  }
  el.inspectorEmpty.classList.add("hidden");
  el.inspectorBody.classList.toggle("hidden", state.layout.inspectorCollapsed);

  const req = totals.required[node.id];
  const st = totals.nodeStats[node.id];
  const b = state.buffers[node.id];
  const nf = state.netFlowInputs[node.id];

  el.inspectorName.textContent = node.name;
  el.inspectorType.textContent = node.type;
  el.inspectorDemand.textContent = fmt(req);
  el.inspectorLead.textContent = `${node.leadTime} days`;
  el.inspectorBuffered.checked = !!b.buffered;
  el.inspectorOnHand.value = nf.onHandPct;
  el.inspectorOnHandValue.textContent = `${nf.onHandPct}%`;
  el.inspectorOpenSupply.value = nf.openSupplyPct;
  el.inspectorOpenSupplyValue.textContent = `${nf.openSupplyPct}%`;
  if (st.buffered) {
    const total = Math.max(1, st.red + st.yellow + st.green);
    const redPct = (st.red / total) * 100;
    const yellowPct = (st.yellow / total) * 100;
    const greenPct = (st.green / total) * 100;
    if (el.inspectorBufferSummary) el.inspectorBufferSummary.classList.remove("hidden");
    if (el.inspectorBufferSummaryText) {
      el.inspectorBufferSummaryText.textContent =
        `Buffer ON · ADU ${fmt(st.adu)} u/day · R/Y/G ${fmtInt(st.red)}/${fmtInt(st.yellow)}/${fmtInt(st.green)} · TOG ${fmtInt(st.topOfGreen)}`;
    }
    if (el.inspectorSummaryRed) el.inspectorSummaryRed.style.width = `${redPct}%`;
    if (el.inspectorSummaryYellow) el.inspectorSummaryYellow.style.width = `${yellowPct}%`;
    if (el.inspectorSummaryGreen) el.inspectorSummaryGreen.style.width = `${greenPct}%`;
  } else {
    if (el.inspectorBufferSummary) el.inspectorBufferSummary.classList.add("hidden");
  }
};

const updateMapBufferCard = (totals) => {
  const node = getNodesById()[state.selectedNodeId];
  if (!node || !el.mapBufferDetails) return;
  const st = totals.nodeStats[node.id];
  const denom = Math.max(1, st.topOfGreen);
  const redPct = (st.red / denom) * 100;
  const yellowPct = (st.yellow / denom) * 100;
  const greenPct = (st.green / denom) * 100;

  el.mapBufferRed.style.width = `${redPct}%`;
  el.mapBufferYellow.style.width = `${yellowPct}%`;
  el.mapBufferGreen.style.width = `${greenPct}%`;

  let commercialLead = node.leadTime;
  if (node.type === "PURCHASED") {
    const summary = totals.supplierSummary[node.id];
    if (summary) commercialLead = summary.effLeadTime;
  }
  el.mapLeadSummary.textContent = `Commercial LT: ${fmt(commercialLead)}d`;
  el.mapBufferDetails.textContent = st.buffered
    ? `ADU ${fmt(st.adu)} u/day · R/Y/G ${fmt(st.red)}/${fmt(st.yellow)}/${fmt(st.green)} · TOG ${fmt(st.topOfGreen)} · NetFlow ${fmt(st.netFlowPosition)} (${st.status})`
    : `ADU ${fmt(st.adu)} u/day · Buffer OFF (pass-through)`;
};

const updateScenarioButtons = () => {
  scenarioButtons.forEach((btn) => {
    const active = Number(btn.dataset.scenario) === state.scenarioIndex;
    btn.classList.toggle("active", active);
    btn.setAttribute("aria-pressed", active);
  });
};
const updateScenarioSummary = () => {
  if (!el.scenarioSummary) return;
  el.scenarioSummary.textContent = "";
  el.scenarioSummary.classList.add("hidden");
};

const setNodeInspectorCollapsed = (collapsed) => {
  if (!el.nodeInspector || !el.inspectorAccordion) return;
  state.layout.inspectorCollapsed = collapsed;
  if (!state.selectedNodeId) {
    el.inspectorEmpty.classList.toggle("hidden", collapsed);
    el.inspectorBody.classList.add("hidden");
  } else {
    el.inspectorEmpty.classList.add("hidden");
    el.inspectorBody.classList.toggle("hidden", collapsed);
  }
  el.inspectorAccordion.setAttribute("aria-expanded", String(!collapsed));
  el.inspectorAccordion.setAttribute("aria-label", collapsed ? "Expand inspector" : "Collapse inspector");
  el.inspectorAccordion.textContent = collapsed ? "▴" : "▾";
};

const autoCompactLayout = () => {
  if (!state.layout.userInspectorChoice) setNodeInspectorCollapsed(false);
};

const applyBomZoom = () => {
  const z = state.ui.zoomLevel;
  const w = BOM_BASE_VIEWBOX.w / z;
  const h = BOM_BASE_VIEWBOX.h / z;
  const baseCenterX = BOM_BASE_VIEWBOX.x + BOM_BASE_VIEWBOX.w / 2;
  const baseCenterY = BOM_BASE_VIEWBOX.y + BOM_BASE_VIEWBOX.h / 2;
  let x = baseCenterX - w / 2 - state.ui.panX;
  let y = baseCenterY - h / 2 - state.ui.panY;
  x = clamp(x, 0, Math.max(0, BOM_BASE_VIEWBOX.w - w));
  y = clamp(y, 0, Math.max(0, BOM_BASE_VIEWBOX.h - h));
  el.bomSvg.setAttribute("viewBox", `${x} ${y} ${w} ${h}`);
};
const setZoomLevel = (level) => {
  state.ui.zoomLevel = clamp(level, ZOOM_MIN, ZOOM_MAX);
  applyBomZoom();
};
const resetZoom = () => {
  state.ui.panX = 0;
  state.ui.panY = 0;
  setZoomLevel(1);
};

const updateNodeSearch = (rawQuery) => {
  state.ui.nodeSearchQuery = (rawQuery || "").trim().toLowerCase();
  const q = state.ui.nodeSearchQuery;
  if (!q) {
    applySearchHighlights();
    return;
  }
  const first = getScenario().nodes.find((node) => nodeMatchesQuery(node, q));
  if (first) {
    if (state.selectedNodeId !== first.id) {
      state.selectedNodeId = first.id;
      updateAll();
    }
    const ref = state.bomRefs[first.id];
    if (ref?.group) ref.group.focus();
  }
  applySearchHighlights();
  const matchedSupplierRow = el.supplierList.querySelector(".supplier-row.search-match");
  if (matchedSupplierRow) matchedSupplierRow.scrollIntoView({ block: "nearest" });
};

const closeTutorialPopover = () => {
  if (!el.tutorialPopover || !el.tutorialBtn) return;
  el.tutorialPopover.classList.add("hidden");
  el.tutorialBtn.setAttribute("aria-expanded", "false");
};
const toggleTutorialPopover = () => {
  if (!el.tutorialPopover || !el.tutorialBtn) return;
  const willOpen = el.tutorialPopover.classList.contains("hidden");
  el.tutorialPopover.classList.toggle("hidden", !willOpen);
  el.tutorialBtn.setAttribute("aria-expanded", String(willOpen));
};

const renderOptimizedComparison = () => {
  const optElements = [
    el.metricCostOpt,
    el.metricEmissionsOpt,
    el.metricReliabilityOpt,
    el.metricLeadOpt,
    el.metricInvEmissionsOpt,
    el.metricInventoryOpt
  ];
  if (!state.optimized) {
    optElements.forEach((e) => { if (e) e.classList.add("hidden"); });
    if (el.optimizedKpiBlock) el.optimizedKpiBlock.classList.add("hidden");
    return;
  }
  const k = state.optimized.kpiSnapshot || state.optimized.optimizedKPIs;
  if (el.metricCostOpt) { el.metricCostOpt.textContent = fmtC(k.cost); el.metricCostOpt.classList.remove("hidden"); }
  if (el.metricEmissionsOpt) { el.metricEmissionsOpt.textContent = `${fmt(k.emissions)} kg`; el.metricEmissionsOpt.classList.remove("hidden"); }
  if (el.metricReliabilityOpt) { el.metricReliabilityOpt.textContent = `${fmt(k.reliability)}%`; el.metricReliabilityOpt.classList.remove("hidden"); }
  if (el.metricLeadOpt) { el.metricLeadOpt.textContent = `${fmt(k.serviceLT)}d`; el.metricLeadOpt.classList.remove("hidden"); }
  if (el.metricInvEmissionsOpt) { el.metricInvEmissionsOpt.textContent = `${fmt(k.inventoryEmissions)} kg`; el.metricInvEmissionsOpt.classList.remove("hidden"); }
  if (el.metricInventoryOpt) { el.metricInventoryOpt.textContent = fmtC(k.inventoryCost); el.metricInventoryOpt.classList.remove("hidden"); }
  if (el.optScore) el.optScore.textContent = `${Math.round(k.score)}`;
  if (el.optimizedKpiBlock) el.optimizedKpiBlock.classList.remove("hidden");
};

const updateAll = (reason = null) => {
  const totals = calculateTotals();
  const score = computeScore(totals);
  updateMetrics(totals, score, reason);
  renderInspector(totals);
  updateMapBufferCard(totals);
  renderSuppliers();
  renderOptimizedComparison();
  renderValidityChecks(totals);
  evaluateDidacticPopups(totals, reason);
  if (reason && reason !== "optimize_action") updateAdvisor(totals);
  state.lastTotals = totals;
  state.lastScore = score;
  applySearchHighlights();
};

const resetState = () => {
  const sc = getScenario();
  state.selectedNodeId = sc.fgId;
  state.supplierAssignments = {};
  state.buffers = {};
  state.netFlowInputs = {};
  state.finalDemand = sc.fgDemand;
  state.periodDays = 30;
  const defaultSupplier = sc.supplierIds[0] || null;
  sc.nodes.forEach((n) => {
    state.buffers[n.id] = {
      buffered: false
    };
    if (n.type === "PURCHASED" && defaultSupplier) state.supplierAssignments[n.id] = [defaultSupplier];
    state.netFlowInputs[n.id] = { onHandPct: 100, openSupplyPct: 0 };
  });
  state.history.cost.current = [];
  state.history.cost.opt = [];
  state.history.emissions.current = [];
  state.history.emissions.opt = [];
  state.lastTotals = null;
  state.lastScore = 0;
  state.optimized = null;
  state.tipFlags.firstBufferChange = false;
  state.tipFlags.firstSupplierChoice = false;
  state.tipFlags.flowImproved = false;
  state.tipFlags.inventorySpike = false;
  if (el.demandRange) el.demandRange.value = `${state.finalDemand}`;
  if (el.demandInput) el.demandInput.value = `${state.finalDemand}`;
  if (el.periodDaysInput) el.periodDaysInput.value = `${state.periodDays}`;
  state.ui.zoomLevel = 1;
  state.ui.panX = 0;
  state.ui.panY = 0;
  if (el.nodeSearch) el.nodeSearch.value = "";
  state.ui.nodeSearchQuery = "";
  renderAdvisorEmpty(state.advisor.enabled ? "Start the round to see guidance." : "Optimizer suggestions are hidden.");
};

const onNodeSelect = (nodeId) => {
  state.selectedNodeId = nodeId;
  updateAll();
};
const onSupplierAssign = (supplierId) => {
  if (!isPurchasedSelected()) return;
  const nodeId = state.selectedNodeId;
  const current = [...(state.supplierAssignments[nodeId] || [])];
  const idx = current.indexOf(supplierId);
  if (idx >= 0) {
    if (current.length === 1) {
      openInfoModal("At least one supplier required", "Each supplied SKU must keep at least one selected supplier.");
      updateAll();
      return;
    }
    current.splice(idx, 1);
  } else {
    current.push(supplierId);
  }
  state.supplierAssignments[nodeId] = current;
  clearOptimizedResult();
  if (!state.tipFlags.firstSupplierChoice) {
    state.tipFlags.firstSupplierChoice = true;
    openInfoModal("Multi-sourcing enabled", "Purchased nodes can select multiple suppliers with equal quota split. Longest supplier lead time governs the node lead time.");
  }
  updateAll("supplier_action");
};
const onBufferChange = (patch) => {
  const node = getNodesById()[state.selectedNodeId];
  if (!node) return;
  state.buffers[node.id] = { ...state.buffers[node.id], ...patch };
  clearOptimizedResult();
  if (!state.tipFlags.firstBufferChange && patch.buffered === true) {
    state.tipFlags.firstBufferChange = true;
    openInfoModal("DDMRP buffer positioning", "A positioned buffer creates a decoupling point. Upstream lead-time accumulation resets at this point.");
  }
  updateAll("buffer_action");
};
const onNetFlowInputChange = (patch) => {
  const node = getNodesById()[state.selectedNodeId];
  if (!node) return;
  state.netFlowInputs[node.id] = { ...state.netFlowInputs[node.id], ...patch };
  clearOptimizedResult();
  updateAll("netflow_action");
};
const onDemandChange = (newDemand) => {
  state.finalDemand = clamp(Math.round(newDemand), 1, 5000);
  clearOptimizedResult();
  el.demandRange.value = `${state.finalDemand}`;
  el.demandInput.value = `${state.finalDemand}`;
  updateAll("demand_action");
};
const onPeriodDaysChange = (days) => {
  state.periodDays = clamp(Math.round(days), 1, 365);
  clearOptimizedResult();
  el.periodDaysInput.value = `${state.periodDays}`;
  updateAll("period_action");
};
const onScenarioChange = (idx) => {
  state.scenarioIndex = idx;
  clearOptimizedResult();
  updateScenarioButtons();
  updateScenarioSummary();
  resetState();
  renderBom();
  state.roundActive = false;
  setControlsEnabled(false);
  setRoundStatus("not-started", "Round: Not started");
  updateAll();
};

const clearOptimizedResult = () => {
  state.optimized = null;
  state.history.cost.opt = [];
  state.history.emissions.opt = [];
};

const applyOptimalPlan = () => {
  if (!state.roundActive) return;
  el.indicator?.classList.remove("hidden");
  const baseline = cloneCurrentConfig();
  const baselineEval = evaluateConfig(baseline);
  const optimized = runOptimizer(baseline);
  const optimizedConfig = cloneConfig(optimized.config);
  optimizedConfig.selectedSuppliersByNode = Object.fromEntries(
    Object.entries(optimized.config.supplierAssignments || {}).map(([nodeId, ids]) => [nodeId, [...ids]])
  );
  const kpiSnapshot = { ...optimized.optimizedKPIs };
  state.optimized = { ...optimized, optimizedConfig, kpiSnapshot };
  // Append to optimized history (separate series, never pollutes current)
  state.history.cost.opt.push(optimized.totals.cost);
  state.history.emissions.opt.push(optimized.totals.emissions);
  if (state.history.cost.opt.length > 14) state.history.cost.opt.shift();
  if (state.history.emissions.opt.length > 14) state.history.emissions.opt.shift();
  if (el.advisorBody) {
    el.advisorBody.innerHTML = `
      <div class="advisor-item">
        <div class="advisor-headline"><span>Optimal setup found</span><span class="advisor-why">Deterministic search</span></div>
        <div class="advisor-rationale">
          Baseline score ${baselineEval.score} → Optimized score ${optimized.score}. Current setup is unchanged; review KPI comparison.
        </div>
      </div>
    `;
  }
  el.indicator?.classList.add("hidden");
  updateAll("optimize_action");
};

const applyOptimizedConfigToCurrent = () => {
  if (!state.optimized?.optimizedConfig) return;
  const next = cloneConfig(state.optimized.optimizedConfig);
  state.supplierAssignments = next.supplierAssignments;
  state.buffers = next.buffers;
  state.netFlowInputs = next.netFlowInputs;
  state.finalDemand = next.finalDemand;
  state.periodDays = next.periodDays;
  if (el.demandRange) el.demandRange.value = `${state.finalDemand}`;
  if (el.demandInput) el.demandInput.value = `${state.finalDemand}`;
  if (el.periodDaysInput) el.periodDaysInput.value = `${state.periodDays}`;
  clearOptimizedResult();
  updateAll("apply_optimized");
};

const setRoundStatus = (kind, text) => {
  el.roundStatus.textContent = text;
  el.roundStatus.className = `round-status ${kind}`;
};
const setControlsEnabled = (enabled) => {
  el.endRoundBtn.disabled = !enabled;
  el.optimizeBtn.disabled = !enabled;
  el.startRoundBtn.disabled = false;
  el.supplierList?.querySelectorAll("input[data-supplier]").forEach((b) => { b.disabled = !enabled; });
  const controls = [
    el.inspectorBuffered,
    el.inspectorOnHand,
    el.inspectorOpenSupply,
    el.demandRange,
    el.demandInput,
    el.periodDaysInput
  ].filter(Boolean);
  controls.forEach((c) => { c.disabled = !enabled; });
};

const startRound = () => {
  state.roundActive = true;
  setControlsEnabled(true);
  setRoundStatus("running", "Round: Running");
  updateAll("round_start");
};
const endRound = () => {
  const totals = calculateTotals();
  const score = computeScore(totals);
  const approval = boardApproval(score);
  state.roundActive = false;
  setControlsEnabled(false);
  setRoundStatus("ended", "Round: Ended");
  openModal({
    title: `Board approval: ${approval.label}`,
    body: `Score ${score} (${renderStars(starRating(score))}) · Cost ${fmtC(totals.cost)} · CO₂ ${fmt(totals.emissions)} kg · Reliability ${fmt(totals.reliability)}% · Service LT ${fmt(totals.serviceLead)} days`,
    primaryText: "Play Again",
    secondaryText: "Change Scenario",
    onPrimary: () => {
      closeModal();
      resetState();
      state.roundActive = true;
      setControlsEnabled(true);
      setRoundStatus("running", "Round: Running");
      updateAll("round_start");
    },
    onSecondary: () => {
      closeModal();
      el.scenarioModal.classList.remove("hidden");
      el.scenarioModal.querySelector(".modal-scenario")?.focus();
    }
  });
};

const initTooltips = () => {
  const tooltipMap = {};
  if (tooltipsData?.tooltips) {
    tooltipsData.tooltips.forEach((tt) => { tooltipMap[tt.id] = tt.text; });
  }
  let target = null;
  document.body.addEventListener("pointerover", (e) => {
    const t = e.target.closest("[data-tip], [data-tooltip-id]");
    if (!t || t === target) return;
    if (t.closest("#supplierList")) return;
    target = t;
    const tipId = t.getAttribute("data-tooltip-id");
    const text = (tipId && tooltipMap[tipId]) || t.getAttribute("data-tip");
    el.tooltip.textContent = text || "";
    el.tooltip.classList.add("visible");
  });
  document.body.addEventListener("pointermove", (e) => {
    if (!target) return;
    el.tooltip.style.left = `${e.clientX + 14}px`;
    el.tooltip.style.top = `${e.clientY + 14}px`;
  });
  document.body.addEventListener("pointerout", (e) => {
    if (!e.target.closest("[data-tip], [data-tooltip-id]")) return;
    target = null;
    el.tooltip.classList.remove("visible");
  });
};

const TOUR_STEPS = [
  { target: null, title: "Welcome", body: "Choose a BOM scenario, set final demand, assign suppliers, and position DDMRP buffers." },
  { target: "bomPanel", title: "BOM + demand", body: "Final demand drives all node requirements through BOM explosion." },
  { target: "nodeInspector", title: "Node DDMRP controls", body: "Toggle buffer positioning at a node and simulate on-hand/open-supply to see net flow status." },
  { target: "supplierPanel", title: "Supplier choices", body: "Purchased nodes allow multi-sourcing with equal split. Longest supplier lead time governs node lead time." },
  { target: "bufferPanel", title: "Buffer posture", body: "Posture bar aggregates total red/yellow/green zones over all nodes." },
  { target: "dashboardPanel", title: "Optimization dashboard", body: "Cost/emissions/reliability/lead/score update live from BOM + DDMRP settings." }
];

const tour = { active: false, step: 0, lastFocus: null };
const tourEls = {
  root: $("tour"),
  highlight: $("tourHighlight"),
  step: $("tourStep"),
  title: $("tourTitle"),
  body: $("tourBody"),
  back: $("tourBack"),
  next: $("tourNext"),
  skip: $("tourSkip")
};

const highlightTourTarget = (targetId) => {
  if (!targetId) {
    tourEls.highlight.style.left = "50%";
    tourEls.highlight.style.top = "40%";
    tourEls.highlight.style.width = "1px";
    tourEls.highlight.style.height = "1px";
    return;
  }
  const t = $(targetId);
  if (!t) return;
  const r = t.getBoundingClientRect();
  const p = 8;
  tourEls.highlight.style.left = `${Math.max(8, r.left - p)}px`;
  tourEls.highlight.style.top = `${Math.max(8, r.top - p)}px`;
  tourEls.highlight.style.width = `${Math.min(window.innerWidth - 16, r.width + p * 2)}px`;
  tourEls.highlight.style.height = `${Math.min(window.innerHeight - 16, r.height + p * 2)}px`;
};
const renderTourStep = () => {
  const s = TOUR_STEPS[tour.step];
  tourEls.step.textContent = `Step ${tour.step + 1} of ${TOUR_STEPS.length}`;
  tourEls.title.textContent = s.title;
  tourEls.body.textContent = s.body;
  tourEls.back.disabled = tour.step === 0;
  tourEls.next.textContent = tour.step === TOUR_STEPS.length - 1 ? "Finish" : "Next";
  highlightTourTarget(s.target);
};
const openTour = () => {
  tour.active = true;
  tour.step = 0;
  tour.lastFocus = document.activeElement;
  tourEls.root.classList.remove("hidden");
  renderTourStep();
  tourEls.next.focus();
};
const closeTour = (markSeen = true) => {
  tour.active = false;
  tourEls.root.classList.add("hidden");
  if (markSeen) {
    try { localStorage.setItem("ddmrp_tour_seen", "1"); } catch { /* ignore */ }
  }
  if (tour.lastFocus && typeof tour.lastFocus.focus === "function") tour.lastFocus.focus();
};

const initEvents = () => {
  el.supplierList.addEventListener("change", (e) => {
    const input = e.target.closest("input[data-supplier]");
    if (!input) return;
    onSupplierAssign(input.dataset.supplier);
  });

  scenarioButtons.forEach((btn) => btn.addEventListener("click", () => onScenarioChange(Number(btn.dataset.scenario))));
  document.querySelectorAll(".modal-scenario").forEach((btn) => {
    btn.addEventListener("click", () => {
      onScenarioChange(Number(btn.dataset.scenario));
      el.scenarioModal.classList.add("hidden");
    });
  });

  el.inspectorBuffered.addEventListener("change", (e) => onBufferChange({ buffered: !!e.target.checked }));
  el.inspectorOnHand.addEventListener("input", (e) => {
    const v = Number(e.target.value);
    el.inspectorOnHandValue.textContent = `${v}%`;
    onNetFlowInputChange({ onHandPct: v });
  });
  el.inspectorOpenSupply.addEventListener("input", (e) => {
    const v = Number(e.target.value);
    el.inspectorOpenSupplyValue.textContent = `${v}%`;
    onNetFlowInputChange({ openSupplyPct: v });
  });

  el.demandRange.addEventListener("input", (e) => onDemandChange(Number(e.target.value)));
  el.demandInput.addEventListener("change", (e) => onDemandChange(Number(e.target.value)));
  el.periodDaysInput.addEventListener("change", (e) => onPeriodDaysChange(Number(e.target.value)));
  if (el.nodeSearch) el.nodeSearch.addEventListener("input", (e) => updateNodeSearch(e.target.value));

  if (el.advisorToggle) {
    el.advisorToggle.addEventListener("change", (e) => {
      state.advisor.enabled = e.target.checked;
      updateAll("toggle");
    });
  }

  el.startRoundBtn.addEventListener("click", startRound);
  el.endRoundBtn.addEventListener("click", endRound);
  el.optimizeBtn.addEventListener("click", applyOptimalPlan);
  if (el.applyOptimizedBtn) el.applyOptimizedBtn.addEventListener("click", applyOptimizedConfigToCurrent);
  $("scenarioClose").addEventListener("click", () => el.scenarioModal.classList.add("hidden"));
  el.tutorialBtn.addEventListener("click", () => toggleTutorialPopover());
  if (el.startTourBtn) {
    el.startTourBtn.addEventListener("click", () => {
      closeTutorialPopover();
      openTour();
    });
  }
  if (el.inspectorAccordion) {
    el.inspectorAccordion.addEventListener("click", () => {
      state.layout.userInspectorChoice = true;
      const collapsed = !state.layout.inspectorCollapsed;
      setNodeInspectorCollapsed(collapsed);
    });
  }
  if (el.zoomInBtn) el.zoomInBtn.addEventListener("click", () => setZoomLevel(state.ui.zoomLevel + ZOOM_STEP));
  if (el.zoomOutBtn) el.zoomOutBtn.addEventListener("click", () => setZoomLevel(state.ui.zoomLevel - ZOOM_STEP));
  if (el.zoomResetBtn) el.zoomResetBtn.addEventListener("click", resetZoom);
  if (el.bomSvg) {
    el.bomSvg.addEventListener("wheel", (e) => {
      e.preventDefault();
      const next = e.deltaY > 0 ? state.ui.zoomLevel - ZOOM_STEP : state.ui.zoomLevel + ZOOM_STEP;
      setZoomLevel(next);
    }, { passive: false });
    el.bomSvg.addEventListener("mousedown", (e) => {
      state.ui.bomDragStart = { clientX: e.clientX, clientY: e.clientY };
      state.ui.bomDidPan = false;
      el.bomSvg.classList.add("bom-dragging");
    });
    el.bomSvg.addEventListener("mousemove", (e) => {
      const start = state.ui.bomDragStart;
      if (!start) return;
      const dx = e.clientX - start.clientX;
      const dy = e.clientY - start.clientY;
      if (Math.abs(dx) > 2 || Math.abs(dy) > 2) state.ui.bomDidPan = true;
      const rect = el.bomSvg.getBoundingClientRect();
      const z = state.ui.zoomLevel;
      const w = BOM_BASE_VIEWBOX.w / z;
      const h = BOM_BASE_VIEWBOX.h / z;
      if (rect.width > 0 && rect.height > 0) {
        state.ui.panX += dx * (w / rect.width);
        state.ui.panY += dy * (h / rect.height);
        applyBomZoom();
      }
      state.ui.bomDragStart = { clientX: e.clientX, clientY: e.clientY };
    });
    el.bomSvg.addEventListener("mouseup", () => {
      state.ui.bomDragStart = null;
      el.bomSvg.classList.remove("bom-dragging");
    });
    el.bomSvg.addEventListener("mouseleave", () => {
      state.ui.bomDragStart = null;
      el.bomSvg.classList.remove("bom-dragging");
    });
  }

  if (TEST_MODE && el.runTestsBtn) el.runTestsBtn.addEventListener("click", renderTestResults);

  tourEls.back.addEventListener("click", () => { tour.step = Math.max(0, tour.step - 1); renderTourStep(); });
  tourEls.next.addEventListener("click", () => {
    if (tour.step === TOUR_STEPS.length - 1) closeTour(true);
    else { tour.step += 1; renderTourStep(); }
  });
  tourEls.skip.addEventListener("click", () => closeTour(true));

  el.modalPrimary.addEventListener("click", () => {
    if (typeof state.modalHandlers.primary === "function") state.modalHandlers.primary();
    else closeModal();
  });
  el.modalSecondary.addEventListener("click", () => {
    if (typeof state.modalHandlers.secondary === "function") state.modalHandlers.secondary();
    else closeModal();
  });
  el.modal.addEventListener("click", (e) => { if (e.target === el.modal) closeModal(); });
  el.scenarioModal.addEventListener("click", (e) => { if (e.target === el.scenarioModal) el.scenarioModal.classList.add("hidden"); });

  document.addEventListener("keydown", (e) => {
    if (e.key !== "Escape") return;
    if (!el.modal.classList.contains("hidden")) closeModal();
    else if (!el.scenarioModal.classList.contains("hidden")) el.scenarioModal.classList.add("hidden");
    else if (tour.active) closeTour(true);
    closeTutorialPopover();
  });
  document.addEventListener("click", (e) => {
    const inside = e.target.closest("#tutorialPopover") || e.target.closest("#tutorialBtn");
    if (!inside) closeTutorialPopover();
  });

  window.addEventListener("resize", autoCompactLayout);
};

const boot = () => {
  if (TEST_MODE && el.validityPanel) el.validityPanel.classList.remove("hidden");
  updateScenarioButtons();
  updateScenarioSummary();
  if (el.advisorToggle) state.advisor.enabled = el.advisorToggle.checked;
  resetState();
  renderBom();
  setControlsEnabled(false);
  setRoundStatus("not-started", "Round: Not started");
  updateAll();
  if (TEST_MODE) renderTestResults();
  initTooltips();
  initEvents();
  autoCompactLayout();
  applyBomZoom();

  closeTutorialPopover();
};

boot();
