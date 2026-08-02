import { execFile } from 'node:child_process';
import { readFile } from 'node:fs/promises';
import { basename } from 'node:path';
import { promisify } from 'node:util';
import type { TranscriptPayload, TranscriptSegmentPayload } from './api-client.js';

const execFileAsync = promisify(execFile);

type TranscriptionProvider = 'openai' | 'command' | 'none';

interface EngineResult {
  text?: string;
  language?: string;
  confidence?: number;
  segments?: TranscriptSegmentPayload[];
}

interface OpenAISegment {
  start?: number;
  end?: number;
  text?: string;
  avg_logprob?: number;
  no_speech_prob?: number;
}

interface OpenAITranscriptionResponse {
  text?: string;
  language?: string;
  duration?: number;
  segments?: OpenAISegment[];
}

export class TranscriptionRunner {
  private readonly provider = this.resolveProvider();
  private readonly command = process.env.TRANSCRIPTION_COMMAND;
  private readonly commandEngine = process.env.TRANSCRIPTION_ENGINE ?? 'local-command';
  private readonly openAiApiKey = process.env.OPENAI_API_KEY;
  private readonly openAiModel = process.env.OPENAI_TRANSCRIPTION_MODEL ?? 'whisper-1';
  private readonly openAiBaseUrl = process.env.OPENAI_BASE_URL ?? 'https://api.openai.com/v1';
  private readonly language = process.env.TRANSCRIPTION_LANGUAGE ?? 'fr';

  async transcribe(reportId: string, clipId: string, userId: string, path: string): Promise<TranscriptPayload> {
    if (this.provider === 'none') {
      return {
        voice_report_id: reportId,
        voice_audio_clip_id: clipId,
        reported_user_discord_id: userId,
        status: 'skipped',
        engine: 'non_configure',
        error_message: 'Configure OPENAI_API_KEY ou TRANSCRIPTION_COMMAND pour activer la transcription.',
      };
    }

    const startedAt = Date.now();

    try {
      const parsed = this.provider === 'openai'
        ? await this.transcribeWithOpenAI(path)
        : this.parseOutput(await this.runCommand(path));

      return {
        voice_report_id: reportId,
        voice_audio_clip_id: clipId,
        reported_user_discord_id: userId,
        status: 'completed',
        text: parsed.text ?? '',
        language: parsed.language ?? this.language,
        confidence: parsed.confidence ?? null,
        engine: this.engineName(),
        duration_ms: Date.now() - startedAt,
        segments: parsed.segments ?? [],
      };
    } catch (error) {
      return {
        voice_report_id: reportId,
        voice_audio_clip_id: clipId,
        reported_user_discord_id: userId,
        status: 'failed',
        engine: this.engineName(),
        duration_ms: Date.now() - startedAt,
        error_message: error instanceof Error ? error.message : 'Erreur de transcription inconnue',
      };
    }
  }

  private resolveProvider(): TranscriptionProvider {
    const configured = process.env.TRANSCRIPTION_PROVIDER?.toLowerCase();
    if (configured === 'openai' || configured === 'command' || configured === 'none') return configured;
    if (process.env.OPENAI_API_KEY) return 'openai';
    if (process.env.TRANSCRIPTION_COMMAND) return 'command';
    return 'none';
  }

  private engineName(): string {
    if (this.provider === 'openai') return `openai:${this.openAiModel}`;
    if (this.provider === 'command') return this.commandEngine;
    return 'non_configure';
  }

  private async transcribeWithOpenAI(path: string): Promise<EngineResult> {
    if (!this.openAiApiKey) throw new Error('OPENAI_API_KEY n’est pas configurée.');

    const form = new FormData();
    const file = new Blob([await readFile(path)], { type: 'audio/wav' });
    form.append('file', file, basename(path));
    form.append('model', this.openAiModel);
    form.append('language', this.language);
    form.append('temperature', '0');

    if (this.openAiModel === 'whisper-1') {
      form.append('response_format', 'verbose_json');
      form.append('timestamp_granularities[]', 'segment');
    } else {
      form.append('response_format', 'json');
    }

    const response = await fetch(`${this.openAiBaseUrl}/audio/transcriptions`, {
      method: 'POST',
      headers: { authorization: `Bearer ${this.openAiApiKey}` },
      body: form,
      signal: AbortSignal.timeout(Number(process.env.TRANSCRIPTION_TIMEOUT_MS ?? 120_000)),
    });

    if (!response.ok) {
      throw new Error(`OpenAI transcription ${response.status}: ${await response.text().catch(() => '')}`);
    }

    return this.parseOpenAIResponse(await response.json() as OpenAITranscriptionResponse);
  }

  private parseOpenAIResponse(response: OpenAITranscriptionResponse): EngineResult {
    const result: EngineResult = {
      text: response.text ?? '',
      segments: Array.isArray(response.segments)
        ? response.segments
          .filter((segment) => typeof segment.text === 'string')
          .map((segment) => ({
            start_seconds: Number(segment.start ?? 0),
            end_seconds: Number(segment.end ?? segment.start ?? 0),
            text: segment.text ?? '',
            confidence: this.confidenceFromSegment(segment),
          }))
        : [],
    };

    if (typeof response.language === 'string') result.language = response.language;

    return result;
  }

  private confidenceFromSegment(segment: OpenAISegment): number | null {
    if (typeof segment.no_speech_prob === 'number') {
      return Math.max(0, Math.min(1, 1 - segment.no_speech_prob));
    }

    if (typeof segment.avg_logprob === 'number') {
      return Math.max(0, Math.min(1, Math.exp(segment.avg_logprob)));
    }

    return null;
  }

  private async runCommand(path: string): Promise<string> {
    const command = this.command?.replace('{file}', this.shellEscape(path));
    if (!command) throw new Error('TRANSCRIPTION_COMMAND n’est pas configuré.');

    const result = await execFileAsync('/bin/sh', ['-lc', command], {
      env: { ...process.env, AUDIO_FILE: path },
      timeout: Number(process.env.TRANSCRIPTION_TIMEOUT_MS ?? 120_000),
      maxBuffer: 10 * 1024 * 1024,
    });

    return result.stdout;
  }

  private parseOutput(output: string): EngineResult {
    const parsed = JSON.parse(output) as EngineResult;
    const result: EngineResult = {
      text: typeof parsed.text === 'string' ? parsed.text : '',
      segments: Array.isArray(parsed.segments)
        ? parsed.segments.filter((segment) => typeof segment.text === 'string')
        : [],
    };

    if (typeof parsed.language === 'string') result.language = parsed.language;
    if (typeof parsed.confidence === 'number') result.confidence = parsed.confidence;

    return result;
  }

  private shellEscape(value: string): string {
    return `'${value.replaceAll("'", "'\\''")}'`;
  }
}
