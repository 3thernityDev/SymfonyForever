import * as THREE from 'three';
import { TerrainSampler } from './TerrainSampler';
import type { LandmarkMeta, NpcData, TrailPoint, ZoneMeta } from './types';
import { createSoftParticleTexture, type WeatherFrameFactors } from './WeatherSystem';

export interface AtmosphereFrameResult {
  sunWorldPos: THREE.Vector3;
  sunColor: THREE.Color;
  dayFactor: number;
  goldenFactor: number;
  nightFactor: number;
}

interface CloudInstance {
  x: number;
  y: number;
  z: number;
  sx: number;
  sy: number;
  sz: number;
  speedX: number;
  speedZ: number;
  rotY: number;
}

interface TorchSite {
  x: number;
  y: number;
  z: number;
  isCampfire: boolean;
  phase: number;
}

/**
 * Renders the living 3D Island of La Réunion:
 * - Displaced terrain mesh colored by biomes, cliffs, trails & Dolomieu magma
 * - Synchronized Day/Night sky, sun/moon arcs, starfield & drifting weather-tinted clouds
 * - Layered tropical flora, flowers, coastal pirogues, waterfalls, fumaroles, zone villages
 * - Illuminated campfires & roadside/village torches with dynamic PointLight pooling & fireflies
 */
export class ReunionWorldRenderer {
  public readonly terrainMesh: THREE.Mesh;
  public readonly waterMesh: THREE.Mesh;

  private readonly scene: THREE.Scene;
  private readonly sunLight: THREE.DirectionalLight;
  private readonly fillLight: THREE.DirectionalLight;
  private readonly hemiLight: THREE.HemisphereLight;
  private readonly ambientLight: THREE.AmbientLight;
  private readonly playerLanternLight: THREE.PointLight;

  private readonly sunMesh: THREE.Mesh;
  private readonly sunHalo: THREE.Sprite;
  private readonly moonMesh: THREE.Mesh;
  private readonly starField: THREE.Points;
  private readonly starMat: THREE.PointsMaterial;
  private readonly volcanoParticles: THREE.Points;
  private readonly volcanoBaseY: number;

  private readonly cloudMesh: THREE.InstancedMesh | null = null;
  private readonly cloudMat: THREE.MeshStandardMaterial;
  private readonly clouds: CloudInstance[] = [];
  private readonly dummy = new THREE.Object3D();

  private readonly windowMat: THREE.MeshStandardMaterial;
  private readonly torchFlameMat: THREE.MeshStandardMaterial;
  private readonly fumaroleMat: THREE.MeshStandardMaterial;
  private readonly waterfallMat: THREE.MeshStandardMaterial;
  private readonly waterfallMeshes: { mesh: THREE.Mesh; baseScaleY: number; phase: number }[] = [];

  private readonly torchSites: TorchSite[] = [];
  private readonly torchLightPool: THREE.PointLight[] = [];
  private readonly campfireFlames: { mesh: THREE.Mesh; baseY: number; phase: number }[] = [];

  private readonly zoneSkyBase = new THREE.Color('#4da6e8');
  private readonly zoneFogBase = new THREE.Color('#a5dff9');
  private readonly zoneAmbientBase = new THREE.Color('#ffe8c2');

  private readonly sunDir = new THREE.Vector3();
  private readonly sunWorldPos = new THREE.Vector3();
  private readonly moonWorldPos = new THREE.Vector3();
  private readonly activeLightWorldPos = new THREE.Vector3();
  private readonly activeSunColor = new THREE.Color('#fff5e0');

  // Scratch colors to avoid per-frame allocations
  private readonly cSky = new THREE.Color();
  private readonly cFog = new THREE.Color();
  private readonly cAmbient = new THREE.Color();
  private readonly cSun = new THREE.Color();
  private readonly cWater = new THREE.Color();
  private readonly cDawnSky = new THREE.Color('#e08a6d');
  private readonly cDuskSky = new THREE.Color('#c9583e');
  private readonly cNightSky = new THREE.Color('#010206');
  private readonly cDawnFog = new THREE.Color('#f0a87e');
  private readonly cNightFog = new THREE.Color('#010205');
  private readonly cRainSky = new THREE.Color('#475c70');
  private readonly cStormSky = new THREE.Color('#1d2738');
  private readonly cSandSky = new THREE.Color('#9c6638');
  private readonly cSandFog = new THREE.Color('#b87946');
  private readonly cNoonSun = new THREE.Color('#fff5e0');
  private readonly cGoldenSun = new THREE.Color('#ff8e42');
  private readonly cMoonLight = new THREE.Color('#8bb4f7');
  private readonly cNightAmbient = new THREE.Color('#060b18');
  private readonly cDayGround = new THREE.Color('#5c6e52');
  private readonly cNightGround = new THREE.Color('#04060b');

