import * as THREE from 'three';

export interface BipedRigBones {
  spine01?: THREE.Bone;
  spine02?: THREE.Bone;
  neck?: THREE.Bone;
  head?: THREE.Bone;
  lUpperarm?: THREE.Bone;
  lForearm?: THREE.Bone;
  rUpperarm?: THREE.Bone;
  rForearm?: THREE.Bone;
  lThigh?: THREE.Bone;
  rThigh?: THREE.Bone;
  lCalf?: THREE.Bone;
  rCalf?: THREE.Bone;
}

export interface ProceduralPoseResult {
  bodyOffsetY: number;
  bodyOffsetZ: number;
  bodyYawOffset: number;
  active: boolean;
}

/**
 * Reusable procedural skill animation engine built on the shared Tripo Biped skeleton.
 * Works additively on top of any base AnimationMixer (idle or walking) so every future
 * character sharing the same rigging automatically inherits all 12 class skill animations!
 *
 * Calibrated world-space bone axis conventions (verified on Tikaf.fbx facing +Z):
 *   Spine01 / Spine02 / Head : +X leans BACK, -X leans FORWARD, +Y twists LEFT, -Y twists RIGHT
 *   R_Upperarm (right = -X)  : +X raises FORWARD/UP, -Z lifts OUTWARD right, +Z sweeps ACROSS chest
 *   R_Forearm                : +X bends elbow FORWARD/UP
 *   L_Upperarm (left = +X)   : +X raises FORWARD/UP, +Z lifts OUTWARD left, -Z sweeps ACROSS chest
 *   L_Forearm                : +X bends elbow FORWARD/UP
 *   L_Thigh / R_Thigh        : +X raises thigh FORWARD/UP
 *   L_Calf / R_Calf          : -X bends knee BACKWARD (crouch / jump tuck)
 */
export class ProceduralBoneAnimator {
  private readonly bones: BipedRigBones;
  private readonly trackedBones: { bone: THREE.Bone; baseQuat: THREE.Quaternion }[] = [];
  private hasCapturedBase = false;
  private activeSkillCode: string | null = null;
  private elapsed = 0;
  private duration = 0.5;

  private readonly tmpEuler = new THREE.Euler(0, 0, 0, 'YXZ');
  private readonly tmpQuat = new THREE.Quaternion();

  public constructor(root: THREE.Object3D) {
    this.bones = ProceduralBoneAnimator.extractBipedBones(root);
    for (const b of Object.values(this.bones)) {
      if (b) {
        this.trackedBones.push({ bone: b, baseQuat: b.quaternion.clone() });
      }
    }
  }

  /** Restores clean pre-additive bone rotations BEFORE AnimationMixer updates this frame. */
  public restoreBasePose(): void {
    if (!this.hasCapturedBase) return;
    for (const entry of this.trackedBones) {
      entry.bone.quaternion.copy(entry.baseQuat);
    }
  }

  /** Captures clean post-AnimationMixer bone rotations BEFORE additive skill offsets are applied. */
  public captureBasePose(): void {
    for (const entry of this.trackedBones) {
      entry.baseQuat.copy(entry.bone.quaternion);
    }
    this.hasCapturedBase = true;
  }

  public static extractBipedBones(root: THREE.Object3D): BipedRigBones {
    const byName: Record<string, THREE.Bone> = {};
    root.traverse((obj) => {
      if ((obj as THREE.Bone).isBone) {
        byName[obj.name] = obj as THREE.Bone;
      }
    });

    const pick = (...names: string[]): THREE.Bone | undefined => {
      for (const n of names) {
        if (byName[n]) return byName[n];
      }
      return undefined;
    };

    return {
      spine01: pick('Spine01', 'Spine', 'mixamorigSpine'),
      spine02: pick('Spine02', 'Spine1', 'Spine2', 'mixamorigSpine2'),
      neck: pick('NeckTwist01', 'Neck', 'mixamorigNeck'),
      head: pick('Head', 'mixamorigHead'),
      lUpperarm: pick('L_Upperarm', 'LeftArm', 'mixamorigLeftArm'),
      lForearm: pick('L_Forearm', 'LeftForeArm', 'mixamorigLeftForeArm'),
      rUpperarm: pick('R_Upperarm', 'RightArm', 'mixamorigRightArm'),
      rForearm: pick('R_Forearm', 'RightForeArm', 'mixamorigRightForeArm'),
      lThigh: pick('L_Thigh', 'LeftUpLeg', 'mixamorigLeftUpLeg'),
      rThigh: pick('R_Thigh', 'RightUpLeg', 'mixamorigRightUpLeg'),
      lCalf: pick('L_Calf', 'LeftLeg', 'mixamorigLeftLeg'),
      rCalf: pick('R_Calf', 'RightLeg', 'mixamorigRightLeg'),
    };
  }

