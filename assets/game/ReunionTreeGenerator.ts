import * as THREE from 'three';

export type ReunionTreeSpecies =
  | 'filao'
  | 'cocotier'
  | 'flamboyant'
  | 'tamarin'
  | 'cryptomeria'
  | 'fanjon'
  | 'vacoa';

export interface ProceduralTreeArchetype {
  species: ReunionTreeSpecies;
  variantIndex: number;
  woodGeo: THREE.BufferGeometry;
  foliageGeo: THREE.BufferGeometry;
}

interface MeshBuilder {
  positions: number[];
  colors: number[];
  indices: number[];
}

interface SkeletonNode {
  id: number;
  parent: number | null;
  children: number[];
  pos: THREE.Vector3;
  dir: THREE.Vector3;
  radius: number;
  depth: number;
}

interface Attractor {
  pos: THREE.Vector3;
  active: boolean;
}

/**
 * Procedural 3D tree generator for La Réunion flora inspired by 3D Space Colonization
 * (Runions et al.) and Allometric Pipe-Model branching (Leonardo da Vinci's rule r_p^alpha = sum(r_c^alpha)),
 * producing low-poly vertex-colored instanced geometries for 7 emblematic island species:
 * - Filao (Casuarina equisetifolia): wind-swept leaning trunk, sinuous boughs, drooping silvery-sage needle tufts
 * - Cocotier / Latanier: curved ringed trunk, coconuts, radial crown of arching 3D pinnate fronds
 * - Flamboyant (Delonix regia): wide sympodial umbrella canopy covered in vivid scarlet-orange blossoms
 * - Tamarin des Hauts (Acacia heterophylla): gnarled twisted highland trunk & horizontal phyllode cloud pads
 * - Cryptoméria (Cryptomeria japonica): straight monopodial reddish trunk with tiered conical emerald whorls
 * - Fanjon (Cyathea borbonica): fibrous tree-fern trunk crowned with unfurling lacy fronds
 * - Vacoa (Pandanus utilis): aerial stilt prop-roots, dichotomous Y-branches & terminal spiky leaf rosettes
 */
export function generateReunionTreeArchetypes(masterSeed = 9742026): ProceduralTreeArchetype[] {
  let seed = masterSeed >>> 0;
  const rand = (): number => {
    seed = (seed * 1664525 + 1013904223) >>> 0;
    return seed / 4294967296;
  };

  const archetypes: ProceduralTreeArchetype[] = [];

  // 1. Filao (Casuarina equisetifolia) — 3 coastal wind-swept variants
  for (let v = 0; v < 3; v++) {
    archetypes.push(buildFilaoVariant(v, rand));
  }

  // 2. Cocotier / Latanier — 2 tropical lagoon palm variants
  for (let v = 0; v < 2; v++) {
    archetypes.push(buildCocotierVariant(v, rand));
  }

  // 3. Flamboyant (Delonix regia) — 2 wide scarlet-flowering umbrella variants
  for (let v = 0; v < 2; v++) {
    archetypes.push(buildFlamboyantVariant(v, rand));
  }

  // 4. Tamarin des Hauts (Acacia heterophylla) — 3 gnarled highland space-colonized variants
  for (let v = 0; v < 3; v++) {
    archetypes.push(buildTamarinVariant(v, rand));
  }

  // 5. Cryptoméria du Japon — 2 tall tiered cirque conifer variants
  for (let v = 0; v < 2; v++) {
    archetypes.push(buildCryptomeriaVariant(v, rand));
  }

  // 6. Fanjon (Fougère arborescente Cyathea borbonica) — 2 rainforest tree-fern variants
  for (let v = 0; v < 2; v++) {
    archetypes.push(buildFanjonVariant(v, rand));
  }

  // 7. Vacoa (Pandanus utilis) — 2 Sud Sauvage stilt-rooted candelabra variants
  for (let v = 0; v < 2; v++) {
    archetypes.push(buildVacoaVariant(v, rand));
  }

  return archetypes;
}

function createBuilder(): MeshBuilder {
  return { positions: [], colors: [], indices: [] };
}

function toBufferGeometry(b: MeshBuilder): THREE.BufferGeometry {
  const geo = new THREE.BufferGeometry();
  geo.setAttribute('position', new THREE.Float32BufferAttribute(b.positions, 3));
  geo.setAttribute('color', new THREE.Float32BufferAttribute(b.colors, 3));
  geo.setIndex(b.indices);
  geo.computeVertexNormals();
  return geo;
}

/**
 * Runs 3D Space Colonization from an initial trunk tip toward a cloud of crown attractors,
 * then applies Leonardo da Vinci's pipe-model rule (r_parent^alpha = sum(r_child^alpha)) bottom-up.
 */
