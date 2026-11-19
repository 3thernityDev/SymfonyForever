import type { InventoryStatePayload, ManifestResponse, QuestStatePayload, SelfState, SyncResponse } from './types';

export type SyncOutcome =
  | { status: 'ok'; data: SyncResponse }
  | { status: 'busy' }
  | { status: 'auth_expired' }
  | { status: 'error' };

export class NetworkClient {
  public lastPingMs = 0;
  private syncing = false;

  public isSyncing(): boolean {
    return this.syncing;
  }

  public async fetchManifest(): Promise<ManifestResponse> {
    const res = await fetch('/api/game/manifest', { credentials: 'same-origin' });
    if (!res.ok) throw new Error('Échec chargement manifest Symfony');
    return (await res.json()) as ManifestResponse;
  }

  public async syncWorld(payload: {
    characterId: number;
    x: number;
    z: number;
    rotY: number;
    isMoving: boolean;
    targetType: string | null;
    targetId: number | null;
    lastEventId: number;
    lastChatId: number;
  }): Promise<SyncOutcome> {
    if (this.syncing) return { status: 'busy' };
    this.syncing = true;
    const t0 = performance.now();
    const controller = new AbortController();
    const timer = window.setTimeout(() => controller.abort(), 6000);
    try {
      const res = await fetch('/api/world/sync', {
        method: 'POST',
        credentials: 'same-origin',
        headers: {
          'Content-Type': 'application/json',
          Accept: 'application/json',
        },
        body: JSON.stringify(payload),
        signal: controller.signal,
      });
      this.lastPingMs = Math.round(performance.now() - t0);

      // Detect session expiration (Symfony form_login redirects /api/* to /login HTML or returns 401/403)
      const contentType = res.headers.get('content-type') ?? '';
      if (res.status === 401 || res.status === 403 || (res.redirected && res.url.includes('/login')) || !contentType.includes('application/json')) {
        if (res.status === 401 || res.status === 403 || res.url.includes('/login')) {
          return { status: 'auth_expired' };
        }
        return { status: 'error' };
      }

      if (!res.ok) return { status: 'error' };
      const data = (await res.json()) as SyncResponse;
      return { status: 'ok', data };
    } catch {
      return { status: 'error' };
    } finally {
      window.clearTimeout(timer);
      this.syncing = false;
    }
  }

  public async castSkill(
    characterId: number,
    skillCode: string,
    targetType: string | null,
    targetId: number | null,
  ): Promise<{
    ok: boolean;
    error?: string;
    self?: SelfState;
    quests?: QuestStatePayload;
    events?: SyncResponse['events'];
    xpGained?: number;
    leveledUp?: boolean;
  }> {
    const res = await fetch('/api/combat/cast', {
      method: 'POST',
      credentials: 'same-origin',
      headers: { 'Content-Type': 'application/json' },
      body: JSON.stringify({ characterId, skillCode, targetType, targetId }),
    });
    return (await res.json()) as {
      ok: boolean;
      error?: string;
      self?: SelfState;
      quests?: QuestStatePayload;
      events?: SyncResponse['events'];
      xpGained?: number;
      leveledUp?: boolean;
    };
  }

  public async autoAttack(
    characterId: number,
    targetType: string | null,
    targetId: number | null,
  ): Promise<{
    ok: boolean;
    error?: string;
    self?: SelfState;
    quests?: QuestStatePayload;
    events?: SyncResponse['events'];
    xpGained?: number;
    leveledUp?: boolean;
    resourceGain?: number;
  }> {
    const res = await fetch('/api/combat/attack', {
      method: 'POST',
      credentials: 'same-origin',
      headers: { 'Content-Type': 'application/json' },
      body: JSON.stringify({ characterId, targetType, targetId }),
    });
    return (await res.json()) as {
      ok: boolean;
      error?: string;
      self?: SelfState;
      quests?: QuestStatePayload;
      events?: SyncResponse['events'];
      xpGained?: number;
      leveledUp?: boolean;
      resourceGain?: number;
    };
  }

  public async allocateStat(characterId: number, stat: string): Promise<{ ok: boolean; error?: string; self?: SelfState }> {
    const res = await fetch('/api/character/allocate-stat', {
      method: 'POST',
      credentials: 'same-origin',
      headers: { 'Content-Type': 'application/json' },
      body: JSON.stringify({ characterId, stat }),
    });
    return (await res.json()) as { ok: boolean; error?: string; self?: SelfState };
  }