  public triggerSkill(skillCode: string): void {
    this.activeSkillCode = skillCode;
    this.elapsed = 0;
    this.duration = ProceduralBoneAnimator.durationForSkill(skillCode);
  }

  public isPlaying(): boolean {
    return this.activeSkillCode !== null;
  }

  public getActiveSkillCode(): string | null {
    return this.activeSkillCode;
  }

  public getProgress(): number {
    return this.duration > 0 ? Math.min(1, this.elapsed / this.duration) : 1;
  }

  public static durationForSkill(skillCode: string): number {
    switch (skillCode) {
      case 'ember_strike':
      case 'shield_bash':
      case 'solar_flare':
        return 0.50;
      case 'burning_brand':
        return 0.60;
      case 'faham_infusion':
      case 'basalt_skin':
        return 0.68;
      case 'piton_taunt':
      case 'salazie_mist':
        return 0.76;
      case 'volcanic_fury':
        return 0.80;
      case 'seismic_slam':
        return 0.86;
      case 'scoria_rain':
      case 'lagoon_blessing':
        return 0.94;
      default:
        return 0.52;
    }
  }

  /**
   * Advances the procedural skill animation and applies additive bone rotations
   * directly on top of the current AnimationMixer pose for this frame.
   */
  public apply(dt: number): ProceduralPoseResult {
    if (!this.activeSkillCode) {
      return { bodyOffsetY: 0, bodyOffsetZ: 0, bodyYawOffset: 0, active: false };
    }

    this.elapsed += dt;
    if (this.elapsed >= this.duration) {
      this.activeSkillCode = null;
      return { bodyOffsetY: 0, bodyOffsetZ: 0, bodyYawOffset: 0, active: false };
    }

    const t = this.elapsed / this.duration;
    // Smooth fade-out tail on last 22% so bones return seamlessly to walk/idle
    const tail = t > 0.78 ? 1 - (t - 0.78) / 0.22 : 1;
    const easeTail = tail * tail * (3 - 2 * tail);

    switch (this.activeSkillCode) {
      // ================== DPS — LAME DE LAVE ==================
      case 'ember_strike':
        return this.animEmberStrike(t, easeTail);
      case 'burning_brand':
        return this.animBurningBrand(t, easeTail);
      case 'volcanic_fury':
        return this.animVolcanicFury(t, easeTail);
      case 'scoria_rain':
        return this.animScoriaRain(t, easeTail);

      // ================== TANK — GARDIEN DU BASALTE ==================
      case 'shield_bash':
        return this.animShieldBash(t, easeTail);
      case 'piton_taunt':
        return this.animPitonTaunt(t, easeTail);
      case 'basalt_skin':
        return this.animBasaltSkin(t, easeTail);
      case 'seismic_slam':
        return this.animSeismicSlam(t, easeTail);

      // ================== HEAL — TISANEUR DES CIRQUES ==================
      case 'solar_flare':
        return this.animSolarFlare(t, easeTail);
      case 'faham_infusion':
        return this.animFahamInfusion(t, easeTail);
      case 'salazie_mist':
        return this.animSalazieMist(t, easeTail);
      case 'lagoon_blessing':
        return this.animLagoonBlessing(t, easeTail);

      default:
        return this.animEmberStrike(t, easeTail);
    }
  }

