/**
 * Game engine: calculations, optimizer
 */
import {
  FIXED_LTF,
  FIXED_VF,
  INVENTORY_EMISSIONS_FACTOR,
  BOM_SCENARIOS
} from "./data.js";
import {
  computeADU,
  computeBuffers,
  computeNetFlowStatus,
  computeAvgInventory,
  explodeBomDemand,
  computeDltMap,
  buildParentMap,
  buildIndegreeMap,
  topoOrderFromRoot,
  computeSegLtMap,
  summarizeSuppliersForNode,
  getNodeInventoryEmissionFactor
} from "./ddmrp.js";
import {
  state,
  getScenario,
  getSuppliersById,
  graphChildren,
  buildWorkingConfig,
  cloneConfig
} from "./state.js";

const clamp = (v, lo, hi) => Math.min(Math.max(v, lo), hi);

export function calculateTotals(overrideConfig = null) {
  const sc = getScenario();
  const cfg = buildWorkingConfig(overrideConfig);
  const suppliersById = getSuppliersById();
  const children = graphChildren();
  const nodeIds = sc.nodes.map((n) => n.id);
  const required = explodeBomDemand(sc.nodes, sc.edges, sc.fgId, cfg.finalDemand);

  let purchasedCost = 0, inventoryCost = 0, purchasedEmissions = 0, internalEmissions = 0, inventoryEmissions = 0;
  let reliabilityWeighted = 0, reliabilityWeightBase = 0, bufferCount = 0;
  let postureRed = 0, postureYellow = 0, postureGreen = 0, invalidSupplierNodes = 0;

  const leadByNode = {};
  const nodeStats = {};
  const supplierSummary = {};
  const bufferedFlags = {};

  sc.nodes.forEach((node) => {
    bufferedFlags[node.id] = !!cfg.buffers[node.id]?.buffered;
    let lead = node.leadTime;
    if (node.type === "PURCHASED") {
      const selected = cfg.supplierAssignments[node.id] || [];
      const summary = summarizeSuppliersForNode(selected, suppliersById);
      supplierSummary[node.id] = summary;
      lead = node.leadTime + summary.effLeadTime;
    }
    leadByNode[node.id] = lead;
  });

  const parentsMap = buildParentMap(nodeIds, sc.edges);
  const indegreeMap = buildIndegreeMap(nodeIds, sc.edges);
  const topoOrder = topoOrderFromRoot(sc.fgId, children, indegreeMap);
  const segLtByNode = computeSegLtMap(topoOrder, parentsMap, leadByNode, bufferedFlags, sc.fgId);
  const segLtUnbuffered = computeSegLtMap(topoOrder, parentsMap, leadByNode, {}, sc.fgId);
  const rawDltByNode = computeDltMap(nodeIds, children, leadByNode, {});
  const effDltByNode = computeDltMap(nodeIds, children, leadByNode, bufferedFlags);

  sc.nodes.forEach((node) => {
    const req = required[node.id];
    const adu = computeADU(req, cfg.periodDays);
    const dlt = effDltByNode[node.id] || 0;
    const isBuffered = !!bufferedFlags[node.id];
    let zones = { redBase: 0, redSafety: 0, red: 0, yellow: 0, green: 0, topOfGreen: 0 };
    if (isBuffered) {
      zones = computeBuffers(adu, dlt, FIXED_LTF, FIXED_VF);
      bufferCount += 1;
      inventoryCost += computeAvgInventory(zones) * node.holdingRate;
      postureRed += zones.red;
      postureYellow += zones.yellow;
      postureGreen += zones.green;
    }
    const avgInv = computeAvgInventory(zones);
    const nf = cfg.netFlowInputs[node.id] || { onHandPct: 100, openSupplyPct: 0 };
    const qd = isBuffered ? adu * Math.min(cfg.periodDays, dlt) : 0;
    const onHand = (nf.onHandPct / 100) * zones.topOfGreen;
    const openSupply = (nf.openSupplyPct / 100) * zones.topOfGreen;
    const netFlowPosition = onHand + openSupply - qd;
    const status = isBuffered
      ? computeNetFlowStatus(netFlowPosition, zones.red, zones.yellow, zones.topOfGreen)
      : "Pass-through";

    if (node.type === "PURCHASED") {
      const s = supplierSummary[node.id];
      purchasedCost += req * s.effCost;
      purchasedEmissions += req * s.effEmissions;
      reliabilityWeighted += s.effReliability * req;
      reliabilityWeightBase += req;
      if (!s.valid) invalidSupplierNodes += 1;
    } else {
      internalEmissions += req * (node.internalEmission || 0);
    }
    if (isBuffered) inventoryEmissions += avgInv * getNodeInventoryEmissionFactor(node);

    nodeStats[node.id] = {
      req, adu, dlt, rawDlt: rawDltByNode[node.id] || 0, buffered: isBuffered,
      ...zones, avgInv, onHand, openSupply, qualifiedDemand: qd, netFlowPosition, status,
      invEmisFactor: getNodeInventoryEmissionFactor(node)
    };
  });

  const reliability = reliabilityWeightBase > 0 ? reliabilityWeighted / reliabilityWeightBase : 0;
  const segLtValues = topoOrder.map((id) => segLtByNode[id] || 0);
  const segLtUnbufferedValues = topoOrder.map((id) => segLtUnbuffered[id] || 0);
  const rawLead = segLtUnbufferedValues.length ? Math.max(...segLtUnbufferedValues) : 0;
  const serviceLead = bufferedFlags[sc.fgId] ? 0 : (segLtValues.length ? Math.max(...segLtValues) : 0);
  const flowResponsiveness = rawLead > 0 ? clamp(((rawLead - serviceLead) / rawLead) * 100, 0, 95) : 0;
  const penaltyCost =
    Math.max(0, 76 - reliability) * 130 +
    Math.max(0, serviceLead - (sc.serviceTarget || 12)) * 350 +
    invalidSupplierNodes * 20000;
  const emissions = purchasedEmissions + internalEmissions + inventoryEmissions;

  return {
    cost: purchasedCost + inventoryCost + penaltyCost,
    emissions,
    reliability,
    serviceLead,
    rawLead,
    flowResponsiveness,
    bufferCount,
    purchasedCost,
    inventoryCost,
    postureRed,
    postureYellow,
    postureGreen,
    inventoryEmissions,
    required,
    nodeStats,
    supplierSummary,
    invalidSupplierNodes
  };
}

