import { EndBehaviorType, type VoiceConnection } from '@discordjs/voice';
import { mkdir, writeFile } from 'node:fs/promises';
import { join } from 'node:path';
import prism from 'prism-media';
import type { LiveAudioServer } from './live-audio-server.js';

interface AudioChunk {capturedAt:number;data:Buffer}
interface ActiveSession {bufferSeconds:number}
export interface AudioSnapshot {path:string;bytes:number;durationSeconds:number;capturedFrom:string;capturedUntil:string;mimeType:string}

const DEFAULT_AUDIO_DIR='../web/storage/audio-clips';
const SAMPLE_RATE=48_000;
const CHANNELS=2;
const BITS_PER_SAMPLE=16;
const FRAME_SIZE=960;

export class AudioBufferRecorder {private sessions=new Map<string,ActiveSession>();private buffers=new Map<string,AudioChunk[]>();private activeCaptures=new Set<string>();
 constructor(private readonly baseDir=process.env.AUDIO_CLIP_DIR??DEFAULT_AUDIO_DIR,private readonly liveAudio?:LiveAudioServer){}
 attach(connection:VoiceConnection,channelId:string,_sessionId:string,bufferSeconds:number):void{this.sessions.set(connection.joinConfig.guildId,{bufferSeconds});connection.receiver.speaking.on('start',userId=>this.capture(connection,channelId,userId));}
 detach(guildId:string):void{this.sessions.delete(guildId);for(const key of this.buffers.keys())if(key.startsWith(`${guildId}:`))this.buffers.delete(key);for(const key of this.activeCaptures)if(key.startsWith(`${guildId}:`))this.activeCaptures.delete(key);}
 async snapshot(guildId:string,userId:string,reportId:string):Promise<AudioSnapshot|null>{const session=this.sessions.get(guildId);const chunks=this.buffers.get(this.key(guildId,userId))??[];const first=chunks[0];const last=chunks.at(-1);if(!session||!first||!last)return null;await mkdir(this.baseDir,{recursive:true});const capturedUntil=new Date(last.capturedAt).toISOString();const capturedFrom=new Date(first.capturedAt).toISOString();const path=join(this.baseDir,`${reportId}-${guildId}-${userId}.wav`);const pcm=this.decodePackets(chunks.map(chunk=>chunk.data));const wav=this.createWav(pcm);await writeFile(path,wav);return {path,bytes:wav.byteLength,durationSeconds:Math.max(1,Math.ceil(pcm.byteLength/(SAMPLE_RATE*CHANNELS*(BITS_PER_SAMPLE/8)))),capturedFrom,capturedUntil,mimeType:'audio/wav'};}
 private capture(connection:VoiceConnection,channelId:string,userId:string):void{const session=this.sessions.get(connection.joinConfig.guildId);const key=this.key(connection.joinConfig.guildId,userId);if(!session||this.activeCaptures.has(key))return;this.activeCaptures.add(key);const stream=connection.receiver.subscribe(userId,{end:{behavior:EndBehaviorType.AfterSilence,duration:1_000}});const decoder=new prism.opus.Decoder({frameSize:FRAME_SIZE,channels:CHANNELS,rate:SAMPLE_RATE});stream.on('data',(chunk:Buffer)=>this.push(connection.joinConfig.guildId,userId,chunk,session.bufferSeconds));decoder.on('data',(chunk:Buffer)=>this.liveAudio?.write(connection.joinConfig.guildId,channelId,userId,Buffer.from(chunk)));stream.pipe(decoder);stream.once('end',()=>this.activeCaptures.delete(key));stream.once('close',()=>this.activeCaptures.delete(key));stream.once('error',()=>this.activeCaptures.delete(key));decoder.once('error',()=>undefined);}
 private push(guildId:string,userId:string,data:Buffer,bufferSeconds:number):void{const key=this.key(guildId,userId);const now=Date.now();const chunks=this.buffers.get(key)??[];chunks.push({capturedAt:now,data:Buffer.from(data)});const keepAfter=now-(bufferSeconds*1000);let first=chunks[0];while(first&&first.capturedAt<keepAfter){chunks.shift();first=chunks[0];}this.buffers.set(key,chunks);}
 private key(guildId:string,userId:string):string{return `${guildId}:${userId}`;}
 private decodePackets(packets:Buffer[]):Buffer{const decoder=new prism.opus.Decoder({frameSize:FRAME_SIZE,channels:CHANNELS,rate:SAMPLE_RATE});const pcm:Buffer[]=[];decoder.on('data',(chunk:Buffer)=>pcm.push(Buffer.from(chunk)));for(const packet of packets)decoder.write(packet);decoder.end();return Buffer.concat(pcm);}
 private createWav(pcm:Buffer):Buffer{const header=Buffer.alloc(44);const byteRate=SAMPLE_RATE*CHANNELS*(BITS_PER_SAMPLE/8);const blockAlign=CHANNELS*(BITS_PER_SAMPLE/8);header.write('RIFF',0);header.writeUInt32LE(36+pcm.byteLength,4);header.write('WAVE',8);header.write('fmt ',12);header.writeUInt32LE(16,16);header.writeUInt16LE(1,20);header.writeUInt16LE(CHANNELS,22);header.writeUInt32LE(SAMPLE_RATE,24);header.writeUInt32LE(byteRate,28);header.writeUInt16LE(blockAlign,32);header.writeUInt16LE(BITS_PER_SAMPLE,34);header.write('data',36);header.writeUInt32LE(pcm.byteLength,40);return Buffer.concat([header,pcm]);}
}