  // 1. DPS Slot 1: Quick diagonal right-to-left fiery slash
  private animEmberStrike(t: number, tail: number): ProceduralPoseResult {
    const wind = this.bell(t, 0.0, 0.28);
    const slash = this.bell(t, 0.22, 0.85);

    this.rot(this.bones.spine01, (-0.28 * slash + 0.14 * wind) * tail, (0.48 * slash - 0.42 * wind) * tail, 0);
    this.rot(this.bones.spine02, (-0.22 * slash) * tail, (0.45 * slash - 0.35 * wind) * tail, 0);
    // Right arm pulls wide right (-Z) & back, then slashes fiercely forward & across chest (+Z)!
    this.rot(this.bones.rUpperarm, (-0.5 * wind + 1.55 * slash) * tail, 0, (-0.85 * wind + 0.95 * slash) * tail);
    this.rot(this.bones.rForearm, (0.65 * wind + 0.55 * slash) * tail, 0, 0);
    // Left arm counter-balances outward left (+Z) & back
    this.rot(this.bones.lUpperarm, (0.45 * wind - 0.45 * slash) * tail, 0, (0.45 * slash) * tail);
    this.rot(this.bones.lForearm, (0.55 * wind + 0.4 * slash) * tail, 0, 0);

    return {
      bodyOffsetY: Math.sin(t * Math.PI) * 0.14,
      bodyOffsetZ: slash * 0.55 * tail,
      bodyYawOffset: (slash * 0.25 - wind * 0.22) * tail,
      active: true,
    };
  }

  // 2. DPS Slot 2: Two-handed fireball thrust
  private animBurningBrand(t: number, tail: number): ProceduralPoseResult {
    const charge = this.bell(t, 0.0, 0.42);
    const push = this.bell(t, 0.32, 0.92);

    this.rot(this.bones.spine01, (0.28 * charge - 0.32 * push) * tail, 0, 0);
    this.rot(this.bones.spine02, (0.2 * charge - 0.22 * push) * tail, 0, 0);
    this.rot(this.bones.head, (-0.15 * charge + 0.18 * push) * tail, 0, 0);

    // Pull elbows wide back to charge -> thrust both palms straight forward & slightly converged!
    this.rot(this.bones.rUpperarm, (0.45 * charge + 1.55 * push) * tail, 0, (-0.28 * charge + 0.22 * push) * tail);
    this.rot(this.bones.rForearm, (1.25 * charge + 0.12 * push) * tail, 0, 0);
    this.rot(this.bones.lUpperarm, (0.45 * charge + 1.55 * push) * tail, 0, (0.28 * charge - 0.22 * push) * tail);
    this.rot(this.bones.lForearm, (1.25 * charge + 0.12 * push) * tail, 0, 0);

    return {
      bodyOffsetY: push * 0.1,
      bodyOffsetZ: (-0.22 * charge + 0.48 * push) * tail,
      bodyYawOffset: 0,
      active: true,
    };
  }

  // 3. DPS Slot 3: Overhead jumping volcanic cleave (Execute)
  private animVolcanicFury(t: number, tail: number): ProceduralPoseResult {
    const rise = this.bell(t, 0.0, 0.50);
    const slam = this.bell(t, 0.42, 0.94);

    // Arch back & raise both arms high overhead during leap, then violently slam downward!
    this.rot(this.bones.spine01, (0.35 * rise - 0.55 * slam) * tail, 0, 0);
    this.rot(this.bones.spine02, (0.25 * rise - 0.4 * slam) * tail, 0, 0);
    this.rot(this.bones.head, (0.25 * rise + 0.22 * slam) * tail, 0, 0);

    this.rot(this.bones.rUpperarm, (2.35 * rise + 0.55 * slam) * tail, 0, (-0.28 * rise + 0.3 * slam) * tail);
    this.rot(this.bones.rForearm, (0.55 * rise + 0.3 * slam) * tail, 0, 0);
    this.rot(this.bones.lUpperarm, (2.35 * rise + 0.55 * slam) * tail, 0, (0.28 * rise - 0.3 * slam) * tail);
    this.rot(this.bones.lForearm, (0.55 * rise + 0.3 * slam) * tail, 0, 0);

    // Athletic jump parabola peaking at t=0.31 + mid-air knee tuck
    const jumpArc = t < 0.62 ? Math.sin((t / 0.62) * Math.PI) : 0;
    this.rot(this.bones.rThigh, 0.55 * jumpArc * tail, 0, 0);
    this.rot(this.bones.rCalf, -1.0 * jumpArc * tail, 0, 0);
    this.rot(this.bones.lThigh, 0.55 * jumpArc * tail, 0, 0);
    this.rot(this.bones.lCalf, -1.0 * jumpArc * tail, 0, 0);

    return {
      bodyOffsetY: jumpArc * 1.15,
      bodyOffsetZ: slam * 0.72 * tail,
      bodyYawOffset: 0,
      active: true,
    };
  }

