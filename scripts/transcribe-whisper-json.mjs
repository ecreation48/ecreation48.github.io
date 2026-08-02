#!/usr/bin/env node
import { mkdtemp, readFile, rm, stat } from 'node:fs/promises';
import { tmpdir } from 'node:os';
import { basename, join } from 'node:path';
import { spawn } from 'node:child_process';

function usage() {
  console.error('Usage: node scripts/transcribe-whisper-json.mjs /path/to/audio.wav');
  console.error('Required env: WHISPER_CPP_MODEL=/opt/whisper.cpp/models/ggml-small.bin');
  process.exit(2);
}

function spawnFile(command, args) {
  return new Promise((resolve) => {
    const child = spawn(command, args, { stdio: ['ignore', 'pipe', 'pipe'] });
    const stdout = [];
    const stderr = [];

    child.stdout.on('data', (chunk) => stdout.push(Buffer.from(chunk)));
    child.stderr.on('data', (chunk) => stderr.push(Buffer.from(chunk)));
    child.on('error', (error) => resolve({ code: 1, stdout: '', stderr: error.message }));
    child.on('close', (code) => resolve({
      code: code ?? 1,
      stdout: Buffer.concat(stdout).toString('utf8'),
      stderr: Buffer.concat(stderr).toString('utf8'),
    }));
  });
}

function stripWrappingQuotes(value) {
  const trimmed = String(value ?? '').trim();
  if (trimmed.length >= 2) {
    const first = trimmed[0];
    const last = trimmed[trimmed.length - 1];

    if ((first === '"' && last === '"') || (first === "'" && last === "'")) {
      return trimmed.slice(1, -1);
    }
  }

  return trimmed;
}

function parseTimestamp(value) {
  if (typeof value === 'number') return value;
  if (typeof value !== 'string') return 0;

  const match = value.trim().match(/^(?:(\d+):)?(\d{1,2}):(\d{1,2})(?:[,.](\d+))?$/);
  if (!match) return Number(value) || 0;

  const hours = Number(match[1] ?? 0);
  const minutes = Number(match[2] ?? 0);
  const seconds = Number(match[3] ?? 0);
  const fraction = Number(`0.${match[4] ?? 0}`);

  return (hours * 3600) + (minutes * 60) + seconds + fraction;
}

function normalizeSegment(segment, index) {
  const timestamps = segment.timestamps ?? {};
  const start = segment.start ?? segment.start_seconds ?? timestamps.from ?? timestamps.start ?? 0;
  const end = segment.end ?? segment.end_seconds ?? timestamps.to ?? timestamps.end ?? start;
  const text = String(segment.text ?? '').trim();

  return {
    start_seconds: parseTimestamp(start),
    end_seconds: parseTimestamp(end),
    text,
    ...(typeof segment.confidence === 'number' ? { confidence: segment.confidence } : {}),
    position: index,
  };
}

function normalizePayload(payload, language) {
  const rawSegments = Array.isArray(payload.segments)
    ? payload.segments
    : Array.isArray(payload.transcription)
      ? payload.transcription
      : [];

  const segments = rawSegments
    .map((segment, index) => normalizeSegment(segment, index))
    .filter((segment) => segment.text !== '');

  const text = typeof payload.text === 'string'
    ? payload.text.trim()
    : segments.map((segment) => segment.text).join(' ').trim();

  return {
    text,
    language: typeof payload.language === 'string' ? payload.language : language,
    segments: segments.map(({ position: _position, ...segment }) => segment),
  };
}

const audioPath = stripWrappingQuotes(process.argv[2] ?? process.env.AUDIO_FILE);
const modelPath = stripWrappingQuotes(process.env.WHISPER_CPP_MODEL);
const binary = stripWrappingQuotes(process.env.WHISPER_CPP_BINARY ?? 'whisper-cli');
const language = process.env.TRANSCRIPTION_LANGUAGE ?? 'fr';
const useGpu = process.env.WHISPER_CPP_USE_GPU === 'true';

if (!audioPath || !modelPath) usage();

const workDir = await mkdtemp(join(tmpdir(), 'voice-guardian-whisper-'));
const outputBase = join(workDir, basename(audioPath).replace(/\.[^.]+$/, ''));

try {
  await stat(audioPath).catch(() => {
    throw new Error(`Fichier audio introuvable pour Whisper : ${audioPath}`);
  });

  await stat(modelPath).catch(() => {
    throw new Error(`Modèle Whisper introuvable : ${modelPath}`);
  });

  const args = [
    '-m', modelPath,
    '-f', audioPath,
    '-l', language,
    '-oj',
    '-of', outputBase,
  ];

  if (!useGpu) {
    args.push('-ng');
  }

  const result = await spawnFile(binary, args);

  if (result.code !== 0) {
    throw new Error(`whisper.cpp a échoué (${result.code}) : ${result.stderr || result.stdout}`);
  }

  const jsonPath = `${outputBase}.json`;
  const payload = JSON.parse(await readFile(jsonPath, 'utf8'));

  console.log(JSON.stringify(normalizePayload(payload, language)));
} finally {
  await rm(workDir, { recursive: true, force: true });
}
