import * as THREE from 'three';
import {
  attachClassEquipment,
  type EquipmentVisualRig,
  type EquippedSlotVisual,
} from './ClassEquipmentBuilder';
import { instantiateTikafRig, TIKAF_FOOT_SPEED_AT_1X } from './ModelAssetCache';
import { ProceduralBoneAnimator } from './ProceduralBoneAnimator';
import type { ClassCode, EquipmentSlot } from './types';

export interface AnimatedAvatar {
  group: THREE.Group;
  body: THREE.Group;
  proceduralRoot: THREE.Group;
  leftArm: THREE.Object3D;
  rightArm: THREE.Object3D;
  leftLeg: THREE.Object3D;
  rightLeg: THREE.Object3D;
  targetRing: THREE.Mesh;
  attackTimer: number;
  activeSkillCode: string | null;
  activeSkillDuration: number;
  fbxRoot: THREE.Group | null;
  fbxMixer: THREE.AnimationMixer | null;
  fbxWalkAction: THREE.AnimationAction | null;
  fbxIdleAction: THREE.AnimationAction | null;
  walkBlend: number;
  idlePhase: number;
  boneAnimator: ProceduralBoneAnimator | null;
  equipmentRig: EquipmentVisualRig | null;
  pendingEquipped: Partial<Record<EquipmentSlot, EquippedSlotVisual | null>> | null;
  mountGroup: THREE.Group | null;
  activeMountCode: string | null;
  mountSeatY: number;
  mountLegs: THREE.Object3D[];
  mountWings: THREE.Object3D[];
}

export function applyAvatarEquipment(
  avatar: AnimatedAvatar,
  equipped: Partial<Record<EquipmentSlot, EquippedSlotVisual | null>>,
): void {
  avatar.pendingEquipped = equipped;
  avatar.equipmentRig?.applyEquippedVisuals(equipped);
}

export function triggerAvatarSkill(avatar: AnimatedAvatar, skillCode: string): void {
  const dur = ProceduralBoneAnimator.durationForSkill(skillCode);
  avatar.activeSkillCode = skillCode;
  avatar.activeSkillDuration = dur;
  avatar.attackTimer = dur;
  if (avatar.boneAnimator) {
    avatar.boneAnimator.triggerSkill(skillCode);
  }
}

const mat = (color: number | string, roughness = 0.65, metalness = 0.15, emissive?: number | string) =>
  new THREE.MeshStandardMaterial({
    color,
    roughness,
    metalness,
    flatShading: true,
    emissive: emissive !== undefined ? new THREE.Color(emissive) : new THREE.Color(0x000000),
    emissiveIntensity: emissive !== undefined ? 0.65 : 0,
  });

export function createPlayerAvatar(classCode: ClassCode): AnimatedAvatar {
  const group = new THREE.Group();
  const body = new THREE.Group();
  const proceduralRoot = new THREE.Group();
  body.add(proceduralRoot);
  group.add(body);

  const skinMat = mat(0xd49b72, 0.7, 0.05);
  const darkMat = mat(0x1f2937, 0.75, 0.25);

  const palette: Record<ClassCode, { main: number; trim: number; glow: number }> = {
    TANK: { main: 0x1d4ed8, trim: 0x94a3b8, glow: 0x38bdf8 },
    DPS: { main: 0xb91c1c, trim: 0x1e1b18, glow: 0xf97316 },
    HEAL: { main: 0x047857, trim: 0xfde68a, glow: 0x34d399 },
  };
  const pal = palette[classCode];

  // Legs
  const legGeo = new THREE.BoxGeometry(0.32, 0.95, 0.34);
  const leftLeg = new THREE.Mesh(legGeo, darkMat);
  leftLeg.position.set(-0.22, 0.48, 0);
  leftLeg.castShadow = true;
  const rightLeg = new THREE.Mesh(legGeo, darkMat);
  rightLeg.position.set(0.22, 0.48, 0);
  rightLeg.castShadow = true;
  proceduralRoot.add(leftLeg, rightLeg);

  // Torso
  const torsoWidth = classCode === 'TANK' ? 1.0 : 0.78;
  const torso = new THREE.Mesh(new THREE.BoxGeometry(torsoWidth, 1.05, 0.52), mat(pal.main, 0.55, 0.3));
  torso.position.y = 1.45;
  torso.castShadow = true;
  proceduralRoot.add(torso);

  // Shoulder pauldrons for Tank / DPS
  if (classCode === 'TANK') {
    const pauldronGeo = new THREE.DodecahedronGeometry(0.32, 0);
    const pL = new THREE.Mesh(pauldronGeo, mat(pal.trim, 0.4, 0.6));
    pL.position.set(-0.62, 1.85, 0);
    const pR = pL.clone();
    pR.position.x = 0.62;
    proceduralRoot.add(pL, pR);
  } else if (classCode === 'DPS') {
    // Volcanic scarf / cape
    const cape = new THREE.Mesh(new THREE.BoxGeometry(0.75, 1.15, 0.08), mat(0x271313, 0.85, 0.05));
    cape.position.set(0, 1.32, -0.32);
    cape.rotation.x = 0.14;
    proceduralRoot.add(cape);
  } else {
    // Heal lower robe flare
    const robe = new THREE.Mesh(new THREE.CylinderGeometry(0.38, 0.58, 0.9, 7), mat(pal.main, 0.7, 0.1));
    robe.position.y = 0.72;
    proceduralRoot.add(robe);
  }

  // Head
  const head = new THREE.Mesh(new THREE.BoxGeometry(0.54, 0.54, 0.54), skinMat);
  head.position.y = 2.28;
  head.castShadow = true;
  proceduralRoot.add(head);

  // Headgear per class
  if (classCode === 'TANK') {
    const helm = new THREE.Mesh(new THREE.BoxGeometry(0.62, 0.36, 0.62), mat(pal.trim, 0.35, 0.7));
    helm.position.y = 2.45;
    const visor = new THREE.Mesh(new THREE.BoxGeometry(0.52, 0.1, 0.12), mat(pal.glow, 0.2, 0.4, pal.glow));
    visor.position.set(0, 2.34, 0.28);
    proceduralRoot.add(helm, visor);
  } else if (classCode === 'DPS') {
    const hood = new THREE.Mesh(new THREE.ConeGeometry(0.45, 0.52, 6), mat(0x7c2d12, 0.75, 0.1));
    hood.position.set(0, 2.68, -0.04);
    proceduralRoot.add(hood);
  } else {
    const crown = new THREE.Mesh(new THREE.TorusGeometry(0.32, 0.06, 6, 12), mat(pal.trim, 0.4, 0.3, pal.glow));
    crown.rotation.x = Math.PI / 2;
    crown.position.y = 2.6;
    proceduralRoot.add(crown);
  }

  // Arms + class weapons
  const leftArm = new THREE.Group();
  leftArm.position.set(-torsoWidth * 0.5 - 0.18, 1.85, 0);
  const rightArm = new THREE.Group();
  rightArm.position.set(torsoWidth * 0.5 + 0.18, 1.85, 0);

  const armMeshL = new THREE.Mesh(new THREE.BoxGeometry(0.26, 0.85, 0.26), mat(pal.main, 0.6, 0.2));
  armMeshL.position.y = -0.35;
  leftArm.add(armMeshL);

  const armMeshR = armMeshL.clone();
  rightArm.add(armMeshR);

  if (classCode === 'TANK') {
    // Heavy Basalt Shield on left arm
    const shield = new THREE.Mesh(new THREE.CylinderGeometry(0.58, 0.52, 0.14, 6), mat(0x334155, 0.45, 0.65));
    shield.rotation.x = Math.PI / 2;
    shield.position.set(-0.14, -0.45, 0.38);
    const emblem = new THREE.Mesh(new THREE.OctahedronGeometry(0.18), mat(pal.glow, 0.2, 0.5, pal.glow));
    emblem.position.set(-0.14, -0.45, 0.48);
    leftArm.add(shield, emblem);

    // Basalt Hammer on right arm
    const handle = new THREE.Mesh(new THREE.CylinderGeometry(0.06, 0.06, 1.35, 6), mat(0x475569));
    handle.rotation.x = Math.PI / 2;
    handle.position.set(0, -0.68, 0.42);
    const hammerHead = new THREE.Mesh(new THREE.BoxGeometry(0.38, 0.38, 0.55), mat(0x64748b, 0.4, 0.6));
    hammerHead.position.set(0, -0.68, 1.0);
    rightArm.add(handle, hammerHead);
  } else if (classCode === 'DPS') {
    // Dual Lava Blades (shown as placeholder while Tikaf.fbx streams in)
    const bladeGeo = new THREE.BoxGeometry(0.1, 0.16, 1.3);
    const bladeMat = mat(0xf97316, 0.25, 0.5, 0xea580c);
    const bladeL = new THREE.Mesh(bladeGeo, bladeMat);
    bladeL.position.set(0, -0.68, 0.65);
    const bladeR = bladeL.clone();
    leftArm.add(bladeL);
    rightArm.add(bladeR);
  } else {
    // Faham Crystal Staff on right arm
    const staff = new THREE.Mesh(new THREE.CylinderGeometry(0.055, 0.065, 2.35, 6), mat(0x78350f, 0.8, 0.05));
    staff.position.set(0.08, -0.2, 0.32);
    staff.rotation.x = 0.2;
    const crystal = new THREE.Mesh(new THREE.OctahedronGeometry(0.24), mat(0x34d399, 0.15, 0.3, 0x10b981));
    crystal.position.set(0.08, 0.98, 0.55);
    rightArm.add(staff, crystal);
  }

  proceduralRoot.add(leftArm, rightArm);

  // Selection ring under feet
  const targetRing = new THREE.Mesh(
    new THREE.RingGeometry(0.85, 1.08, 24),
    new THREE.MeshBasicMaterial({ color: 0x34d399, side: THREE.DoubleSide, transparent: true, opacity: 0.85 }),
  );
  targetRing.rotation.x = -Math.PI / 2;
  targetRing.position.y = 0.08;
  targetRing.visible = false;
  group.add(targetRing);

  const avatar: AnimatedAvatar = {
    group,
    body,
    proceduralRoot,
    leftArm,
    rightArm,
    leftLeg,
    rightLeg,
    targetRing,
    attackTimer: 0,
    activeSkillCode: null,
    activeSkillDuration: 0.5,
    fbxRoot: null,
    fbxMixer: null,
    fbxWalkAction: null,
    fbxIdleAction: null,
    walkBlend: 0,
    idlePhase: Math.random() * Math.PI * 2,
    boneAnimator: null,
    equipmentRig: null,
    pendingEquipped: null,
    mountGroup: null,
    activeMountCode: null,
    mountSeatY: 0,
    mountLegs: [],
    mountWings: [],
  };

  // Universal Tikaf.fbx base rig for ALL classes (TANK, DPS, HEAL):
  // class identity comes from bone-parented heavy armor, flowing healer robe, or volcanic blades!
  void instantiateTikafRig()
    .then((rig) => {
      const equipRig = attachClassEquipment(rig.root, classCode);
      rig.root.traverse((obj) => {
        obj.userData = group.userData;
      });
      body.add(rig.root);
      proceduralRoot.visible = false;
      avatar.fbxRoot = rig.root;
      avatar.fbxMixer = rig.mixer;
      avatar.fbxWalkAction = rig.walkAction;
      avatar.fbxIdleAction = rig.idleAction;
      avatar.walkBlend = 0;
      avatar.boneAnimator = new ProceduralBoneAnimator(rig.root);
      avatar.equipmentRig = equipRig;
      if (avatar.pendingEquipped) {
        equipRig.applyEquippedVisuals(avatar.pendingEquipped);
      }
    })
    .catch((err) => {
      console.warn('Impossible de charger Tikaf.fbx, fallback procédural actif :', err);
    });

  return avatar;
}