function growSpaceColonizedSkeleton(opts: {
  trunkBase: THREE.Vector3;
  trunkHeight: number;
  trunkSegments: number;
  trunkLean: THREE.Vector3;
  attractors: Attractor[];
  stepLength: number;
  influenceRadius: number;
  killDistance: number;
  maxIterations: number;
  maxChildrenPerNode: number;
  tropism: THREE.Vector3;
  jitter: number;
  tipRadius: number;
  maxBaseRadius: number;
  pipeExponent: number;
  rand: () => number;
}): SkeletonNode[] {
  const nodes: SkeletonNode[] = [];
  const segLen = opts.trunkHeight / opts.trunkSegments;
  let curPos = opts.trunkBase.clone();
  let curDir = new THREE.Vector3(0, 1, 0).add(opts.trunkLean).normalize();

  nodes.push({
    id: 0,
    parent: null,
    children: [],
    pos: curPos.clone(),
    dir: curDir.clone(),
    radius: opts.maxBaseRadius,
    depth: 0,
  });

  // Grow primary trunk up to fork height
  for (let s = 1; s <= opts.trunkSegments; s++) {
    const wobble = new THREE.Vector3(
      (opts.rand() - 0.5) * 0.14,
      0,
      (opts.rand() - 0.5) * 0.14,
    );
    curDir = curDir.clone().add(opts.trunkLean.clone().multiplyScalar(0.25)).add(wobble).normalize();
    curPos = curPos.clone().addScaledVector(curDir, segLen);
    const parentId = s - 1;
    const id = nodes.length;
    nodes[parentId].children.push(id);
    nodes.push({
      id,
      parent: parentId,
      children: [],
      pos: curPos.clone(),
      dir: curDir.clone(),
      radius: opts.tipRadius,
      depth: s,
    });
  }

  // Space colonization iterations in the crown envelope
  const colonizableStartIdx = Math.max(1, Math.floor(opts.trunkSegments * 0.6));
  for (let iter = 0; iter < opts.maxIterations; iter++) {
    const influences = new Map<number, THREE.Vector3>();
    const counts = new Map<number, number>();

    for (const attr of opts.attractors) {
      if (!attr.active) continue;
      let bestNodeId = -1;
      let bestDist = opts.influenceRadius;

      for (let nIdx = colonizableStartIdx; nIdx < nodes.length; nIdx++) {
        const d = attr.pos.distanceTo(nodes[nIdx].pos);
        if (d < bestDist) {
          bestDist = d;
          bestNodeId = nIdx;
        }
      }

      if (bestNodeId !== -1) {
        const node = nodes[bestNodeId];
        const pull = attr.pos.clone().sub(node.pos).normalize();
        const acc = influences.get(bestNodeId) ?? new THREE.Vector3();
        acc.add(pull);
        influences.set(bestNodeId, acc);
        counts.set(bestNodeId, (counts.get(bestNodeId) ?? 0) + 1);
      }
    }

    if (influences.size === 0) break;

    let addedAny = false;
    for (const [nodeId, accVec] of influences.entries()) {
      const parentNode = nodes[nodeId];
      if (parentNode.children.length >= opts.maxChildrenPerNode) continue;

      const count = counts.get(nodeId) ?? 1;
      const avgDir = accVec
        .divideScalar(count)
        .add(opts.tropism)
        .add(
          new THREE.Vector3(
            (opts.rand() - 0.5) * opts.jitter,
            (opts.rand() - 0.5) * opts.jitter * 0.6,
            (opts.rand() - 0.5) * opts.jitter,
          ),
        )
        .normalize();

      // Prevent downward diving branches inside primary scaffold
      if (avgDir.y < -0.18) avgDir.y = -0.18;
      avgDir.normalize();

      const newPos = parentNode.pos.clone().addScaledVector(avgDir, opts.stepLength);
      // Skip duplicate sibling branch in almost identical direction
      let tooClose = false;
      for (const cId of parentNode.children) {
        if (nodes[cId].pos.distanceTo(newPos) < opts.stepLength * 0.45) {
          tooClose = true;
          break;
        }
      }
      if (tooClose) continue;

      const newId = nodes.length;
      parentNode.children.push(newId);
      nodes.push({
        id: newId,
        parent: nodeId,
        children: [],
        pos: newPos,
        dir: avgDir,
        radius: opts.tipRadius,
        depth: parentNode.depth + 1,
      });
      addedAny = true;
    }

    // Kill attractors reached by new nodes
    for (const attr of opts.attractors) {
      if (!attr.active) continue;
      for (let nIdx = colonizableStartIdx; nIdx < nodes.length; nIdx++) {
        if (attr.pos.distanceTo(nodes[nIdx].pos) <= opts.killDistance) {
          attr.active = false;
          break;
        }
      }
    }

    if (!addedAny) break;
  }

  // Bottom-up allometric radius computation (Leonardo da Vinci's pipe model law)
  const alpha = opts.pipeExponent;
  for (let i = nodes.length - 1; i >= 0; i--) {
    const node = nodes[i];
    if (node.children.length === 0) {
      node.radius = opts.tipRadius;
    } else {
      let sumPow = 0;
      for (const cId of node.children) {
        sumPow += Math.pow(nodes[cId].radius, alpha);
      }
      // Slight taper thickening toward root
      const pipeR = Math.pow(sumPow, 1 / alpha) * 1.04;
      node.radius = Math.min(opts.maxBaseRadius, Math.max(opts.tipRadius * 1.15, pipeR));
    }
  }
  // Ensure smooth root flare on node 0
  nodes[0].radius = Math.min(opts.maxBaseRadius * 1.18, nodes[0].radius * 1.22);

  return nodes;
}

