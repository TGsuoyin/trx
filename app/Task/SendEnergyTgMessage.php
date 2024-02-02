<?php
namespace App\Task;

use App\Model\Energy\EnergyWalletTradeList;
use App\Model\Energy\EnergyAiTrusteeship;
use App\Model\Energy\EnergyAiBishu;
use App\Model\Telegram\TelegramBotUser;
use App\Library\Log;

class SendEnergyTgMessage
{
    public function execute()
    { 
        //自助下单成功
        try {
            $data = EnergyWalletTradeList::from('energy_wallet_trade_list as a')
                    ->leftJoin('energy_platform_bot as b','a.energy_platform_bot_rid','b.rid')
                    ->leftJoin('telegram_bot as c','b.bot_rid','c.rid')
                    ->leftJoin('energy_platform_package as d','a.energy_package_rid','d.rid')
                    ->where('a.tg_notice_status_receive','N')
                    ->orWhere('a.tg_notice_status_send','N')
                    ->select('a.rid','a.tx_hash','a.transferfrom_address','a.coin_name','a.amount','a.process_status','a.tg_notice_status_receive','a.tg_notice_status_send','b.tg_notice_obj_receive','b.tg_notice_obj_send','c.bot_token','b.receive_wallet','d.energy_amount','d.package_name','c.bot_username','c.bot_admin_username')
                    ->limit(5)
                    ->get();

            if($data->count() > 0){
                foreach ($data as $k => $v) {
                    if(empty($v->bot_token)){
                        $save_data = [];
                        $save_data['tg_notice_status_receive'] = 'Y';
                        $save_data['tg_notice_status_send'] = 'Y';
                        EnergyWalletTradeList::where('rid',$v->rid)->update($save_data);
                        continue;
                    }

                    $notice_receive = 'N'; 
                    $notice_send = 'N'; 
                    
                    if(empty($v->tg_notice_obj_receive) && $v->tg_notice_obj_receive == ''){
                        $notice_receive = 'Y';
                    }
                    
                    if(empty($v->tg_notice_obj_send) && $v->tg_notice_obj_send == ''){
                        $notice_send = 'Y';
                    }

                    //['6' => '能量钱包未启用','7' => '金额无对应套餐','8' => '下单中','9' => '下单成功','1' => '待下单','5' => '能量钱包未配置私钥','4' => '下单失败'];
                    //接收的通知,某些状态才通知
                    if($v->tg_notice_status_receive == 'N' && in_array($v->process_status, [1,4,8,9]) && !empty($v->tg_notice_obj_receive) && $v->tg_notice_obj_receive != ''){
                        $replytext = "🔋有新的能量交易：\n"
                                    ."➖➖➖➖➖➖➖➖\n"
                                    ."转入交易哈希：<code>".$v->tx_hash."</code>\n"
                                    ."转入钱包地址：<code>".$v->transferfrom_address."</code>\n"
                                    ."转入币名：".$v->coin_name ."\n"
                                    ."转入金额：".$v->amount;

                        $url = 'https://tronscan.io/#/transaction/'.$v->tx_hash;

                        //内联按钮
                        $keyboard = [
                            'inline_keyboard' => [
                                [
                                    ['text' => '查看转入交易', 'url' => $url]
                                ]
                            ]
                        ];
                        $encodedKeyboard = json_encode($keyboard);
                        $receivelist = explode(',',$v->tg_notice_obj_receive);

                        foreach ($receivelist as $x => $y) {
                            $sendmessageurl = 'https://api.telegram.org/bot'.$v->bot_token.'/sendMessage?chat_id='.$y.'&text='.urlencode($replytext).'&parse_mode=HTML&reply_markup='.urlencode($encodedKeyboard);
                            
                            Get_Pay($sendmessageurl);
                        }
                        
                        $notice_receive = 'Y';
                    }
                    
                    //回款的通知,某些状态才通知
                    if($v->tg_notice_status_send == 'N' && $v->process_status == 9 && !empty($v->tg_notice_obj_send) && $v->tg_notice_obj_send != ''){
                        $replytext = "🔋<b>新的能量订单成功</b> \n"
                                ."认准24小时自动购买能量地址(点击复制)：<code>".$v->receive_wallet."</code>\n"
                                ."➖➖➖➖➖➖➖➖\n"
                                ."<b>下单模式</b>：自助下单\n"
                                ."<b>购买套餐</b>：".$v->package_name ."\n"
                                ."<b>能量数量</b>：".$v->energy_amount ."\n"
                                ."<b>支付金额</b>：".$v->amount ." TRX\n"
                                ."<b>支付地址</b>：".mb_substr($v->transferfrom_address,0,8).'****'.mb_substr($v->transferfrom_address,-8,8) ."\n\n"
                                ."<b>能量已经到账！请在时间范围内使用！</b>\n"
                                ."发送 /buyenergy 继续购买能量！\n"
                                ."➖➖➖➖➖➖➖➖";
        	
                        $url = 'https://tronscan.org/#/address/'.$v->transferfrom_address;
                        
                        //内联按钮
                        $keyboard = [
                            'inline_keyboard' => [
                                [
                                    ['text' => '能量闪租', 'url' => 'https://t.me/'.$v->bot_username],
                                    ['text' => '笔数套餐', 'url' => 'https://t.me/'.$v->bot_username],
                                    ['text' => '智能托管', 'url' => 'https://t.me/'.$v->bot_username]
                                ],
                                [
                                    ['text' => '联系客服', 'url' => 'https://t.me/'.mb_substr($v->bot_admin_username,1)],
                                    ['text' => 'TRX闪兑', 'url' => 'https://t.me/'.$v->bot_username],
                                    ['text' => 'TRX预支', 'url' => 'https://t.me/'.mb_substr($v->bot_admin_username,1)]
                                ]
                            ]
                        ];
                        
                        $encodedKeyboard = json_encode($keyboard);
                        
                        $sendlist = explode(',',$v->tg_notice_obj_send);
                        
                        foreach ($sendlist as $x => $y) {
                            $sendmessageurl = 'https://api.telegram.org/bot'.$v->bot_token.'/sendMessage?chat_id='.$y.'&text='.urlencode($replytext).'&parse_mode=HTML&reply_markup='.urlencode($encodedKeyboard);
                            
                            Get_Pay($sendmessageurl);
                        }
                        
                        $notice_send = 'Y';
                    
                    //['6' => '能量钱包未启用','7' => '金额无对应套餐','8' => '下单中','9' => '下单成功','1' => '待下单','5' => '能量钱包未配置私钥','4' => '下单失败'];
                    //某些状态直接改为Y,其他状态不改,避免还没闪兑成功发不出去通知
                    }elseif(in_array($v->process_status, [6,7,5,4])){
                        $notice_send = 'Y';
                        $notice_receive = 'Y';
                    }
                    
                    if($notice_send == 'Y' || $notice_receive = 'Y'){
                        $save_data = [];
                        $save_data['tg_notice_status_receive'] = $notice_receive == 'Y' ? 'Y' : $v->tg_notice_status_receive;
                        $save_data['tg_notice_status_send'] = $notice_send == 'Y' ? 'Y' : $v->tg_notice_status_send;
                        EnergyWalletTradeList::where('rid',$v->rid)->update($save_data);
                    }
                }
            }
            
        }catch (\Exception $e){
            $this->log('sendtransittgmessage','----------任务执行报错，请联系管理员。报错原因：----------'.$e->getMessage());
        }
        
        //智能托管通知
        try {
            $data = EnergyAiTrusteeship::from('energy_ai_trusteeship as a')
                    ->leftJoin('energy_platform_bot as b','a.bot_rid','b.bot_rid')
                    ->leftJoin('telegram_bot as c','a.bot_rid','c.rid')
                    ->where('a.is_notice','Y')
                    ->orWhere('a.is_notice_admin','Y')
                    ->select('a.rid','a.tg_uid','a.wallet_addr','a.per_buy_energy_quantity','c.bot_token','a.is_notice_admin','a.is_notice','b.tg_admin_uid','a.comments','b.tg_notice_obj_send','c.bot_username','c.bot_admin_username','a.bot_rid','a.max_buy_quantity','a.total_buy_quantity','b.trx_price_energy_32000','b.trx_price_energy_65000')
                    ->limit(5)
                    ->get();

            if($data->count() > 0){
                foreach ($data as $k => $v) {
                    if(empty($v->bot_token)){
                        $save_data = [];
                        $save_data['is_notice'] = $v->is_notice == 'Y' ?'N':$v->is_notice;
                        $save_data['is_notice_admin'] = $v->is_notice_admin == 'Y' ?'N':$v->is_notice_admin;
                        EnergyAiTrusteeship::where('rid',$v->rid)->update($save_data);
                        continue;
                    }
                    
                    //回款的通知
                    if(!empty($v->tg_uid) && $v->tg_uid != '' && $v->is_notice == 'Y'){
                        if($v->max_buy_quantity > 0){
                            $syCount = $v->max_buy_quantity - $v->total_buy_quantity." 次(地址设置最多 <b>".$v->max_buy_quantity."</b> 次),已使用 <b>".$v->total_buy_quantity." </b>次";
                        }else{
                            $botUser = TelegramBotUser::where("bot_rid",$v->bot_rid)->where("tg_uid",$v->tg_uid)->first();
                            if($botUser){
                                $perPrice = $v->per_buy_energy_quantity == 32000 ? $v->trx_price_energy_32000:$v->trx_price_energy_65000;
                                $syCount = floor($botUser->cash_trx / $perPrice)." 次(每次消耗 <b>".$perPrice."</b> TRX. 余额剩余：<b>". $botUser->cash_trx."</b> TRX)";
                            }else{
                                $syCount = "未知！未查询到用户余额，联系客服！";
                            }
                        }
                        
                        $replytextuid = "🔋<b>新的能量订单成功</b> \n"
                                ."➖➖➖➖➖➖➖➖\n"
                                ."<b>下单模式</b>：智能托管\n"
                                ."<b>能量数量</b>：".$v->per_buy_energy_quantity ."\n"
                                ."<b>能量地址</b>：".mb_substr($v->wallet_addr,0,8).'****'.mb_substr($v->wallet_addr,-8,8) ."\n\n"
                                ."<b>能量已经到账！请在时间范围内使用！</b>\n"
                                ."发送 /buyenergy 继续购买能量！\n\n"
                                ."⚠️<u>预计剩余：</u>".$syCount."\n"
                                ."➖➖➖➖➖➖➖➖";
        	
                        $url = 'https://tronscan.org/#/address/'.$v->wallet_addr;
                        
                        //内联按钮
                        $keyboard = [
                            'inline_keyboard' => [
                                [
                                    ['text' => '能量闪租', 'url' => 'https://t.me/'.$v->bot_username],
                                    ['text' => '笔数套餐', 'url' => 'https://t.me/'.$v->bot_username],
                                    ['text' => '智能托管', 'url' => 'https://t.me/'.$v->bot_username]
                                ],
                                [
                                    ['text' => '联系客服', 'url' => 'https://t.me/'.mb_substr($v->bot_admin_username,1)],
                                    ['text' => 'TRX闪兑', 'url' => 'https://t.me/'.$v->bot_username],
                                    ['text' => 'TRX预支', 'url' => 'https://t.me/'.mb_substr($v->bot_admin_username,1)]
                                ]
                            ]
                        ];
                        
                        $encodedKeyboard = json_encode($keyboard);
                        
                        $sendmessageurl = 'https://api.telegram.org/bot'.$v->bot_token.'/sendMessage?chat_id='.$v->tg_uid.'&text='.urlencode($replytextuid).'&parse_mode=HTML&reply_markup='.urlencode($encodedKeyboard);
                        Get_Pay($sendmessageurl);
                        
                        //通知到群
                        if(!empty($v->tg_notice_obj_send) && $v->tg_notice_obj_send != ''){
                            $replytext = "🔋<b>新的能量订单成功</b> \n"
                                ."➖➖➖➖➖➖➖➖\n"
                                ."<b>下单模式</b>：智能托管\n"
                                ."<b>能量数量</b>：".$v->per_buy_energy_quantity ."\n"
                                ."<b>能量地址</b>：".mb_substr($v->wallet_addr,0,8).'****'.mb_substr($v->wallet_addr,-8,8) ."\n\n"
                                ."<b>能量已经到账！请在时间范围内使用！</b>\n"
                                ."发送 /buyenergy 继续购买能量！\n"
                                ."➖➖➖➖➖➖➖➖";
                                
                            $sendlist = explode(',',$v->tg_notice_obj_send);
                        
                            foreach ($sendlist as $x => $y) {
                                $sendmessageurl = 'https://api.telegram.org/bot'.$v->bot_token.'/sendMessage?chat_id='.$y.'&text='.urlencode($replytext).'&parse_mode=HTML&reply_markup='.urlencode($encodedKeyboard);
                                Get_Pay($sendmessageurl);
                            }
                            
                        }
                    }
                    
                    //管理员通知
                    if(!empty($v->tg_admin_uid) && $v->tg_admin_uid != '' && $v->is_notice_admin == 'Y'){
                        $replytext = "❌<b>智能托管，能量代理失败！</b> \n"
                                ."➖➖➖➖➖➖➖➖\n"
                                ."<b>下单模式</b>：智能托管\n"
                                ."<b>能量数量</b>：".$v->per_buy_energy_quantity ."\n"
                                ."<b>能量地址</b>：<code>".$v->wallet_addr."</code>\n"
                                ."<b>失败原因</b>：".$v->comments."\n\n"
                                ."<b>请立即查看管理后台，如需要重新发起，在 能量管理->智能托管 刷新该地址即可！</b>"."\n"
                                ."<b>如果不刷新该地址的智能托管，后续不会再智能托管！</b>"."\n"
                                ."➖➖➖➖➖➖➖➖";
        	
                        $sendlist = explode(',',$v->tg_admin_uid);
                        
                        foreach ($sendlist as $x => $y) {
                            $sendmessageurl = 'https://api.telegram.org/bot'.$v->bot_token.'/sendMessage?chat_id='.$y.'&text='.urlencode($replytext).'&parse_mode=HTML';
                            
                            Get_Pay($sendmessageurl);
                        }
                    }
                    
                    $save_data = [];
                    $save_data['is_notice'] = $v->is_notice == 'Y' ?'N':$v->is_notice;
                    $save_data['is_notice_admin'] = $v->is_notice_admin == 'Y' ?'N':$v->is_notice_admin;
                    EnergyAiTrusteeship::where('rid',$v->rid)->update($save_data);
                }
            }
            
        }catch (\Exception $e){
            $this->log('sendtransittgmessage','----------任务执行报错，请联系管理员。报错原因：----------'.$e->getMessage());
        }
        
        //笔数套餐通知
        try {
            $data = EnergyAiBishu::from('energy_ai_bishu as a')
                    ->leftJoin('energy_platform_bot as b','a.bot_rid','b.bot_rid')
                    ->leftJoin('telegram_bot as c','a.bot_rid','c.rid')
                    ->where('a.is_notice','Y')
                    ->orWhere('a.is_notice_admin','Y')
                    ->select('a.rid','a.tg_uid','a.wallet_addr','b.per_bishu_energy_quantity','c.bot_token','a.is_notice_admin','a.is_notice','b.tg_admin_uid','a.comments','b.tg_notice_obj_send','c.bot_username','c.bot_admin_username','a.bot_rid','a.max_buy_quantity','a.total_buy_quantity')
                    ->limit(5)
                    ->get();

            if($data->count() > 0){
                foreach ($data as $k => $v) {
                    if(empty($v->bot_token)){
                        $save_data = [];
                        $save_data['is_notice'] = $v->is_notice == 'Y' ?'N':$v->is_notice;
                        $save_data['is_notice_admin'] = $v->is_notice_admin == 'Y' ?'N':$v->is_notice_admin;
                        EnergyAiBishu::where('rid',$v->rid)->update($save_data);
                        continue;
                    }
                    
                    //内联按钮
                    $keyboard = [
                        'inline_keyboard' => [
                            [
                                ['text' => '能量闪租', 'url' => 'https://t.me/'.$v->bot_username],
                                ['text' => '笔数套餐', 'url' => 'https://t.me/'.$v->bot_username],
                                ['text' => '智能托管', 'url' => 'https://t.me/'.$v->bot_username]
                            ],
                            [
                                ['text' => '联系客服', 'url' => 'https://t.me/'.mb_substr($v->bot_admin_username,1)],
                                ['text' => 'TRX闪兑', 'url' => 'https://t.me/'.$v->bot_username],
                                ['text' => 'TRX预支', 'url' => 'https://t.me/'.mb_substr($v->bot_admin_username,1)]
                            ]
                        ]
                    ];
                    
                    $encodedKeyboard = json_encode($keyboard);
                    
                    //回款的通知
                    if(!empty($v->tg_uid) && $v->tg_uid != '' && $v->is_notice == 'Y'){
                        if($v->max_buy_quantity > 0){
                            $syCount = $v->max_buy_quantity - $v->total_buy_quantity." 次(地址设置最多 <b>".$v->max_buy_quantity."</b> 次),已使用 <b>".$v->total_buy_quantity." </b>次";
                        }else{
                            $syCount = "0 次";
                        }
                        
                        $replytextuid = "🖌<b>新的笔数能量订单成功</b> \n"
                                ."➖➖➖➖➖➖➖➖\n"
                                ."<b>下单模式</b>：笔数套餐\n"
                                ."<b>能量数量</b>：".$v->per_bishu_energy_quantity ."\n"
                                ."<b>能量地址</b>：".mb_substr($v->wallet_addr,0,8).'****'.mb_substr($v->wallet_addr,-8,8) ."\n\n"
                                ."<b>能量已经到账！请在时间范围内使用！</b>\n"
                                ."发送 /buyenergy 继续购买能量！\n\n"
                                ."⚠️<u>预计剩余：</u>".$syCount."\n"
                                ."➖➖➖➖➖➖➖➖";
        	
                        $url = 'https://tronscan.org/#/address/'.$v->wallet_addr;
                        
                        $sendmessageurl = 'https://api.telegram.org/bot'.$v->bot_token.'/sendMessage?chat_id='.$v->tg_uid.'&text='.urlencode($replytextuid).'&parse_mode=HTML&reply_markup='.urlencode($encodedKeyboard);
                        Get_Pay($sendmessageurl);
                    }
                    
                    //通知到群
                    if(!empty($v->tg_notice_obj_send) && $v->tg_notice_obj_send != '' && $v->is_notice == 'Y'){
                        $replytext = "🖌<b>新的笔数能量订单成功</b> \n"
                            ."➖➖➖➖➖➖➖➖\n"
                            ."<b>下单模式</b>：笔数套餐\n"
                            ."<b>能量数量</b>：".$v->per_bishu_energy_quantity ."\n"
                            ."<b>能量地址</b>：".mb_substr($v->wallet_addr,0,8).'****'.mb_substr($v->wallet_addr,-8,8) ."\n\n"
                            ."<b>能量已经到账！请在时间范围内使用！</b>\n"
                            ."发送 /buyenergy 继续购买能量！\n"
                            ."➖➖➖➖➖➖➖➖";
                            
                        $sendlist = explode(',',$v->tg_notice_obj_send);
                    
                        foreach ($sendlist as $x => $y) {
                            $sendmessageurl = 'https://api.telegram.org/bot'.$v->bot_token.'/sendMessage?chat_id='.$y.'&text='.urlencode($replytext).'&parse_mode=HTML&reply_markup='.urlencode($encodedKeyboard);
                            Get_Pay($sendmessageurl);
                        }
                    }
                    
                    //管理员通知
                    if(!empty($v->tg_admin_uid) && $v->tg_admin_uid != '' && $v->is_notice_admin == 'Y'){
                        $replytext = "❌<b>智能托管，能量代理失败！</b> \n"
                                ."➖➖➖➖➖➖➖➖\n"
                                ."<b>下单模式</b>：笔数套餐\n"
                                ."<b>能量数量</b>：".$v->per_bishu_energy_quantity ."\n"
                                ."<b>能量地址</b>：<code>".$v->wallet_addr."</code>\n"
                                ."<b>失败原因</b>：".$v->comments."\n\n"
                                ."<b>请立即查看管理后台，如需要重新发起，在 能量管理->智能托管 刷新该地址即可！</b>"."\n"
                                ."<b>如果不刷新该地址的智能托管，后续不会再智能托管！</b>"."\n"
                                ."➖➖➖➖➖➖➖➖";
        	
                        $sendlist = explode(',',$v->tg_admin_uid);
                        
                        foreach ($sendlist as $x => $y) {
                            $sendmessageurl = 'https://api.telegram.org/bot'.$v->bot_token.'/sendMessage?chat_id='.$y.'&text='.urlencode($replytext).'&parse_mode=HTML';
                            
                            Get_Pay($sendmessageurl);
                        }
                    }
                    
                    $save_data = [];
                    $save_data['is_notice'] = $v->is_notice == 'Y' ?'N':$v->is_notice;
                    $save_data['is_notice_admin'] = $v->is_notice_admin == 'Y' ?'N':$v->is_notice_admin;
                    EnergyAiBishu::where('rid',$v->rid)->update($save_data);
                }
            }
            
        }catch (\Exception $e){
            $this->log('sendtransittgmessage','----------任务执行报错，请联系管理员。报错原因：----------'.$e->getMessage());
        }
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