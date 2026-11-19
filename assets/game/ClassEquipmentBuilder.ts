import * as THREE from 'three';
import type { ClassCode, EquipmentSlot, ItemRarity } from './types';

export interface EquippedSlotVisual {
  itemCode: string;
  rarity: ItemRarity;
}

export interface EquipmentVisualRig {
  classCode: ClassCode;
  weaponGlowMats: THREE.MeshStandardMaterial[];
  armorAccentMats: THREE.MeshStandardMaterial[];
  helmGlowMats: THREE.MeshStandardMaterial[];
  floatingHalo: THREE.Object3D | null;
  accessoryOrb: THREE.Object3D | null;
  applyEquippedVisuals: (equipped: Partial<Record<EquipmentSlot, EquippedSlotVisual | null>>) => void;
  update: (dt: number, time: number) => void;
}

const RARITY_GLOW: Record<ItemRarity, { color: number; intensity: number }> = {
  COMMON: { color: 0xe2e8f0, intensity: 0.45 },
  UNCOMMON: { color: 0x22c55e, intensity: 0.65 },
  RARE: { color: 0x38bdf8, intensity: 0.85 },
  EPIC: { color: 0xc084fc, intensity: 1.05 },
  LEGENDARY: { color: 0xf97316, intensity: 1.35 },
};

const CLASS_BASE_GLOW: Record<ClassCode, number> = {
  TANK: 0x38bdf8,
  DPS: 0xf97316,
  HEAL: 0x34d399,
};

function makeMat(
  color: number,
  roughness = 0.58,
  metalness = 0.18,
  emissiveColor?: number,
  emissiveIntensity = 0.25,
  doubleSide = false,
): THREE.MeshStandardMaterial {
  return new THREE.MeshStandardMaterial({
    color,
    roughness,
    metalness,
    flatShading: true,
    side: doubleSide ? THREE.DoubleSide : THREE.FrontSide,
    emissive: emissiveColor !== undefined ? new THREE.Color(emissiveColor) : new THREE.Color(0x000000),
    emissiveIntensity: emissiveColor !== undefined ? emissiveIntensity : 0,
  });
}

function indexBones(root: THREE.Object3D): Record<string, THREE.Bone> {
  const byName: Record<string, THREE.Bone> = {};
  root.traverse((o) => {
    if ((o as THREE.Bone).isBone) {
      byName[o.name] = o as THREE.Bone;
    }
  });
  return byName;
}

function pickBone(bones: Record<string, THREE.Bone>, ...names: string[]): THREE.Bone | undefined {
  for (const n of names) {
    if (bones[n]) return bones[n];
  }
  return undefined;
}

function addShadows(obj: THREE.Object3D): void {
  obj.traverse((child) => {
    const mesh = child as THREE.Mesh;
    if (mesh.isMesh) {
      mesh.castShadow = true;
      mesh.receiveShadow = true;
    }
  });
}

/**
 * Attaches class-defining 3D equipment directly onto the shared Tikaf biped skeleton bones.
 * Because every piece is parented to its corresponding bone (Spine01/02, Pelvis, Head,
 * Upperarm/Forearm/Hand, Thigh/Calf), all armor plates, flowing robe panels, shields,
 * hammers, staves and blades automatically follow both walk.001 and all 12 procedural
 * skill bone animations!
 *
 * Bone-local coordinate conventions measured directly on Tikaf.fbx (raw height ~99.3u):
 *   Torso/Head/Pelvis : +X = Right, +Y = Up, -Z = Forward, +Z = Back (backpack reaches +Z≈19.5)
 *   Arms & Legs       : +Y = Down along limb, +Z = Forward, +X = Right (L outward = -X, R outward = +X)
 */
