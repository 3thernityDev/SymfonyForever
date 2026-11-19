import type { ManifestResponse } from './types';

/**
 * Client-side Réunion heightmap & biome sampler mirroring PHP ReunionTerrainService 1:1.
 */
export class TerrainSampler {
  public readonly resolution: number;
  public readonly worldSize: number;
  public readonly halfWorld: number;
  public readonly maxElevation: number;
  public readonly seaLevel: number;
  public readonly walkableMinY: number;
  public readonly maxWalkableSlope: number;

  private heights: Float32Array;
  private biomes: Uint8Array;
  private trails: Uint8Array;

  private constructor(
    manifest: ManifestResponse['world'],
    heights: Float32Array,
    biomes: Uint8Array,
    trails: Uint8Array,
  ) {
    this.resolution = manifest.mapResolution;
    this.worldSize = manifest.worldSize;
    this.halfWorld = manifest.worldSize * 0.5;
    this.maxElevation = manifest.maxElevation;
    this.seaLevel = manifest.seaLevel;
    this.walkableMinY = manifest.walkableMinY;
    this.maxWalkableSlope = manifest.maxWalkableSlope;
    this.heights = heights;
    this.biomes = biomes;
    this.trails = trails;
  }

  public static async load(world: ManifestResponse['world']): Promise<TerrainSampler> {
    const [hImg, bImg] = await Promise.all([
      TerrainSampler.loadImage(world.heightmapUrl),
      TerrainSampler.loadImage(world.biomesUrl),
    ]);

    const res = world.mapResolution;
    const canvas = document.createElement('canvas');
    canvas.width = res;
    canvas.height = res;
    const ctx = canvas.getContext('2d', { willReadFrequently: true });
    if (!ctx) {
      throw new Error('Canvas 2D indisponible');
    }

    ctx.drawImage(hImg, 0, 0, res, res);
    const hData = ctx.getImageData(0, 0, res, res).data;
    const heights = new Float32Array(res * res);
    for (let i = 0; i < res * res; i++) {
      const r = hData[i * 4];
      const g = hData[i * 4 + 1];
      const h16 = (r << 8) | g;
      heights[i] = (h16 / 65535.0) * world.maxElevation;
    }

    ctx.clearRect(0, 0, res, res);
    ctx.drawImage(bImg, 0, 0, res, res);
    const bData = ctx.getImageData(0, 0, res, res).data;
    const biomes = new Uint8Array(res * res);
    const trails = new Uint8Array(res * res);
    for (let i = 0; i < res * res; i++) {
      biomes[i] = bData[i * 4];
      trails[i] = bData[i * 4 + 2];
    }

    return new TerrainSampler(world, heights, biomes, trails);
  }

  private static loadImage(url: string): Promise<HTMLImageElement> {
    return new Promise((resolve, reject) => {
      const img = new Image();
      img.crossOrigin = 'anonymous';
      img.onload = () => resolve(img);
      img.onerror = () => reject(new Error(`Impossible de charger ${url}`));
      img.src = url;
    });
  }

  public getHeight(wx: number, wz: number): number {
    const u = wx / this.worldSize + 0.5;
    const v = wz / this.worldSize + 0.5;
    if (u < 0 || u > 1 || v < 0 || v > 1) return 0;

    const fx = u * (this.resolution - 1);
    const fz = v * (this.resolution - 1);
    const x0 = Math.floor(fx);
    const z0 = Math.floor(fz);
    const x1 = Math.min(this.resolution - 1, x0 + 1);
    const z1 = Math.min(this.resolution - 1, z0 + 1);
    const tx = fx - x0;
    const tz = fz - z0;

    const h00 = this.heights[z0 * this.resolution + x0];
    const h10 = this.heights[z0 * this.resolution + x1];
    const h01 = this.heights[z1 * this.resolution + x0];
    const h11 = this.heights[z1 * this.resolution + x1];

    const hx0 = h00 + (h10 - h00) * tx;
    const hx1 = h01 + (h11 - h01) * tx;
    return hx0 + (hx1 - hx0) * tz;
  }

  public getSlope(wx: number, wz: number): number {
    const eps = 4.0;
    const hL = this.getHeight(wx - eps, wz);
    const hR = this.getHeight(wx + eps, wz);
    const hD = this.getHeight(wx, wz - eps);
    const hU = this.getHeight(wx, wz + eps);
    const dx = (hR - hL) / (2 * eps);
    const dz = (hU - hD) / (2 * eps);
    return Math.hypot(dx, dz);
  }

  public getBiome(wx: number, wz: number): number {
    const u = Math.max(0, Math.min(1, wx / this.worldSize + 0.5));
    const v = Math.max(0, Math.min(1, wz / this.worldSize + 0.5));
    const gx = Math.round(u * (this.resolution - 1));
    const gz = Math.round(v * (this.resolution - 1));
    return this.biomes[gz * this.resolution + gx];
  }

  public getTrail(wx: number, wz: number): number {
    const u = Math.max(0, Math.min(1, wx / this.worldSize + 0.5));
    const v = Math.max(0, Math.min(1, wz / this.worldSize + 0.5));
    const gx = Math.round(u * (this.resolution - 1));
    const gz = Math.round(v * (this.resolution - 1));
    return this.trails[gz * this.resolution + gx];
  }

  public isWalkable(wx: number, wz: number): boolean {
    if (Math.abs(wx) > this.halfWorld - 20 || Math.abs(wz) > this.halfWorld - 20) return false;
    const h = this.getHeight(wx, wz);
    if (h < this.walkableMinY) return false;
    return this.getSlope(wx, wz) <= this.maxWalkableSlope;
  }
}
