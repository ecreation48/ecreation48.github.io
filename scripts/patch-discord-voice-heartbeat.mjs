import { existsSync, readFileSync, writeFileSync } from 'node:fs';
import { join } from 'node:path';

const candidates = [
  join(process.cwd(), 'node_modules/@discordjs/voice/dist/index.js'),
  join(process.cwd(), 'node_modules/@discordjs/voice/dist/index.mjs'),
  join(process.cwd(), 'apps/discord-manager/node_modules/@discordjs/voice/dist/index.js'),
  join(process.cwd(), 'apps/discord-manager/node_modules/@discordjs/voice/dist/index.mjs'),
];

const marker = 'this.sendHeartbeat();\n      this.debug?.("sent immediate heartbeat");';
const needle = `      this.heartbeatInterval = setInterval(() => {
        if (this.lastHeartbeatSend !== 0 && this.missedHeartbeats >= 3) {
          this.ws.close();
          this.setHeartbeatInterval(-1);
        }
        this.sendHeartbeat();
      }, ms);
`;
const replacement = `      this.heartbeatInterval = setInterval(() => {
        if (this.lastHeartbeatSend !== 0 && this.missedHeartbeats >= 3) {
          this.ws.close();
          this.setHeartbeatInterval(-1);
        }
        this.sendHeartbeat();
      }, ms);
      ${marker}
`;

let patched = 0;

for (const path of candidates) {
  if (!existsSync(path)) continue;

  const source = readFileSync(path, 'utf8');
  if (source.includes(marker)) {
    patched++;
    continue;
  }

  if (!source.includes(needle)) {
    throw new Error(`Unable to find @discordjs/voice heartbeat block in ${path}`);
  }

  writeFileSync(path, source.replace(needle, replacement));
  patched++;
}

if (patched === 0) {
  throw new Error('Unable to find @discordjs/voice dist/index.js to patch');
}

console.log(`Patched @discordjs/voice heartbeat in ${patched} installation(s).`);