export function attachClassEquipment(rigRoot: THREE.Object3D, classCode: ClassCode): EquipmentVisualRig {
  const bones = indexBones(rigRoot);
  const bPelvis = pickBone(bones, 'Pelvis', 'Hip', 'mixamorigHips');
  const bWaist = pickBone(bones, 'Waist', 'Pelvis');
  const bSpine01 = pickBone(bones, 'Spine01', 'Spine', 'mixamorigSpine');
  const bSpine02 = pickBone(bones, 'Spine02', 'Spine1', 'Spine2', 'mixamorigSpine2');
  const bHead = pickBone(bones, 'Head', 'mixamorigHead');
  const bLUpper = pickBone(bones, 'L_Upperarm', 'LeftArm', 'mixamorigLeftArm');
  const bRUpper = pickBone(bones, 'R_Upperarm', 'RightArm', 'mixamorigRightArm');
  const bLFore = pickBone(bones, 'L_Forearm', 'LeftForeArm', 'mixamorigLeftForeArm');
  const bRFore = pickBone(bones, 'R_Forearm', 'RightForeArm', 'mixamorigRightForeArm');
  const bLHand = pickBone(bones, 'L_Hand', 'LeftHand', 'mixamorigLeftHand');
  const bRHand = pickBone(bones, 'R_Hand', 'RightHand', 'mixamorigRightHand');
  const bLThigh = pickBone(bones, 'L_Thigh', 'LeftUpLeg', 'mixamorigLeftUpLeg');
  const bRThigh = pickBone(bones, 'R_Thigh', 'RightUpLeg', 'mixamorigRightUpLeg');
  const bLCalf = pickBone(bones, 'L_Calf', 'LeftLeg', 'mixamorigLeftLeg');
  const bRCalf = pickBone(bones, 'R_Calf', 'RightLeg', 'mixamorigRightLeg');

  const weaponGlowMats: THREE.MeshStandardMaterial[] = [];
  const armorAccentMats: THREE.MeshStandardMaterial[] = [];
  const helmGlowMats: THREE.MeshStandardMaterial[] = [];
  let floatingHalo: THREE.Object3D | null = null;

  // Optional orbiting talisman orb when an ACCESSORY item is equipped
  let accessoryOrb: THREE.Object3D | null = null;
  let accessoryOrbMat: THREE.MeshStandardMaterial | null = null;
  if (bSpine02) {
    const orbPivot = new THREE.Group();
    orbPivot.position.set(0, 12, 0);
    accessoryOrbMat = makeMat(CLASS_BASE_GLOW[classCode], 0.15, 0.25, CLASS_BASE_GLOW[classCode], 0.95);
    const orbMesh = new THREE.Mesh(new THREE.OctahedronGeometry(2.6, 0), accessoryOrbMat);
    orbMesh.position.set(20, 4, -8);
    const orbRing = new THREE.Mesh(
      new THREE.TorusGeometry(3.8, 0.45, 6, 14),
      makeMat(0xfde047, 0.3, 0.35, 0xfacc15, 0.55),
    );
    orbRing.position.copy(orbMesh.position);
    orbRing.rotation.x = Math.PI / 3;
    orbPivot.add(orbMesh, orbRing);
    orbPivot.visible = false;
    bSpine02.add(orbPivot);
    accessoryOrb = orbPivot;
  }

  if (classCode === 'TANK') {
    buildTankHeavyArmor({
      bPelvis,
      bWaist,
      bSpine01,
      bSpine02,
      bHead,
      bLUpper,
      bRUpper,
      bLFore,
      bRFore,
      bRHand,
      bLThigh,
      bRThigh,
      bLCalf,
      bRCalf,
      weaponGlowMats,
      armorAccentMats,
      helmGlowMats,
    });
  } else if (classCode === 'HEAL') {
    floatingHalo = buildHealerRobe({
      bPelvis,
      bWaist,
      bSpine01,
      bSpine02,
      bHead,
      bLUpper,
      bRUpper,
      bLFore,
      bRFore,
      bRHand,
      bLThigh,
      bRThigh,
      weaponGlowMats,
      armorAccentMats,
      helmGlowMats,
    });
  } else {
    buildDpsVolcanicGear({
      bWaist,
      bSpine02,
      bHead,
      bLUpper,
      bRUpper,
      bLFore,
      bRFore,
      bLHand,
      bRHand,
      weaponGlowMats,
      armorAccentMats,
      helmGlowMats,
    });
  }

  const baseColor = CLASS_BASE_GLOW[classCode];

  const setMatsGlow = (mats: THREE.MeshStandardMaterial[], slotData: EquippedSlotVisual | null | undefined, baseIntensity: number) => {
    const preset = slotData ? RARITY_GLOW[slotData.rarity] : { color: baseColor, intensity: baseIntensity };
    for (const m of mats) {
      m.color.setHex(preset.color);
      m.emissive.setHex(preset.color);
      m.emissiveIntensity = preset.intensity;
    }
  };

  const applyEquippedVisuals = (equipped: Partial<Record<EquipmentSlot, EquippedSlotVisual | null>>): void => {
    setMatsGlow(weaponGlowMats, equipped.WEAPON, 0.8);
    setMatsGlow(armorAccentMats, equipped.ARMOR, 0.65);
    setMatsGlow(helmGlowMats, equipped.HELM, 0.75);

    if (accessoryOrb && accessoryOrbMat) {
      const acc = equipped.ACCESSORY ?? null;
      accessoryOrb.visible = acc !== null;
      if (acc) {
        const preset = RARITY_GLOW[acc.rarity];
        accessoryOrbMat.color.setHex(preset.color);
        accessoryOrbMat.emissive.setHex(preset.color);
        accessoryOrbMat.emissiveIntensity = preset.intensity;
      }
    }
  };

  const update = (dt: number, time: number): void => {
    if (floatingHalo) {
      floatingHalo.rotation.y += dt * 1.15;
      floatingHalo.position.y = 37.5 + Math.sin(time * 2.8) * 1.1;
    }
    if (accessoryOrb && accessoryOrb.visible) {
      accessoryOrb.rotation.y += dt * 1.6;
      accessoryOrb.position.y = 12 + Math.sin(time * 3.4) * 1.6;
    }
  };

  return {
    classCode,
    weaponGlowMats,
    armorAccentMats,
    helmGlowMats,
    floatingHalo,
    accessoryOrb,
    applyEquippedVisuals,
    update,
  };
}

interface RigSlotBag {
  bPelvis?: THREE.Bone;
  bWaist?: THREE.Bone;
  bSpine01?: THREE.Bone;
  bSpine02?: THREE.Bone;
  bHead?: THREE.Bone;
  bLUpper?: THREE.Bone;
  bRUpper?: THREE.Bone;
  bLFore?: THREE.Bone;
  bRFore?: THREE.Bone;
  bLHand?: THREE.Bone;
  bRHand?: THREE.Bone;
  bLThigh?: THREE.Bone;
  bRThigh?: THREE.Bone;
  bLCalf?: THREE.Bone;
  bRCalf?: THREE.Bone;
  weaponGlowMats: THREE.MeshStandardMaterial[];
  armorAccentMats: THREE.MeshStandardMaterial[];
  helmGlowMats: THREE.MeshStandardMaterial[];
}