function disposeMountGroup(root: THREE.Object3D): void {
  root.traverse((obj) => {
    const mesh = obj as THREE.Mesh;
    if (!mesh.isMesh) return;
    mesh.geometry?.dispose();
    const mats = Array.isArray(mesh.material) ? mesh.material : [mesh.material];
    for (const m of mats) {
      if (m) m.dispose();
    }
  });
}

function buildMountVisual(mountCode: string): {
  group: THREE.Group;
  seatY: number;
  legs: THREE.Object3D[];
  wings: THREE.Object3D[];
} {
  const group = new THREE.Group();
  const legs: THREE.Object3D[] = [];
  const wings: THREE.Object3D[] = [];

  if (mountCode === 'mount_vol_100') {
    // Paille-en-Queue Royal (Vol +100%)
    const whiteMat = mat(0xf8fafc, 0.45, 0.1);
    const goldMat = mat(0xfacc15, 0.25, 0.65, 0xf59e0b);
    const saddleMat = mat(0x0f766e, 0.55, 0.2, 0x14b8a6);
    const beakMat = mat(0xf97316, 0.35, 0.25);

    const torso = new THREE.Mesh(new THREE.SphereGeometry(0.75, 10, 8), whiteMat);
    torso.scale.set(0.95, 0.72, 2.05);
    torso.position.set(0, 1.5, 0.05);

    const breast = new THREE.Mesh(new THREE.ConeGeometry(0.56, 1.15, 7), whiteMat);
    breast.rotation.x = Math.PI / 2.2;
    breast.position.set(0, 1.52, 1.25);

    const neck = new THREE.Mesh(new THREE.CylinderGeometry(0.22, 0.32, 0.95, 7), whiteMat);
    neck.rotation.x = 0.55;
    neck.position.set(0, 2.05, 1.55);

    const head = new THREE.Mesh(new THREE.SphereGeometry(0.32, 8, 7), whiteMat);
    head.scale.set(0.9, 0.9, 1.25);
    head.position.set(0, 2.45, 1.92);

    const crown = new THREE.Mesh(new THREE.ConeGeometry(0.14, 0.42, 5), goldMat);
    crown.position.set(0, 2.82, 1.85);
    crown.rotation.x = -0.3;

    const beak = new THREE.Mesh(new THREE.ConeGeometry(0.11, 0.65, 5), beakMat);
    beak.rotation.x = Math.PI / 2;
    beak.position.set(0, 2.42, 2.45);

    const saddle = new THREE.Mesh(new THREE.BoxGeometry(0.86, 0.16, 0.92), saddleMat);
    saddle.position.set(0, 1.98, 0.05);
    const trim = new THREE.Mesh(new THREE.TorusGeometry(0.55, 0.05, 5, 14), goldMat);
    trim.rotation.x = Math.PI / 2;
    trim.position.set(0, 2.02, 0.05);

    // Long white twin tail streamers iconic of La Réunion's Paille-en-Queue
    const tailBase = new THREE.Mesh(new THREE.ConeGeometry(0.45, 1.0, 6), whiteMat);
    tailBase.rotation.x = -Math.PI / 2;
    tailBase.position.set(0, 1.52, -1.65);

    const streamerGeo = new THREE.CylinderGeometry(0.04, 0.015, 2.6, 5);
    const s1 = new THREE.Mesh(streamerGeo, mat(0xffffff, 0.25, 0.15, 0xfef08a));
    s1.rotation.x = Math.PI / 2;
    s1.rotation.z = 0.08;
    s1.position.set(-0.14, 1.54, -3.0);
    const s2 = s1.clone();
    s2.rotation.z = -0.08;
    s2.position.x = 0.14;

    group.add(torso, breast, neck, head, crown, beak, saddle, trim, tailBase, s1, s2);

    // Articulated soaring wings
    for (const side of [-1, 1]) {
      const wingPivot = new THREE.Group();
      wingPivot.position.set(side * 0.52, 1.72, 0.28);

      const innerWing = new THREE.Mesh(new THREE.BoxGeometry(1.55, 0.1, 1.05), whiteMat);
      innerWing.position.set(side * 0.78, 0, -0.05);

      const outerWing = new THREE.Mesh(new THREE.BoxGeometry(1.35, 0.08, 0.72), whiteMat);
      outerWing.position.set(side * 2.05, 0.06, -0.22);
      outerWing.rotation.y = -side * 0.22;

      const goldTip = new THREE.Mesh(new THREE.BoxGeometry(0.65, 0.09, 0.56), goldMat);
      goldTip.position.set(side * 2.75, 0.08, -0.38);
      goldTip.rotation.y = -side * 0.28;

      wingPivot.add(innerWing, outerWing, goldTip);
      group.add(wingPivot);
      wings.push(wingPivot);
    }

    return { group, seatY: 1.45, legs, wings };
  }

  // Ground mounts: mount_sol_60 (Poney des Plaines) vs mount_sol_100 (Destrier du Basalte)
  const isEliteGround = mountCode === 'mount_sol_100';
  const coatMat = mat(isEliteGround ? 0x1e293b : 0x7c2d12, isEliteGround ? 0.45 : 0.75, isEliteGround ? 0.45 : 0.1);
  const maneMat = mat(
    isEliteGround ? 0xf97316 : 0x291509,
    0.4,
    0.25,
    isEliteGround ? 0xea580c : undefined,
  );
  const saddleMat = mat(isEliteGround ? 0xb91c1c : 0x0d9488, 0.55, 0.2);
  const hoofMat = mat(isEliteGround ? 0xf97316 : 0x1f2937, 0.35, 0.4, isEliteGround ? 0xf97316 : undefined);
  const bodyScale = isEliteGround ? 1.15 : 0.98;
  const seatY = isEliteGround ? 1.18 : 0.96;

  const horseBody = new THREE.Group();
  horseBody.scale.setScalar(bodyScale);
  group.add(horseBody);

  const torso = new THREE.Mesh(new THREE.BoxGeometry(0.88, 0.78, 2.05), coatMat);
  torso.position.set(0, 1.18, 0);
  torso.castShadow = true;

  const neck = new THREE.Mesh(new THREE.BoxGeometry(0.42, 0.88, 0.46), coatMat);
  neck.rotation.x = 0.48;
  neck.position.set(0, 1.72, 1.02);

  const head = new THREE.Mesh(new THREE.BoxGeometry(0.42, 0.42, 0.76), coatMat);
  head.rotation.x = 0.25;
  head.position.set(0, 2.05, 1.38);

  const mane = new THREE.Mesh(new THREE.BoxGeometry(0.16, 0.82, 0.22), maneMat);
  mane.rotation.x = 0.48;
  mane.position.set(0, 1.84, 0.84);

  const saddle = new THREE.Mesh(new THREE.BoxGeometry(0.94, 0.16, 0.85), saddleMat);
  saddle.position.set(0, 1.62, -0.05);

  const tail = new THREE.Mesh(new THREE.ConeGeometry(0.16, 0.88, 5), maneMat);
  tail.rotation.x = 0.42;
  tail.position.set(0, 1.05, -1.24);

  horseBody.add(torso, neck, head, mane, saddle, tail);

  if (isEliteGround) {
    // Basalt warhorse barding + glowing magma brow horn
    const armorMat = mat(0x475569, 0.3, 0.75);
    const chestArmor = new THREE.Mesh(new THREE.BoxGeometry(0.94, 0.65, 0.55), armorMat);
    chestArmor.position.set(0, 1.24, 0.88);
    const helm = new THREE.Mesh(new THREE.BoxGeometry(0.48, 0.26, 0.68), armorMat);
    helm.rotation.x = 0.25;
    helm.position.set(0, 2.16, 1.4);
    const horn = new THREE.Mesh(new THREE.ConeGeometry(0.09, 0.42, 5), hoofMat);
    horn.rotation.x = 0.6;
    horn.position.set(0, 2.38, 1.56);
    horseBody.add(chestArmor, helm, horn);
  }

  // 4 animated legs
  const legCoords: [number, number][] = [
    [-0.28, 0.72],
    [0.28, 0.72],
    [-0.28, -0.72],
    [0.28, -0.72],
  ];
  const legGeo = new THREE.BoxGeometry(0.22, 0.82, 0.24);
  const hoofGeo = new THREE.BoxGeometry(0.24, 0.16, 0.26);
  for (const [lx, lz] of legCoords) {
    const pivot = new THREE.Group();
    pivot.position.set(lx, 0.88, lz);
    const legMesh = new THREE.Mesh(legGeo, coatMat);
    legMesh.position.y = -0.38;
    const hoof = new THREE.Mesh(hoofGeo, hoofMat);
    hoof.position.y = -0.8;
    pivot.add(legMesh, hoof);
    horseBody.add(pivot);
    legs.push(pivot);
  }

  return { group, seatY, legs, wings };
}

