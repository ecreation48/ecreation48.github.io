<?php
namespace App\Http\Controllers\Api\V1;
use App\Http\Controllers\Controller; use App\Models\DiscordBot; use App\Models\WorkerInstance; use Illuminate\Http\JsonResponse; use Illuminate\Http\Request;
class BotController extends Controller {
 public function index(): JsonResponse { return response()->json(['data'=>DiscordBot::query()->where('is_active',true)->get(['id','name','client_id','connection_status'])]); }
 public function credentials(DiscordBot $discordBot): JsonResponse { abort_unless($discordBot->is_active,404); return response()->json(['data'=>['id'=>$discordBot->id,'token'=>$discordBot->token,'client_id'=>$discordBot->client_id]]); }
 public function heartbeat(Request $request, DiscordBot $discordBot): JsonResponse { $data=$request->validate(['worker_id'=>'required|string|max:255','hostname'=>'required|string|max:255','status'=>'required|in:connecting,online,offline,error','version'=>'nullable|string|max:50','error'=>'nullable|string|max:2000']); $worker=WorkerInstance::query()->updateOrCreate(['name'=>$data['worker_id']],['type'=>'discord-manager','hostname'=>$data['hostname'],'status'=>'online','version'=>$data['version']??null,'last_heartbeat_at'=>now()]); $discordBot->update(['worker_instance_id'=>$worker->id,'connection_status'=>$data['status'],'last_connected_at'=>$data['status']==='online'?now():$discordBot->last_connected_at,'last_error_at'=>$data['status']==='error'?now():$discordBot->last_error_at,'error_message'=>$data['error']??null]); return response()->json(['data'=>['accepted'=>true]]); }
}
