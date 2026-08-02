import type { ApiClient, TranscriptJob } from './api-client.js';
import { TranscriptionRunner } from './transcription-runner.js';

export class TranscriptionJobRunner {
  private running = false;
  private readonly runner = new TranscriptionRunner();

  constructor(private readonly api: ApiClient) {}

  async runPending(): Promise<void> {
    if (this.running) return;

    this.running = true;

    try {
      const jobs = await this.api.transcripts(Number(process.env.TRANSCRIPTION_BATCH_SIZE ?? 3));

      for (const job of jobs) {
        await this.runJob(job);
      }
    } finally {
      this.running = false;
    }
  }

  private async runJob(job: TranscriptJob): Promise<void> {
    try {
      await this.api.createTranscript({
        voice_report_id: job.voice_report_id,
        voice_audio_clip_id: job.voice_audio_clip_id,
        reported_user_discord_id: job.reported_user_discord_id,
        status: 'processing',
      });

      const transcript = await this.runner.transcribe(
        job.voice_report_id,
        job.voice_audio_clip_id,
        job.reported_user_discord_id,
        job.storage_path,
      );

      await this.api.createTranscript(transcript);
    } catch (error) {
      const message = error instanceof Error ? error.message : 'Erreur de transcription inconnue';

      await this.api.createTranscript({
        voice_report_id: job.voice_report_id,
        voice_audio_clip_id: job.voice_audio_clip_id,
        reported_user_discord_id: job.reported_user_discord_id,
        status: 'failed',
        engine: 'worker',
        error_message: message,
      }).catch((storeError) => {
        const storeMessage = storeError instanceof Error ? storeError.message : 'Erreur inconnue';
        console.error(JSON.stringify({
          level: 'error',
          event: 'transcription_failure_store_failed',
          transcript_job_id: job.id,
          message,
          store_message: storeMessage,
        }));
      });
    }
  }
}