function appendTaperedBranch(
  b: MeshBuilder,
  p0: THREE.Vector3,
  p1: THREE.Vector3,
  r0: number,
  r1: number,
  sides: number,
  col0: THREE.Color,
  col1: THREE.Color,
): void {
  const axis = p1.clone().sub(p0);
  const len = axis.length();
  if (len < 0.001) return;
  axis.divideScalar(len);

  const ref = Math.abs(axis.y) < 0.92 ? new THREE.Vector3(0, 1, 0) : new THREE.Vector3(1, 0, 0);
  const right = new THREE.Vector3().crossVectors(axis, ref).normalize();
  const forward = new THREE.Vector3().crossVectors(right, axis).normalize();

  const baseIdx = b.positions.length / 3;

  for (let ring = 0; ring < 2; ring++) {
    const center = ring === 0 ? p0 : p1;
    const radius = ring === 0 ? r0 : r1;
    const col = ring === 0 ? col0 : col1;

    for (let s = 0; s < sides; s++) {
      const ang = (s / sides) * Math.PI * 2;
      const cos = Math.cos(ang) * radius;
      const sin = Math.sin(ang) * radius;
      const vx = center.x + right.x * cos + forward.x * sin;
      const vy = center.y + right.y * cos + forward.y * sin;
      const vz = center.z + right.z * cos + forward.z * sin;
      b.positions.push(vx, vy, vz);
      b.colors.push(col.r, col.g, col.b);
    }
  }

  for (let s = 0; s < sides; s++) {
    const next = (s + 1) % sides;
    const b0 = baseIdx + s;
    const b1 = baseIdx + next;
    const t0 = baseIdx + sides + s;
    const t1 = baseIdx + sides + next;
    b.indices.push(b0, t0, b1, b1, t0, t1);
  }
}

function appendSkeletonWood(
  b: MeshBuilder,
  nodes: SkeletonNode[],
  sides: number,
  baseBarkHex: number,
  upperBarkHex: number,
): void {
  const cBase = new THREE.Color(baseBarkHex);
  const cUpper = new THREE.Color(upperBarkHex);
  const maxDepth = Math.max(1, ...nodes.map((n) => n.depth));

  for (const node of nodes) {
    if (node.parent === null) continue;
    const parent = nodes[node.parent];
    const t0 = Math.min(1, parent.depth / maxDepth);
    const t1 = Math.min(1, node.depth / maxDepth);
    const col0 = cBase.clone().lerp(cUpper, t0);
    const col1 = cBase.clone().lerp(cUpper, t1);
    appendTaperedBranch(b, parent.pos, node.pos, parent.radius, node.radius, sides, col0, col1);
  }
}

/**
 * Appends a stylized low-poly 12-face foliage/blossom cluster (deformed octahedron/dodecahedron)
 * with organic vertex color gradient from shaded underside to sunlit top.
 */
function appendFoliageCluster(
  b: MeshBuilder,
  center: THREE.Vector3,
  rx: number,
  ry: number,
  rz: number,
  rotY: number,
  topColorHex: number,
  bottomColorHex: number,
  rand: () => number,
): void {
  const cTop = new THREE.Color(topColorHex);
  const cBot = new THREE.Color(bottomColorHex);

  const ringCount = 5;
  const baseIdx = b.positions.length / 3;
  const cosY = Math.cos(rotY);
  const sinY = Math.sin(rotY);

  const emitVertex = (lx: number, ly: number, lz: number, shadeT: number) => {
    const wx = center.x + (lx * cosY - lz * sinY);
    const wy = center.y + ly;
    const wz = center.z + (lx * sinY + lz * cosY);
    b.positions.push(wx, wy, wz);
    const c = cBot.clone().lerp(cTop, THREE.MathUtils.clamp(shadeT, 0, 1));
    b.colors.push(c.r, c.g, c.b);
  };

  // Bottom pole (0)
  emitVertex(0, -ry * 0.85, 0, 0.05);

  // Lower ring (1..5)
  for (let i = 0; i < ringCount; i++) {
    const a = (i / ringCount) * Math.PI * 2;
    const j = 0.88 + rand() * 0.24;
    emitVertex(Math.cos(a) * rx * 0.92 * j, -ry * 0.22, Math.sin(a) * rz * 0.92 * j, 0.38);
  }

  // Upper ring (6..10)
  for (let i = 0; i < ringCount; i++) {
    const a = ((i + 0.5) / ringCount) * Math.PI * 2;
    const j = 0.88 + rand() * 0.24;
    emitVertex(Math.cos(a) * rx * 0.82 * j, ry * 0.36, Math.sin(a) * rz * 0.82 * j, 0.78);
  }

  // Top apex (11)
  emitVertex(0, ry, 0, 1.0);

  for (let i = 0; i < ringCount; i++) {
    const next = (i + 1) % ringCount;
    const l0 = baseIdx + 1 + i;
    const l1 = baseIdx + 1 + next;
    const u0 = baseIdx + 1 + ringCount + i;
    const u1 = baseIdx + 1 + ringCount + next;

    // Bottom cap
    b.indices.push(baseIdx, l1, l0);
    // Middle belt
    b.indices.push(l0, l1, u0, l1, u1, u0);
    // Top cap
    b.indices.push(u0, u1, baseIdx + 1 + ringCount * 2);
  }
}

