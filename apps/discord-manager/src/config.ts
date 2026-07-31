import { z } from 'zod';
export const configSchema=z.object({INTERNAL_API_URL:z.string().url(),WORKER_SERVICE_TOKEN:z.string().min(24),WORKER_ID:z.string().min(1),REDIS_URL:z.string().default('redis://redis:6379'),POLL_INTERVAL_MS:z.coerce.number().int().min(5000).default(15000),LOCK_TTL_MS:z.coerce.number().int().min(10000).default(30000)});
export type Config=z.infer<typeof configSchema>;
export const loadConfig=(environment:NodeJS.ProcessEnv):Config=>configSchema.parse(environment);