export function computeScore(totals) {
  const sc = getScenario();
  if (totals.cost <= 0 || totals.emissions <= 0) return 0;
  const nc = clamp(totals.cost / sc.targetCost, 0, 2);
  const ne = clamp(totals.emissions / sc.targetEmissions, 0, 2);
  const ni = clamp(totals.inventoryEmissions / Math.max(1, sc.targetInventoryEmissions || 1), 0, 2);
  const w = sc.objective || { cost: 330, emissions: 320, inventoryEmissions: 120, reliability: 2.3, service: 4.4 };
  const raw =
    1000 - (nc * w.cost) - (ne * w.emissions) - (ni * w.inventoryEmissions) +
    (totals.reliability * w.reliability) - (totals.serviceLead * w.service);
  return Math.round(clamp(raw, 0, 1000));
}

export function starRating(s) {
  return s >= 850 ? 5 : s >= 700 ? 4 : s >= 550 ? 3 : s >= 400 ? 2 : 1;
}

export function boardApproval(score) {
  return score >= 700 ? { label: "Approved", cls: "approved" }
    : score >= 500 ? { label: "Needs Review", cls: "needs-review" }
    : { label: "Rejected", cls: "rejected" };
}

function ensureFeasibleSupplierCoverage(config) {
  const sc = getScenario();
  const fallback = sc.supplierIds[0];
  sc.nodes.forEach((node) => {
    if (node.type !== "PURCHASED") return;
    const arr = config.supplierAssignments[node.id] || [];
    if (!arr.length && fallback) config.supplierAssignments[node.id] = [fallback];
  });
}