  // 4. DPS Slot 4: Skyward meteor summon (AoE)
  private animScoriaRain(t: number, tail: number): ProceduralPoseResult {
    const channel = this.bell(t, 0.0, 0.62);
    const drop = this.bell(t, 0.52, 0.96);
    const tremor = channel * Math.sin(t * 48) * 0.06;

    this.rot(this.bones.spine01, (0.28 * channel - 0.35 * drop) * tail, 0, tremor);
    this.rot(this.bones.spine02, (0.22 * channel - 0.25 * drop) * tail, 0, -tremor);
    this.rot(this.bones.head, (0.48 * channel - 0.15 * drop) * tail, 0, 0);

    // Wide V arms reaching up toward the volcano sky (R:-Z, L:+Z), then crashing downward!
    this.rot(this.bones.rUpperarm, (1.85 * channel + 0.85 * drop) * tail, 0, (-0.75 * channel + 0.2 * drop) * tail);
    this.rot(this.bones.rForearm, (0.35 * channel + 0.5 * drop) * tail, 0, 0);
    this.rot(this.bones.lUpperarm, (1.85 * channel + 0.85 * drop) * tail, 0, (0.75 * channel - 0.2 * drop) * tail);
    this.rot(this.bones.lForearm, (0.35 * channel + 0.5 * drop) * tail, 0, 0);

    return {
      bodyOffsetY: channel * 0.28,
      bodyOffsetZ: drop * 0.35 * tail,
      bodyYawOffset: 0,
      active: true,
    };
  }

  // 5. Tank Slot 1: Left-arm basalt shield bash
  private animShieldBash(t: number, tail: number): ProceduralPoseResult {
    const prep = this.bell(t, 0.0, 0.30);
    const bash = this.bell(t, 0.24, 0.88);

    this.rot(this.bones.spine01, (-0.3 * bash) * tail, (-0.55 * bash + 0.35 * prep) * tail, 0);
    this.rot(this.bones.spine02, (-0.2 * bash) * tail, (-0.4 * bash + 0.25 * prep) * tail, 0);
    // Left arm drives shield forward & across front (-Z), right arm pulls outward back (-Z)
    this.rot(this.bones.lUpperarm, (1.45 * bash - 0.35 * prep) * tail, 0, (0.4 * prep - 0.65 * bash) * tail);
    this.rot(this.bones.lForearm, (1.15 * bash + 0.7 * prep) * tail, 0, 0);
    this.rot(this.bones.rUpperarm, (-0.45 * bash) * tail, 0, (-0.35 * bash) * tail);

    return {
      bodyOffsetY: 0,
      bodyOffsetZ: bash * 0.65 * tail,
      bodyYawOffset: (-0.28 * bash + 0.2 * prep) * tail,
      active: true,
    };
  }

  // 6. Tank Slot 2: Challenging war-cry roar (Taunt)
  private animPitonTaunt(t: number, tail: number): ProceduralPoseResult {
    const roar = this.bell(t, 0.08, 0.90);
    const shake = roar * Math.sin(t * 42) * 0.05;

    this.rot(this.bones.spine01, (0.32 * roar) * tail, shake, 0);
    this.rot(this.bones.spine02, (0.28 * roar) * tail, -shake, 0);
    this.rot(this.bones.head, (0.46 * roar) * tail, shake * 1.5, 0);

    // Flexed arms spread wide open (R:-Z, L:+Z) in intimidating war-cry pose
    this.rot(this.bones.rUpperarm, (0.65 * roar) * tail, 0, (-1.05 * roar) * tail);
    this.rot(this.bones.rForearm, (1.25 * roar) * tail, 0, 0);
    this.rot(this.bones.lUpperarm, (0.65 * roar) * tail, 0, (1.05 * roar) * tail);
    this.rot(this.bones.lForearm, (1.25 * roar) * tail, 0, 0);

    return {
      bodyOffsetY: roar * 0.12,
      bodyOffsetZ: 0,
      bodyYawOffset: 0,
      active: true,
    };
  }