/**
 * Appends a 3D arching pinnate frond (for Cocotier / Fanjon tree fern) with segmented V-profile leaflets.
 */
function appendArchingFrond(
  b: MeshBuilder,
  origin: THREE.Vector3,
  yaw: number,
  length: number,
  width: number,
  rise: number,
  droop: number,
  segments: number,
  baseHex: number,
  tipHex: number,
): void {
  const cBase = new THREE.Color(baseHex);
  const cTip = new THREE.Color(tipHex);
  const dirX = Math.cos(yaw);
  const dirZ = Math.sin(yaw);
  const perpX = -dirZ;
  const perpZ = dirX;

  const baseIdx = b.positions.length / 3;

  for (let s = 0; s <= segments; s++) {
    const t = s / segments;
    const dist = t * length;
    // Parabolic arch: rises near stem, droops gracefully toward tip
    const y = origin.y + Math.sin(t * Math.PI * 0.78) * rise - t * t * droop;
    const cx = origin.x + dirX * dist;
    const cz = origin.z + dirZ * dist;

    // Width profile: narrow at petiole, widest at middle, tapered to point at tip
    const w = width * Math.sin(Math.pow(t, 0.65) * Math.PI) * (1.0 - t * 0.25);
    const ribLift = w * 0.22;

    const col = cBase.clone().lerp(cTip, t);
    const edgeCol = col.clone().offsetHSL(0, 0.03, -0.05);

    // 3 vertices per cross-section: Left leaflet edge, Center raised midrib, Right leaflet edge
    b.positions.push(cx + perpX * w, y - ribLift, cz + perpZ * w);
    b.colors.push(edgeCol.r, edgeCol.g, edgeCol.b);

    b.positions.push(cx, y + ribLift, cz);
    b.colors.push(col.r, col.g, col.b);

    b.positions.push(cx - perpX * w, y - ribLift, cz - perpZ * w);
    b.colors.push(edgeCol.r, edgeCol.g, edgeCol.b);
  }

  for (let s = 0; s < segments; s++) {
    const row0 = baseIdx + s * 3;
    const row1 = baseIdx + (s + 1) * 3;
    // Left blade (double-sided via top & bottom triangles or DoubleSide/winding)
    b.indices.push(row0, row1, row0 + 1, row0 + 1, row1, row1 + 1);
    b.indices.push(row0, row0 + 1, row1, row0 + 1, row1 + 1, row1);
    // Right blade
    b.indices.push(row0 + 1, row1 + 1, row0 + 2, row0 + 2, row1 + 1, row1 + 2);
    b.indices.push(row0 + 1, row0 + 2, row1 + 1, row0 + 2, row1 + 2, row1 + 1);
  }
}

/**
 * 1. FILAO (Casuarina equisetifolia)
 * Tall, wind-swept coastal tree of Saint-Gilles & Lagon with leaning greyish trunk,
 * ascending Space-Colonized boughs and weeping sage-green casuarina needle tufts.
 */