// ============================================================================
// 1. TANK — GARDIEN DU BASALTE (Grosse Armure Lourde + Bouclier + Marteau)
// ============================================================================
function buildTankHeavyArmor(r: RigSlotBag): void {
  // Bright-enough slate steel + royal blue tabard so armor pops clearly without envMap darkening
  const plateMat = makeMat(0x475569, 0.44, 0.28, 0x1e293b, 0.22);
  const darkPlateMat = makeMat(0x334155, 0.52, 0.24, 0x0f172a, 0.18);
  const trimMat = makeMat(0xcbd5e1, 0.35, 0.35, 0x475569, 0.22);
  const tabardMat = makeMat(0x1d4ed8, 0.65, 0.12, 0x1e3a8a, 0.2, true);
  const armorGlow = makeMat(0x38bdf8, 0.2, 0.3, 0x38bdf8, 0.72);
  const weaponGlow = makeMat(0x38bdf8, 0.2, 0.3, 0x38bdf8, 0.85);
  const helmGlow = makeMat(0x38bdf8, 0.18, 0.3, 0x38bdf8, 0.8);
  r.armorAccentMats.push(armorGlow);
  r.weaponGlowMats.push(weaponGlow);
  r.helmGlowMats.push(helmGlow);

  // --- HEAVY LAYERED CHEST CUIRASS & ARMORED CARAPACE BACKPLATE (Spine02) ---
  if (r.bSpine02) {
    const chest = new THREE.Group();

    // Sculpted thick front & side breastplate wrapping torso cleanly (torso X:±12, front -Z≈-10)
    const breast = new THREE.Mesh(new THREE.CylinderGeometry(14.6, 13.6, 16.5, 8), plateMat);
    breast.scale.set(1.06, 1.0, 0.88);
    breast.position.set(0, 1.5, -1.5);
    chest.add(breast);

    // Bold angled front sternum plate
    const sternum = new THREE.Mesh(new THREE.BoxGeometry(18, 12.5, 5.2), trimMat);
    sternum.position.set(0, 2.8, -11.8);
    sternum.rotation.x = 0.08;
    chest.add(sternum);

    // Glowing basalt core diamond emblem on chest
    const core = new THREE.Mesh(new THREE.OctahedronGeometry(3.4, 0), armorGlow);
    core.scale.set(1.0, 1.35, 0.55);
    core.position.set(0, 3.2, -14.6);
    chest.add(core);

    // High armored gorget / fortress collar framing the neck
    const gorget = new THREE.Mesh(new THREE.CylinderGeometry(13.5, 15.2, 5.2, 8, 1, false), darkPlateMat);
    gorget.position.set(0, 10.2, -0.5);
    chest.add(gorget);
    const gorgetRim = new THREE.Mesh(new THREE.TorusGeometry(13.6, 1.15, 6, 12), trimMat);
    gorgetRim.rotation.x = Math.PI / 2;
    gorgetRim.position.set(0, 12.4, -0.5);
    chest.add(gorgetRim);

    // Massive fortress backplate wrapping cleanly over Tikaf's backpack (+Z up to 21.5)
    const backPackPlate = new THREE.Mesh(new THREE.BoxGeometry(22.5, 19, 14.5), darkPlateMat);
    backPackPlate.position.set(0, 0.5, 14.2);
    chest.add(backPackPlate);

    const backCrestL = new THREE.Mesh(new THREE.BoxGeometry(4.5, 16, 4.0), trimMat);
    backCrestL.position.set(-7.5, 2.0, 21.8);
    const backCrestR = backCrestL.clone();
    backCrestR.position.x = 7.5;
    const backRune = new THREE.Mesh(new THREE.BoxGeometry(4.2, 11.5, 2.2), armorGlow);
    backRune.position.set(0, 2.0, 21.4);
    chest.add(backCrestL, backCrestR, backRune);

    addShadows(chest);
    r.bSpine02.add(chest);
  }

  // --- LOWER ABDOMINAL ARMOR (Spine01) ---
  if (r.bSpine01) {
    const ab = new THREE.Group();
    const abPlate = new THREE.Mesh(new THREE.CylinderGeometry(13.8, 13.4, 10.5, 8), darkPlateMat);
    abPlate.scale.set(1.04, 1.0, 0.88);
    abPlate.position.set(0, 3.5, -1.2);
    ab.add(abPlate);
    addShadows(ab);
    r.bSpine01.add(ab);
  }

  // --- HEAVY WAR BELT & ROYAL TABARD (Waist / Pelvis) ---
  const hipBone = r.bPelvis ?? r.bWaist;
  if (hipBone) {
    const beltGroup = new THREE.Group();
    const belt = new THREE.Mesh(new THREE.CylinderGeometry(14.5, 14.8, 5.4, 8), trimMat);
    belt.scale.set(1.05, 1.0, 0.92);
    belt.position.set(0, 1.2, -0.8);
    beltGroup.add(belt);

    const buckle = new THREE.Mesh(new THREE.BoxGeometry(7.2, 6.2, 3.2), armorGlow);
    buckle.position.set(0, 1.2, -13.4);
    beltGroup.add(buckle);

    // Front & back armored royal blue tabard flaps
    const frontFlap = new THREE.Mesh(new THREE.BoxGeometry(10.2, 16.5, 1.6), tabardMat);
    frontFlap.position.set(0, -8.5, -11.5);
    frontFlap.rotation.x = -0.1;
    const frontTrim = new THREE.Mesh(new THREE.BoxGeometry(11.0, 2.0, 2.0), trimMat);
    frontTrim.position.set(0, -16.2, -12.3);

    const backFlap = new THREE.Mesh(new THREE.BoxGeometry(13.0, 17.5, 1.8), tabardMat);
    backFlap.position.set(0, -8.8, 11.2);
    backFlap.rotation.x = 0.14;

    // Armored hip side guards (faulds)
    const fauldL = new THREE.Mesh(new THREE.BoxGeometry(4.2, 11.5, 14.5), plateMat);
    fauldL.position.set(-14.2, -5.5, -0.5);
    fauldL.rotation.z = -0.18;
    const fauldR = fauldL.clone();
    fauldR.position.x = 14.2;
    fauldR.rotation.z = 0.18;

    beltGroup.add(frontFlap, frontTrim, backFlap, fauldL, fauldR);
    addShadows(beltGroup);
    hipBone.add(beltGroup);
  }

  // --- MASSIVE SHOULDER PAULDRONS (L_Upperarm & R_Upperarm) ---
  const addPauldron = (bone: THREE.Bone | undefined, sideSign: number) => {
    if (!bone) return;
    // sideSign: -1 for L_Upperarm (outward is -X), +1 for R_Upperarm (outward is +X)
    const grp = new THREE.Group();
    // Main oversized curved basalt dome
    const dome = new THREE.Mesh(new THREE.DodecahedronGeometry(9.2, 1), plateMat);
    dome.scale.set(1.15, 0.85, 1.12);
    dome.position.set(sideSign * 3.2, -0.8, 0.5);
    grp.add(dome);

    // Upper layered crest flange
    const crest = new THREE.Mesh(new THREE.BoxGeometry(11.5, 3.2, 13.5), trimMat);
    crest.position.set(sideSign * 4.0, -6.2, 0.5);
    crest.rotation.z = sideSign * -0.22;
    grp.add(crest);

    // Lower overlapping shoulder plate + glowing cyan ridge
    const lower = new THREE.Mesh(new THREE.CylinderGeometry(8.2, 9.6, 7.2, 7), darkPlateMat);
    lower.position.set(sideSign * 2.2, 5.2, 0.4);
    const rune = new THREE.Mesh(new THREE.BoxGeometry(2.4, 8.2, 3.2), armorGlow);
    rune.position.set(sideSign * 11.6, 0.5, 0.8);
    grp.add(lower, rune);

    addShadows(grp);
    bone.add(grp);
  };
  addPauldron(r.bLUpper, -1);
  addPauldron(r.bRUpper, 1);

  // --- HEAVY GAUNTLETS / VAMBRACES (L_Forearm & R_Forearm) ---
  const addGauntlet = (bone: THREE.Bone | undefined, sideSign: number) => {
    if (!bone) return;
    const g = new THREE.Group();
    const sleeve = new THREE.Mesh(new THREE.CylinderGeometry(5.6, 6.8, 11.5, 7), plateMat);
    sleeve.position.set(0, 5.5, 0);
    const elbowCop = new THREE.Mesh(new THREE.DodecahedronGeometry(4.6, 0), trimMat);
    elbowCop.position.set(sideSign * 2.5, 0.2, -2.5);
    const cuff = new THREE.Mesh(new THREE.TorusGeometry(6.4, 1.0, 6, 10), trimMat);
    cuff.rotation.x = Math.PI / 2;
    cuff.position.set(0, 10.6, 0);
    g.add(sleeve, elbowCop, cuff);
    addShadows(g);
    bone.add(g);
  };
  addGauntlet(r.bLFore, -1);
  addGauntlet(r.bRFore, 1);

  // --- THIGH TASSETS & KNEE COPS (L_Thigh & R_Thigh) ---
  const addThighArmor = (bone: THREE.Bone | undefined, sideSign: number) => {
    if (!bone) return;
    const grp = new THREE.Group();
    const cuisse = new THREE.Mesh(new THREE.CylinderGeometry(7.6, 6.8, 14.5, 7), darkPlateMat);
    cuisse.position.set(sideSign * 0.6, 8.2, 0.4);
    const knee = new THREE.Mesh(new THREE.DodecahedronGeometry(5.2, 0), trimMat);
    knee.scale.set(1.05, 1.0, 0.85);
    knee.position.set(sideSign * 0.4, 16.5, 5.2);
    grp.add(cuisse, knee);
    addShadows(grp);
    bone.add(grp);
  };
  addThighArmor(r.bLThigh, -1);
  addThighArmor(r.bRThigh, 1);

  // --- HEAVY BASALT GREAVES (L_Calf & R_Calf) ---
  const addGreave = (bone: THREE.Bone | undefined) => {
    if (!bone) return;
    const grp = new THREE.Group();
    const shin = new THREE.Mesh(new THREE.CylinderGeometry(6.5, 5.8, 15.5, 7), plateMat);
    shin.position.set(0, 8.8, 0.6);
    const shinRidge = new THREE.Mesh(new THREE.BoxGeometry(3.2, 13.5, 2.4), trimMat);
    shinRidge.position.set(0, 8.8, 6.2);
    grp.add(shin, shinRidge);
    addShadows(grp);
    bone.add(grp);
  };
  addGreave(r.bLCalf);
  addGreave(r.bRCalf);

  // --- KNIGHT GREAT-HELM & BASALT CREST (Head) ---
  // Fully encases Tikaf's hat brim (Y=16..24, R=21) & crown (Y=24..34) while keeping eyes expressive!
  if (r.bHead) {
    const helm = new THREE.Group();

    // Heavy domed helm crown covering hat top cleanly
    const dome = new THREE.Mesh(new THREE.CylinderGeometry(12.2, 18.2, 16.0, 8), plateMat);
    dome.position.set(0, 27.6, -0.6);
    helm.add(dome);

    // Thick armored brim guard fully wrapping the straw hat brim (zero straw peeking out)
    const brimGuard = new THREE.Mesh(new THREE.CylinderGeometry(22.4, 23.6, 6.4, 12), darkPlateMat);
    brimGuard.position.set(0, 18.8, 0.4);
    brimGuard.rotation.x = -0.14;
    helm.add(brimGuard);

    const brimTrim = new THREE.Mesh(new THREE.TorusGeometry(22.9, 1.3, 6, 16), trimMat);
    brimTrim.rotation.x = Math.PI / 2 - 0.14;
    brimTrim.position.set(0, 18.0, 0.4);
    helm.add(brimTrim);

    // Cheek & jaw armor plates framing Tikaf's face
    const cheekL = new THREE.Mesh(new THREE.BoxGeometry(4.4, 12.5, 11.5), plateMat);
    cheekL.position.set(-13.2, 10.5, -3.5);
    const cheekR = cheekL.clone();
    cheekR.position.x = 13.2;
    helm.add(cheekL, cheekR);

    // Glowing cyan visor brow bar across the forehead
    const visor = new THREE.Mesh(new THREE.BoxGeometry(19.5, 2.8, 3.6), helmGlow);
    visor.position.set(0, 15.8, -14.2);
    helm.add(visor);

    // Iconic tall metallic Knight Crest plume on top of helm
    const crest = new THREE.Mesh(new THREE.BoxGeometry(3.4, 10.5, 22.0), trimMat);
    crest.position.set(0, 36.8, 0.5);
    const crestGlow = new THREE.Mesh(new THREE.BoxGeometry(1.8, 6.2, 18.0), helmGlow);
    crestGlow.position.set(0, 41.2, 1.0);
    helm.add(crest, crestGlow);

    addShadows(helm);
    r.bHead.add(helm);
  }

  // --- MASSIVE BASALT TOWER SHIELD (Parented to L_Forearm -> moves directly with shield_bash!) ---
  if (r.bLFore) {
    const shield = new THREE.Group();
    // Mounted cleanly outside left gauntlet (L_Forearm outward = -X, forward = +Z)
    shield.position.set(-11.2, 6.2, 4.4);
    shield.rotation.set(0.14, -Math.PI / 2 + 0.34, 0.08);

    const body = new THREE.Mesh(new THREE.CylinderGeometry(16.5, 14.2, 3.2, 6), darkPlateMat);
    body.rotation.x = Math.PI / 2;
    const rim = new THREE.Mesh(new THREE.TorusGeometry(15.6, 1.7, 6, 6), trimMat);
    const boss = new THREE.Mesh(new THREE.OctahedronGeometry(5.2, 0), weaponGlow);
    boss.scale.set(1.0, 1.35, 0.65);
    boss.position.set(0, 0, 2.2);
    const crossV = new THREE.Mesh(new THREE.BoxGeometry(2.6, 26.5, 1.6), trimMat);
    crossV.position.z = 1.6;
    const crossH = new THREE.Mesh(new THREE.BoxGeometry(24.0, 2.6, 1.6), trimMat);
    crossH.position.z = 1.6;

    shield.add(body, rim, boss, crossV, crossH);
    addShadows(shield);
    r.bLFore.add(shield);
  }

  // --- HEAVY BASALT WAR HAMMER (Parented to R_Hand -> swings with seismic_slam & attacks!) ---
  if (r.bRHand) {
    const hammer = new THREE.Group();
    // Held in right fist pointing forward & slightly outward right (calibrated to R_Hand world axes!)
    hammer.position.set(3.2, 5.2, 3.0);
    hammer.rotation.set(-0.14, 0.63, 0.05);

    const shaft = new THREE.Mesh(new THREE.CylinderGeometry(1.6, 1.9, 44, 7), makeMat(0x52525b, 0.6, 0.25));
    shaft.rotation.x = Math.PI / 2;
    shaft.position.set(0, 0, 14);

    const pommel = new THREE.Mesh(new THREE.DodecahedronGeometry(3.0, 0), trimMat);
    pommel.position.set(0, 0, -8.5);

    const headBlock = new THREE.Mesh(new THREE.BoxGeometry(11.5, 11.5, 16.5), darkPlateMat);
    headBlock.position.set(0, 0, 34);

    const headCapF = new THREE.Mesh(new THREE.BoxGeometry(13.2, 13.2, 3.0), trimMat);
    headCapF.position.set(0, 0, 42.5);
    const headCapB = headCapF.clone();
    headCapB.position.z = 25.5;

    const headRune = new THREE.Mesh(new THREE.BoxGeometry(12.4, 4.2, 10.5), weaponGlow);
    headRune.position.set(0, 0, 34);

    hammer.add(shaft, pommel, headBlock, headCapF, headCapB, headRune);
    addShadows(hammer);
    r.bRHand.add(hammer);
  }
}

