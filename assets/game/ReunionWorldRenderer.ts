import * as THREE from 'three';
import { TerrainSampler } from './TerrainSampler';
import type { LandmarkMeta, ZoneMeta } from './types';

/**
 * Renders the 3D Island of La Réunion:
 * - Displaced terrain mesh colored by real biomes, slope basalt cliffs, trails & Dolomieu magma
 * - Animated Indian Ocean & shallow turquoise Western Lagoon
 * - Instanced low-poly palms/filaos, tropical trees, basalt rocks, Saint-Gilles Creole huts & campfires
 */
export class ReunionWorldRenderer {
  public readonly terrainMesh: THREE.Mesh;
  public readonly waterMesh: THREE.Mesh;
  private readonly volcanoParticles: THREE.Points;
  private readonly sunLight: THREE.DirectionalLight;
  private readonly hemiLight: THREE.HemisphereLight;
  private readonly scene: THREE.Scene;
  private readonly volcanoBaseY: number;

  private targetSky = new THREE.Color('#4da6e8');
  private targetFog = new THREE.Color('#a5dff9');
  private targetAmbient = new THREE.Color('#ffe8c2');

  public constructor(scene: THREE.Scene, sampler: TerrainSampler, landmarks: LandmarkMeta[]) {
    this.scene = scene;

    const hubLandmark = landmarks.find((l) => l.type === 'hub');
    const bossLandmark = landmarks.find((l) => l.type === 'boss');
    const hubX = hubLandmark?.x ?? -2047.5;
    const hubZ = hubLandmark?.z ?? -332.5;
    const bossX = bossLandmark?.x ?? 1540.0;
    const bossZ = bossLandmark?.z ?? 962.5;
    this.volcanoBaseY = sampler.getHeight(bossX, bossZ);

    // Sky & atmospheric fog tuned for the expanded 7168 x 7168 island vista
    scene.background = this.targetSky.clone();
    scene.fog = new THREE.FogExp2(this.targetFog.getHex(), 0.00075);

    // Balanced tropical island lighting (sun + ambient + opposite sky fill so characters are never silhouette-dark)
    const ambientLight = new THREE.AmbientLight(0xffffff, 0.72);
    scene.add(ambientLight);

    this.hemiLight = new THREE.HemisphereLight(0xeef6ff, 0x7c8a6c, 1.05);
    scene.add(this.hemiLight);

    this.sunLight = new THREE.DirectionalLight(0xfff5e0, 1.75);
    this.sunLight.position.set(-1200, 1400, 1100);
    scene.add(this.sunLight);

    const fillLight = new THREE.DirectionalLight(0xcce7ff, 0.65);
    fillLight.position.set(1200, 900, -1100);
    scene.add(fillLight);

    // 1. Displaced Terrain Geometry (384 x 384 grid over 7168 x 7168 world units)
    const segs = 384;
    const geo = new THREE.PlaneGeometry(sampler.worldSize, sampler.worldSize, segs, segs);
    geo.rotateX(-Math.PI / 2);

    const pos = geo.attributes.position as THREE.BufferAttribute;
    const colors = new Float32Array(pos.count * 3);

    const biomeColors: Record<number, THREE.Color> = {
      0: new THREE.Color(0x0d5c8a), // Ocean floor
      1: new THREE.Color(0xe9d498), // Lagoon golden beach
      2: new THREE.Color(0x6da64a), // West coast savanna/green
      3: new THREE.Color(0x287846), // Salazie lush emerald forest
      4: new THREE.Color(0x4b7748), // Cirque valleys
      5: new THREE.Color(0x8e99a8), // Piton des Neiges alpine rock
      6: new THREE.Color(0xc4733f), // Plaine des Sables ochre desert
      7: new THREE.Color(0x5c261d), // Fournaise volcanic rock
      8: new THREE.Color(0x2e373d), // Sud Sauvage dark basalt
    };
    const cliffColor = new THREE.Color(0x3a4046);
    const trailColor = new THREE.Color(0xb89b72);
    const lavaColor = new THREE.Color(0xff5518);
    const tmp = new THREE.Color();

    for (let i = 0; i < pos.count; i++) {
      const wx = pos.getX(i);
      const wz = pos.getZ(i);
      const h = sampler.getHeight(wx, wz);
      pos.setY(i, h);

      const biome = sampler.getBiome(wx, wz);
      const slope = sampler.getSlope(wx, wz);
      const trail = sampler.getTrail(wx, wz);

      tmp.copy(biomeColors[biome] ?? biomeColors[2]);

      // Subtle organic micro-variation
      const grain = (Math.sin(wx * 0.08) * Math.cos(wz * 0.08)) * 0.04;
      tmp.offsetHSL(0, 0, grain);

      // Steep remparts become dramatic dark volcanic basalt!
      if (h > sampler.seaLevel + 2 && slope > 0.34) {
        const cliffFactor = Math.min(0.85, (slope - 0.34) * 1.55);
        tmp.lerp(cliffColor, cliffFactor);
      }

      // Hiking trails tint
      if (trail > 85 && h > sampler.seaLevel + 1) {
        tmp.lerp(trailColor, Math.min(0.55, (trail / 255) * 0.65));
      }

      // Glowing molten core in Dolomieu Crater
      const dCrater = Math.hypot(wx - bossX, wz - bossZ);
      if (dCrater < 55) {
        tmp.lerp(lavaColor, Math.max(0, 1 - dCrater / 55));
      }

      colors[i * 3] = tmp.r;
      colors[i * 3 + 1] = tmp.g;
      colors[i * 3 + 2] = tmp.b;
    }

    geo.setAttribute('color', new THREE.BufferAttribute(colors, 3));
    geo.computeVertexNormals();

    this.terrainMesh = new THREE.Mesh(
      geo,
      new THREE.MeshStandardMaterial({
        vertexColors: true,
        roughness: 0.9,
        metalness: 0.04,
        flatShading: true,
      }),
    );
    scene.add(this.terrainMesh);

    // 2. Indian Ocean & Turquoise Lagoon Water Plane
    const waterGeo = new THREE.PlaneGeometry(sampler.worldSize * 1.6, sampler.worldSize * 1.6, 40, 40);
    waterGeo.rotateX(-Math.PI / 2);
    this.waterMesh = new THREE.Mesh(
      waterGeo,
      new THREE.MeshStandardMaterial({
        color: 0x0ea5b7,
        roughness: 0.22,
        metalness: 0.25,
        transparent: true,
        opacity: 0.82,
        flatShading: true,
      }),
    );
    this.waterMesh.position.y = sampler.seaLevel;
    scene.add(this.waterMesh);

    // 3. Instanced vegetation, rocks & village hubs
    this.populateVegetationAndRocks(sampler, hubX, hubZ);
    this.buildCreoleHubAndLandmarks(sampler, landmarks, hubX, hubZ);

    // 4. Rising glowing embers over Piton de la Fournaise
    this.volcanoParticles = this.createVolcanoEmbers(bossX, bossZ);
    scene.add(this.volcanoParticles);
  }