  public constructor(
    scene: THREE.Scene,
    sampler: TerrainSampler,
    landmarks: LandmarkMeta[],
    zones?: Record<string, ZoneMeta>,
    npcs?: NpcData[],
    trails?: TrailPoint[][],
  ) {
    this.scene = scene;

    const hubLandmark = landmarks.find((l) => l.type === 'hub');
    const bossLandmark = landmarks.find((l) => l.type === 'boss');
    const hubX = hubLandmark?.x ?? -2047.5;
    const hubZ = hubLandmark?.z ?? -332.5;
    const bossX = bossLandmark?.x ?? 1540.0;
    const bossZ = bossLandmark?.z ?? 962.5;
    this.volcanoBaseY = sampler.getHeight(bossX, bossZ);

    const softTex = createSoftParticleTexture();

    // Sky & linear distance fog (strict <= 50m visibility at night without light sources)
    scene.background = this.zoneSkyBase.clone();
    scene.fog = new THREE.Fog(this.zoneFogBase.getHex(), 240, 2050);

    // Core lights modulated smoothly over Day/Night & Weather
    this.ambientLight = new THREE.AmbientLight(0xffffff, 0.7);
    scene.add(this.ambientLight);

    this.hemiLight = new THREE.HemisphereLight(0xeef6ff, 0x5c6e52, 1.0);
    scene.add(this.hemiLight);

    this.sunLight = new THREE.DirectionalLight(0xfff5e0, 1.75);
    this.sunLight.position.set(-1200, 1400, 1100);
    scene.add(this.sunLight);

    this.fillLight = new THREE.DirectionalLight(0xcce7ff, 0.58);
    this.fillLight.position.set(1200, 900, -1100);
    scene.add(this.fillLight);

    this.playerLanternLight = new THREE.PointLight(0xffd699, 0, 15, 1.8);
    scene.add(this.playerLanternLight);

    // 1. Celestial bodies (Orbiting Sun + Halo, Moon, Starfield, Drifting Clouds)
    this.sunMesh = new THREE.Mesh(
      new THREE.SphereGeometry(62, 16, 12),
      new THREE.MeshBasicMaterial({ color: 0xfff3c4, fog: false }),
    );
    scene.add(this.sunMesh);

    this.sunHalo = new THREE.Sprite(
      new THREE.SpriteMaterial({
        map: softTex,
        color: 0xffd27d,
        transparent: true,
        opacity: 0.72,
        blending: THREE.AdditiveBlending,
        depthWrite: false,
        fog: false,
      }),
    );
    this.sunHalo.scale.set(380, 380, 1);
    scene.add(this.sunHalo);

    this.moonMesh = new THREE.Mesh(
      new THREE.SphereGeometry(42, 14, 10),
      new THREE.MeshBasicMaterial({ color: 0xdbeafe, fog: false }),
    );
    scene.add(this.moonMesh);

    const starCount = 900;
    const starPos = new Float32Array(starCount * 3);
    let starSeed = 42974;
    const starRand = () => {
      starSeed = (starSeed * 1664525 + 1013904223) >>> 0;
      return starSeed / 4294967296;
    };
    for (let i = 0; i < starCount; i++) {
      const theta = starRand() * Math.PI * 2;
      const phi = Math.acos(0.08 + starRand() * 0.92);
      const r = 2050 + starRand() * 300;
      starPos[i * 3] = Math.sin(phi) * Math.cos(theta) * r;
      starPos[i * 3 + 1] = Math.cos(phi) * r;
      starPos[i * 3 + 2] = Math.sin(phi) * Math.sin(theta) * r;
    }
    const starGeo = new THREE.BufferGeometry();
    starGeo.setAttribute('position', new THREE.BufferAttribute(starPos, 3));
    this.starMat = new THREE.PointsMaterial({
      color: 0xf8fafc,
      map: softTex,
      size: 13,
      transparent: true,
      opacity: 0,
      depthWrite: false,
      fog: false,
    });
    this.starField = new THREE.Points(starGeo, this.starMat);
    this.starField.frustumCulled = false;
    scene.add(this.starField);

    this.cloudMat = new THREE.MeshStandardMaterial({
      color: 0xffffff,
      roughness: 0.95,
      metalness: 0.0,
      transparent: true,
      opacity: 0.84,
      flatShading: true,
    });
    const cloudGeo = new THREE.DodecahedronGeometry(16, 0);
    const cloudCount = 75;
    this.cloudMesh = new THREE.InstancedMesh(cloudGeo, this.cloudMat, cloudCount);
    this.cloudMesh.frustumCulled = false;
    for (let i = 0; i < cloudCount; i++) {
      const c: CloudInstance = {
        x: (starRand() - 0.5) * 6800,
        y: 380 + starRand() * 110,
        z: (starRand() - 0.5) * 6800,
        sx: 2.2 + starRand() * 1.8,
        sy: 0.55 + starRand() * 0.35,
        sz: 1.3 + starRand() * 1.2,
        speedX: 3.5 + starRand() * 5.0,
        speedZ: 1.0 + starRand() * 2.5,
        rotY: starRand() * Math.PI * 2,
      };
      this.clouds.push(c);
    }
    scene.add(this.cloudMesh);

    // Shared animated/emissive materials
    this.windowMat = new THREE.MeshStandardMaterial({
      color: 0x1e293b,
      emissive: 0xffb347,
      emissiveIntensity: 0.0,
      roughness: 0.4,
    });
    this.torchFlameMat = new THREE.MeshStandardMaterial({
      color: 0xffd166,
      emissive: 0xff6b1a,
      emissiveIntensity: 1.2,
      roughness: 0.3,
    });
    this.fumaroleMat = new THREE.MeshStandardMaterial({
      color: 0x3b1810,
      emissive: 0xff4500,
      emissiveIntensity: 1.4,
      roughness: 0.7,
    });
    this.waterfallMat = new THREE.MeshStandardMaterial({
      color: 0xe0f7fa,
      emissive: 0x38bdf8,
      emissiveIntensity: 0.35,
      transparent: true,
      opacity: 0.72,
      roughness: 0.2,
      side: THREE.DoubleSide,
    });

    // 2. Displaced Terrain Geometry (384 x 384 grid over 7168 x 7168 world units)
    const segs = 384;
    const geo = new THREE.PlaneGeometry(sampler.worldSize, sampler.worldSize, segs, segs);
    geo.rotateX(-Math.PI / 2);

    const pos = geo.attributes.position as THREE.BufferAttribute;
    const colors = new Float32Array(pos.count * 3);

    const biomeColors: Record<number, THREE.Color> = {
      0: new THREE.Color(0x0d5c8a),
      1: new THREE.Color(0xe9d498),
      2: new THREE.Color(0x6da64a),
      3: new THREE.Color(0x287846),
      4: new THREE.Color(0x4b7748),
      5: new THREE.Color(0x8e99a8),
      6: new THREE.Color(0xc4733f),
      7: new THREE.Color(0x5c261d),
      8: new THREE.Color(0x2e373d),
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

      const grain = Math.sin(wx * 0.08) * Math.cos(wz * 0.08) * 0.04;
      tmp.offsetHSL(0, 0, grain);

      if (h > sampler.seaLevel + 2 && slope > 0.34) {
        const cliffFactor = Math.min(0.85, (slope - 0.34) * 1.55);
        tmp.lerp(cliffColor, cliffFactor);
      }

      if (trail > 85 && h > sampler.seaLevel + 1) {
        tmp.lerp(trailColor, Math.min(0.55, (trail / 255) * 0.65));
      }

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

    // 3. Indian Ocean & Turquoise Lagoon Water Plane
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

    // 4. Environment: Dense flora, flowers, rocks, pirogues, waterfalls, fumaroles, villages & torches
    const clearZones = this.buildClearZones(zones, landmarks, npcs, hubX, hubZ, bossX, bossZ);
    this.populateVegetationAndRocks(sampler, clearZones);
    this.buildCoastalPiroguesAndFeatures(sampler, bossX, bossZ, hubX, hubZ);
    this.buildAllZoneVillagesAndLandmarks(sampler, landmarks, zones, npcs, hubX, hubZ);
    this.placeRoadsideTrailTorches(sampler, trails, zones, npcs, bossX, bossZ);
    this.renderTorchInstances();

    // Pool of 10 dynamic PointLights bound dynamically to nearest torches/campfires around player
    for (let i = 0; i < 10; i++) {
      const pl = new THREE.PointLight(0xff8c32, 0, 64, 1.15);
      scene.add(pl);
      this.torchLightPool.push(pl);
    }

    // 5. Rising glowing embers over Piton de la Fournaise
    this.volcanoParticles = this.createVolcanoEmbers(bossX, bossZ);
    scene.add(this.volcanoParticles);
  }

  public applyZoneAtmosphere(zone: ZoneMeta): void {
    this.zoneSkyBase.set(zone.skyColor);
    this.zoneFogBase.set(zone.fogColor);
    this.zoneAmbientBase.set(zone.ambientColor);
  }

  public update(
    dt: number,
    elapsed: number,
    playerPos: THREE.Vector3,
    timeOfDay: number,
    weather: WeatherFrameFactors,
    cameraDistToPlayer = 18,
  ): AtmosphereFrameResult {
    // 1. Tide animation
    this.waterMesh.position.y = 6.0 + Math.sin(elapsed * 1.3) * 0.22;

    // 2. Solar & Lunar arc across Réunion (-X West lagoon sunset, +X East sunrise)
    let elevAngle: number;
    if (timeOfDay >= 6.0 && timeOfDay <= 19.0) {
      const u = (timeOfDay - 6.0) / 13.0;
      elevAngle = u * Math.PI;
    } else {
      const nightHours = timeOfDay > 19.0 ? timeOfDay - 19.0 : timeOfDay + 5.0;
      const u = Math.min(1.0, Math.max(0.0, nightHours / 11.0));
      elevAngle = Math.PI + u * Math.PI;
    }

    this.sunDir.set(Math.cos(elevAngle) * 0.92, Math.sin(elevAngle), 0.38).normalize();
    const sunElev = this.sunDir.y;

    const dayFactor = THREE.MathUtils.clamp((sunElev + 0.05) / 0.45, 0, 1);
    const goldenFactor = Math.exp(-Math.pow((sunElev - 0.1) / 0.22, 2));
    const nightFactor = THREE.MathUtils.clamp((-sunElev + 0.06) / 0.3, 0, 1);
    const isDusk = timeOfDay > 12.5;

    this.sunWorldPos.set(
      playerPos.x + this.sunDir.x * 1850,
      this.sunDir.y * 1850,
      playerPos.z + this.sunDir.z * 1850,
    );
    this.moonWorldPos.set(
      playerPos.x - this.sunDir.x * 1850,
      -this.sunDir.y * 1850,
      playerPos.z - this.sunDir.z * 1850,
    );

    this.sunMesh.position.copy(this.sunWorldPos);
    this.sunHalo.position.copy(this.sunWorldPos);
    this.moonMesh.position.copy(this.moonWorldPos);

    const sunAbove = this.sunDir.y > -0.05;
    this.sunMesh.visible = sunAbove;
    this.sunHalo.visible = sunAbove;
    this.moonMesh.visible = !sunAbove || nightFactor > 0.05;

    // 3. Sky, Fog & Ambient blending with Day/Night + Weather
    this.cSun.copy(this.cNoonSun).lerp(this.cGoldenSun, goldenFactor * 0.9);
    (this.sunHalo.material as THREE.SpriteMaterial).color.copy(this.cSun);
    (this.sunHalo.material as THREE.SpriteMaterial).opacity =
      Math.max(0.08, (0.58 + goldenFactor * 0.35) * (1 - weather.stormFactor * 0.85 - weather.sandstormFactor * 0.7)) *
      THREE.MathUtils.clamp((this.sunDir.y + 0.06) * 4, 0, 1);

    const goldenSky = isDusk ? this.cDuskSky : this.cDawnSky;
    this.cSky.copy(this.zoneSkyBase);
    if (goldenFactor > 0.01) {
      this.cSky.lerp(goldenSky, goldenFactor * 0.72);
    }
    if (nightFactor > 0.01) {
      this.cSky.lerp(this.cNightSky, nightFactor * 0.995);
    }

    this.cFog.copy(this.zoneFogBase);
    if (goldenFactor > 0.01) {
      this.cFog.lerp(this.cDawnFog, goldenFactor * 0.6);
    }
    if (nightFactor > 0.01) {
      this.cFog.lerp(this.cNightFog, nightFactor * 0.995);
    }

    // Weather tints sky & fog (kept deep dark at night so night fog stays black, never washed-out brown)
    const nightDarkScale = 1 - nightFactor * 0.92;
    if (weather.rainFactor > 0.01) {
      const targetRain = this.cRainSky.clone().multiplyScalar(nightDarkScale);
      this.cSky.lerp(targetRain, weather.rainFactor * 0.55);
      this.cFog.lerp(targetRain, weather.rainFactor * 0.5);
    }
    if (weather.stormFactor > 0.01) {
      const targetStorm = this.cStormSky.clone().multiplyScalar(nightDarkScale);
      this.cSky.lerp(targetStorm, weather.stormFactor * 0.78);
      this.cFog.lerp(targetStorm, weather.stormFactor * 0.72);
    }
    if (weather.sandstormFactor > 0.01) {
      const sandSky = this.cSandSky.clone().lerp(this.cNightSky, nightFactor * 0.92);
      const sandFog = this.cSandFog.clone().lerp(this.cNightFog, nightFactor * 0.92);
      this.cSky.lerp(sandSky, weather.sandstormFactor * 0.86);
      this.cFog.lerp(sandFog, weather.sandstormFactor * 0.88);
    }

    // Brief lightning illumination on sky & fog during storm flashes
    if (weather.lightningFlash > 0.01) {
      const flashCol = new THREE.Color(0xcbdcff);
      this.cSky.lerp(flashCol, Math.min(0.65, weather.lightningFlash * 0.55));
      this.cFog.lerp(flashCol, Math.min(0.45, weather.lightningFlash * 0.4));
    }

    const bg = this.scene.background as THREE.Color;
    bg.lerp(this.cSky, Math.min(1, dt * 4.5));
    const fog = this.scene.fog as THREE.Fog;
    fog.color.lerp(this.cFog, Math.min(1, dt * 4.5));

    // Linear visibility distance: crisp 50m max cutoff from player at night without light sources
    let dayNear = 240;
    let dayFar = 2050;
    dayNear = THREE.MathUtils.lerp(dayNear, 70, weather.rainFactor);
    dayFar = THREE.MathUtils.lerp(dayFar, 780, weather.rainFactor);
    dayNear = THREE.MathUtils.lerp(dayNear, 40, weather.stormFactor);
    dayFar = THREE.MathUtils.lerp(dayFar, 460, weather.stormFactor);
    dayNear = THREE.MathUtils.lerp(dayNear, 22, weather.sandstormFactor);
    dayFar = THREE.MathUtils.lerp(dayFar, 175, weather.sandstormFactor);

    const camDist = Math.max(6, cameraDistToPlayer);
    const nightNear = camDist + 4;
    const nightFar = Math.max(
      camDist + 16,
      camDist + 48 - weather.stormFactor * 6 - weather.sandstormFactor * 12 + weather.lightningFlash * 85,
    );

    const targetNear = THREE.MathUtils.lerp(dayNear, nightNear, Math.pow(nightFactor, 0.7));
    const targetFar = Math.exp(
      THREE.MathUtils.lerp(Math.log(Math.max(30, dayFar)), Math.log(Math.max(20, nightFar)), nightFactor),
    );
    const fogBlend = Math.min(1, dt * 4.0);
    fog.near += (targetNear - fog.near) * fogBlend;
    fog.far += (targetFar - fog.far) * fogBlend;

    // Water & cloud coloring
    this.cWater.setHex(0x0ea5b7).lerp(new THREE.Color(0x030c18), nightFactor * 0.95).lerp(new THREE.Color(0x1b2b36), weather.stormFactor * 0.55);
    (this.waterMesh.material as THREE.MeshStandardMaterial).color.copy(this.cWater);

    this.cloudMat.color
      .setHex(0xffffff)
      .lerp(this.cDawnFog, goldenFactor * 0.75)
      .lerp(new THREE.Color(0x050914), nightFactor * 0.94)
      .lerp(new THREE.Color(0x334155), weather.stormFactor * 0.7)
      .lerp(new THREE.Color(0xc48b56), weather.sandstormFactor * (0.6 - nightFactor * 0.5));

    // 4. Directional / Hemisphere / Ambient intensities (pitch-dark unlit night)
    this.cAmbient.copy(this.zoneAmbientBase).lerp(this.cNightAmbient, nightFactor * 0.95);
    this.hemiLight.color.lerp(this.cAmbient, Math.min(1, dt * 4.0));
    this.hemiLight.groundColor.copy(this.cDayGround).lerp(this.cNightGround, nightFactor);
    this.hemiLight.intensity = Math.max(0.06, 1.05 * (1 - nightFactor * 0.94) * (1 - weather.stormFactor * 0.35));
    this.ambientLight.intensity = Math.max(0.04, 0.72 * (1 - nightFactor * 0.94) * (1 - weather.stormFactor * 0.25));
    this.fillLight.intensity = Math.max(0.015, 0.62 * (1 - nightFactor * 0.97));

    if (sunAbove) {
      this.sunLight.position.copy(this.sunDir).multiplyScalar(1400);
      this.sunLight.color.copy(this.cSun);
      const skyAtten = Math.max(0.2, 1 - weather.stormFactor * 0.75 - weather.rainFactor * 0.45 - weather.sandstormFactor * 0.65);
      this.sunLight.intensity = (1.75 * dayFactor + 1.15 * goldenFactor) * skyAtten;
      this.activeLightWorldPos.copy(this.sunWorldPos);
      this.activeSunColor.copy(this.cSun);
    } else {
      const moonDir = this.sunDir.clone().negate();
      this.sunLight.position.copy(moonDir).multiplyScalar(1400);
      this.sunLight.color.copy(this.cMoonLight);
      this.sunLight.intensity = 0.12 * Math.max(0.2, 1 - weather.stormFactor * 0.75);
      this.activeLightWorldPos.copy(this.moonWorldPos);
      this.activeSunColor.copy(this.cMoonLight);
    }

    // 5. Stars & drifting clouds
    this.starField.position.set(playerPos.x, 0, playerPos.z);
    this.starMat.opacity = Math.max(0, nightFactor * 0.92 * (1 - weather.stormFactor * 0.92 - weather.sandstormFactor * 0.85));

    if (this.cloudMesh) {
      for (let i = 0; i < this.clouds.length; i++) {
        const c = this.clouds[i];
        c.x += c.speedX * dt * (1 + weather.stormFactor * 1.2 + weather.sandstormFactor * 1.4);
        c.z += c.speedZ * dt;
        if (c.x > 3450) c.x = -3450;
        if (c.z > 3450) c.z = -3450;
        this.dummy.position.set(c.x, c.y, c.z);
        this.dummy.rotation.set(0, c.rotY, 0);
        this.dummy.scale.set(c.sx, c.sy, c.sz);
        this.dummy.updateMatrix();
        this.cloudMesh.setMatrixAt(i, this.dummy.matrix);
      }
      this.cloudMesh.instanceMatrix.needsUpdate = true;
    }

    // 6. Glowing night hut windows, torches, campfires, waterfalls, fumaroles & lantern
    this.windowMat.emissiveIntensity = nightFactor * 3.2 + goldenFactor * 0.7;
    const flickerBase = 0.88 + Math.sin(elapsed * 9.5) * 0.1 + Math.sin(elapsed * 16.3) * 0.06;
    this.torchFlameMat.emissiveIntensity = (0.35 + nightFactor * 3.4 + goldenFactor * 1.0) * flickerBase;
    this.fumaroleMat.emissiveIntensity = (1.1 + nightFactor * 1.4) * (0.85 + Math.sin(elapsed * 3.7) * 0.22);

    this.waterfallMat.opacity = 0.62 + Math.sin(elapsed * 3.8) * 0.1 + weather.rainFactor * 0.18;
    for (const wf of this.waterfallMeshes) {
      wf.mesh.scale.y = wf.baseScaleY * (1.0 + Math.sin(elapsed * 4.5 + wf.phase) * 0.035);
    }

    for (const cf of this.campfireFlames) {
      const f = 0.86 + Math.sin(elapsed * 11 + cf.phase) * 0.18;
      cf.mesh.scale.set(f, 0.9 + Math.cos(elapsed * 13 + cf.phase) * 0.2, f);
      cf.mesh.position.y = cf.baseY + Math.sin(elapsed * 9 + cf.phase) * 0.06;
    }

    this.updateTorchLightPool(playerPos, elapsed, nightFactor, goldenFactor);

    this.playerLanternLight.position.set(playerPos.x, playerPos.y + 2.4, playerPos.z);
    this.playerLanternLight.intensity = nightFactor * 0.48 + goldenFactor * 0.15;

    // 7. Volcano embers
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

    return {
      sunWorldPos: this.activeLightWorldPos,
      sunColor: this.activeSunColor,
      dayFactor,
      goldenFactor,
      nightFactor,
    };
  }

  private updateTorchLightPool(playerPos: THREE.Vector3, elapsed: number, nightFactor: number, goldenFactor: number): void {
    const scored: { d2: number; t: TorchSite }[] = [];
    for (let i = 0; i < this.torchSites.length; i++) {
      const t = this.torchSites[i];
      const dx = t.x - playerPos.x;
      const dz = t.z - playerPos.z;
      const d2 = dx * dx + dz * dz;
      if (d2 <= 220 * 220) {
        scored.push({ d2, t });
      }
    }
    scored.sort((a, b) => a.d2 - b.d2);

    const baseStrength = 0.32 + goldenFactor * 1.85 + nightFactor * 7.2;
    for (let i = 0; i < this.torchLightPool.length; i++) {
      const pl = this.torchLightPool[i];
      if (i < scored.length) {
        const site = scored[i].t;
        const flick = 0.85 + Math.sin(elapsed * 10.2 + site.phase) * 0.12 + Math.cos(elapsed * 17.1 + site.phase) * 0.08;
        pl.position.set(site.x, site.y + (site.isCampfire ? 1.6 : 3.6), site.z);
        pl.distance = site.isCampfire ? 78 : 64;
        pl.decay = 1.15;
        pl.intensity = baseStrength * (site.isCampfire ? 1.35 : 1.0) * flick;
      } else {
        pl.intensity = 0;
      }
    }
  }

  private buildClearZones(
    zones: Record<string, ZoneMeta> | undefined,
    landmarks: LandmarkMeta[],
    npcs: NpcData[] | undefined,
    hubX: number,
    hubZ: number,
    bossX: number,
    bossZ: number,
  ): { x: number; z: number; r: number }[] {
    const list: { x: number; z: number; r: number }[] = [
      { x: hubX, z: hubZ, r: 44 },
      { x: bossX, z: bossZ, r: 85 },
    ];
    if (zones) {
      for (const z of Object.values(zones)) {
        list.push({ x: z.respawnX, z: z.respawnZ, r: 38 });
      }
    } else {
      for (const lm of landmarks) {
        list.push({ x: lm.x, z: lm.z, r: lm.type === 'boss' ? 85 : 36 });
      }
    }
    if (npcs) {
      for (const npc of npcs) {
        list.push({ x: npc.x, z: npc.z, r: 16 });
      }
    }
    return list;
  }

  private isInsideClearZone(x: number, z: number, clearZones: { x: number; z: number; r: number }[]): boolean {
    for (const cz of clearZones) {
      if (Math.hypot(x - cz.x, z - cz.z) < cz.r) return true;
    }
    return false;
  }

  private populateVegetationAndRocks(sampler: TerrainSampler, clearZones: { x: number; z: number; r: number }[]): void {
    const palmMatrices: THREE.Matrix4[] = [];
    const treeMatrices: THREE.Matrix4[] = [];
    const bushMatrices: THREE.Matrix4[] = [];
    const flowerMatrices: THREE.Matrix4[] = [];
    const rockMatrices: THREE.Matrix4[] = [];
    const obsidianMatrices: THREE.Matrix4[] = [];
    const dummy = new THREE.Object3D();

    let seed = 1337974;
    const rand = () => {
      seed = (seed * 1664525 + 1013904223) >>> 0;
      return seed / 4294967296;
    };

    for (let i = 0; i < 36000; i++) {
      const wx = (rand() - 0.5) * (sampler.worldSize - 220);
      const wz = (rand() - 0.5) * (sampler.worldSize - 220);
      const h = sampler.getHeight(wx, wz);
      if (h < sampler.seaLevel + 1.8) continue;

      const slope = sampler.getSlope(wx, wz);
      const trail = sampler.getTrail(wx, wz);
      if (this.isInsideClearZone(wx, wz, clearZones)) continue;

      const biome = sampler.getBiome(wx, wz);
      dummy.position.set(wx, h - 0.28, wz);
      dummy.rotation.set(0, rand() * Math.PI * 2, 0);

      // Keep hiking trails mostly open, except small flowers beside trails
      if (trail > 80 && trail <= 115 && (biome >= 1 && biome <= 4) && flowerMatrices.length < 2000 && rand() < 0.35) {
        const s = 0.7 + rand() * 0.7;
        dummy.scale.set(s, s, s);
        dummy.updateMatrix();
        flowerMatrices.push(dummy.matrix.clone());
        continue;
      }
      if (trail > 90) continue;

      if ((biome === 1 || biome === 2) && slope < 0.55 && palmMatrices.length < 3400) {
        const s = 0.85 + rand() * 0.75;
        dummy.scale.set(s, s, s);
        dummy.updateMatrix();
        palmMatrices.push(dummy.matrix.clone());
      } else if ((biome === 3 || biome === 4) && slope < 0.72 && treeMatrices.length < 6800) {
        const s = 0.9 + rand() * 1.05;
        dummy.scale.set(s, s * (0.9 + rand() * 0.45), s);
        dummy.updateMatrix();
        treeMatrices.push(dummy.matrix.clone());
      } else if ((biome >= 1 && biome <= 4) && slope < 0.62 && bushMatrices.length < 4500 && rand() < 0.65) {
        const s = 0.75 + rand() * 0.9;
        dummy.scale.set(s, s * 0.75, s);
        dummy.updateMatrix();
        bushMatrices.push(dummy.matrix.clone());
      } else if ((biome === 6 || biome === 7) && slope < 0.65 && obsidianMatrices.length < 1400 && rand() < 0.35) {
        const s = 0.9 + rand() * 1.8;
        dummy.scale.set(s, s * (1.4 + rand() * 1.3), s);
        dummy.rotation.set((rand() - 0.5) * 0.35, rand() * Math.PI * 2, (rand() - 0.5) * 0.35);
        dummy.updateMatrix();
        obsidianMatrices.push(dummy.matrix.clone());
      } else if ((biome === 5 || biome === 6 || biome === 7 || biome === 8 || slope > 0.44) && rockMatrices.length < 3600) {
        const s = 0.9 + rand() * 2.2;
        dummy.scale.set(s, s * (0.6 + rand() * 0.7), s);
        dummy.rotation.set(rand(), rand() * Math.PI, rand());
        dummy.updateMatrix();
        rockMatrices.push(dummy.matrix.clone());
      }
    }

    // Layered palms / filaos & cirque cryptomeria trees
    this.addInstancedTrees(palmMatrices, 0x8b5a2b, 0x2e8b57, 4.9, 2.45);
    this.addInstancedTrees(treeMatrices, 0x5c3a21, 0x1e6b3a, 3.8, 3.3);

    // Lush bushes / ferns
    if (bushMatrices.length > 0) {
      const bushGeo = new THREE.DodecahedronGeometry(1.15, 0);
      bushGeo.translate(0, 0.7, 0);
      const bushMat = new THREE.MeshStandardMaterial({ color: 0x2f7d44, roughness: 0.9, flatShading: true });
      const bushes = new THREE.InstancedMesh(bushGeo, bushMat, bushMatrices.length);
      bushMatrices.forEach((m, idx) => bushes.setMatrixAt(idx, m));
      bushes.instanceMatrix.needsUpdate = true;
      this.scene.add(bushes);
    }

    // Tropical flower clusters (hibiscus / bougainvillea tones)
    if (flowerMatrices.length > 0) {
      const flGeo = new THREE.OctahedronGeometry(0.42, 0);
      flGeo.translate(0, 0.38, 0);
      const flMat = new THREE.MeshStandardMaterial({ color: 0xf43f5e, emissive: 0x4c0519, emissiveIntensity: 0.25, roughness: 0.7, flatShading: true });
      const flowers = new THREE.InstancedMesh(flGeo, flMat, flowerMatrices.length);
      flowerMatrices.forEach((m, idx) => flowers.setMatrixAt(idx, m));
      flowers.instanceMatrix.needsUpdate = true;
      this.scene.add(flowers);
    }

    // Basalt rocks
    if (rockMatrices.length > 0) {
      const rockGeo = new THREE.DodecahedronGeometry(1.4, 0);
      const rockMat = new THREE.MeshStandardMaterial({ color: 0x475569, roughness: 0.92, flatShading: true });
      const rocks = new THREE.InstancedMesh(rockGeo, rockMat, rockMatrices.length);
      rockMatrices.forEach((m, idx) => rocks.setMatrixAt(idx, m));
      rocks.instanceMatrix.needsUpdate = true;
      this.scene.add(rocks);
    }

    // Volcanic obsidian spires
    if (obsidianMatrices.length > 0) {
      const spGeo = new THREE.ConeGeometry(1.1, 4.4, 5);
      spGeo.translate(0, 2.0, 0);
      const spMat = new THREE.MeshStandardMaterial({ color: 0x1e1b24, roughness: 0.65, metalness: 0.2, flatShading: true });
      const spires = new THREE.InstancedMesh(spGeo, spMat, obsidianMatrices.length);
      obsidianMatrices.forEach((m, idx) => spires.setMatrixAt(idx, m));
      spires.instanceMatrix.needsUpdate = true;
      this.scene.add(spires);
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

  private buildCoastalPiroguesAndFeatures(sampler: TerrainSampler, bossX: number, bossZ: number, hubX: number, hubZ: number): void {
    // 1. Coastal pirogues moored in Saint-Gilles lagoon water
    let placedBoats = 0;
    for (let stepZ = -180; stepZ <= 180 && placedBoats < 5; stepZ += 70) {
      for (let stepX = -160; stepX <= -40; stepX += 14) {
        const bx = hubX + stepX;
        const bz = hubZ + stepZ;
        const h = sampler.getHeight(bx, bz);
        if (h <= sampler.seaLevel - 0.1 && h >= sampler.seaLevel - 1.5) {
          const boat = new THREE.Group();
          boat.position.set(bx, sampler.seaLevel + 0.18, bz);
          boat.rotation.y = stepZ * 0.02 + 0.4;

          const hull = new THREE.Mesh(
            new THREE.BoxGeometry(1.5, 0.55, 5.2),
            new THREE.MeshStandardMaterial({ color: 0x92400e, roughness: 0.85, flatShading: true }),
          );
          const trim = new THREE.Mesh(
            new THREE.BoxGeometry(1.65, 0.2, 4.4),
            new THREE.MeshStandardMaterial({ color: placedBoats % 2 === 0 ? 0x0284c7 : 0xdc2626, roughness: 0.7 }),
          );
          trim.position.y = 0.25;
          boat.add(hull, trim);
          this.scene.add(boat);
          placedBoats++;
          break;
        }
      }
    }

    // 2. Voile de la Mariée animated waterfalls on steep Salazie cliffs
    let waterfallsPlaced = 0;
    for (let gz = -1400; gz <= -650 && waterfallsPlaced < 3; gz += 180) {
      for (let gx = 100; gx <= 650 && waterfallsPlaced < 3; gx += 160) {
        const slope = sampler.getSlope(gx, gz);
        const h = sampler.getHeight(gx, gz);
        if (slope > 0.55 && h > 45) {
          const wfGeo = new THREE.PlaneGeometry(5.5, 34);
          const wf = new THREE.Mesh(wfGeo, this.waterfallMat);
          wf.position.set(gx, h - 12, gz);
          wf.rotation.y = waterfallsPlaced * 0.9;
          this.scene.add(wf);
          this.waterfallMeshes.push({ mesh: wf, baseScaleY: 1.0, phase: waterfallsPlaced * 1.4 });
          waterfallsPlaced++;
        }
      }
    }

    // 3. Glowing magma fumarole vents around Piton de la Fournaise / Dolomieu approaches
    for (let i = 0; i < 8; i++) {
      const ang = (i / 8) * Math.PI * 2;
      const dist = 75 + (i % 3) * 45;
      const fx = bossX + Math.cos(ang) * dist;
      const fz = bossZ + Math.sin(ang) * dist;
      if (!sampler.isWalkable(fx, fz)) continue;
      const fy = sampler.getHeight(fx, fz);

      const vent = new THREE.Mesh(new THREE.ConeGeometry(1.8, 1.4, 6), this.fumaroleMat);
      vent.position.set(fx, fy + 0.5, fz);
      this.scene.add(vent);
    }
  }

  private buildAllZoneVillagesAndLandmarks(
    sampler: TerrainSampler,
    landmarks: LandmarkMeta[],
    zones: Record<string, ZoneMeta> | undefined,
    npcs: NpcData[] | undefined,
    hubX: number,
    hubZ: number,
  ): void {
    const campConfigs = zones
      ? Object.values(zones).map((z) => ({ code: z.code, x: z.respawnX, z: z.respawnZ }))
      : landmarks.filter((l) => l.type !== 'boss').map((l, idx) => ({ code: l.name + '_' + idx, x: l.x, z: l.z }));

    const villageLayouts = [
      { angle: 2.4, roof: 0xdc2626, wall: 0xfef3c7 },
      { angle: -2.2, roof: 0x0284c7, wall: 0xe0f2fe },
      { angle: 0.7, roof: 0x16a34a, wall: 0xfef9c3 },
    ];

    campConfigs.forEach((camp, cIdx) => {
      const hutCount = camp.code === 'lagon_ouest' ? 3 : 2;
      for (let hIdx = 0; hIdx < hutCount; hIdx++) {
        const cfg = villageLayouts[(hIdx + cIdx) % villageLayouts.length];
        const dist = 20.5;
        const hx = camp.x + Math.cos(cfg.angle) * dist;
        const hz = camp.z + Math.sin(cfg.angle) * dist;
        const hy = sampler.getHeight(hx, hz);

        const wallColor =
          camp.code === 'piton_neiges' ? 0xd6cfc7 : camp.code === 'fournaise' || camp.code === 'sud_sauvage' ? 0x475569 : cfg.wall;
        const roofColor =
          camp.code === 'piton_neiges' ? 0x64748b : camp.code === 'fournaise' ? 0x7c2d12 : cfg.roof;

        this.addCreoleHut(hx, hy, hz, Math.atan2(camp.x - hx, camp.z - hz), wallColor, roofColor);
      }

      // Village campfire offset safely from spawn center
      const cfX = camp.x + 6.5;
      const cfZ = camp.z + 6.5;
      const cfY = sampler.getHeight(cfX, cfZ);
      this.addCampfire(cfX, cfY, cfZ, cIdx * 1.1);

      // Ring of 6 village perimeter torches
      for (let tIdx = 0; tIdx < 6; tIdx++) {
        const ang = (tIdx / 6) * Math.PI * 2 + 0.35;
        const tx = camp.x + Math.cos(ang) * 15.5;
        const tz = camp.z + Math.sin(ang) * 15.5;
        const ty = sampler.getHeight(tx, tz);
        this.torchSites.push({ x: tx, y: ty, z: tz, isCampfire: false, phase: tIdx * 0.9 + cIdx });
      }
    });

    // Dedicated torch next to each Quest NPC
    if (npcs) {
      npcs.forEach((npc, idx) => {
        const tx = npc.x + 2.5;
        const tz = npc.z - 2.0;
        const ty = sampler.getHeight(tx, tz);
        this.torchSites.push({ x: tx, y: ty, z: tz, isCampfire: false, phase: idx * 1.3 });
      });
    }

    // Zone Relay Beacons
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

    // Keep starter hub welcome arch subtle & warm
    void hubX;
    void hubZ;
  }

  private addCreoleHut(x: number, y: number, z: number, rotY: number, wallColor: number, roofColor: number): void {
    const hut = new THREE.Group();
    hut.position.set(x, y, z);
    hut.rotation.y = rotY;

    const base = new THREE.Mesh(
      new THREE.BoxGeometry(7.0, 4.0, 5.2),
      new THREE.MeshStandardMaterial({ color: wallColor, roughness: 0.82, flatShading: true }),
    );
    base.position.y = 2.0;

    const roof = new THREE.Mesh(
      new THREE.ConeGeometry(5.5, 2.8, 4),
      new THREE.MeshStandardMaterial({ color: roofColor, roughness: 0.72, flatShading: true }),
    );
    roof.position.y = 5.3;
    roof.rotation.y = Math.PI / 4;

    // Warm emissive windows facing camp plaza & sides (light up brightly at night!)
    const frontWinL = new THREE.Mesh(new THREE.BoxGeometry(1.1, 1.1, 0.15), this.windowMat);
    frontWinL.position.set(-1.6, 2.2, 2.62);
    const frontWinR = new THREE.Mesh(new THREE.BoxGeometry(1.1, 1.1, 0.15), this.windowMat);
    frontWinR.position.set(1.6, 2.2, 2.62);
    const door = new THREE.Mesh(
      new THREE.BoxGeometry(1.2, 2.1, 0.14),
      new THREE.MeshStandardMaterial({ color: 0x5c3a21, roughness: 0.9 }),
    );
    door.position.set(0, 1.05, 2.62);

    hut.add(base, roof, frontWinL, frontWinR, door);
    this.scene.add(hut);
  }

  private addCampfire(x: number, y: number, z: number, phase: number): void {
    const group = new THREE.Group();
    group.position.set(x, y, z);

    const stoneMat = new THREE.MeshStandardMaterial({ color: 0x475569, roughness: 0.95, flatShading: true });
    for (let i = 0; i < 6; i++) {
      const a = (i / 6) * Math.PI * 2;
      const st = new THREE.Mesh(new THREE.DodecahedronGeometry(0.32, 0), stoneMat);
      st.position.set(Math.cos(a) * 0.95, 0.2, Math.sin(a) * 0.95);
      group.add(st);
    }

    const logMat = new THREE.MeshStandardMaterial({ color: 0x451a03, roughness: 0.95 });
    for (let i = 0; i < 3; i++) {
      const log = new THREE.Mesh(new THREE.CylinderGeometry(0.14, 0.18, 1.6, 5), logMat);
      log.position.y = 0.35;
      log.rotation.set(0.45, (i * Math.PI) / 3, 1.25);
      group.add(log);
    }

    const flame = new THREE.Mesh(new THREE.ConeGeometry(0.55, 1.45, 6), this.torchFlameMat);
    flame.position.y = 0.85;
    group.add(flame);

    this.scene.add(group);
    this.campfireFlames.push({ mesh: flame, baseY: 0.85, phase });
    this.torchSites.push({ x, y, z, isCampfire: true, phase });
  }

  private placeRoadsideTrailTorches(
    sampler: TerrainSampler,
    trails: TrailPoint[][] | undefined,
    zones: Record<string, ZoneMeta> | undefined,
    npcs: NpcData[] | undefined,
    bossX: number,
    bossZ: number,
  ): void {
    const zoneRespawns = zones ? Object.values(zones) : [];
    let placed = 0;

    const tryHeight = (tx: number, tz: number, minTorchDistSq: number): number | null => {
      const h = sampler.getHeight(tx, tz);
      if (h <= sampler.seaLevel + 1.0 || sampler.getSlope(tx, tz) > 1.25) return null;
      if (Math.hypot(tx - bossX, tz - bossZ) < 62) return null;
      for (const z of zoneRespawns) {
        if (Math.hypot(tx - z.respawnX, tz - z.respawnZ) < 14) return null;
      }
      if (npcs) {
        for (const npc of npcs) {
          if (Math.hypot(tx - npc.x, tz - npc.z) < 5.5) return null;
        }
      }
      for (let i = 0; i < this.torchSites.length; i++) {
        const existing = this.torchSites[i];
        const dx = tx - existing.x;
        const dz = tz - existing.z;
        if (dx * dx + dz * dz < minTorchDistSq) return null;
      }
      return h;
    };

    if (trails && trails.length > 0) {
      const spacing = 40.0;
      const lateralOffset = 8.5;
      const minTorchDistSq = 24 * 24;
      let side = 1;

      for (const line of trails) {
        if (line.length < 2) continue;
        let distToNext = spacing * 0.5;
        for (let i = 0; i < line.length - 1; i++) {
          const a = line[i];
          const b = line[i + 1];
          const segDx = b.x - a.x;
          const segDz = b.z - a.z;
          const segLen = Math.hypot(segDx, segDz);
          if (segLen < 0.001) continue;

          const dirX = segDx / segLen;
          const dirZ = segDz / segLen;
          const perpX = -dirZ;
          const perpZ = dirX;

          let cursor = distToNext;
          while (cursor <= segLen) {
            const px = a.x + dirX * cursor;
            const pz = a.z + dirZ * cursor;

            for (const lat of [side * lateralOffset, -side * lateralOffset, 0.0]) {
              const tx = px + perpX * lat;
              const tz = pz + perpZ * lat;
              const ty = tryHeight(tx, tz, minTorchDistSq);
              if (ty !== null) {
                this.torchSites.push({ x: tx, y: ty, z: tz, isCampfire: false, phase: placed * 0.77 });
                placed++;
                break;
              }
            }

            side *= -1;
            cursor += spacing;
          }
          distToNext = cursor - segLen;
        }
      }
      return;
    }

    // Fallback scan if trail polylines are absent
    const minDistSq = 42 * 42;
    for (let gz = -3200; gz <= 3200 && placed < 320; gz += 40) {
      for (let gx = -3200; gx <= 3200 && placed < 320; gx += 40) {
        if (sampler.getTrail(gx, gz) < 92) continue;
        const h = tryHeight(gx, gz, minDistSq);
        if (h === null) continue;
        this.torchSites.push({ x: gx, y: h, z: gz, isCampfire: false, phase: placed * 0.77 });
        placed++;
      }
    }
  }

  private renderTorchInstances(): void {
    const postSites = this.torchSites.filter((t) => !t.isCampfire);
    if (postSites.length === 0) return;

    const postGeo = new THREE.CylinderGeometry(0.11, 0.16, 3.2, 5);
    postGeo.translate(0, 1.6, 0);
    const postMesh = new THREE.InstancedMesh(
      postGeo,
      new THREE.MeshStandardMaterial({ color: 0x5c3a21, roughness: 0.9, flatShading: true }),
      postSites.length,
    );

    const brazierGeo = new THREE.CylinderGeometry(0.28, 0.14, 0.34, 6);
    brazierGeo.translate(0, 3.18, 0);
    const brazierMesh = new THREE.InstancedMesh(
      brazierGeo,
      new THREE.MeshStandardMaterial({ color: 0x27272a, roughness: 0.65, metalness: 0.45, flatShading: true }),
      postSites.length,
    );

    const flameGeo = new THREE.ConeGeometry(0.28, 0.75, 5);
    flameGeo.translate(0, 3.45, 0);
    const flameMesh = new THREE.InstancedMesh(flameGeo, this.torchFlameMat, postSites.length);

    postSites.forEach((t, idx) => {
      this.dummy.position.set(t.x, t.y - 0.1, t.z);
      this.dummy.rotation.set(0, t.phase, 0);
      this.dummy.scale.set(1, 1, 1);
      this.dummy.updateMatrix();
      postMesh.setMatrixAt(idx, this.dummy.matrix);
      brazierMesh.setMatrixAt(idx, this.dummy.matrix);
      flameMesh.setMatrixAt(idx, this.dummy.matrix);
    });

    postMesh.instanceMatrix.needsUpdate = true;
    brazierMesh.instanceMatrix.needsUpdate = true;
    flameMesh.instanceMatrix.needsUpdate = true;
    this.scene.add(postMesh, brazierMesh, flameMesh);
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