export function setAvatarMountVisual(avatar: AnimatedAvatar, mountCode: string | null): void {
  const normalized = mountCode && mountCode.length > 0 ? mountCode : null;
  if (avatar.activeMountCode === normalized) {
    return;
  }

  if (avatar.mountGroup) {
    avatar.group.remove(avatar.mountGroup);
    disposeMountGroup(avatar.mountGroup);
    avatar.mountGroup = null;
    avatar.mountLegs = [];
    avatar.mountWings = [];
    avatar.mountSeatY = 0;
  }

  avatar.activeMountCode = normalized;
  if (!normalized) {
    avatar.body.position.y = 0;
    avatar.body.rotation.x = 0;
    return;
  }

  const built = buildMountVisual(normalized);
  built.group.traverse((obj) => {
    obj.userData = avatar.group.userData;
  });
  avatar.group.add(built.group);
  avatar.mountGroup = built.group;
  avatar.mountSeatY = built.seatY;
  avatar.mountLegs = built.legs;
  avatar.mountWings = built.wings;
}

export function animateAvatar(
  avatar: AnimatedAvatar,
  dt: number,
  isMoving: boolean,
  isDead: boolean,
  time: number,
  distanceMoved = 0,
): void {
  if (isDead) {
    avatar.body.rotation.x = THREE.MathUtils.lerp(avatar.body.rotation.x, -Math.PI / 2, Math.min(1, dt * 8));
    avatar.body.position.y = THREE.MathUtils.lerp(avatar.body.position.y, 0.35, Math.min(1, dt * 8));
    return;
  }

  if (avatar.mountGroup) {
    const activelyMoving = distanceMoved > 0.0015 || isMoving;
    const isFlyingMount = avatar.mountWings.length > 0;
    let rideBob = 0;

    if (isFlyingMount) {
      const flapFreq = activelyMoving ? 8.5 : 4.2;
      const flapAmp = activelyMoving ? 0.56 : 0.28;
      const flapAngle = Math.sin(time * flapFreq) * flapAmp;
      if (avatar.mountWings[0]) avatar.mountWings[0].rotation.z = -flapAngle;
      if (avatar.mountWings[1]) avatar.mountWings[1].rotation.z = flapAngle;
      const hoverLift = 0.25 + Math.sin(time * flapFreq) * 0.14;
      avatar.mountGroup.position.y = hoverLift;
      rideBob = hoverLift;
    } else {
      const gallopFreq = activelyMoving ? 13.0 : 2.5;
      if (activelyMoving) {
        for (let i = 0; i < avatar.mountLegs.length; i++) {
          const phase = i === 0 || i === 3 ? 0 : Math.PI;
          avatar.mountLegs[i].rotation.x = Math.sin(time * gallopFreq + phase) * 0.58;
        }
        rideBob = Math.abs(Math.sin(time * gallopFreq)) * 0.14;
      } else {
        for (const leg of avatar.mountLegs) {
          leg.rotation.x = THREE.MathUtils.lerp(leg.rotation.x, 0, Math.min(1, dt * 10));
        }
        rideBob = Math.sin(time * 2.5) * 0.03;
      }
      avatar.mountGroup.position.y = 0;
    }

    // Seat rider cleanly atop the mount
    avatar.body.rotation.x = THREE.MathUtils.lerp(avatar.body.rotation.x, activelyMoving ? 0.12 : 0.03, Math.min(1, dt * 12));
    avatar.body.rotation.y = THREE.MathUtils.lerp(avatar.body.rotation.y, 0, Math.min(1, dt * 12));
    avatar.body.position.z = THREE.MathUtils.lerp(avatar.body.position.z, 0, Math.min(1, dt * 12));
    avatar.body.position.y = THREE.MathUtils.lerp(avatar.body.position.y, avatar.mountSeatY + rideBob, Math.min(1, dt * 18));

    if (avatar.fbxRoot && avatar.fbxMixer && avatar.fbxWalkAction) {
      avatar.boneAnimator?.restoreBasePose();
      avatar.walkBlend = 0;
      avatar.fbxWalkAction.setEffectiveWeight(0);
      avatar.fbxWalkAction.paused = true;
      avatar.fbxIdleAction?.setEffectiveWeight(1);
      avatar.fbxMixer.update(0);
      avatar.boneAnimator?.captureBasePose();
      avatar.boneAnimator?.applyIdle(time + avatar.idlePhase, 1.0, true, isFlyingMount, activelyMoving);
      avatar.boneAnimator?.apply(dt);
      avatar.equipmentRig?.update(dt, time);
    } else {
      avatar.leftLeg.rotation.x = THREE.MathUtils.lerp(avatar.leftLeg.rotation.x, -0.35, Math.min(1, dt * 10));
      avatar.rightLeg.rotation.x = THREE.MathUtils.lerp(avatar.rightLeg.rotation.x, -0.35, Math.min(1, dt * 10));
      avatar.leftArm.rotation.x = THREE.MathUtils.lerp(avatar.leftArm.rotation.x, -0.45, Math.min(1, dt * 10));
      avatar.rightArm.rotation.x = THREE.MathUtils.lerp(avatar.rightArm.rotation.x, -0.45, Math.min(1, dt * 10));
    }

    return;
  }

  // Custom FBX SkinnedMesh animation path (Tikaf + all future characters using the same biped rig!)
  if (avatar.fbxRoot) {
    const speed = dt > 0.0001 ? distanceMoved / dt : 0;
    const activelyMoving = distanceMoved > 0.0015 || (isMoving && distanceMoved > 0.0002);

    // 1. Restore base bone rotations before mixer updates so procedural additive offsets never drift
    avatar.boneAnimator?.restoreBasePose();

    // 2. Smoothly blend walk vs synthesized standing idle & drive locomotion without foot sliding
    const blendTarget = activelyMoving ? 1 : 0;
    avatar.walkBlend = THREE.MathUtils.lerp(
      avatar.walkBlend,
      blendTarget,
      Math.min(1, dt * (activelyMoving ? 12 : 9)),
    );
    if (avatar.walkBlend < 0.005) avatar.walkBlend = 0;
    if (avatar.walkBlend > 0.995) avatar.walkBlend = 1;

    if (avatar.fbxMixer && avatar.fbxWalkAction) {
      const clipLen = avatar.fbxWalkAction.getClip().duration || 2.3333;
      avatar.fbxWalkAction.setEffectiveWeight(avatar.walkBlend);
      avatar.fbxIdleAction?.setEffectiveWeight(1 - avatar.walkBlend);

      if (activelyMoving) {
        const exactAnimStep = distanceMoved / TIKAF_FOOT_SPEED_AT_1X;
        const clampedStep = Math.min(exactAnimStep, dt * 5.2);
        avatar.fbxWalkAction.paused = false;
        avatar.fbxWalkAction.time = (avatar.fbxWalkAction.time + clampedStep) % clipLen;
      } else if (avatar.walkBlend > 0.005) {
        avatar.fbxWalkAction.paused = false;
        avatar.fbxWalkAction.time = (avatar.fbxWalkAction.time + dt * 0.35) % clipLen;
      } else {
        avatar.fbxWalkAction.paused = true;
      }
      avatar.fbxMixer.update(0);
    }

    // 3. Snapshot clean post-mixer pose, then layer procedural idle + active skill animation on top!
    avatar.boneAnimator?.captureBasePose();
    const idleWeight = 1 - avatar.walkBlend;
    const idleFactor = avatar.boneAnimator?.isPlaying() ? idleWeight * 0.25 : idleWeight;
    const idlePose = avatar.boneAnimator
      ? avatar.boneAnimator.applyIdle(time + avatar.idlePhase, idleFactor, false, false, false)
      : { bodyOffsetY: 0, bodyOffsetZ: 0, bodyYawOffset: 0, active: false };
    const skillPose = avatar.boneAnimator
      ? avatar.boneAnimator.apply(dt)
      : { bodyOffsetY: 0, bodyOffsetZ: 0, bodyYawOffset: 0, active: false };
    avatar.equipmentRig?.update(dt, time);

    if (avatar.attackTimer > 0) {
      avatar.attackTimer = Math.max(0, avatar.attackTimer - dt);
    }

    const baseTiltX = activelyMoving ? Math.min(0.12, speed * 0.011) : 0.0;
    avatar.body.rotation.x = THREE.MathUtils.lerp(avatar.body.rotation.x, baseTiltX, Math.min(1, dt * 14));
    avatar.body.rotation.y = THREE.MathUtils.lerp(
      avatar.body.rotation.y,
      idlePose.bodyYawOffset + skillPose.bodyYawOffset,
      Math.min(1, dt * 18),
    );
    avatar.body.position.z = THREE.MathUtils.lerp(
      avatar.body.position.z,
      idlePose.bodyOffsetZ + skillPose.bodyOffsetZ,
      Math.min(1, dt * 18),
    );
    avatar.body.position.y = THREE.MathUtils.lerp(
      avatar.body.position.y,
      idlePose.bodyOffsetY + skillPose.bodyOffsetY,
      Math.min(1, dt * 20),
    );
    return;
  }

  // Procedural low-poly animation path (Tank & Heal until their FBX models are added)
  avatar.body.rotation.x = THREE.MathUtils.lerp(avatar.body.rotation.x, 0, Math.min(1, dt * 10));
  avatar.body.rotation.y = THREE.MathUtils.lerp(avatar.body.rotation.y, 0, Math.min(1, dt * 12));
  avatar.body.position.z = THREE.MathUtils.lerp(avatar.body.position.z, 0, Math.min(1, dt * 12));

  if (isMoving || distanceMoved > 0.002) {
    const freq = Math.max(6.5, Math.min(13.5, (dt > 0.0001 ? distanceMoved / dt : 6) * 1.3));
    const swing = Math.sin(time * freq) * 0.65;
    avatar.leftLeg.rotation.x = swing;
    avatar.rightLeg.rotation.x = -swing;
    avatar.leftArm.rotation.x = -swing * 0.55;
    avatar.rightArm.rotation.x = swing * 0.55;
    avatar.leftArm.rotation.z = THREE.MathUtils.lerp(avatar.leftArm.rotation.z, 0, Math.min(1, dt * 10));
    avatar.rightArm.rotation.z = THREE.MathUtils.lerp(avatar.rightArm.rotation.z, 0, Math.min(1, dt * 10));
    avatar.body.position.y = Math.abs(Math.sin(time * freq)) * 0.14;
  } else {
    const breath = Math.sin((time + avatar.idlePhase) * 2.1);
    avatar.leftLeg.rotation.x = THREE.MathUtils.lerp(avatar.leftLeg.rotation.x, 0, Math.min(1, dt * 10));
    avatar.rightLeg.rotation.x = THREE.MathUtils.lerp(avatar.rightLeg.rotation.x, 0, Math.min(1, dt * 10));
    avatar.leftArm.rotation.x = THREE.MathUtils.lerp(avatar.leftArm.rotation.x, breath * 0.06, Math.min(1, dt * 8));
    avatar.rightArm.rotation.x = THREE.MathUtils.lerp(avatar.rightArm.rotation.x, -breath * 0.06, Math.min(1, dt * 8));
    avatar.leftArm.rotation.z = THREE.MathUtils.lerp(avatar.leftArm.rotation.z, 0.05 + breath * 0.025, Math.min(1, dt * 8));
    avatar.rightArm.rotation.z = THREE.MathUtils.lerp(avatar.rightArm.rotation.z, -(0.05 + breath * 0.025), Math.min(1, dt * 8));
    avatar.body.position.y = breath * 0.035;
  }

  if (avatar.attackTimer > 0) {
    avatar.attackTimer = Math.max(0, avatar.attackTimer - dt);
    const t = 1 - avatar.attackTimer / Math.max(0.1, avatar.activeSkillDuration);
    const phase = Math.sin(t * Math.PI);
    const code = avatar.activeSkillCode;

    if (code === 'shield_bash') {
      avatar.leftArm.rotation.x = -1.7 * phase;
      avatar.body.position.z = 0.65 * phase;
      avatar.body.rotation.y = -0.35 * phase;
    } else if (code === 'piton_taunt') {
      avatar.leftArm.rotation.z = 1.15 * phase;
      avatar.rightArm.rotation.z = -1.15 * phase;
      avatar.body.rotation.x = -0.25 * phase;
    } else if (code === 'basalt_skin') {
      avatar.leftArm.rotation.x = -1.2 * phase;
      avatar.rightArm.rotation.x = -1.2 * phase;
      avatar.leftArm.rotation.z = -0.55 * phase;
      avatar.rightArm.rotation.z = 0.55 * phase;
    } else if (code === 'seismic_slam' || code === 'volcanic_fury') {
      const jump = t < 0.55 ? Math.sin((t / 0.55) * Math.PI) * 1.0 : 0;
      avatar.body.position.y = jump;
      avatar.leftArm.rotation.x = t < 0.45 ? -2.4 * phase : 0.4 * phase;
      avatar.rightArm.rotation.x = t < 0.45 ? -2.4 * phase : 0.4 * phase;
    } else if (code === 'lagoon_blessing' || code === 'scoria_rain') {
      avatar.body.position.y = 0.55 * phase;
      avatar.leftArm.rotation.x = -2.2 * phase;
      avatar.rightArm.rotation.x = -2.2 * phase;
      avatar.leftArm.rotation.z = 0.5 * phase;
      avatar.rightArm.rotation.z = -0.5 * phase;
    } else if (code === 'faham_infusion' || code === 'salazie_mist') {
      avatar.leftArm.rotation.x = -1.35 * phase;
      avatar.rightArm.rotation.x = -1.35 * phase;
      avatar.body.position.y = 0.2 * phase;
    } else if (code === 'auto_attack') {
      avatar.rightArm.rotation.x = -1.45 * phase;
      avatar.body.position.z = 0.28 * phase;
      avatar.body.rotation.y = 0.22 * phase;
    } else {
      avatar.rightArm.rotation.x = -1.75 * phase;
      avatar.leftArm.rotation.x = -0.75 * phase;
      avatar.body.position.z = 0.4 * phase;
    }

    if (avatar.attackTimer <= 0) {
      avatar.activeSkillCode = null;
    }
  }
}

