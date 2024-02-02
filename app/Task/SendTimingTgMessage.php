<?php
namespace App\Task;

use App\Model\Transit\TransitWalletCoin;
use App\Model\Telegram\TelegramBotAd;
use App\Model\Telegram\TelegramBotAdKeyboard;
use App\Library\Log;

class SendTimingTgMessage
{
    public function execute()
    { 
        try {
            $time = nowDate();
        
            $data = TelegramBotAd::from('telegram_bot_ad as a')
                    ->Join('telegram_bot as b','a.bot_rid','b.rid')
                    ->where('a.status',0)
                    ->select('a.rid','b.bot_token','a.last_notice_time','a.notice_cycle','a.notice_obj','a.notice_ad','a.bot_rid','a.notice_photo','b.bot_username','b.bot_admin_username')
                    ->get();
                    
            if($data->count() > 0){
                foreach ($data as $k => $v) {
                    //通知周期:1每分钟,2每10分钟,3每30分钟,4每小时,5每天
                    $notice_cycle = $v->notice_cycle;
                    
                    $send = 'N';
                    
                    //每分钟,且上次通知已过了一分钟
                    if($notice_cycle == 1 && strtotime($v->last_notice_time) + 60 <= strtotime($time)){
                        $send = 'Y';
                    //每10分钟
                    }elseif($notice_cycle == 2 && strtotime($v->last_notice_time) + 600 <= strtotime($time)){
                        $send = 'Y';
                    //每30分钟
                    }elseif($notice_cycle == 3 && strtotime($v->last_notice_time) + 1800 <= strtotime($time)){
                        $send = 'Y';
                    //每小时
                    }elseif($notice_cycle == 4 && strtotime($v->last_notice_time) + 3600 <= strtotime($time)){
                        $send = 'Y';
                    //每天
                    }elseif($notice_cycle == 5 && strtotime($v->last_notice_time) + 86400 <= strtotime($time)){
                        $send = 'Y';
                    }
                    
                    //添加联系老板，写死
                    //内联按钮
                    // $keyboard = [
                    //     'inline_keyboard' => [
                    //         [
                    //             ['text' => '联系老板', 'callback_data' => '客服']
                    //         ]
                    //     ]
                    // ];
                    // $encodedKeyboard = json_encode($keyboard);
                    
                    #查询键盘,放入
                    $keyboardList = TelegramBotAdKeyboard::from('telegram_bot_ad_keyboard as a')
                                ->join('telegram_bot_keyboard as b','a.keyboard_rid','b.rid')
                                ->where('a.bot_rid', $v->bot_rid)
                                ->where('a.ad_rid', $v->rid)
                                ->where('b.status', 0)
                                ->select('b.keyboard_name','b.keyboard_type','b.inline_type','b.keyboard_value')
                                ->orderBy('b.seq_sn','desc')
                                ->get();
                    
                    $keyboard = [];
                    $is_keyboard = 'N';
                    
                    //有键盘的时候显示
                    if($keyboardList->count() > 0){
                        $keyboardone = [];
                        $keyboardtwo = [];
                        $keyboardthree = [];
                        $keyboard = [];
                        $s = 0;
                        
                        foreach ($keyboardList as $ka => $va) {
                            //键盘
                            if($va->keyboard_type == 1){
                                if(count($keyboardone) == 3){
                                    if(count($keyboardtwo) == 3){
                                        array_push($keyboardthree,$va->keyboard_name);
                                    }else{
                                        array_push($keyboardtwo,$va->keyboard_name);
                                    }
                                }else{
                                    array_push($keyboardone,$va->keyboard_name);
                                }
                                
                            //内联按钮
                            }else{
                                //url
                                if($va->inline_type == 1){
                                    $keyboardone['text'] = $va->keyboard_name;
                                    $keyboardone['url'] = $va->keyboard_value;
                                    
                                //回调
                                }else{
                                    $keyboardone['text'] = $va->keyboard_name;
                                    $keyboardone['callback_data'] = $va->keyboard_value;
                                }
                                
                                if(!empty($keyboard)){
                                    if(count($keyboard[$s]) == 2){
                                        $s++;
                                    }
                                }
                                
                                $keyboard[$s][] = $keyboardone;
                                $keyboardone = [];
                            }
                        }
                        
                        //键盘
                        if($va->keyboard_type == 1){
                            array_push($keyboard,$keyboardone);
                            array_push($keyboard,$keyboardtwo);
                            array_push($keyboard,$keyboardthree);
                            
                            $reply_markup = [
                                'keyboard' => $keyboard, 
                                'resize_keyboard' => true,  //设置为true键盘不会那么高
                                'one_time_keyboard' => false
                            ];
                        //内联按钮
                        }else{
                            $reply_markup = [
                                'inline_keyboard' => $keyboard
                            ];
                        }
                        
                        $encodedKeyboard = json_encode($reply_markup);
                        $is_keyboard = 'Y';
                    }
                    
                    #过了周期
                    if($send == 'Y'){
                        $save_data = [];
                        $save_data['last_notice_time'] = $time;
                        TelegramBotAd::where('rid',$v->rid)->update($save_data);
                        
                        $bot_token = $v->bot_token;
                        $notice_obj = $v->notice_obj; #多个逗号隔开
                        
                        //定时通知的消息
                        $replytext = $v->notice_ad;
                        $replyphoto = $v->notice_photo;
                        
                        if (strpos($replytext, 'trxusdtrate') !== false || strpos($replytext, 'trxusdtwallet') !== false || strpos($replytext, 'tgbotadmin') !== false || strpos($replytext, 'trxusdtshownotes') !== false || strpos($replytext, 'tgbotname') !== false || strpos($replytext, 'trx10usdtrate') !== false || strpos($replytext, 'trx100usdtrate') !== false || strpos($replytext, 'trx1000usdtrate') !== false) {
                            //替换变量
                            $walletcoin = TransitWalletCoin::from('transit_wallet_coin as a')
                                        ->join('transit_wallet as b','a.transit_wallet_id','b.rid')
                                        ->where('b.bot_rid', $v->bot_rid)
                                        ->where('in_coin_name','usdt')
                                        ->where('out_coin_name','trx')
                                        ->select('a.exchange_rate','b.receive_wallet','b.show_notes')
                                        ->first();
                            if(!empty($walletcoin)){
                                $paraData = [
                                    'trxusdtrate' => $walletcoin->exchange_rate,
                                    'trxusdtwallet' => $walletcoin->receive_wallet,
                                    'tgbotadmin' => $v->bot_admin_username,
                                    'trxusdtshownotes' => $walletcoin->show_notes,
                                    'tgbotname' => '@' . $v->bot_username,
                                    'trx10usdtrate' => bcmul($walletcoin->exchange_rate, 10, 2) + 0,
                                    'trx100usdtrate' => bcmul($walletcoin->exchange_rate, 100, 2) + 0,
                                    'trx1000usdtrate' => bcmul($walletcoin->exchange_rate, 1000, 2) + 0,
                                ];
                                
                                //检查参数是否匹配
                                preg_match_all('/\${.*?}/', $replytext, $matches);
                                $params = $matches[0];
                                $values = [];
                                foreach ($params as $param) {
                                    $key = str_replace(['${', '}'], '', $param);
                                    if(isset($paraData[$key])){
                                        $values[$param] = $paraData[$key];
                                    }
                                }
                         
                                $replytext = strtr($replytext, $values);
                                //替换结束
                            }
                        }
                        
                        $receivelist = explode(',',$notice_obj);
                        
                        foreach ($receivelist as $x => $y) {
                            if(!empty($replyphoto)){
                                //调用官方方法
                                if($is_keyboard == 'Y'){
                                    $data = [
                                        'chat_id' => $y, 
                                        'photo' => $replyphoto,
                                        'caption' => $replytext,
                                        'parse_mode' => 'HTML',
                                        'reply_markup' => $encodedKeyboard
                                    ];
                                }else{
                                    $data = [
                                        'chat_id' => $y, 
                                        'photo' => $replyphoto,
                                        'caption' => $replytext,
                                        'parse_mode' => 'HTML'
                                    ];
                                }
                                
                                $urlString = "https://api.telegram.org/bot$bot_token/sendPhoto";
                                
                                $response = post_multi($urlString,$data);
                                
                            }else{
                                $sendmessageurl = 'https://api.telegram.org/bot'.$v->bot_token.'/sendMessage?chat_id='.$y.'&text='.urlencode($replytext).'&parse_mode=HTML&reply_markup='.urlencode($encodedKeyboard);
                            
                                Get_Pay($sendmessageurl);
                            }
                        }
                    }
                }
            }
            
        }catch (\Exception $e){
            $this->log('sendtimingtgmessage','----------任务执行报错，请联系管理员。报错原因：----------'.$e->getMessage());
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