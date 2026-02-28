/**
 * Pure DDMRP calculation functions
 */
import { FIXED_LTF, FIXED_VF, INVENTORY_EMISSIONS_FACTOR } from "./data.js";

export { FIXED_LTF, FIXED_VF, INVENTORY_EMISSIONS_FACTOR };

export function computeADU(reqQty, periodDays) {
  const p = Math.max(1, periodDays);
  return reqQty / p;
}

export function computeBuffers(adu, dlt, ltf, vf) {
  const DLT = Math.max(0, dlt);
  const LTF = Math.max(0, ltf);
  const VF = Math.max(0, vf);
  const redBase = adu * DLT * LTF;
  const redSafety = redBase * VF;
  const red = redBase + redSafety;
  const yellow = adu * DLT;
  const green = yellow;
  const topOfGreen = red + yellow + green;
  return { redBase, redSafety, red, yellow, green, topOfGreen };
}

export function computeNetFlowStatus(netFlowPosition, red, yellow, topOfGreen) {
  if (netFlowPosition < red) return "Red";
  if (netFlowPosition < red + yellow) return "Yellow";
  if (netFlowPosition < topOfGreen) return "Green";
  return "Above Green";
}

export function computeAvgInventory(bufferResult) {
  return bufferResult.topOfGreen / 2;
}

export function explodeBomDemand(nodes, edges, fgId, finalDemand) {
  const children = {};
  const indegree = {};
  nodes.forEach((n) => {
    children[n.id] = [];
    indegree[n.id] = 0;
  });
  edges.forEach((e) => {
    children[e.parent].push(e);
    indegree[e.child] += 1;
  });

  const req = {};
  nodes.forEach((n) => { req[n.id] = 0; });
  req[fgId] = finalDemand;

  const q = [];
  Object.keys(indegree).forEach((id) => { if (indegree[id] === 0) q.push(id); });
  while (q.length) {
    const id = q.shift();
    const base = req[id] || 0;
    children[id].forEach((e) => {
      req[e.child] += base * e.qty;
      indegree[e.child] -= 1;
      if (indegree[e.child] === 0) q.push(e.child);
    });
  }
  return req;
}

export function expectedReqFromParents(nodeId, req, edges, fgId, finalDemand) {
  if (nodeId === fgId) return finalDemand;
  return edges
    .filter((e) => e.child === nodeId)
    .reduce((sum, e) => sum + (req[e.parent] || 0) * e.qty, 0);
}

export function computeDltMap(nodeIds, childrenMap, leadByNode, buffered) {
  const memo = {};
  const dfs = (id) => {
    if (memo[id] != null) return memo[id];
    let maxChild = 0;
    const children = childrenMap[id] || [];
    for (let i = 0; i < children.length; i += 1) {
      const childId = children[i].child;
      if (buffered[childId]) continue;
      maxChild = Math.max(maxChild, dfs(childId));
    }
    memo[id] = Math.max(0, leadByNode[id] || 0) + maxChild;
    return memo[id];
  };
  nodeIds.forEach((id) => dfs(id));
  return memo;
}

export function buildParentMap(nodeIds, edges) {
  const parents = {};
  nodeIds.forEach((id) => { parents[id] = []; });
  edges.forEach((e) => {
    if (!parents[e.child]) parents[e.child] = [];
    parents[e.child].push(e.parent);
  });
  return parents;
}

export function buildIndegreeMap(nodeIds, edges) {
  const indegree = {};
  nodeIds.forEach((id) => { indegree[id] = 0; });
  edges.forEach((e) => { indegree[e.child] = (indegree[e.child] || 0) + 1; });
  return indegree;
}

export function topoOrderFromRoot(fgId, childrenMap, indegree) {
  const order = [];
  const indeg = { ...indegree };
  const q = [fgId];
  const seen = new Set();
  while (q.length) {
    const id = q.shift();
    if (seen.has(id)) continue;
    seen.add(id);
    order.push(id);
    const children = childrenMap[id] || [];
    children.forEach((e) => {
      const childId = e.child;
      indeg[childId] -= 1;
      if (indeg[childId] === 0) q.push(childId);
    });
  }
  return order;
}

export function computeSegLtMap(order, parentsMap, effLT, buffered, fgId) {
  const segLT = {};
  order.forEach((id) => {
    if (id === fgId) {
      segLT[id] = Math.max(0, effLT[id] || 0);
      return;
    }
    const parents = parentsMap[id] || [];
    let maxParent = 0;
    parents.forEach((p) => {
      const parentSeg = segLT[p] ?? 0;
      const candidate = buffered[p] ? 0 : parentSeg;
      if (candidate > maxParent) maxParent = candidate;
    });
    segLT[id] = Math.max(0, effLT[id] || 0) + maxParent;
  });
  return segLT;
}

export function summarizeSuppliersForNode(selected, suppliersById) {
  const supplierRows = selected.map((sid) => suppliersById[sid]).filter(Boolean);
  if (!supplierRows.length) {
    return {
      valid: false,
      selectedCount: 0,
      effCost: 18,
      effEmissions: 7,
      effLeadTime: 12,
      effReliability: 50
    };
  }
  const n = supplierRows.length;
  const inv = 1 / n;
  const effCost = supplierRows.reduce((sum, s) => sum + s.unitCost * inv, 0);
  const effEmissions = supplierRows.reduce((sum, s) => sum + s.unitEmissions * inv, 0);
  const effLeadTime = supplierRows.reduce((mx, s) => Math.max(mx, s.leadTime), 0);
  const effReliability = supplierRows.reduce((mn, s) => Math.min(mn, s.reliability), 100);
  return { valid: true, selectedCount: n, effCost, effEmissions, effLeadTime, effReliability };
}

export function getNodeInventoryEmissionFactor(node) {
  if (Number.isFinite(node.invEmisFactor)) return node.invEmisFactor;
  return Math.max(0.01, (node.holdingRate || 0.2) * INVENTORY_EMISSIONS_FACTOR);
}