export function evaluateConfig(config) {
  const totals = calculateTotals(config);
  const score = computeScore(totals);
  return { totals, score };
}

function enumerateMoves(config) {
  const sc = getScenario();
  const moves = [];
  sc.nodes.forEach((node) => {
    const currentBuffered = !!config.buffers[node.id]?.buffered;
    moves.push({ key: `B|${node.id}|${currentBuffered ? "off" : "on"}`, kind: "buffer", nodeId: node.id, buffered: !currentBuffered });
    if (node.type !== "PURCHASED") return;
    const selected = config.supplierAssignments[node.id] || [];
    sc.supplierIds.forEach((sid) => {
      const has = selected.includes(sid);
      if (has && selected.length <= 1) return;
      moves.push({ key: `S|${node.id}|${sid}|${has ? "remove" : "add"}`, kind: "supplier", nodeId: node.id, sid, op: has ? "remove" : "add" });
    });
  });
  moves.sort((a, b) => a.key.localeCompare(b.key));
  return moves;
}

function applyMove(config, move) {
  if (move.kind === "buffer") {
    config.buffers[move.nodeId] = { ...(config.buffers[move.nodeId] || { buffered: false }), buffered: move.buffered };
    return;
  }
  if (move.kind === "supplier") {
    const cur = [...(config.supplierAssignments[move.nodeId] || [])];
    if (move.op === "add" && !cur.includes(move.sid)) cur.push(move.sid);
    if (move.op === "remove" && cur.includes(move.sid) && cur.length > 1) cur.splice(cur.indexOf(move.sid), 1);
    config.supplierAssignments[move.nodeId] = cur;
  }
}

export function runOptimizer(baseConfig) {
  const MAX_ITERS = 18;
  let current = cloneConfig(baseConfig);
  ensureFeasibleSupplierCoverage(current);
  let currentEval = evaluateConfig(current);
  let best = { config: cloneConfig(current), ...currentEval };

  for (let iter = 0; iter < MAX_ITERS; iter += 1) {
    const moves = enumerateMoves(current);
    let moveBest = null;
    let moveBestEval = currentEval;
    moves.forEach((move) => {
      const candidate = cloneConfig(current);
      applyMove(candidate, move);
      ensureFeasibleSupplierCoverage(candidate);
      const candidateEval = evaluateConfig(candidate);
      if (candidateEval.score > moveBestEval.score ||
        (candidateEval.score === moveBestEval.score && moveBest && move.key < moveBest.key)) {
        moveBestEval = candidateEval;
        moveBest = { ...move, config: candidate };
      }
    });
    if (!moveBest || moveBestEval.score <= currentEval.score) break;
    current = moveBest.config;
    currentEval = moveBestEval;
    if (currentEval.score > best.score) best = { config: cloneConfig(current), ...currentEval };
  }
  return {
    config: best.config,
    totals: best.totals,
    score: best.score,
    optimizedKPIs: {
      cost: best.totals.cost,
      emissions: best.totals.emissions,
      reliability: best.totals.reliability,
      serviceLT: best.totals.serviceLead,
      inventoryCost: best.totals.inventoryCost,
      inventoryEmissions: best.totals.inventoryEmissions,
      score: best.score
    }
  };
}

export { cloneConfig, FIXED_LTF, FIXED_VF, BOM_SCENARIOS };
export { computeBuffers, computeADU, explodeBomDemand, expectedReqFromParents, buildParentMap, buildIndegreeMap, topoOrderFromRoot, computeSegLtMap, summarizeSuppliersForNode } from "./ddmrp.js";