// ============================================================================
// 2. HEAL — TISANEUR DES CIRQUES (Robe Fluide Articulée + Halo + Bâton Faham)
// ============================================================================
function buildHealerRobe(r: RigSlotBag): THREE.Object3D | null {
  const robeMat = makeMat(0x047857, 0.72, 0.08, 0x064e3b, 0.24, true);
  const innerRobeMat = makeMat(0xecfdf5, 0.78, 0.04, 0xd1fae5, 0.14, true);
  const goldMat = makeMat(0xf59e0b, 0.36, 0.32, 0xb45309, 0.25);
  const armorGlow = makeMat(0x34d399, 0.2, 0.22, 0x34d399, 0.72);
  const weaponGlow = makeMat(0x34d399, 0.15, 0.25, 0x34d399, 0.9);
  const helmGlow = makeMat(0x5eead4, 0.15, 0.25, 0x34d399, 0.85);
  r.armorAccentMats.push(armorGlow);
  r.weaponGlowMats.push(weaponGlow);
  r.helmGlowMats.push(helmGlow);

  // --- UPPER ROBE VESTMENTS & DRAPED CAPELET MANTLE (Spine02) ---
  if (r.bSpine02) {
    const upper = new THREE.Group();

    // Tailored emerald surcoat covering chest
    const tunic = new THREE.Mesh(new THREE.CylinderGeometry(13.4, 13.8, 17.5, 10), robeMat);
    tunic.scale.set(1.04, 1.0, 0.88);
    tunic.position.set(0, 1.0, -1.2);
    upper.add(tunic);

    // Cream inner collar & golden embroidered front stole
    const frontStole = new THREE.Mesh(new THREE.BoxGeometry(6.8, 17.0, 2.2), innerRobeMat);
    frontStole.position.set(0, 1.2, -11.4);
    const stoleGoldL = new THREE.Mesh(new THREE.BoxGeometry(1.4, 17.0, 2.5), goldMat);
    stoleGoldL.position.set(-3.6, 1.2, -11.5);
    const stoleGoldR = stoleGoldL.clone();
    stoleGoldR.position.x = 3.6;
    upper.add(frontStole, stoleGoldL, stoleGoldR);

    // High cream-and-gold ceremonial healer collar covering red shirt neckline cleanly
    const collar = new THREE.Mesh(new THREE.CylinderGeometry(9.6, 12.2, 5.2, 10), innerRobeMat);
    collar.position.set(0, 10.8, -0.4);
    const collarRim = new THREE.Mesh(new THREE.TorusGeometry(9.8, 0.9, 6, 14), goldMat);
    collarRim.rotation.x = Math.PI / 2;
    collarRim.position.set(0, 13.0, -0.4);
    upper.add(collar, collarRim);

    // Draped flared emerald shoulder mantle / capelet + glowing Faham chest clasp
    const mantle = new THREE.Mesh(new THREE.CylinderGeometry(11.5, 20.5, 8.5, 10, 1, true), robeMat);
    mantle.position.set(0, 6.8, 0.2);
    const mantleRim = new THREE.Mesh(new THREE.TorusGeometry(20.2, 0.95, 6, 16), goldMat);
    mantleRim.rotation.x = Math.PI / 2;
    mantleRim.position.set(0, 2.7, 0.2);
    const clasp = new THREE.Mesh(new THREE.OctahedronGeometry(3.0, 0), armorGlow);
    clasp.scale.set(1.0, 1.25, 0.55);
    clasp.position.set(0, 7.6, -12.6);
    upper.add(mantle, mantleRim, clasp);

    // Flowing mystical healer back-cloak gracefully draping over Tikaf's backpack (+Z≈20)
    const cloak = new THREE.Mesh(new THREE.CylinderGeometry(13.5, 18.5, 26.0, 8, 1, true, -Math.PI * 0.45, Math.PI * 0.9), robeMat);
    cloak.position.set(0, -3.5, 8.8);
    cloak.rotation.x = 0.16;
    const cloakGold = new THREE.Mesh(new THREE.BoxGeometry(4.2, 23.0, 1.4), goldMat);
    cloakGold.position.set(0, -3.5, 21.6);
    cloakGold.rotation.x = 0.16;
    upper.add(cloak, cloakGold);

    addShadows(upper);
    r.bSpine02.add(upper);
  }

  // --- MID ROBE TORSO (Spine01) ---
  if (r.bSpine01) {
    const mid = new THREE.Group();
    const midTunic = new THREE.Mesh(new THREE.CylinderGeometry(13.6, 14.4, 11.5, 10), robeMat);
    midTunic.scale.set(1.03, 1.0, 0.88);
    midTunic.position.set(0, 3.2, -1.0);
    const midStole = new THREE.Mesh(new THREE.BoxGeometry(6.6, 11.2, 2.0), innerRobeMat);
    midStole.position.set(0, 3.2, -11.6);
    mid.add(midTunic, midStole);
    addShadows(mid);
    r.bSpine01.add(mid);
  }

  // --- GOLDEN WAIST SASH, HERBAL POUCH & UPPER FLOWING SKIRT (Pelvis / Waist) ---
  const hipBone = r.bPelvis ?? r.bWaist;
  if (hipBone) {
    const skirtGrp = new THREE.Group();

    // Braided golden waist sash
    const sash = new THREE.Mesh(new THREE.CylinderGeometry(14.6, 15.2, 4.6, 12), goldMat);
    sash.scale.set(1.04, 1.0, 0.92);
    sash.position.set(0, 1.4, -0.6);
    const sashGem = new THREE.Mesh(new THREE.OctahedronGeometry(2.6, 0), armorGlow);
    sashGem.position.set(0, 1.4, -13.5);
    skirtGrp.add(sash, sashGem);

    // Upper flowing bell skirt around hips
    const upperSkirt = new THREE.Mesh(new THREE.CylinderGeometry(14.4, 18.8, 17.5, 12, 1, true), robeMat);
    upperSkirt.scale.set(1.04, 1.0, 0.94);
    upperSkirt.position.set(0, -8.5, -0.4);
    skirtGrp.add(upperSkirt);

    // Long embroidered front & back robe stole panels reaching toward ankles
    const frontPanel = new THREE.Mesh(new THREE.BoxGeometry(9.2, 29.5, 1.5), innerRobeMat);
    frontPanel.position.set(0, -15.2, -13.2);
    frontPanel.rotation.x = -0.14;
    const frontHem = new THREE.Mesh(new THREE.BoxGeometry(10.0, 2.4, 1.9), goldMat);
    frontHem.position.set(0, -29.2, -15.2);
    frontHem.rotation.x = -0.14;

    const backPanel = new THREE.Mesh(new THREE.BoxGeometry(14.5, 31.0, 1.8), robeMat);
    backPanel.position.set(0, -16.0, 13.8);
    backPanel.rotation.x = 0.16;
    const backHem = new THREE.Mesh(new THREE.BoxGeometry(15.2, 2.4, 2.0), goldMat);
    backHem.position.set(0, -30.5, 16.2);
    backHem.rotation.x = 0.16;

    // Mystical glowing tisane potion vial hanging at left hip (-X)
    const vial = new THREE.Mesh(new THREE.CylinderGeometry(2.0, 2.4, 5.2, 7), armorGlow);
    vial.position.set(-15.5, -3.5, -3.5);
    vial.rotation.z = 0.25;
    const cork = new THREE.Mesh(new THREE.CylinderGeometry(1.3, 1.4, 1.8, 6), goldMat);
    cork.position.set(-16.1, -0.5, -3.5);
    cork.rotation.z = 0.25;

    skirtGrp.add(frontPanel, frontHem, backPanel, backHem, vial, cork);
    addShadows(skirtGrp);
    hipBone.add(skirtGrp);
  }

  // --- ARTICULATED LOWER ROBE SKIRT DRAPES (Parented to L_Thigh & R_Thigh -> walks without leg clipping!) ---
  const addSkirtLegDrape = (bone: THREE.Bone | undefined, sideSign: number) => {
    if (!bone) return;
    const drape = new THREE.Group();
    // Generous flared robe leg skirt following thigh motion down to ankles (+Y = down along leg)
    const panel = new THREE.Mesh(new THREE.CylinderGeometry(8.8, 12.6, 28.5, 9, 1, true), robeMat);
    panel.position.set(sideSign * 1.0, 14.5, 0.2);
    const goldTrim = new THREE.Mesh(new THREE.TorusGeometry(12.4, 0.85, 6, 12), goldMat);
    goldTrim.rotation.x = Math.PI / 2;
    goldTrim.position.set(sideSign * 1.0, 28.4, 0.2);
    drape.add(panel, goldTrim);
    addShadows(drape);
    bone.add(drape);
  };
  addSkirtLegDrape(r.bLThigh, -1);
  addSkirtLegDrape(r.bRThigh, 1);

  // --- FLOWING BELL SLEEVES & GOLD CUFFS (L_Upperarm/Forearm & R_Upperarm/Forearm) ---
  const addRobeUpperSleeve = (bone: THREE.Bone | undefined) => {
    if (!bone) return;
    const sl = new THREE.Mesh(new THREE.CylinderGeometry(6.4, 7.2, 13.5, 8), robeMat);
    sl.position.set(0, 6.2, 0);
    addShadows(sl);
    bone.add(sl);
  };
  addRobeUpperSleeve(r.bLUpper);
  addRobeUpperSleeve(r.bRUpper);

  const addBellCuff = (bone: THREE.Bone | undefined) => {
    if (!bone) return;
    const grp = new THREE.Group();
    const bell = new THREE.Mesh(new THREE.CylinderGeometry(6.2, 9.2, 11.2, 8, 1, true), robeMat);
    bell.position.set(0, 5.4, 0);
    const cuffGold = new THREE.Mesh(new THREE.TorusGeometry(9.0, 0.9, 6, 12), goldMat);
    cuffGold.rotation.x = Math.PI / 2;
    cuffGold.position.set(0, 10.8, 0);
    grp.add(bell, cuffGold);
    addShadows(grp);
    bone.add(grp);
  };
  addBellCuff(r.bLFore);
  addBellCuff(r.bRFore);

  // --- BOTANICAL HEALER DIADEM & FLOATING HALO (Head) ---
  let haloPivot: THREE.Object3D | null = null;
  if (r.bHead) {
    const headGrp = new THREE.Group();

    // Golden embroidered band around hat crown
    const band = new THREE.Mesh(new THREE.CylinderGeometry(14.8, 15.8, 4.4, 12), goldMat);
    band.position.set(0, 24.5, -0.6);
    band.rotation.x = -0.1;
    headGrp.add(band);

    // Glowing Faham emerald jewel on front of crown
    const jewel = new THREE.Mesh(new THREE.OctahedronGeometry(3.2, 0), helmGlow);
    jewel.scale.set(0.95, 1.3, 0.65);
    jewel.position.set(0, 25.4, -15.8);
    headGrp.add(jewel);

    // Sculpted golden leaf-wing accents on left & right of crown
    const wingL = new THREE.Mesh(new THREE.ConeGeometry(2.4, 11.5, 5), goldMat);
    wingL.position.set(-15.5, 29.0, -3.0);
    wingL.rotation.set(-0.35, 0, 0.55);
    const wingR = wingL.clone();
    wingR.position.x = 15.5;
    wingR.rotation.z = -0.55;
    headGrp.add(wingL, wingR);

    // Luminous floating healing halo hovering above Tikaf's hat!
    haloPivot = new THREE.Group();
    haloPivot.position.set(0, 37.5, 0);
    const haloRing = new THREE.Mesh(new THREE.TorusGeometry(13.5, 1.15, 8, 24), helmGlow);
    haloRing.rotation.x = Math.PI / 2;
    haloPivot.add(haloRing);
    for (let i = 0; i < 4; i++) {
      const ang = (i * Math.PI) / 2;
      const gem = new THREE.Mesh(new THREE.OctahedronGeometry(1.9, 0), goldMat);
      gem.position.set(Math.cos(ang) * 13.5, 0, Math.sin(ang) * 13.5);
      haloPivot.add(gem);
    }
    headGrp.add(haloPivot);

    addShadows(headGrp);
    r.bHead.add(headGrp);
  }

  // --- FAHAM CRYSTAL HEALING STAFF (Parented to R_Hand -> channels heals & solar flare!) ---
  if (r.bRHand) {
    const staff = new THREE.Group();
    // Calibrated directly to R_Hand world axes so staff stands tall upright & outward-right (+Z -> world (-0.25, +0.92, +0.28))!
    staff.position.set(4.5, 4.8, 2.8);
    staff.rotation.set(1.56, 0.50, -0.49);

    const woodMat = makeMat(0x78350f, 0.75, 0.08, 0x451a03, 0.14);
    const shaft = new THREE.Mesh(new THREE.CylinderGeometry(1.4, 1.75, 86, 7), woodMat);
    shaft.rotation.x = Math.PI / 2;
    shaft.position.set(0, 0, 12);

    // Golden spiral collar & prongs holding floating jade Faham crystal high beside right shoulder
    const ferrule = new THREE.Mesh(new THREE.CylinderGeometry(3.2, 2.0, 7.5, 7), goldMat);
    ferrule.rotation.x = Math.PI / 2;
    ferrule.position.set(0, 0, 55);

    const crescent = new THREE.Mesh(new THREE.TorusGeometry(6.2, 1.1, 6, 12, Math.PI * 1.35), goldMat);
    crescent.position.set(0, 0, 61.5);
    crescent.rotation.z = -Math.PI * 0.18;

    const crystal = new THREE.Mesh(new THREE.OctahedronGeometry(5.4, 0), weaponGlow);
    crystal.scale.set(0.85, 0.85, 1.35);
    crystal.position.set(0, 0, 62.5);

    staff.add(shaft, ferrule, crescent, crystal);
    addShadows(staff);
    r.bRHand.add(staff);
  }

  return haloPivot;
}