  public applyZoneAtmosphere(zone: ZoneMeta): void {
    this.targetSky.set(zone.skyColor);
    this.targetFog.set(zone.fogColor);
    this.targetAmbient.set(zone.ambientColor);
  }

  public update(dt: number, elapsed: number): void {
    // Smooth water tide
    this.waterMesh.position.y = 6.0 + Math.sin(elapsed * 1.3) * 0.22;

    // Smooth atmospheric transitions between zones
    const bg = this.scene.background as THREE.Color;
    bg.lerp(this.targetSky, Math.min(1, dt * 1.4));
    const fog = this.scene.fog as THREE.FogExp2;
    fog.color.lerp(this.targetFog, Math.min(1, dt * 1.4));
    this.hemiLight.color.lerp(this.targetAmbient, Math.min(1, dt * 1.4));

    // Animate volcano embers
    const pAttr = this.volcanoParticles.geometry.attributes.position as THREE.BufferAttribute;
    const minY = this.volcanoBaseY + 4;
    const maxY = this.volcanoBaseY + 115;
    for (let i = 0; i < pAttr.count; i++) {
      let y = pAttr.getY(i) + dt * (10 + (i % 7) * 2.5);
      if (y > maxY) {
        y = minY + (i % 15);
      }
      pAttr.setY(i, y);
    }
    pAttr.needsUpdate = true;
  }