  public async upgradeSkill(
    characterId: number,
    skillCode: string,
  ): Promise<{ ok: boolean; error?: string; skillCode?: string; newRank?: number; self?: SelfState }> {
    const res = await fetch('/api/character/upgrade-skill', {
      method: 'POST',
      credentials: 'same-origin',
      headers: { 'Content-Type': 'application/json' },
      body: JSON.stringify({ characterId, skillCode }),
    });
    return (await res.json()) as { ok: boolean; error?: string; skillCode?: string; newRank?: number; self?: SelfState };
  }

  public async respawn(characterId: number): Promise<{ ok: boolean; self?: SelfState }> {
    const res = await fetch('/api/character/respawn', {
      method: 'POST',
      credentials: 'same-origin',
      headers: { 'Content-Type': 'application/json' },
      body: JSON.stringify({ characterId }),
    });
    return (await res.json()) as { ok: boolean; self?: SelfState };
  }

  public async travel(
    characterId: number,
    zoneCode: string,
  ): Promise<{ ok: boolean; error?: string; self?: SelfState; quests?: QuestStatePayload }> {
    const res = await fetch('/api/character/travel', {
      method: 'POST',
      credentials: 'same-origin',
      headers: { 'Content-Type': 'application/json' },
      body: JSON.stringify({ characterId, zoneCode }),
    });
    return (await res.json()) as { ok: boolean; error?: string; self?: SelfState; quests?: QuestStatePayload };
  }

  public async acceptQuest(
    characterId: number,
    questCode: string,
  ): Promise<{ ok: boolean; error?: string; self?: SelfState; quests?: QuestStatePayload }> {
    const res = await fetch('/api/quests/accept', {
      method: 'POST',
      credentials: 'same-origin',
      headers: { 'Content-Type': 'application/json' },
      body: JSON.stringify({ characterId, questCode }),
    });
    return (await res.json()) as { ok: boolean; error?: string; self?: SelfState; quests?: QuestStatePayload };
  }

  public async completeQuest(
    characterId: number,
    questCode: string,
  ): Promise<{
    ok: boolean;
    error?: string;
    rewardXp?: number;
    rewardStatPoints?: number;
    leveledUp?: boolean;
    newLevel?: number;
    completionText?: string;
    self?: SelfState;
    quests?: QuestStatePayload;
  }> {
    const res = await fetch('/api/quests/complete', {
      method: 'POST',
      credentials: 'same-origin',
      headers: { 'Content-Type': 'application/json' },
      body: JSON.stringify({ characterId, questCode }),
    });
    return (await res.json()) as {
      ok: boolean;
      error?: string;
      rewardXp?: number;
      rewardStatPoints?: number;
      leveledUp?: boolean;
      newLevel?: number;
      completionText?: string;
      self?: SelfState;
      quests?: QuestStatePayload;
    };
  }

  public async sendChat(characterId: number, channel: string, content: string): Promise<{ ok: boolean; error?: string }> {
    const res = await fetch('/api/chat/send', {
      method: 'POST',
      credentials: 'same-origin',
      headers: { 'Content-Type': 'application/json' },
      body: JSON.stringify({ characterId, channel, content }),
    });
    return (await res.json()) as { ok: boolean; error?: string };
  }

  public async partyAction(
    action: 'invite' | 'accept' | 'decline' | 'leave',
    body: Record<string, unknown>,
  ): Promise<{ ok: boolean; error?: string; message?: string }> {
    const res = await fetch(`/api/party/${action}`, {
      method: 'POST',
      credentials: 'same-origin',
      headers: { 'Content-Type': 'application/json' },
      body: JSON.stringify(body),
    });
    return (await res.json()) as { ok: boolean; error?: string; message?: string };
  }

  public async inventoryPost<T extends Record<string, unknown> = Record<string, unknown>>(
    path: string,
    body: Record<string, unknown>,
  ): Promise<T & { ok: boolean; error?: string; self?: SelfState; inventory?: InventoryStatePayload }> {
    const res = await fetch(path, {
      method: 'POST',
      credentials: 'same-origin',
      headers: { 'Content-Type': 'application/json' },
      body: JSON.stringify(body),
    });
    return (await res.json()) as T & { ok: boolean; error?: string; self?: SelfState; inventory?: InventoryStatePayload };
  }
}