// ============================================================================
// 3. DPS — LAME DE LAVE (Tikaf Aventurier Volcanique + Doubles Lames de Lave)
// ============================================================================
function buildDpsVolcanicGear(r: RigSlotBag): void {
  const obsidianMat = makeMat(0x292524, 0.48, 0.25, 0x1c1917, 0.22);
  const leatherMat = makeMat(0x572612, 0.72, 0.1, 0x2a0f06, 0.16);
  const scarfMat = makeMat(0x9a3412, 0.75, 0.08, 0x7c2d12, 0.25, true);
  const armorGlow = makeMat(0xf97316, 0.22, 0.28, 0xf97316, 0.75);
  const weaponGlow = makeMat(0xf97316, 0.16, 0.3, 0xea580c, 0.95);
  const helmGlow = makeMat(0xfb923c, 0.2, 0.25, 0xf97316, 0.8);
  r.armorAccentMats.push(armorGlow);
  r.weaponGlowMats.push(weaponGlow);
  r.helmGlowMats.push(helmGlow);

  // --- ASYMMETRIC VOLCANIC PAULDRON (R_Upperarm) + LIGHT BAND (L_Upperarm) ---
  if (r.bRUpper) {
    const p = new THREE.Group();
    const plate = new THREE.Mesh(new THREE.DodecahedronGeometry(6.8, 0), obsidianMat);
    plate.scale.set(1.08, 0.72, 1.04);
    plate.position.set(2.4, 0.6, 0.4);
    const spike = new THREE.Mesh(new THREE.ConeGeometry(2.0, 7.2, 5), armorGlow);
    spike.position.set(7.6, -1.8, 0.4);
    spike.rotation.z = -1.18;
    p.add(plate, spike);
    addShadows(p);
    r.bRUpper.add(p);
  }
  if (r.bLUpper) {
    const band = new THREE.Mesh(new THREE.CylinderGeometry(5.5, 5.8, 4.5, 8), leatherMat);
    band.position.set(0, 4.5, 0);
    addShadows(band);
    r.bLUpper.add(band);
  }

  // --- VOLCANIC BRACERS (L_Forearm & R_Forearm) ---
  const addBracer = (bone: THREE.Bone | undefined) => {
    if (!bone) return;
    const grp = new THREE.Group();
    const wrap = new THREE.Mesh(new THREE.CylinderGeometry(5.1, 5.8, 8.5, 8), leatherMat);
    wrap.position.set(0, 6.2, 0);
    const emberRing = new THREE.Mesh(new THREE.TorusGeometry(5.6, 0.7, 6, 10), armorGlow);
    emberRing.rotation.x = Math.PI / 2;
    emberRing.position.set(0, 9.8, 0);
    grp.add(wrap, emberRing);
    addShadows(grp);
    bone.add(grp);
  };
  addBracer(r.bLFore);
  addBracer(r.bRFore);

  // --- SNUG VOLCANIC SCARF COLLAR (Spine02) & EMBER SASH (Waist) ---
  if (r.bSpine02) {
    const scarf = new THREE.Group();
    const neckWrap = new THREE.Mesh(new THREE.TorusGeometry(7.8, 1.7, 6, 12), scarfMat);
    neckWrap.rotation.x = Math.PI / 2;
    neckWrap.position.set(0, 10.6, -0.4);
    const tail = new THREE.Mesh(new THREE.BoxGeometry(6.2, 18.0, 1.2), scarfMat);
    tail.position.set(-4.2, -1.5, 20.6);
    tail.rotation.set(0.18, 0, -0.14);
    scarf.add(neckWrap, tail);
    addShadows(scarf);
    r.bSpine02.add(scarf);
  }
  if (r.bWaist) {
    const beltGrp = new THREE.Group();
    const belt = new THREE.Mesh(new THREE.CylinderGeometry(13.2, 13.5, 3.8, 10), obsidianMat);
    belt.scale.set(1.03, 1.0, 0.88);
    belt.position.set(0, 2.0, -0.8);
    const buckle = new THREE.Mesh(new THREE.OctahedronGeometry(2.5, 0), armorGlow);
    buckle.scale.set(1.3, 1.0, 0.55);
    buckle.position.set(0, 2.0, -12.4);
    beltGrp.add(belt, buckle);
    addShadows(beltGrp);
    r.bWaist.add(beltGrp);
  }

  // --- VOLCANIC HAT BAND & EMBER FEATHER (Head — keeps Tikaf's iconic straw hat intact!) ---
  if (r.bHead) {
    const hatAccent = new THREE.Group();
    const band = new THREE.Mesh(new THREE.CylinderGeometry(14.5, 15.2, 2.6, 12), obsidianMat);
    band.position.set(0, 24.4, -0.6);
    band.rotation.x = -0.1;
    const feather = new THREE.Mesh(new THREE.ConeGeometry(2.0, 11.5, 5), helmGlow);
    feather.position.set(-14.2, 30.2, -1.5);
    feather.rotation.set(-0.25, 0, 0.42);
    hatAccent.add(band, feather);
    addShadows(hatAccent);
    r.bHead.add(hatAccent);
  }

  // --- DUAL VOLCANIC LAVA BLADES (R_Hand & L_Hand -> slash fiercely on every DPS skill!) ---
  const buildLavaBlade = (lengthScale: number) => {
    const bladeGrp = new THREE.Group();
    const grip = new THREE.Mesh(new THREE.CylinderGeometry(1.1, 1.3, 9.5, 6), obsidianMat);
    grip.rotation.x = Math.PI / 2;
    grip.position.set(0, 0, 1.5);

    const guard = new THREE.Mesh(new THREE.BoxGeometry(8.5, 2.0, 2.4), obsidianMat);
    guard.position.set(0, 0, 6.8);

    // Core dark obsidian blade + incandescent lava cutting edge
    const core = new THREE.Mesh(new THREE.BoxGeometry(1.6, 4.2, 30 * lengthScale), obsidianMat);
    core.position.set(0, 0.2, 6.8 + 15 * lengthScale);

    const edge = new THREE.Mesh(new THREE.BoxGeometry(0.9, 2.2, 32 * lengthScale), weaponGlow);
    edge.position.set(0, -1.6, 7.6 + 15.5 * lengthScale);

    const tip = new THREE.Mesh(new THREE.ConeGeometry(2.6, 9.5 * lengthScale, 4), weaponGlow);
    tip.rotation.x = Math.PI / 2;
    tip.scale.set(0.45, 1.0, 1.0);
    tip.position.set(0, -0.3, 6.8 + 30 * lengthScale + 4.5 * lengthScale);

    bladeGrp.add(grip, guard, core, edge, tip);
    addShadows(bladeGrp);
    return bladeGrp;
  };

  if (r.bRHand) {
    const rightBlade = buildLavaBlade(1.05);
    // Point cleanly forward & slightly outward right (calibrated to R_Hand world axes!)
    rightBlade.position.set(3.2, 5.2, 3.0);
    rightBlade.rotation.set(-0.14, 0.63, 0.05);
    r.bRHand.add(rightBlade);
  }
  if (r.bLHand) {
    const leftBlade = buildLavaBlade(0.88);
    // Point cleanly forward & slightly outward left (calibrated to L_Hand world axes!)
    leftBlade.position.set(-3.2, 5.0, 3.0);
    leftBlade.rotation.set(-1.24, -0.72, -0.52);
    r.bLHand.add(leftBlade);
  }
}