function buildFilaoVariant(variant: number, rand: () => number): ProceduralTreeArchetype {
  const wood = createBuilder();
  const foliage = createBuilder();

  const attractors: Attractor[] = [];
  const windX = 0.75 + variant * 0.25;
  const windZ = (variant - 1) * 0.45;

  for (let i = 0; i < 68; i++) {
    const a = rand() * Math.PI * 2;
    const r = Math.pow(rand(), 0.55) * 3.1;
    const h = 4.2 + rand() * 5.2;
    const shift = (h - 3.5) * 0.32;
    attractors.push({
      pos: new THREE.Vector3(Math.cos(a) * r + windX * shift, h, Math.sin(a) * r + windZ * shift),
      active: true,
    });
  }

  const nodes = growSpaceColonizedSkeleton({
    trunkBase: new THREE.Vector3(0, 0, 0),
    trunkHeight: 4.1 + variant * 0.35,
    trunkSegments: 4,
    trunkLean: new THREE.Vector3(windX * 0.14, 0, windZ * 0.14),
    attractors,
    stepLength: 0.92,
    influenceRadius: 3.1,
    killDistance: 1.05,
    maxIterations: 11,
    maxChildrenPerNode: 3,
    tropism: new THREE.Vector3(windX * 0.12, 0.22, windZ * 0.08),
    jitter: 0.38,
    tipRadius: 0.055,
    maxBaseRadius: 0.36,
    pipeExponent: 2.35,
    rand,
  });

  appendSkeletonWood(wood, nodes, 5, 0x57493e, 0x7a6a5e);

  // Weeping feathery casuarina needle tufts at terminal and upper branch nodes
  const leafPalettes = [
    [0x5f8d6e, 0x355841],
    [0x6b9978, 0x3d634a],
    [0x527d60, 0x2c4c37],
  ];

  for (const node of nodes) {
    if (node.depth < 4) continue;
    const isTip = node.children.length === 0;
    if (!isTip && node.depth < 6) continue;

    const [topHex, botHex] = leafPalettes[(node.id + variant) % leafPalettes.length];
    const rHoriz = (isTip ? 0.92 : 0.76) * (0.88 + rand() * 0.28);
    const rVert = rHoriz * 1.38; // Weeping elongated needle curtain
    const dropPos = node.pos.clone().add(new THREE.Vector3(0, -0.22, 0));
    appendFoliageCluster(foliage, dropPos, rHoriz, rVert, rHoriz, rand() * Math.PI * 2, topHex, botHex, rand);
  }

  return {
    species: 'filao',
    variantIndex: variant,
    woodGeo: toBufferGeometry(wood),
    foliageGeo: toBufferGeometry(foliage),
  };
}

/**
 * 2. COCOTIER / LATANIER (Cocos nucifera / Latania)
 * Gracefully curved ringed trunk with coconuts and a 3D radial crown of arching pinnate fronds.
 */
function buildCocotierVariant(variant: number, rand: () => number): ProceduralTreeArchetype {
  const wood = createBuilder();
  const foliage = createBuilder();

  const trunkSegs = 7;
  const totalH = 6.4 + variant * 0.7;
  const leanAngle = variant === 0 ? 0.35 : 2.1;
  const leanMag = 1.35 + variant * 0.35;

  const cBase = new THREE.Color(0x6e5036);
  const cTop = new THREE.Color(0x967354);

  let prevPos = new THREE.Vector3(0, 0, 0);
  let prevR = 0.36;

  for (let s = 1; s <= trunkSegs; s++) {
    const t = s / trunkSegs;
    // Smooth S/J curve toward the lagoon sun
    const curve = Math.sin(t * Math.PI * 0.72) * leanMag;
    const curPos = new THREE.Vector3(
      Math.cos(leanAngle) * curve,
      t * totalH,
      Math.sin(leanAngle) * curve,
    );
    const curR = THREE.MathUtils.lerp(0.34, 0.16, Math.pow(t, 0.75));
    const col0 = cBase.clone().lerp(cTop, (s - 1) / trunkSegs);
    const col1 = cBase.clone().lerp(cTop, t);
    appendTaperedBranch(wood, prevPos, curPos, prevR, curR, 6, col0, col1);
    prevPos = curPos;
    prevR = curR;
  }

  const crownPos = prevPos.clone();

  // Cluster of 3 golden-brown coconuts tucked beneath the frond crown
  for (let c = 0; c < 3; c++) {
    const ca = (c / 3) * Math.PI * 2 + variant;
    const nutPos = crownPos.clone().add(new THREE.Vector3(Math.cos(ca) * 0.24, -0.18, Math.sin(ca) * 0.24));
    appendFoliageCluster(wood, nutPos, 0.19, 0.21, 0.19, ca, 0x78350f, 0x451a03, rand);
  }

  // Two tiers of arching pinnate palm fronds (lower mature drooping + upper ascending)
  const frondCount = 9;
  for (let i = 0; i < frondCount; i++) {
    const isUpper = i % 2 === 0;
    const yaw = (i / frondCount) * Math.PI * 2 + (rand() - 0.5) * 0.22;
    const len = (isUpper ? 2.85 : 3.25) * (0.92 + rand() * 0.16);
    const width = isUpper ? 0.68 : 0.78;
    const rise = isUpper ? 1.15 : 0.68;
    const droop = isUpper ? 1.05 : 1.75;
    const origin = crownPos.clone().add(new THREE.Vector3(0, isUpper ? 0.12 : -0.05, 0));
    appendArchingFrond(
      foliage,
      origin,
      yaw,
      len,
      width,
      rise,
      droop,
      5,
      isUpper ? 0x4d9e3f : 0x2e7d32,
      isUpper ? 0x7cb342 : 0x43a047,
    );
  }

  return {
    species: 'cocotier',
    variantIndex: variant,
    woodGeo: toBufferGeometry(wood),
    foliageGeo: toBufferGeometry(foliage),
  };
}

/**
 * 3. FLAMBOYANT (Delonix regia)
 * Emblematic flowering tree of La Réunion: wide sympodial umbrella branching
 * with flat-topped scarlet-vermilion blossom canopy mixed with fern-green pads.
 */