  // 7. Tank Slot 3: Defensive basalt brace (crossed arms)
  private animBasaltSkin(t: number, tail: number): ProceduralPoseResult {
    const brace = this.bell(t, 0.05, 0.92);

    this.rot(this.bones.spine01, (-0.24 * brace) * tail, 0, 0);
    this.rot(this.bones.head, (-0.18 * brace) * tail, 0, 0);
    // Cross both forearms tightly across the chest (R:+Z, L:-Z) & bend knees in solid stance
    this.rot(this.bones.rUpperarm, (1.15 * brace) * tail, 0, (0.75 * brace) * tail);
    this.rot(this.bones.rForearm, (1.35 * brace) * tail, 0, 0);
    this.rot(this.bones.lUpperarm, (1.15 * brace) * tail, 0, (-0.75 * brace) * tail);
    this.rot(this.bones.lForearm, (1.35 * brace) * tail, 0, 0);
    this.rot(this.bones.rThigh, 0.35 * brace * tail, 0, 0);
    this.rot(this.bones.rCalf, -0.65 * brace * tail, 0, 0);
    this.rot(this.bones.lThigh, 0.35 * brace * tail, 0, 0);
    this.rot(this.bones.lCalf, -0.65 * brace * tail, 0, 0);

    return {
      bodyOffsetY: -0.18 * brace * tail,
      bodyOffsetZ: 0,
      bodyYawOffset: 0,
      active: true,
    };
  }

  // 8. Tank Slot 4: Heavy jump + ground shockwave slam
  private animSeismicSlam(t: number, tail: number): ProceduralPoseResult {
    const lift = this.bell(t, 0.0, 0.48);
    const impact = this.bell(t, 0.42, 0.95);
    const jumpArc = t < 0.56 ? Math.sin((t / 0.56) * Math.PI) : 0;
    const jump = t < 0.56 ? jumpArc * 0.95 : -impact * 0.24;

    this.rot(this.bones.spine01, (0.28 * lift - 0.6 * impact) * tail, 0, 0);
    this.rot(this.bones.spine02, (0.2 * lift - 0.35 * impact) * tail, 0, 0);
    this.rot(this.bones.rUpperarm, (2.15 * lift + 0.65 * impact) * tail, 0, (-0.4 * lift) * tail);
    this.rot(this.bones.lUpperarm, (2.15 * lift + 0.65 * impact) * tail, 0, (0.4 * lift) * tail);
    this.rot(this.bones.rForearm, (0.6 * lift + 0.35 * impact) * tail, 0, 0);
    this.rot(this.bones.lForearm, (0.6 * lift + 0.35 * impact) * tail, 0, 0);

    const kneeBend = Math.max(jumpArc * 0.85, impact * 0.65);
    this.rot(this.bones.rThigh, 0.48 * kneeBend * tail, 0, 0);
    this.rot(this.bones.rCalf, -0.9 * kneeBend * tail, 0, 0);
    this.rot(this.bones.lThigh, 0.48 * kneeBend * tail, 0, 0);
    this.rot(this.bones.lCalf, -0.9 * kneeBend * tail, 0, 0);

    return {
      bodyOffsetY: jump,
      bodyOffsetZ: impact * 0.3 * tail,
      bodyYawOffset: 0,
      active: true,
    };
  }

  // 9. Heal Slot 1: Quick right-hand solar bolt cast
  private animSolarFlare(t: number, tail: number): ProceduralPoseResult {
    const cast = this.bell(t, 0.08, 0.88);
    this.rot(this.bones.spine01, (-0.15 * cast) * tail, (0.38 * cast) * tail, 0);
    this.rot(this.bones.rUpperarm, (1.65 * cast) * tail, 0, (-0.12 * cast) * tail);
    this.rot(this.bones.rForearm, (0.25 * cast) * tail, 0, 0);
    this.rot(this.bones.lUpperarm, (-0.3 * cast) * tail, 0, (0.35 * cast) * tail);

    return {
      bodyOffsetY: cast * 0.08,
      bodyOffsetZ: cast * 0.22 * tail,
      bodyYawOffset: 0.16 * cast * tail,
      active: true,
    };
  }