  private populateVegetationAndRocks(sampler: TerrainSampler, hubX: number, hubZ: number): void {
    const palmMatrices: THREE.Matrix4[] = [];
    const treeMatrices: THREE.Matrix4[] = [];
    const rockMatrices: THREE.Matrix4[] = [];
    const dummy = new THREE.Object3D();

    // Deterministic pseudo-random scattering across the expanded 7168 x 7168 island
    let seed = 1337974; // 974 = La Réunion!
    const rand = () => {
      seed = (seed * 1664525 + 1013904223) >>> 0;
      return seed / 4294967296;
    };

    for (let i = 0; i < 22000; i++) {
      const wx = (rand() - 0.5) * (sampler.worldSize - 240);
      const wz = (rand() - 0.5) * (sampler.worldSize - 240);
      const h = sampler.getHeight(wx, wz);
      if (h < sampler.seaLevel + 1.8) continue;

      const slope = sampler.getSlope(wx, wz);
      const trail = sampler.getTrail(wx, wz);
      if (trail > 95) continue; // Keep walking trails clear!

      // Keep Saint-Gilles starter plaza clear
      if (Math.hypot(wx - hubX, wz - hubZ) < 42) continue;

      const biome = sampler.getBiome(wx, wz);
      dummy.position.set(wx, h - 0.3, wz);
      dummy.rotation.set(0, rand() * Math.PI * 2, 0);

      if ((biome === 1 || biome === 2) && slope < 0.55 && palmMatrices.length < 2400) {
        const s = 0.85 + rand() * 0.7;
        dummy.scale.set(s, s, s);
        dummy.updateMatrix();
        palmMatrices.push(dummy.matrix.clone());
      } else if ((biome === 3 || biome === 4) && slope < 0.72 && treeMatrices.length < 5500) {
        const s = 0.9 + rand() * 0.95;
        dummy.scale.set(s, s * (0.9 + rand() * 0.4), s);
        dummy.updateMatrix();
        treeMatrices.push(dummy.matrix.clone());
      } else if ((biome === 5 || biome === 6 || biome === 7 || biome === 8 || slope > 0.45) && rockMatrices.length < 3000) {
        const s = 0.9 + rand() * 2.2;
        dummy.scale.set(s, s * (0.6 + rand() * 0.7), s);
        dummy.rotation.set(rand(), rand() * Math.PI, rand());
        dummy.updateMatrix();
        rockMatrices.push(dummy.matrix.clone());
      }
    }

    // Palms / Filaos (trunk + crown instanced pair)
    this.addInstancedTrees(palmMatrices, 0x8b5a2b, 0x2e8b57, 4.8, 2.3);
    // Tropical Cirque / Cryptomeria trees
    this.addInstancedTrees(treeMatrices, 0x5c3a21, 0x1e6b3a, 3.6, 3.2);

    // Basalt Rocks
    if (rockMatrices.length > 0) {
      const rockGeo = new THREE.DodecahedronGeometry(1.4, 0);
      const rockMat = new THREE.MeshStandardMaterial({ color: 0x475569, roughness: 0.92, flatShading: true });
      const rocks = new THREE.InstancedMesh(rockGeo, rockMat, rockMatrices.length);
      rockMatrices.forEach((m, idx) => rocks.setMatrixAt(idx, m));
      rocks.instanceMatrix.needsUpdate = true;
      this.scene.add(rocks);
    }
  }

  private addInstancedTrees(matrices: THREE.Matrix4[], trunkCol: number, leafCol: number, trunkH: number, crownR: number): void {
    if (matrices.length === 0) return;

    const trunkGeo = new THREE.CylinderGeometry(0.22, 0.38, trunkH, 5);
    trunkGeo.translate(0, trunkH * 0.5, 0);
    const trunkMesh = new THREE.InstancedMesh(
      trunkGeo,
      new THREE.MeshStandardMaterial({ color: trunkCol, roughness: 0.9, flatShading: true }),
      matrices.length,
    );

    const crownGeo = new THREE.ConeGeometry(crownR, crownR * 1.85, 6);
    crownGeo.translate(0, trunkH + crownR * 0.65, 0);
    const crownMesh = new THREE.InstancedMesh(
      crownGeo,
      new THREE.MeshStandardMaterial({ color: leafCol, roughness: 0.85, flatShading: true }),
      matrices.length,
    );

    matrices.forEach((m, idx) => {
      trunkMesh.setMatrixAt(idx, m);
      crownMesh.setMatrixAt(idx, m);
    });
    trunkMesh.instanceMatrix.needsUpdate = true;
    crownMesh.instanceMatrix.needsUpdate = true;
    this.scene.add(trunkMesh, crownMesh);
  }