function buildFlamboyantVariant(variant: number, rand: () => number): ProceduralTreeArchetype {
  const wood = createBuilder();
  const foliage = createBuilder();

  const attractors: Attractor[] = [];
  // Wide oblate umbrella dome of attractors (radius up to 4.2m, height 3.4..6.2m)
  for (let i = 0; i < 74; i++) {
    const a = rand() * Math.PI * 2;
    const r = (0.35 + Math.pow(rand(), 0.5) * 0.65) * 4.1;
    const domeY = 3.6 + Math.sqrt(Math.max(0, 1 - Math.pow(r / 4.4, 2))) * 2.3 + (rand() - 0.5) * 0.6;
    attractors.push({
      pos: new THREE.Vector3(Math.cos(a) * r, domeY, Math.sin(a) * r),
      active: true,
    });
  }

  const nodes = growSpaceColonizedSkeleton({
    trunkBase: new THREE.Vector3(0, 0, 0),
    trunkHeight: 2.35 + variant * 0.25,
    trunkSegments: 3,
    trunkLean: new THREE.Vector3((variant - 0.5) * 0.08, 0, 0),
    attractors,
    stepLength: 0.95,
    influenceRadius: 3.4,
    killDistance: 1.1,
    maxIterations: 10,
    maxChildrenPerNode: 3,
    tropism: new THREE.Vector3(0, 0.08, 0), // Strong horizontal spread
    jitter: 0.35,
    tipRadius: 0.065,
    maxBaseRadius: 0.44,
    pipeExponent: 2.3,
    rand,
  });

  appendSkeletonWood(wood, nodes, 5, 0x6b5b4f, 0x8c7a6b);

  // Broad flat foliage & vermilion-scarlet blossom cushions across the umbrella crown
  for (const node of nodes) {
    if (node.depth < 3) continue;
    const isTip = node.children.length === 0;
    if (!isTip && node.depth < 5) continue;

    const rHoriz = (isTip ? 1.28 : 1.05) * (0.9 + rand() * 0.22);
    const rVert = rHoriz * 0.46; // Flat umbrella pad

    // Upper/outer pads burst with flamboyant crimson/orange flowers; inner pads show lush bipinnate green
    const isBlossom = isTip || node.pos.y > 4.4 || (node.id + variant) % 3 !== 0;
    if (isBlossom) {
      const topHex = (node.id + variant) % 2 === 0 ? 0xf97316 : 0xef4444;
      const botHex = 0xb91c1c;
      appendFoliageCluster(foliage, node.pos, rHoriz, rVert, rHoriz, rand() * Math.PI * 2, topHex, botHex, rand);
    } else {
      appendFoliageCluster(foliage, node.pos, rHoriz * 0.95, rVert, rHoriz * 0.95, rand() * Math.PI * 2, 0x4ade80, 0x15803d, rand);
    }
  }

  return {
    species: 'flamboyant',
    variantIndex: variant,
    woodGeo: toBufferGeometry(wood),
    foliageGeo: toBufferGeometry(foliage),
  };
}

/**
 * 4. TAMARIN DES HAUTS (Acacia heterophylla)
 * Endemic high-altitude tree of Bélouve, Mafate & Cilaos: gnarled, wind-twisted dark trunk
 * and multi-lobed horizontal cloud pads of olive-emerald phyllodes.
 */
function buildTamarinVariant(variant: number, rand: () => number): ProceduralTreeArchetype {
  const wood = createBuilder();
  const foliage = createBuilder();

  const attractors: Attractor[] = [];
  // 3 distinct cloud lobes at different heights & offsets
  const lobes = [
    { x: -1.4, y: 4.5, z: 0.6, rx: 2.1, ry: 1.1 },
    { x: 1.5, y: 5.3, z: -0.7, rx: 2.3, ry: 1.2 },
    { x: 0.2, y: 6.4, z: 0.8, rx: 2.4, ry: 1.15 },
  ];
  for (const lobe of lobes) {
    for (let i = 0; i < 24; i++) {
      const a = rand() * Math.PI * 2;
      const r = Math.pow(rand(), 0.5) * lobe.rx;
      const y = lobe.y + (rand() - 0.5) * lobe.ry * 2;
      attractors.push({
        pos: new THREE.Vector3(lobe.x + Math.cos(a) * r, y, lobe.z + Math.sin(a) * r),
        active: true,
      });
    }
  }

  const nodes = growSpaceColonizedSkeleton({
    trunkBase: new THREE.Vector3(0, 0, 0),
    trunkHeight: 2.8 + variant * 0.3,
    trunkSegments: 4,
    trunkLean: new THREE.Vector3((variant - 1) * 0.16, 0, (variant % 2 === 0 ? 1 : -1) * 0.12),
    attractors,
    stepLength: 0.88,
    influenceRadius: 3.2,
    killDistance: 1.0,
    maxIterations: 11,
    maxChildrenPerNode: 3,
    tropism: new THREE.Vector3(0.08, 0.15, -0.05),
    jitter: 0.48, // Gnarled tortuous limbs
    tipRadius: 0.07,
    maxBaseRadius: 0.46,
    pipeExponent: 2.25,
    rand,
  });

  appendSkeletonWood(wood, nodes, 5, 0x3b281a, 0x5c422d);

  const tamarinColors = [
    [0x4d7c52, 0x26422b],
    [0x5c8a58, 0x2e4d30],
    [0x3f6b48, 0x1e3824],
  ];

  for (const node of nodes) {
    if (node.depth < 4) continue;
    if (node.children.length > 1 && node.depth < 6) continue;
    const [topHex, botHex] = tamarinColors[(node.id + variant) % tamarinColors.length];
    const rHoriz = (node.children.length === 0 ? 1.22 : 0.95) * (0.88 + rand() * 0.25);
    const rVert = rHoriz * 0.52;
    appendFoliageCluster(foliage, node.pos, rHoriz, rVert, rHoriz, rand() * Math.PI * 2, topHex, botHex, rand);
  }

  return {
    species: 'tamarin',
    variantIndex: variant,
    woodGeo: toBufferGeometry(wood),
    foliageGeo: toBufferGeometry(foliage),
  };
}