export interface MonsterVisual {
  group: THREE.Group;
  body: THREE.Group;
  targetRing: THREE.Mesh;
  hitFlash: number;
  orbitGroup?: THREE.Group;
}

export function createMonsterMesh(meshKey: string, primaryColor: string, scale: number, isBoss: boolean): MonsterVisual {
  const group = new THREE.Group();
  const body = new THREE.Group();
  group.add(body);
  let orbitGroup: THREE.Group | undefined;

  const mainMat = mat(primaryColor, 0.65, 0.2);
  const eyeMat = mat(0xfef08a, 0.2, 0.2, 0xfacc15);

  if (meshKey === 'crab') {
    const shell = new THREE.Mesh(new THREE.CylinderGeometry(0.95, 1.05, 0.52, 7), mainMat);
    shell.position.y = 0.65;
    body.add(shell);
    const clawGeo = new THREE.BoxGeometry(0.42, 0.32, 0.72);
    const cL = new THREE.Mesh(clawGeo, mainMat);
    cL.position.set(-0.95, 0.6, 0.72);
    const cR = cL.clone();
    cR.position.x = 0.95;
    body.add(cL, cR);
  } else if (meshKey === 'wasp' || meshKey === 'bird' || meshKey === 'tropicbird' || meshKey === 'songbird' || meshKey === 'bat' || meshKey === 'butterfly') {
    const hoverY = meshKey === 'butterfly' ? 2.15 : 2.0;
    const isTinyBird = meshKey === 'songbird';
    const core = meshKey === 'butterfly'
      ? new THREE.Mesh(new THREE.CylinderGeometry(0.14, 0.16, 1.35, 6), mat(0x1e293b, 0.6, 0.2))
      : new THREE.Mesh(
          meshKey === 'bat'
            ? new THREE.DodecahedronGeometry(0.52, 0)
            : isTinyBird
              ? new THREE.SphereGeometry(0.54, 7, 6)
              : new THREE.ConeGeometry(0.55, 1.6, 6),
          mainMat,
        );
    if (!isTinyBird) {
      core.rotation.x = Math.PI / 2;
    } else {
      core.scale.set(0.95, 0.88, 1.25);
    }
    core.position.y = hoverY;
    body.add(core);

    if (meshKey === 'butterfly') {
      const upperGeo = new THREE.BoxGeometry(1.45, 0.06, 1.05);
      const lowerGeo = new THREE.BoxGeometry(1.05, 0.06, 0.85);
      const wingMat = mat(primaryColor, 0.35, 0.15, primaryColor);
      const trimMat = mat(0xfacc15, 0.3, 0.2, 0xf59e0b);
      for (const side of [-1, 1]) {
        const uw = new THREE.Mesh(upperGeo, wingMat);
        uw.position.set(side * 0.86, hoverY + 0.15, 0.22);
        uw.rotation.z = side * 0.22;
        const lw = new THREE.Mesh(lowerGeo, trimMat);
        lw.position.set(side * 0.65, hoverY + 0.08, -0.52);
        lw.rotation.z = side * 0.18;
        body.add(uw, lw);
      }
    } else {
      const span = meshKey === 'tropicbird' ? 3.2 : meshKey === 'bat' ? 2.85 : isTinyBird ? 1.85 : 2.4;
      const wingColor = meshKey === 'wasp' ? 0xfef08a : meshKey === 'tropicbird' ? 0xf8fafc : meshKey === 'bat' ? 0x18181b : isTinyBird ? primaryColor : 0x52525b;
      const wingGeo = new THREE.BoxGeometry(span, 0.08, meshKey === 'bat' ? 0.95 : 0.72);
      const wings = new THREE.Mesh(wingGeo, mat(wingColor, 0.4, 0.1));
      wings.position.y = hoverY + 0.16;
      body.add(wings);

      if (meshKey === 'tropicbird') {
        // Iconic Paille-en-Queue / Petrel long twin tail streamers
        const streamerGeo = new THREE.CylinderGeometry(0.03, 0.015, 1.85, 5);
        const s1 = new THREE.Mesh(streamerGeo, mat(0xffffff, 0.3, 0.1, 0xe2e8f0));
        s1.rotation.x = Math.PI / 2;
        s1.position.set(-0.1, hoverY + 0.05, -1.55);
        const s2 = s1.clone();
        s2.position.x = 0.1;
        body.add(s1, s2);
      }
      if (isTinyBird) {
        const beak = new THREE.Mesh(new THREE.ConeGeometry(0.14, 0.45, 5), mat(0xfacc15, 0.4, 0.1));
        beak.rotation.x = Math.PI / 2;
        beak.position.set(0, hoverY + 0.04, 0.85);
        body.add(beak);
      }
      if (meshKey === 'bat') {
        const earGeo = new THREE.ConeGeometry(0.14, 0.38, 4);
        const earL = new THREE.Mesh(earGeo, mainMat);
        earL.position.set(-0.22, hoverY + 0.5, 0.35);
        const earR = earL.clone();
        earR.position.x = 0.22;
        body.add(earL, earR);
      }
    }
  } else if (meshKey === 'ibis') {
    // Solitaire de La Réunion (plump white flightless ibis with curved dark beak & dark wingtips)
    const torso = new THREE.Mesh(new THREE.SphereGeometry(0.76, 8, 7), mainMat);
    torso.scale.set(0.95, 0.95, 1.25);
    torso.position.y = 1.15;
    const head = new THREE.Mesh(new THREE.SphereGeometry(0.36, 7, 6), mainMat);
    head.position.set(0, 1.95, 0.72);
    const beak = new THREE.Mesh(new THREE.CylinderGeometry(0.05, 0.09, 0.78, 5), mat(0x1f2937, 0.5, 0.2));
    beak.rotation.x = Math.PI / 1.75;
    beak.position.set(0, 1.78, 1.18);
    const tail = new THREE.Mesh(new THREE.ConeGeometry(0.38, 0.75, 5), mat(0x334155, 0.6, 0.1));
    tail.rotation.x = -Math.PI / 2.6;
    tail.position.set(0, 1.25, -0.95);
    body.add(torso, head, beak, tail);
  } else if (meshKey === 'spider') {
    // Bibe Géante de Mafate (Nephila orb-weaver with golden striped abdomen + 8 long legs)
    const abdomen = new THREE.Mesh(new THREE.SphereGeometry(0.78, 8, 6), mainMat);
    abdomen.scale.set(0.82, 0.78, 1.3);
    abdomen.position.set(0, 1.05, -0.45);
    const cephalo = new THREE.Mesh(new THREE.SphereGeometry(0.45, 7, 6), mat(0x18181b, 0.5, 0.2));
    cephalo.position.set(0, 1.0, 0.65);
    const legGeo = new THREE.CylinderGeometry(0.045, 0.045, 1.65, 5);
    const legMat = mat(0x1f2937, 0.6, 0.2);
    for (let i = 0; i < 4; i++) {
      for (const side of [-1, 1]) {
        const leg = new THREE.Mesh(legGeo, legMat);
        leg.position.set(side * (0.75 + (i % 2) * 0.18), 0.78, -0.35 + i * 0.42);
        leg.rotation.z = side * 0.82;
        leg.rotation.y = (i - 1.5) * 0.28 * side;
        body.add(leg);
      }
    }
    body.add(abdomen, cephalo);
  } else if (meshKey === 'tortoise') {
    // Tortue Géante de Bourbon (high domed carapace + head + sturdy pillar legs)
    const shell = new THREE.Mesh(new THREE.DodecahedronGeometry(1.05, 0), mainMat);
    shell.scale.set(1.05, 0.78, 1.28);
    shell.position.y = 1.02;
    const head = new THREE.Mesh(new THREE.BoxGeometry(0.44, 0.4, 0.65), mat(0x78716c, 0.75, 0.1));
    head.position.set(0, 0.96, 1.28);
    const legGeo = new THREE.CylinderGeometry(0.2, 0.22, 0.65, 6);
    const legMat = mat(0x44403c, 0.8, 0.1);
    for (const lx of [-0.68, 0.68]) {
      for (const lz of [-0.65, 0.65]) {
        const leg = new THREE.Mesh(legGeo, legMat);
        leg.position.set(lx, 0.34, lz);
        body.add(leg);
      }
    }
    body.add(shell, head);
  } else if (meshKey === 'centipede') {
    // Scolopendre du Grand Brûlé (multi-segmented volcanic armor + twin pincers)
    const segGeo = new THREE.SphereGeometry(0.46, 7, 6);
    const darkMat = mat(0x18181b, 0.5, 0.25);
    for (let i = 0; i < 5; i++) {
      const seg = new THREE.Mesh(segGeo, i % 2 === 0 ? mainMat : darkMat);
      seg.scale.set(1.05, 0.72, 1.0);
      seg.position.set(Math.sin(i * 0.7) * 0.14, 0.56, 1.0 - i * 0.65);
      body.add(seg);
    }
    const pincerGeo = new THREE.ConeGeometry(0.1, 0.52, 4);
    for (const side of [-1, 1]) {
      const p = new THREE.Mesh(pincerGeo, mat(0xfacc15, 0.3, 0.2));
      p.rotation.x = Math.PI / 2;
      p.rotation.z = side * 0.3;
      p.position.set(side * 0.25, 0.58, 1.48);
      body.add(p);
    }
  } else if (meshKey === 'chameleon' || meshKey === 'gecko') {
    // Endormi / Gecko Vert de Bourbon (emerald reptile + tail; crest for chameleon, scarlet spots for gecko)
    const torso = new THREE.Mesh(new THREE.BoxGeometry(0.76, 0.65, 1.55), mainMat);
    torso.position.y = 0.78;
    const head = new THREE.Mesh(new THREE.BoxGeometry(0.6, 0.56, 0.64), mainMat);
    head.position.set(0, 0.96, 0.96);
    const tail = new THREE.Mesh(new THREE.ConeGeometry(0.22, 1.3, 5), mainMat);
    tail.rotation.x = -Math.PI / 2;
    tail.position.set(0, 0.72, -1.25);
    body.add(torso, head, tail);
    if (meshKey === 'chameleon') {
      const casque = new THREE.Mesh(new THREE.ConeGeometry(0.28, 0.55, 4), mat(0x34d399, 0.5, 0.15));
      casque.position.set(0, 1.38, 0.85);
      casque.rotation.x = -0.35;
      const tailCoil = new THREE.Mesh(new THREE.TorusGeometry(0.25, 0.08, 5, 10), mainMat);
      tailCoil.rotation.y = Math.PI / 2;
      tailCoil.position.set(0, 0.86, -1.65);
      body.add(casque, tailCoil);
    } else {
      const spotMat = mat(0xef4444, 0.35, 0.2, 0xdc2626);
      for (let i = 0; i < 3; i++) {
        const spot = new THREE.Mesh(new THREE.SphereGeometry(0.16, 6, 5), spotMat);
        spot.position.set(0, 1.12, -0.35 + i * 0.42);
        body.add(spot);
      }
    }
  } else if (meshKey === 'golem' || meshKey === 'boss_volcano') {
    const isVolcanoBoss = meshKey === 'boss_volcano';
    const glow = isVolcanoBoss ? 0xff4500 : undefined;
    const gMat = mat(isVolcanoBoss ? 0x271212 : primaryColor, 0.65, 0.3, glow);
    const lavaMat = mat(0xff5500, 0.15, 0.25, 0xff4500);
    lavaMat.emissiveIntensity = 1.2;

    const torso = new THREE.Mesh(new THREE.DodecahedronGeometry(1.12, 0), gMat);
    torso.position.y = 1.95;
    const head = new THREE.Mesh(new THREE.OctahedronGeometry(0.62), mat(0x1f2937, 0.45, 0.35, glow));
    head.position.y = 3.15;
    const armGeo = new THREE.BoxGeometry(0.58, 1.75, 0.58);
    const aL = new THREE.Mesh(armGeo, gMat);
    aL.position.set(-1.38, 1.65, 0.18);
    const aR = aL.clone();
    aR.position.x = 1.38;
    body.add(torso, head, aL, aR);

    if (isVolcanoBoss) {
      // Colossal Volcanic World Boss enhancements: molten heart, horns, spikes & orbiting magma rings!
      const lavaCore = new THREE.Mesh(new THREE.OctahedronGeometry(0.55, 0), lavaMat);
      lavaCore.position.set(0, 1.95, 0.85);
      const hornGeo = new THREE.ConeGeometry(0.18, 0.95, 5);
      const hL = new THREE.Mesh(hornGeo, lavaMat);
      hL.position.set(-0.55, 3.65, 0.05);
      hL.rotation.z = 0.42;
      const hR = hL.clone();
      hR.position.x = 0.55;
      hR.rotation.z = -0.42;

      const spGeo = new THREE.ConeGeometry(0.32, 1.15, 5);
      const sL = new THREE.Mesh(spGeo, lavaMat);
      sL.position.set(-1.45, 2.85, 0);
      sL.rotation.z = 0.52;
      const sR = sL.clone();
      sR.position.x = 1.45;
      sR.rotation.z = -0.52;

      const crown = new THREE.Mesh(new THREE.TorusGeometry(0.82, 0.13, 6, 14), lavaMat);
      crown.rotation.x = Math.PI / 2;
      crown.position.y = 3.72;

      // Orbiting twin volcanic rings + magma shards
      orbitGroup = new THREE.Group();
      orbitGroup.position.set(0, 2.25, 0);
      const ring1 = new THREE.Mesh(
        new THREE.TorusGeometry(2.15, 0.09, 6, 28),
        new THREE.MeshBasicMaterial({ color: 0xf97316, transparent: true, opacity: 0.78 }),
      );
      ring1.rotation.x = Math.PI / 2.3;
      const ring2 = new THREE.Mesh(
        new THREE.TorusGeometry(1.85, 0.07, 6, 24),
        new THREE.MeshBasicMaterial({ color: 0xfacc15, transparent: true, opacity: 0.65 }),
      );
      ring2.rotation.set(Math.PI / 3, 0.4, 0.6);
      for (let i = 0; i < 4; i++) {
        const ang = (i * Math.PI) / 2;
        const shard = new THREE.Mesh(new THREE.OctahedronGeometry(0.24, 0), lavaMat);
        shard.position.set(Math.cos(ang) * 2.15, 0, Math.sin(ang) * 2.15);
        orbitGroup.add(shard);
      }
      orbitGroup.add(ring1, ring2);
      body.add(lavaCore, hL, hR, sL, sR, crown, orbitGroup);

      // Fiery volcanic danger perimeter on ground
      const dangerHalo = new THREE.Mesh(
        new THREE.RingGeometry(1.75, 2.15, 36),
        new THREE.MeshBasicMaterial({ color: 0xef4444, side: THREE.DoubleSide, transparent: true, opacity: 0.55 }),
      );
      dangerHalo.rotation.x = -Math.PI / 2;
      dangerHalo.position.y = 0.06;
      body.add(dangerHalo);
    } else if (isBoss) {
      const crown = new THREE.Mesh(new THREE.TorusGeometry(0.75, 0.12, 6, 12), mat(0x38bdf8, 0.2, 0.4, 0x38bdf8));
      crown.rotation.x = Math.PI / 2;
      crown.position.y = 3.6;
      body.add(crown);
    }
  } else {
    // Quadruped beast (tangue, boar, stag, lizard, hound)
    const torso = new THREE.Mesh(new THREE.BoxGeometry(1.0, 0.85, 1.65), mainMat);
    torso.position.y = 0.95;
    const head = new THREE.Mesh(new THREE.BoxGeometry(0.66, 0.62, 0.72), mainMat);
    head.position.set(0, 1.18, 0.98);
    body.add(torso, head);
    if (meshKey === 'stag') {
      const antlerGeo = new THREE.CylinderGeometry(0.04, 0.05, 0.85, 5);
      const a1 = new THREE.Mesh(antlerGeo, mat(0xfef3c7));
      a1.position.set(-0.28, 1.85, 0.98);
      a1.rotation.z = 0.45;
      const a2 = a1.clone();
      a2.position.x = 0.28;
      a2.rotation.z = -0.45;
      body.add(a1, a2);
    }
    if (meshKey === 'tangue') {
      for (let i = 0; i < 6; i++) {
        const spine = new THREE.Mesh(new THREE.ConeGeometry(0.12, 0.45, 4), mat(0xfde68a));
        spine.position.set((i % 2 === 0 ? -0.22 : 0.22), 1.5, -0.5 + i * 0.2);
        body.add(spine);
      }
    }
  }

  // Glowing eyes facing +Z
  const eyeGeo = new THREE.BoxGeometry(0.14, 0.12, 0.14);
  const eyeY =
    meshKey === 'golem' || meshKey === 'boss_volcano'
      ? 3.05
      : meshKey === 'butterfly'
        ? 2.2
        : meshKey === 'wasp' || meshKey === 'bird' || meshKey === 'tropicbird' || meshKey === 'songbird' || meshKey === 'bat'
          ? 2.05
          : meshKey === 'ibis'
            ? 2.02
            : meshKey === 'centipede'
              ? 0.72
              : 1.05;
  const eyeZ =
    meshKey === 'golem' || meshKey === 'boss_volcano'
      ? 0.52
      : meshKey === 'tortoise'
        ? 1.56
        : meshKey === 'centipede'
          ? 1.36
          : 1.15;
  const eL = new THREE.Mesh(eyeGeo, eyeMat);
  eL.position.set(-0.2, eyeY, eyeZ);
  const eR = eL.clone();
  eR.position.x = 0.2;
  body.add(eL, eR);

  body.scale.setScalar(scale);

  const ringRadius = (isBoss ? 1.85 : 1.15) * Math.max(1, scale * 0.85);
  const targetRing = new THREE.Mesh(
    new THREE.RingGeometry(ringRadius, ringRadius + 0.28, 28),
    new THREE.MeshBasicMaterial({ color: isBoss ? 0xfacc15 : 0xef4444, side: THREE.DoubleSide, transparent: true, opacity: 0.9 }),
  );
  targetRing.rotation.x = -Math.PI / 2;
  targetRing.position.y = 0.12;
  targetRing.visible = false;
  group.add(targetRing);

  return { group, body, targetRing, hitFlash: 0, orbitGroup };
}