  private buildCreoleHubAndLandmarks(sampler: TerrainSampler, landmarks: LandmarkMeta[], hubX: number, hubZ: number): void {
    // Saint-Gilles starter village (colorful Creole huts around the Lagoon Hub)
    const hutSpots = [
      { x: hubX - 19, z: hubZ - 15, rot: 0.5, roof: 0xdc2626, wall: 0xfef3c7 },
      { x: hubX - 21, z: hubZ + 17, rot: 1.4, roof: 0x0284c7, wall: 0xe0f2fe },
      { x: hubX + 13, z: hubZ - 21, rot: -0.3, roof: 0x16a34a, wall: 0xfef9c3 },
    ];

    for (const h of hutSpots) {
      const y = sampler.getHeight(h.x, h.z);
      const hut = new THREE.Group();
      hut.position.set(h.x, y, h.z);
      hut.rotation.y = h.rot;

      const base = new THREE.Mesh(
        new THREE.BoxGeometry(7.2, 4.0, 5.4),
        new THREE.MeshStandardMaterial({ color: h.wall, roughness: 0.8, flatShading: true }),
      );
      base.position.y = 2.0;

      const roof = new THREE.Mesh(
        new THREE.ConeGeometry(5.6, 2.8, 4),
        new THREE.MeshStandardMaterial({ color: h.roof, roughness: 0.7, flatShading: true }),
      );
      roof.position.y = 5.3;
      roof.rotation.y = Math.PI / 4;

      hut.add(base, roof);
      this.scene.add(hut);
    }

    // Zone Relay Beacons (offset slightly from spawn center so players never overlap the pillar)
    for (const lm of landmarks) {
      const bx = lm.type === 'boss' ? lm.x : lm.x + 7.5;
      const bz = lm.type === 'boss' ? lm.z : lm.z - 7.5;
      const y = sampler.getHeight(bx, bz);
      const color = lm.type === 'boss' ? 0xff4500 : lm.type === 'hub' ? 0x2dd4bf : lm.type === 'peak' ? 0x93c5fd : 0xfacc15;

      const pillar = new THREE.Mesh(
        new THREE.CylinderGeometry(0.45, 0.75, 5.5, 6),
        new THREE.MeshStandardMaterial({ color: 0x334155, roughness: 0.8, flatShading: true }),
      );
      pillar.position.set(bx, y + 2.7, bz);
      this.scene.add(pillar);

      const orb = new THREE.Mesh(
        new THREE.OctahedronGeometry(0.95),
        new THREE.MeshStandardMaterial({ color, emissive: color, emissiveIntensity: 0.9, roughness: 0.2 }),
      );
      orb.position.set(bx, y + 6.4, bz);
      this.scene.add(orb);

      if (lm.type === 'hub' || lm.type === 'boss') {
        const beam = new THREE.Mesh(
          new THREE.CylinderGeometry(0.18, 0.9, 65, 8, 1, true),
          new THREE.MeshBasicMaterial({ color, transparent: true, opacity: 0.16, side: THREE.DoubleSide }),
        );
        beam.position.set(bx, y + 34, bz);
        this.scene.add(beam);
      }
    }
  }

  private createVolcanoEmbers(bossX: number, bossZ: number): THREE.Points {
    const count = 90;
    const positions = new Float32Array(count * 3);
    const baseY = this.volcanoBaseY;
    for (let i = 0; i < count; i++) {
      const a = (i / count) * Math.PI * 2 * 5;
      const r = (i % 19) * 2.2;
      positions[i * 3] = bossX + Math.cos(a) * r;
      positions[i * 3 + 1] = baseY + 5 + (i % 35) * 3;
      positions[i * 3 + 2] = bossZ + Math.sin(a) * r;
    }
    const geo = new THREE.BufferGeometry();
    geo.setAttribute('position', new THREE.BufferAttribute(positions, 3));
    return new THREE.Points(
      geo,
      new THREE.PointsMaterial({ color: 0xff6b2b, size: 2.2, transparent: true, opacity: 0.85 }),
    );
  }
}