/**
 * 5. CRYPTOMÉRIA DU JAPON (Cryptomeria japonica)
 * Iconic conifer of Salazie, Cilaos & Plaine des Palmistes: straight monopodial
 * fibrous red-brown trunk with whorled plagiotropic branches and layered conical needle masses.
 */
function buildCryptomeriaVariant(variant: number, rand: () => number): ProceduralTreeArchetype {
  const wood = createBuilder();
  const foliage = createBuilder();

  const totalH = 8.4 + variant * 0.9;
  const trunkSegs = 6;
  const cBase = new THREE.Color(0x5c2e18);
  const cTop = new THREE.Color(0x7c4328);

  let prevPos = new THREE.Vector3(0, 0, 0);
  let prevR = 0.42;
  for (let s = 1; s <= trunkSegs; s++) {
    const t = s / trunkSegs;
    const curPos = new THREE.Vector3((rand() - 0.5) * 0.08, t * totalH, (rand() - 0.5) * 0.08);
    const curR = THREE.MathUtils.lerp(0.42, 0.07, t);
    appendTaperedBranch(
      wood,
      prevPos,
      curPos,
      prevR,
      curR,
      6,
      cBase.clone().lerp(cTop, (s - 1) / trunkSegs),
      cBase.clone().lerp(cTop, t),
    );
    prevPos = curPos;
    prevR = curR;
  }

  // Whorled lateral branches + dense conical needle tiers from 28% height up to apex
  const tiers = 5;
  for (let tier = 0; tier < tiers; tier++) {
    const u = tier / (tiers - 1);
    const y = totalH * (0.28 + u * 0.66);
    const tierRadius = THREE.MathUtils.lerp(2.35, 0.72, u);
    const branchCount = tier < 3 ? 4 : 3;

    for (let b = 0; b < branchCount; b++) {
      const ang = (b / branchCount) * Math.PI * 2 + tier * 0.85 + variant * 0.5;
      const bStart = new THREE.Vector3(0, y, 0);
      const bEnd = new THREE.Vector3(
        Math.cos(ang) * tierRadius * 0.78,
        y - 0.25 + u * 0.15,
        Math.sin(ang) * tierRadius * 0.78,
      );
      appendTaperedBranch(wood, bStart, bEnd, 0.11 * (1 - u * 0.5), 0.04, 4, cBase, cTop);

      const puffR = tierRadius * 0.62 * (0.9 + rand() * 0.2);
      appendFoliageCluster(
        foliage,
        bEnd.clone().add(new THREE.Vector3(0, 0.22, 0)),
        puffR,
        puffR * 0.85,
        puffR,
        ang,
        tier % 2 === 0 ? 0x1e6b3a : 0x257a44,
        0x0f3d20,
        rand,
      );
    }
  }

  // Conical spire crown at the very top
  appendFoliageCluster(
    foliage,
    new THREE.Vector3(0, totalH * 0.96, 0),
    0.85,
    1.45,
    0.85,
    0,
    0x2a854b,
    0x124726,
    rand,
  );

  return {
    species: 'cryptomeria',
    variantIndex: variant,
    woodGeo: toBufferGeometry(wood),
    foliageGeo: toBufferGeometry(foliage),
  };
}

/**
 * 6. FANJON — FOUGÈRE ARBORESCENTE (Cyathea borbonica)
 * Endemic tree fern of Salazie & Bélouve cloud forests: dark fibrous caudex
 * topped with a radiant rosette of lacy arching fern fronds.
 */