  // 10. Heal Slot 2: Gentle two-hand healing offering
  private animFahamInfusion(t: number, tail: number): ProceduralPoseResult {
    const gather = this.bell(t, 0.0, 0.45);
    const offer = this.bell(t, 0.35, 0.94);

    this.rot(this.bones.spine01, (0.14 * gather - 0.18 * offer) * tail, 0, 0);
    this.rot(this.bones.head, (-0.12 * gather + 0.14 * offer) * tail, 0, 0);
    // Gather inward over heart (R:+Z, L:-Z), then open palms outward forward (R:-Z, L:+Z)
    this.rot(this.bones.rUpperarm, (0.85 * gather + 1.35 * offer) * tail, 0, (0.25 * gather - 0.25 * offer) * tail);
    this.rot(this.bones.rForearm, (1.05 * gather + 0.2 * offer) * tail, 0, 0);
    this.rot(this.bones.lUpperarm, (0.85 * gather + 1.35 * offer) * tail, 0, (-0.25 * gather + 0.25 * offer) * tail);
    this.rot(this.bones.lForearm, (1.05 * gather + 0.2 * offer) * tail, 0, 0);

    return {
      bodyOffsetY: Math.sin(t * Math.PI) * 0.22,
      bodyOffsetZ: offer * 0.18 * tail,
      bodyYawOffset: 0,
      active: true,
    };
  }

  // 11. Heal Slot 3: Sweeping misty blessing wave
  private animSalazieMist(t: number, tail: number): ProceduralPoseResult {
    const sweep = this.bell(t, 0.05, 0.92);
    const wave = Math.sin(t * Math.PI * 2) * 0.35;

    this.rot(this.bones.spine01, (0.1 * sweep) * tail, wave * tail, 0);
    // Open both arms outward gracefully (R:-Z, L:+Z)
    this.rot(this.bones.rUpperarm, (1.1 * sweep) * tail, 0, (-0.95 * sweep) * tail);
    this.rot(this.bones.rForearm, (0.45 * sweep) * tail, 0, 0);
    this.rot(this.bones.lUpperarm, (1.1 * sweep) * tail, 0, (0.95 * sweep) * tail);
    this.rot(this.bones.lForearm, (0.45 * sweep) * tail, 0, 0);

    return {
      bodyOffsetY: sweep * 0.2,
      bodyOffsetZ: 0,
      bodyYawOffset: wave * 0.4 * tail,
      active: true,
    };
  }

  // 12. Heal Slot 4: Levitating skyward lagoon party blessing (AoE)
  private animLagoonBlessing(t: number, tail: number): ProceduralPoseResult {
    const ascend = this.bell(t, 0.05, 0.92);

    this.rot(this.bones.spine01, (0.22 * ascend) * tail, 0, 0);
    this.rot(this.bones.head, (0.38 * ascend) * tail, 0, 0);
    // Wide angelic arms reaching high toward the sky (R:-Z, L:+Z)
    this.rot(this.bones.rUpperarm, (1.95 * ascend) * tail, 0, (-0.85 * ascend) * tail);
    this.rot(this.bones.lUpperarm, (1.95 * ascend) * tail, 0, (0.85 * ascend) * tail);
    this.rot(this.bones.rForearm, (0.25 * ascend) * tail, 0, 0);
    this.rot(this.bones.lForearm, (0.25 * ascend) * tail, 0, 0);
    this.rot(this.bones.rThigh, 0.2 * ascend * tail, 0, 0);
    this.rot(this.bones.rCalf, -0.45 * ascend * tail, 0, 0);
    this.rot(this.bones.lThigh, 0.2 * ascend * tail, 0, 0);
    this.rot(this.bones.lCalf, -0.45 * ascend * tail, 0, 0);

    return {
      bodyOffsetY: Math.sin(t * Math.PI) * 0.65,
      bodyOffsetZ: 0,
      bodyYawOffset: 0,
      active: true,
    };
  }

  private bell(t: number, start: number, end: number): number {
    if (t <= start || t >= end) return 0;
    const u = (t - start) / (end - start);
    return Math.sin(u * Math.PI);
  }

  private rot(bone: THREE.Bone | undefined, x: number, y: number, z: number): void {
    if (!bone || (Math.abs(x) < 0.0005 && Math.abs(y) < 0.0005 && Math.abs(z) < 0.0005)) return;
    this.tmpEuler.set(x, y, z, 'YXZ');
    this.tmpQuat.setFromEuler(this.tmpEuler);
    bone.quaternion.multiply(this.tmpQuat);
  }
}
