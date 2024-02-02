<?php
declare(strict_types=1);

namespace App\Controller\Api;
use App\Controller\AbstractController;
use App\Model\Energy\EnergyAiBishu;
use Hyperf\HttpServer\Contract\RequestInterface;
use App\Library\Log;

class TrongasIoController extends AbstractController
{
    // trongas笔数回调通知
    public function notice(RequestInterface $request)
    {
    	$receiveAddress = $request->input('receiveAddress');
    	$residue = $request->input('residue');
    	
    	if(!empty($receiveAddress)){
    	    //查地址通知
        	$bishu = EnergyAiBishu::from('energy_ai_bishu as a')
                    ->leftJoin('energy_platform_bot as b','a.bot_rid','b.bot_rid')
                    ->leftJoin('telegram_bot as c','a.bot_rid','c.rid')
                    ->where('a.wallet_addr',$receiveAddress)
                    ->select('a.rid','a.tg_uid','a.wallet_addr','c.bot_token','a.is_notice_admin','a.is_notice','b.tg_admin_uid','b.tg_notice_obj_send','c.bot_username','c.bot_admin_username','b.per_bishu_energy_quantity')
                    ->first();
            
            //内联按钮
            $keyboard = [
                'inline_keyboard' => [
                    [
                        ['text' => '能量闪租', 'url' => 'https://t.me/'.$bishu->bot_username],
                        ['text' => '笔数套餐', 'url' => 'https://t.me/'.$bishu->bot_username],
                        ['text' => '智能托管', 'url' => 'https://t.me/'.$bishu->bot_username]
                    ],
                    [
                        ['text' => '联系客服', 'url' => 'https://t.me/'.mb_substr($bishu->bot_admin_username,1)],
                        ['text' => 'TRX闪兑', 'url' => 'https://t.me/'.$bishu->bot_username],
                        ['text' => 'TRX预支', 'url' => 'https://t.me/'.mb_substr($bishu->bot_admin_username,1)]
                    ]
                ]
            ];
            
            $encodedKeyboard = json_encode($keyboard);
            
        	if(!empty($bishu) && isset($bishu->tg_uid) && !empty($bishu->tg_uid)){
        	    $replytextuid = "🖌<b>新的笔数能量订单成功</b> \n"
                                ."➖➖➖➖➖➖➖➖\n"
                                ."<b>下单模式</b>：笔数套餐\n"
                                ."<b>能量数量</b>：".$bishu->per_bishu_energy_quantity." \n"
                                ."<b>能量地址</b>：".mb_substr($receiveAddress,0,8).'****'.mb_substr($receiveAddress,-8,8) ."\n\n"
                                ."<b>能量已经到账！请在时间范围内使用！</b>\n"
                                ."发送 /buyenergy 继续购买能量！\n\n"
                                ."⚠️<u>预计剩余：</u>".$residue."\n"
                                ."➖➖➖➖➖➖➖➖";
    
                
                
                $sendmessageurl = 'https://api.telegram.org/bot'.$bishu->bot_token.'/sendMessage?chat_id='.$bishu->tg_uid.'&text='.urlencode($replytextuid).'&parse_mode=HTML&reply_markup='.urlencode($encodedKeyboard);
                Get_Pay($sendmessageurl);
    	    }
    	    
    	    //通知到群
            if(!empty($bishu->tg_notice_obj_send) && $bishu->tg_notice_obj_send != ''){
                $replytext = "🖌<b>新的笔数能量订单成功</b> \n"
                    ."➖➖➖➖➖➖➖➖\n"
                    ."<b>下单模式</b>：笔数套餐\n"
                    ."<b>能量数量</b>：".$bishu->per_bishu_energy_quantity." \n"
                    ."<b>能量地址</b>：".mb_substr($receiveAddress,0,8).'****'.mb_substr($receiveAddress,-8,8) ."\n\n"
                    ."<b>能量已经到账！请在时间范围内使用！</b>\n"
                    ."发送 /buyenergy 继续购买能量！\n"
                    ."➖➖➖➖➖➖➖➖";
                    
                $sendlist = explode(',',$bishu->tg_notice_obj_send);
            
                foreach ($sendlist as $x => $y) {
                    $sendmessageurl = 'https://api.telegram.org/bot'.$bishu->bot_token.'/sendMessage?chat_id='.$y.'&text='.urlencode($replytext).'&parse_mode=HTML&reply_markup='.urlencode($encodedKeyboard);
                    Get_Pay($sendmessageurl);
                }
            }
    	}
    	
    	return $this->responseApi(200,'success');
    }
    
    /**
     * 记入日志
     * @param $log_title [日志路径]
     * @param $message [内容，不支持数组]
     * @param $remarks [备注]
    */
    protected function log($log_title,$message,$remarks='info'){
        Log::get($remarks,$log_title)->info($message);
    }
}
