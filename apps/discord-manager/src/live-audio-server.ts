import { createServer, type ServerResponse } from 'node:http';

const SAMPLE_RATE = 48_000;
const CHANNELS = 2;
const BITS_PER_SAMPLE = 16;
const FRAME_MS = 20;
const FRAME_BYTES = SAMPLE_RATE * CHANNELS * (BITS_PER_SAMPLE / 8) * (FRAME_MS / 1000);
const MAX_FRAMES_PER_SPEAKER = 50;

type Client = ServerResponse;

interface ChannelState {
  clients: Set<Client>;
  speakers: Map<string, Buffer[]>;
  pending: Map<string, Buffer>;
  timer: NodeJS.Timeout | null;
}

export class LiveAudioServer {
  private readonly channels = new Map<string, ChannelState>();

  constructor(private readonly port = Number(process.env.LIVE_AUDIO_PORT ?? 8787)) {}

  start(): void {
    const server = createServer((request, response) => {
      const url = new URL(request.url ?? '/', `http://${request.headers.host ?? '127.0.0.1'}`);

      if (url.pathname === '/health') {
        response.writeHead(200, {
          'Content-Type': 'application/json',
          'Cache-Control': 'no-store',
        });
        response.end(JSON.stringify({ ok: true, channels: this.channels.size }));
        return;
      }

      const match = url.pathname.match(/^\/live\/([^/]+)\/([^/]+)$/);

      if (!match) {
        response.writeHead(404).end('Flux introuvable');
        return;
      }

      const [, guildId, channelId] = match;

      if (request.method === 'HEAD') {
        response.writeHead(200, {
          'Content-Type': 'audio/wav',
          'Cache-Control': 'no-store',
          'X-Voice-Guardian-Live': this.channels.has(`${guildId}:${channelId}`) ? 'connected' : 'ready',
        });
        response.end();
        return;
      }

      this.addClient(`${guildId}:${channelId}`, response);
    });

    server.on('error', (error) => {
      console.error(JSON.stringify({ level: 'error', event: 'live_audio_server_failed', message: error instanceof Error ? error.message : 'unknown', port: this.port }));
    });

    server.listen(this.port, '127.0.0.1', () => {
      console.log(JSON.stringify({ level: 'info', event: 'live_audio_server_started', port: this.port }));
    });
  }

  write(guildId: string, channelId: string, userId: string, pcm: Buffer): void {
    const state = this.channels.get(`${guildId}:${channelId}`);
    if (!state || state.clients.size === 0) return;

    this.enqueue(state, userId, pcm);
  }

  private addClient(key: string, response: ServerResponse): void {
    response.writeHead(200, {
      'Content-Type': 'audio/wav',
      'Cache-Control': 'no-store',
      Connection: 'keep-alive',
      'X-Accel-Buffering': 'no',
    });
    response.write(this.wavHeader());

    const state = this.channels.get(key) ?? {
      clients: new Set<Client>(),
      speakers: new Map<string, Buffer[]>(),
      pending: new Map<string, Buffer>(),
      timer: null,
    };

    state.clients.add(response);
    this.channels.set(key, state);

    console.log(JSON.stringify({
      level: 'info',
      event: 'live_audio_client_connected',
      channel_key: key,
      client_count: state.clients.size,
    }));

    if (!state.timer) {
      state.timer = setInterval(() => this.broadcastFrame(key, state), FRAME_MS);
    }

    response.on('close', () => {
      state.clients.delete(response);

      console.log(JSON.stringify({
        level: 'info',
        event: 'live_audio_client_disconnected',
        channel_key: key,
        client_count: state.clients.size,
      }));

      if (state.clients.size === 0) {
        if (state.timer) clearInterval(state.timer);
        this.channels.delete(key);
      }
    });
  }

  private enqueue(state: ChannelState, userId: string, pcm: Buffer): void {
    const pending = state.pending.get(userId);
    const next = pending ? Buffer.concat([pending, pcm]) : Buffer.from(pcm);
    const frames = state.speakers.get(userId) ?? [];
    let offset = 0;

    while (offset + FRAME_BYTES <= next.byteLength) {
      frames.push(next.subarray(offset, offset + FRAME_BYTES));
      offset += FRAME_BYTES;
    }

    while (frames.length > MAX_FRAMES_PER_SPEAKER) {
      frames.shift();
    }

    state.speakers.set(userId, frames);
    state.pending.set(userId, offset < next.byteLength ? next.subarray(offset) : Buffer.alloc(0));
  }

  private broadcastFrame(key: string, state: ChannelState): void {
    const frame = this.mixNextFrame(state);

    for (const client of state.clients) {
      client.write(frame);
    }

    if (state.clients.size === 0) {
      if (state.timer) clearInterval(state.timer);
      this.channels.delete(key);
    }
  }

  private mixNextFrame(state: ChannelState): Buffer {
    const sourceFrames: Buffer[] = [];

    for (const [userId, frames] of state.speakers) {
      const frame = frames.shift();

      if (frame) {
        sourceFrames.push(frame);
      }

      if (frames.length === 0) {
        state.speakers.delete(userId);
      }
    }

    if (sourceFrames.length === 0) {
      return Buffer.alloc(FRAME_BYTES);
    }

    if (sourceFrames.length === 1) {
      return sourceFrames[0] ?? Buffer.alloc(FRAME_BYTES);
    }

    const output = Buffer.alloc(FRAME_BYTES);

    for (let offset = 0; offset < FRAME_BYTES; offset += 2) {
      let sample = 0;

      for (const frame of sourceFrames) {
        sample += frame.readInt16LE(offset);
      }

      sample = Math.max(-32768, Math.min(32767, Math.round(sample / Math.sqrt(sourceFrames.length))));
      output.writeInt16LE(sample, offset);
    }

    return output;
  }

  private wavHeader(): Buffer {
    const header = Buffer.alloc(44);
    const byteRate = SAMPLE_RATE * CHANNELS * (BITS_PER_SAMPLE / 8);
    const blockAlign = CHANNELS * (BITS_PER_SAMPLE / 8);

    header.write('RIFF', 0);
    header.writeUInt32LE(0xffffffff, 4);
    header.write('WAVE', 8);
    header.write('fmt ', 12);
    header.writeUInt32LE(16, 16);
    header.writeUInt16LE(1, 20);
    header.writeUInt16LE(CHANNELS, 22);
    header.writeUInt32LE(SAMPLE_RATE, 24);
    header.writeUInt32LE(byteRate, 28);
    header.writeUInt16LE(blockAlign, 32);
    header.writeUInt16LE(BITS_PER_SAMPLE, 34);
    header.write('data', 36);
    header.writeUInt32LE(0xffffffff, 40);

    return header;
  }
}
