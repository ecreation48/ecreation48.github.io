<?php
namespace App\Http\Controllers\Api\V1;
use App\Http\Controllers\Controller; use App\Models\DiscordBot; use App\Models\ModerationAction; use Illuminate\Http\JsonResponse; use Illuminate\Http\Request;
class ModerationActionController extends Controller {
 public function index(Request $request): JsonResponse { $bot=DiscordBot::query()->findOrFail($request->query('bot_id')); $actions=ModerationAction::query()->where('result','pending')->whereHas('guild',fn($query)=>$query->where('discord_bot_id',$bot->id))->with('guild')->oldest()->limit(20)->get()->map(fn(ModerationAction $action)=>['id'=>$action->id,'guild_discord_id'=>$action->guild->discord_id,'target_user_discord_id'=>$action->target_user_discord_id,'type'=>$action->type,'duration_seconds'=>$action->duration_seconds,'reason'=>$action->reason]); return response()->json(['data'=>$actions]);}
 public function update(Request $request, ModerationAction $moderationAction): JsonResponse { $data=$request->validate(['result'=>'required|in:success,failed,recorded','error_message'=>'nullable|string|max:2000']); $moderationAction->update(['result'=>$data['result'],'error_message'=>$data['error_message']??null,'actioned_at'=>now()]); if($moderationAction->voice_report_id&&$data['result']==='success'){$moderationAction->report()->update(['status'=>'actioned']);} return response()->json(['data'=>['accepted'=>true]]);}
}