export interface NpcVisual {
  group: THREE.Group;
  markerMesh: THREE.Mesh;
  markerMat: THREE.MeshStandardMaterial;
}

export function createNpcMesh(coatColor: string, accentColor: string): NpcVisual {
  const group = new THREE.Group();

  const skinMat = mat(0xd49b72, 0.7, 0.05);
  const coatMat = mat(coatColor, 0.65, 0.12);
  const accMat = mat(accentColor, 0.4, 0.25, accentColor);

  // Robe / lower tunic
  const skirt = new THREE.Mesh(new THREE.CylinderGeometry(0.42, 0.62, 1.05, 8), coatMat);
  skirt.position.y = 0.55;
  group.add(skirt);

  // Torso
  const torso = new THREE.Mesh(new THREE.BoxGeometry(0.84, 0.98, 0.52), coatMat);
  torso.position.y = 1.48;
  group.add(torso);

  // Sash / scarf
  const sash = new THREE.Mesh(new THREE.BoxGeometry(0.88, 0.22, 0.56), accMat);
  sash.position.y = 1.88;
  group.add(sash);

  // Head + straw/island explorer hat
  const head = new THREE.Mesh(new THREE.BoxGeometry(0.54, 0.54, 0.54), skinMat);
  head.position.y = 2.28;
  group.add(head);

  const hatBrim = new THREE.Mesh(new THREE.CylinderGeometry(0.68, 0.72, 0.08, 10), mat(0xeab308, 0.85, 0.05));
  hatBrim.position.y = 2.58;
  const hatCrown = new THREE.Mesh(new THREE.CylinderGeometry(0.32, 0.4, 0.28, 8), mat(0xca8a04, 0.85, 0.05));
  hatCrown.position.y = 2.74;
  group.add(hatBrim, hatCrown);

  // Guiding lantern staff on right side
  const staff = new THREE.Mesh(new THREE.CylinderGeometry(0.05, 0.06, 2.5, 6), mat(0x78350f));
  staff.position.set(0.62, 1.25, 0.24);
  const lantern = new THREE.Mesh(new THREE.OctahedronGeometry(0.22), accMat);
  lantern.position.set(0.62, 2.58, 0.24);
  group.add(staff, lantern);

  // Warm ground halo ring
  const ring = new THREE.Mesh(
    new THREE.RingGeometry(1.05, 1.32, 24),
    new THREE.MeshBasicMaterial({ color: 0xfacc15, side: THREE.DoubleSide, transparent: true, opacity: 0.55 }),
  );
  ring.rotation.x = -Math.PI / 2;
  ring.position.y = 0.09;
  group.add(ring);

  // Floating 3D quest marker crystal above NPC's head
  const markerMat = new THREE.MeshStandardMaterial({
    color: 0xfacc15,
    emissive: 0xfacc15,
    emissiveIntensity: 0.9,
    roughness: 0.2,
    flatShading: true,
  });
  const markerMesh = new THREE.Mesh(new THREE.OctahedronGeometry(0.38, 0), markerMat);
  markerMesh.position.y = 3.7;
  markerMesh.visible = false;
  group.add(markerMesh);

  return { group, markerMesh, markerMat };
}

