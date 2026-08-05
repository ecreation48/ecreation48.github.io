import type { Redis } from 'ioredis';
import type { AudioBufferRecorder } from './audio-buffer-recorder.js';
import type { ApiClient, ChannelAssignment, ForbiddenWord, TranscriptPayload, TranscriptSegmentPayload } from './api-client.js';
import { TranscriptionRunner } from './transcription-runner.js';

interface Target extends ChannelAssignment {
  user_ids: string[];
}

interface Match {
  word: ForbiddenWord;
  confidence: number | null;
  segment?: TranscriptSegmentPayload;
}

const ENABLED = process.env.AUTO_BLOCKED_WORD_DETECTION !== 'false';
const INTERVAL_MS = Number(process.env.AUTO_BLOCKED_WORD_INTERVAL_MS ?? 30_000);
const MAX_TRANSCRIPTIONS_PER_CYCLE = Number(process.env.AUTO_BLOCKED_WORD_MAX_TRANSCRIPTIONS_PER_CYCLE ?? 2);
const COOLDOWN_SECONDS = Number(process.env.AUTO_BLOCKED_WORD_COOLDOWN_SECONDS ?? 300);
const REPORTER_ID = 'automatic:blocked-word-detection';

export class AutomaticBlockedWordRunner {
  private running = false;
  private lastRunAt = 0;
  private forbiddenWords: ForbiddenWord[] = [];
  private forbiddenWordsLoadedAt = 0;
  private readonly transcriber = new TranscriptionRunner();

  constructor(
    private readonly api: ApiClient,
    private readonly redis: Redis,
    private readonly audioRecorder: AudioBufferRecorder,
  ) {}

  async runDue(targets: Target[]): Promise<void> {
    if (!ENABLED || this.running || Date.now() - this.lastRunAt < INTERVAL_MS) return;

    this.running = true;

    try {
      await this.refreshForbiddenWords();
      if (this.forbiddenWords.length === 0) return;

      let processed = 0;

      const prioritizedTargets = targets
        .filter((target) => target.auto_detection_enabled !== false)
        .sort((a, b) => Number(b.auto_detection_priority ?? 0) - Number(a.auto_detection_priority ?? 0));

      for (const target of prioritizedTargets) {
        for (const userId of target.user_ids) {
          if (processed >= MAX_TRANSCRIPTIONS_PER_CYCLE) return;

          await this.scanUser(target, userId).catch((error) => {
            console.error(JSON.stringify({
              level: 'error',
              event: 'auto_blocked_word_scan_failed',
              guild_id: target.guild_discord_id,
              channel_id: target.channel_discord_id,
              user_id: userId,
              message: error instanceof Error ? error.message : 'unknown',
            }));
          });

          processed++;
        }
      }
    } finally {
      this.lastRunAt = Date.now();
      this.running = false;
    }
  }

  private async scanUser(target: Target, userId: string): Promise<void> {
    const reportKey = `${target.channel_id}-${userId}-${Date.now()}`;
    const snapshot = await this.audioRecorder.snapshot(target.guild_discord_id, userId, `auto-${reportKey}`);
    if (!snapshot || snapshot.durationSeconds < 2) return;

    const transcript = await this.transcriber.transcribe(`auto-${reportKey}`, `auto-${reportKey}`, userId, snapshot.path);
    if (transcript.status !== 'completed' || !transcript.text) return;

    const match = this.firstMatch(transcript);
    if (!match) return;
    if (!await this.claimCooldown(target, userId, match.word.normalized_word)) return;

    const confidence = match.confidence ?? transcript.confidence ?? 0.6;
    const report = await this.api.createVoiceReport({
      guild_discord_id: target.guild_discord_id,
      channel_discord_id: target.channel_discord_id,
      reported_user_discord_id: userId,
      reporter_user_discord_id: REPORTER_ID,
      reason: 'Détection de mots bloqués',
      comment: [
        `Signalement automatique par détection de mots bloqués.`,
        `Mot détecté : ${match.word.word}`,
        `Gravité : ${match.word.severity}`,
        `Confiance estimée : ${Math.round(confidence * 100)}%`,
      ].join('\n'),
      source: 'blocked_word',
      detection_confidence: confidence,
      detection_metadata: {
        matched_word: match.word.word,
        normalized_word: match.word.normalized_word,
        severity: match.word.severity,
        transcript_text: transcript.text,
        segment: match.segment ?? null,
        engine: transcript.engine ?? null,
      },
    });

    const clip = await this.api.createAudioClip({
      voice_report_id: report.id,
      guild_discord_id: target.guild_discord_id,
      channel_discord_id: target.channel_discord_id,
      reported_user_discord_id: userId,
      storage_path: snapshot.path,
      mime_type: snapshot.mimeType,
      size_bytes: snapshot.bytes,
      duration_seconds: snapshot.durationSeconds,
      captured_from: snapshot.capturedFrom,
      captured_until: snapshot.capturedUntil,
    });

    await this.api.createTranscript({
      ...transcript,
      voice_report_id: report.id,
      voice_audio_clip_id: clip.id,
      reported_user_discord_id: userId,
    });

    console.log(JSON.stringify({
      level: 'warning',
      event: 'auto_blocked_word_report_created',
      report_id: report.id,
      guild_id: target.guild_discord_id,
      channel_id: target.channel_discord_id,
      user_id: userId,
      word: match.word.word,
      confidence,
    }));
  }

  private firstMatch(transcript: TranscriptPayload): Match | null {
    const segments = transcript.segments && transcript.segments.length > 0
      ? transcript.segments
      : [{ start_seconds: 0, end_seconds: 0, text: transcript.text ?? '', confidence: transcript.confidence ?? null }];

    for (const segment of segments) {
      const normalized = this.normalize(segment.text);

      for (const word of this.forbiddenWords) {
        const normalizedWord = this.normalize(word.normalized_word || word.word);
        if (!normalizedWord || !this.containsNormalizedTerm(normalized, normalizedWord)) continue;

        return {
          word,
          confidence: segment.confidence ?? transcript.confidence ?? null,
          segment,
        };
      }
    }

    return null;
  }

  private containsNormalizedTerm(text: string, term: string): boolean {
    if (term.includes(' ')) return text.includes(term);

    return new RegExp(`(^|\\s)${this.escapeRegExp(term)}($|\\s)`, 'u').test(text);
  }

  private async claimCooldown(target: Target, userId: string, normalizedWord: string): Promise<boolean> {
    const key = `auto-blocked-word:${target.guild_discord_id}:${target.channel_discord_id}:${userId}:${normalizedWord}`;
    return (await this.redis.set(key, '1', 'EX', COOLDOWN_SECONDS, 'NX').catch(() => null)) === 'OK';
  }

  private async refreshForbiddenWords(): Promise<void> {
    if (Date.now() - this.forbiddenWordsLoadedAt < 60_000) return;

    this.forbiddenWords = await this.api.forbiddenWords();
    this.forbiddenWordsLoadedAt = Date.now();
  }

  private normalize(text: string): string {
    return text
      .normalize('NFD')
      .replace(/\p{Diacritic}/gu, '')
      .toLowerCase()
      .replace(/[^\p{Letter}\p{Number}]+/gu, ' ')
      .replace(/\s+/g, ' ')
      .trim();
  }

  private escapeRegExp(value: string): string {
    return value.replace(/[.*+?^${}()|[\]\\]/g, '\\$&');
  }
}