function buildFanjonVariant(variant: number, rand: () => number): ProceduralTreeArchetype {
  const wood = createBuilder();
  const foliage = createBuilder();

  const totalH = 4.2 + variant * 0.6;
  const segs = 5;
  const cBase = new THREE.Color(0x2e1d12);
  const cTop = new THREE.Color(0x4a3020);

  let prevPos = new THREE.Vector3(0, 0, 0);
  let prevR = 0.28;
  for (let s = 1; s <= segs; s++) {
    const t = s / segs;
    const curPos = new THREE.Vector3(
      Math.sin(t * Math.PI) * 0.22 * (variant === 0 ? 1 : -1),
      t * totalH,
      Math.cos(t * Math.PI) * 0.16,
    );
    const curR = THREE.MathUtils.lerp(0.28, 0.17, t);
    appendTaperedBranch(wood, prevPos, curPos, prevR, curR, 5, cBase, cTop);
    prevPos = curPos;
    prevR = curR;
  }

  const frondCount = 9;
  for (let i = 0; i < frondCount; i++) {
    const yaw = (i / frondCount) * Math.PI * 2 + variant * 0.4 + (rand() - 0.5) * 0.18;
    const isInner = i % 3 === 0;
    const len = (isInner ? 2.15 : 2.65) * (0.92 + rand() * 0.16);
    const width = isInner ? 0.62 : 0.76;
    const rise = isInner ? 0.95 : 0.62;
    const droop = isInner ? 0.65 : 1.15;
    appendArchingFrond(
      foliage,
      prevPos,
      yaw,
      len,
      width,
      rise,
      droop,
      5,
      0x228b22,
      0x65d654,
    );
  }

  return {
    species: 'fanjon',
    variantIndex: variant,
    woodGeo: toBufferGeometry(wood),
    foliageGeo: toBufferGeometry(foliage),
  };
}

/**
 * 7. VACOA (Pandanus utilis)
 * Emblematic screw-pine of Sud Sauvage (Saint-Philippe / Cap Méchant):
 * aerial stilt prop-roots at the base, dichotomous candelabra forks, and spiky leaf rosettes.
 */
function buildVacoaVariant(variant: number, rand: () => number): ProceduralTreeArchetype {
  const wood = createBuilder();
  const foliage = createBuilder();

  const cRoot = new THREE.Color(0x574838);
  const cBark = new THREE.Color(0x786552);
  const joinPos = new THREE.Vector3(0, 1.35, 0);

  // Aerial prop roots (racines échasses) anchoring the trunk to basalt rock
  const rootCount = 5;
  for (let r = 0; r < rootCount; r++) {
    const a = (r / rootCount) * Math.PI * 2 + variant * 0.5;
    const foot = new THREE.Vector3(Math.cos(a) * 0.82, 0, Math.sin(a) * 0.82);
    appendTaperedBranch(wood, foot, joinPos, 0.09, 0.13, 4, cRoot, cBark);
  }
  // Central stem core from ground through prop-root junction up to first dichotomy
  const fork1 = new THREE.Vector3((variant - 0.5) * 0.18, 2.95, 0);
  appendTaperedBranch(wood, new THREE.Vector3(0, 0, 0), joinPos, 0.18, 0.28, 5, cRoot, cBark);
  appendTaperedBranch(wood, joinPos, fork1, 0.28, 0.23, 5, cRoot, cBark);

  // Dichotomous candelabra Y-branching (2 levels -> 5–6 terminal heads)
  const branchCount = 3;
  for (let b = 0; b < branchCount; b++) {
    const a1 = (b / branchCount) * Math.PI * 2 + variant * 0.7;
    const mid = new THREE.Vector3(
      fork1.x + Math.cos(a1) * 1.15,
      fork1.y + 1.25 + (b % 2) * 0.25,
      fork1.z + Math.sin(a1) * 1.15,
    );
    appendTaperedBranch(wood, fork1, mid, 0.18, 0.13, 5, cBark, cBark);

    const subForks = b === 0 ? 1 : 2;
    for (let sf = 0; sf < subForks; sf++) {
      const a2 = a1 + (sf === 0 ? -0.48 : 0.48);
      const tip = new THREE.Vector3(
        mid.x + Math.cos(a2) * (subForks === 1 ? 0.55 : 0.95),
        mid.y + 0.95 + sf * 0.2,
        mid.z + Math.sin(a2) * (subForks === 1 ? 0.55 : 0.95),
      );
      appendTaperedBranch(wood, mid, tip, 0.12, 0.085, 4, cBark, cBark);

      // Spiky spiral rosette of sword leaves at each candelabra tip
      const rosetteLeaves = 7;
      for (let lf = 0; lf < rosetteLeaves; lf++) {
        const yaw = (lf / rosetteLeaves) * Math.PI * 2 + sf * 0.5 + rand() * 0.2;
        const isTop = lf % 2 === 0;
        appendArchingFrond(
          foliage,
          tip,
          yaw,
          isTop ? 1.35 : 1.55,
          0.26,
          isTop ? 0.65 : 0.35,
          isTop ? 0.35 : 0.85,
          3,
          0x2f6e3b,
          0x529e57,
        );
      }
    }
  }

  return {
    species: 'vacoa',
    variantIndex: variant,
    woodGeo: toBufferGeometry(wood),
    foliageGeo: toBufferGeometry(foliage),
  };
}