export interface LootVisual {
  group: THREE.Group;
  chest: THREE.Group;
}

export function createLootDropMesh(rarity: string): LootVisual {
  const group = new THREE.Group();
  const chest = new THREE.Group();
  group.add(chest);

  const rarityColors: Record<string, number> = {
    COMMON: 0xe2e8f0,
    UNCOMMON: 0x22c55e,
    RARE: 0x38bdf8,
    EPIC: 0xc084fc,
    LEGENDARY: 0xfb923c,
  };
  const glowColor = rarityColors[rarity] ?? 0xe2e8f0;

  // Compact treasure chest / loot pouch
  const box = new THREE.Mesh(new THREE.BoxGeometry(0.95, 0.62, 0.68), mat(0x78350f, 0.7, 0.2));
  box.position.y = 0.45;
  const trim = new THREE.Mesh(new THREE.BoxGeometry(1.0, 0.16, 0.72), mat(0xfacc15, 0.35, 0.65, glowColor));
  trim.position.y = 0.62;
  const clasp = new THREE.Mesh(new THREE.OctahedronGeometry(0.16), mat(glowColor, 0.15, 0.4, glowColor));
  clasp.position.set(0, 0.56, 0.38);
  chest.add(box, trim, clasp);

  // Glowing ground ring
  const ring = new THREE.Mesh(
    new THREE.RingGeometry(0.7, 0.95, 20),
    new THREE.MeshBasicMaterial({ color: glowColor, side: THREE.DoubleSide, transparent: true, opacity: 0.75 }),
  );
  ring.rotation.x = -Math.PI / 2;
  ring.position.y = 0.08;
  group.add(ring);

  // Vertical rarity loot beam
  const beamHeight = rarity === 'LEGENDARY' ? 11 : rarity === 'EPIC' ? 8.5 : rarity === 'RARE' ? 6.5 : 4.2;
  const beam = new THREE.Mesh(
    new THREE.CylinderGeometry(0.08, 0.38, beamHeight, 8, 1, true),
    new THREE.MeshBasicMaterial({ color: glowColor, transparent: true, opacity: 0.3, side: THREE.DoubleSide }),
  );
  beam.position.y = beamHeight * 0.5 + 0.3;
  group.add(beam);

  return { group, chest };
}
