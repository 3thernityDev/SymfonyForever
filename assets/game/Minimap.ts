import type { LandmarkMeta, MonsterState, NpcData, PublicPlayer, QuestStatePayload } from './types';

export class Minimap {
  private readonly ctx: CanvasRenderingContext2D;
  private bgImage: HTMLImageElement | null = null;

  public constructor(
    private readonly canvas: HTMLCanvasElement,
    private readonly worldSize: number,
    private readonly landmarks: LandmarkMeta[],
    minimapUrl: string,
  ) {
    const ctx = canvas.getContext('2d');
    if (!ctx) throw new Error('Minimap 2D context unavailable');
    this.ctx = ctx;

    const img = new Image();
    img.onload = () => {
      this.bgImage = img;
    };
    img.src = minimapUrl;
  }

  public render(
    selfX: number,
    selfZ: number,
    selfYaw: number,
    partyId: number | null,
    players: PublicPlayer[],
    monsters: MonsterState[],
    npcs: NpcData[],
    quests: QuestStatePayload | null,
    timeSec: number,
  ): void {
    const w = this.canvas.width;
    const h = this.canvas.height;
    const ctx = this.ctx;

    ctx.clearRect(0, 0, w, h);
    if (this.bgImage) {
      ctx.drawImage(this.bgImage, 0, 0, w, h);
    } else {
      ctx.fillStyle = '#0e4880';
      ctx.fillRect(0, 0, w, h);
    }

    const toPx = (wx: number, wz: number): [number, number] => [
      ((wx / this.worldSize) + 0.5) * w,
      ((wz / this.worldSize) + 0.5) * h,
    ];

    // Landmarks
    for (const lm of this.landmarks) {
      const [lx, ly] = toPx(lm.x, lm.z);
      ctx.fillStyle = lm.type === 'boss' ? '#ff4500' : lm.type === 'hub' ? '#2dd4bf' : 'rgba(253,224,71,0.5)';
      ctx.beginPath();
      ctx.arc(lx, ly, lm.type === 'boss' ? 3.2 : 1.8, 0, Math.PI * 2);
      ctx.fill();
    }

    // Active Quest Guidance Pulsing Ring
    if (quests?.guidance) {
      const [gx, gy] = toPx(quests.guidance.x, quests.guidance.z);
      const pulse = 5.5 + Math.sin(timeSec * 5.0) * 2.5;
      ctx.strokeStyle = quests.guidance.mode === 'TURN_IN' ? '#34d399' : '#facc15';
      ctx.lineWidth = 2;
      ctx.beginPath();
      ctx.arc(gx, gy, pulse, 0, Math.PI * 2);
      ctx.stroke();
    }

    // Quest NPCs
    for (const npc of npcs) {
      const marker = quests?.npcMarkers[npc.code] ?? 'NONE';
      const [nx, ny] = toPx(npc.x, npc.z);
      if (marker === 'TURN_IN' || marker === 'AVAILABLE') {
        ctx.fillStyle = marker === 'TURN_IN' ? '#34d399' : '#fde047';
        ctx.strokeStyle = '#07111a';
        ctx.lineWidth = 1.2;
        ctx.beginPath();
        ctx.arc(nx, ny, 4.0, 0, Math.PI * 2);
        ctx.fill();
        ctx.stroke();
      } else {
        ctx.fillStyle = '#fef3c7';
        ctx.beginPath();
        ctx.arc(nx, ny, 2.2, 0, Math.PI * 2);
        ctx.fill();
      }
    }

    // Nearby Monsters
    for (const m of monsters) {
      if (m.state === 'DEAD') continue;
      const [mx, my] = toPx(m.x, m.z);
      ctx.fillStyle = m.isBoss ? '#ff2a2a' : '#f87171';
      ctx.beginPath();
      ctx.arc(mx, my, m.isBoss ? 4.0 : 2.0, 0, Math.PI * 2);
      ctx.fill();
    }

    // Nearby Other Players
    for (const p of players) {
      const [px, py] = toPx(p.x, p.z);
      ctx.fillStyle = partyId !== null && p.partyId === partyId ? '#34d399' : '#38bdf8';
      ctx.beginPath();
      ctx.arc(px, py, 3.0, 0, Math.PI * 2);
      ctx.fill();
    }

    // Self Player Directional Arrow
    const [sx, sy] = toPx(selfX, selfZ);
    ctx.save();
    ctx.translate(sx, sy);
    ctx.rotate(-selfYaw);
    ctx.fillStyle = '#ffffff';
    ctx.strokeStyle = '#07111a';
    ctx.lineWidth = 1.5;
    ctx.beginPath();
    ctx.moveTo(0, -6);
    ctx.lineTo(4.2, 4.5);
    ctx.lineTo(0, 2);
    ctx.lineTo(-4.2, 4.5);
    ctx.closePath();
    ctx.fill();
    ctx.stroke();
    ctx.restore();
  }
}
