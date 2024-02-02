<?php
namespace App\Task;

use App\Model\Transit\TransitWalletCoin;
use App\Model\Transit\TransitWalletTradeList;
use App\Library\Log;

class SendTransitTgMessage
{
    public function execute()
    { 
        try {
            $data = TransitWalletTradeList::from('transit_wallet_trade_list as a')
                    ->leftJoin('transit_wallet as b','a.transferto_address','b.receive_wallet')
                    ->leftJoin('telegram_bot as c','b.bot_rid','c.rid')
                    ->where('a.tg_notice_status_receive','N')
                    ->orWhere('a.tg_notice_status_send','N')
                    ->select('a.rid','a.tx_hash','a.transferfrom_address','a.coin_name','a.amount','a.process_status','a.tg_notice_status_receive','a.tg_notice_status_send','a.sendback_coin_name','a.sendback_tx_hash','a.sendback_amount','b.tg_notice_obj_receive','b.tg_notice_obj_send','c.bot_token','b.receive_wallet','a.current_huan_yuzhi_amount','a.sendback_time','c.bot_admin_username')
                    ->limit(5)
                    ->get();

            if($data->count() > 0){
                foreach ($data as $k => $v) {
                    
                    if(empty($v->bot_token)){
                        $save_data = [];
                        $save_data['tg_notice_status_receive'] = 'Y';
                        $save_data['tg_notice_status_send'] = 'Y';
                        TransitWalletTradeList::where('rid',$v->rid)->update($save_data);
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

                    //['6' => '黑钱包','7' => '转入金额不符','8' => '转帐中','9' => '转账成功','1' => '待兑换','10' => '余额不足','5' => '币种无效','2' => '交易失败','0' => '待确认'];
                    //接收的通知,某些状态才通知
                    if($v->tg_notice_status_receive == 'N' && in_array($v->process_status, [1,8,9,10]) && !empty($v->tg_notice_obj_receive) && $v->tg_notice_obj_receive != ''){
                        $replytext = "有新的闪兑交易：\n"
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
                        if($v->current_huan_yuzhi_amount > 0){
                            $replytext = "✅<b>USDT 兑换 TRX成功</b> \n"
                                    ."认准24小时自动回TRX地址(点击复制)：<code>".$v->receive_wallet."</code>\n"
                                    ."➖➖➖➖➖➖➖➖\n"
                                    ."<b>兑换金额</b>：".$v->amount ." USDT\n"
                                    ."<b>TRX数量</b>：".$v->sendback_amount ." TRX\n"
                                    ."<b>归还预支TRX数量</b>：".$v->current_huan_yuzhi_amount ." TRX\n"
                                    ."<b>兑换地址</b>：".mb_substr($v->transferfrom_address,0,8).'****'.mb_substr($v->transferfrom_address,-8,8) ."\n"
                                    ."<b>兑换时间</b>：".$v->sendback_time ."\n"
                                    // ."<b>交易HASH</b>：".$v->sendback_tx_hash ."\n"
                                    ."➖➖➖➖➖➖➖➖";
                        }else{
                            $replytext = "✅<b>USDT 兑换 TRX成功</b> \n"
                                    ."认准24小时自动回TRX地址(点击复制)：<code>".$v->receive_wallet."</code>\n"
                                    ."➖➖➖➖➖➖➖➖\n"
                                    ."<b>兑换金额</b>：".$v->amount ." USDT\n"
                                    ."<b>TRX数量</b>：".$v->sendback_amount ." TRX\n"
                                    ."<b>兑换地址</b>：".mb_substr($v->transferfrom_address,0,8).'****'.mb_substr($v->transferfrom_address,-8,8) ."\n"
                                    ."<b>兑换时间</b>：".$v->sendback_time ."\n"
                                    // ."<b>交易HASH</b>：".$v->sendback_tx_hash ."\n"
                                    ."➖➖➖➖➖➖➖➖";
                        }
                                    	
                        // $url = 'https://tronscan.io/#/transaction/'.$v->sendback_tx_hash;
                        
                        // //内联按钮
                        // $keyboard = [
                        //     'inline_keyboard' => [
                        //         [
                        //             ['text' => '查看回款交易', 'url' => $url]
                        //         ]
                        //     ]
                        // ];
                        $keyboard = [
                            'inline_keyboard' => [
                                [
                                    ['text' => '👨联系客服', 'url' => 'https://t.me/'.mb_substr($v->bot_admin_username,1)]
                                ]
                            ]
                        ];
                        
                        $encodedKeyboard = json_encode($keyboard);
                        
                        $sendlist = explode(',',$v->tg_notice_obj_send);
                        
                        foreach ($sendlist as $x => $y) {
                            // $sendmessageurl = 'https://api.telegram.org/bot'.$v->bot_token.'/sendMessage?chat_id='.$y.'&text='.urlencode($replytext).'&parse_mode=HTML';
                            $sendmessageurl = 'https://api.telegram.org/bot'.$v->bot_token.'/sendMessage?chat_id='.$y.'&text='.urlencode($replytext).'&parse_mode=HTML&reply_markup='.urlencode($encodedKeyboard);
                            
                            Get_Pay($sendmessageurl);
                        }
                        
                        $notice_send = 'Y';
                    
                    //['6' => '黑钱包','7' => '转入金额不符','8' => '转帐中','9' => '转账成功','1' => '待兑换','10' => '余额不足','5' => '币种无效','2' => '交易失败','0' => '待确认'];
                    //某些状态直接改为Y,其他状态不改,避免还没闪兑成功发不出去通知
                    }elseif(in_array($v->process_status, [6,7,10,5,2])){
                        $notice_send = 'Y';
                        $notice_receive = 'Y';
                    }
                    
                    if($notice_send == 'Y' || $notice_receive = 'Y'){
                        $save_data = [];
                        $save_data['tg_notice_status_receive'] = $notice_receive == 'Y' ? 'Y' : $v->tg_notice_status_receive;
                        $save_data['tg_notice_status_send'] = $notice_send == 'Y' ? 'Y' : $v->tg_notice_status_send;
                        TransitWalletTradeList::where('rid',$v->rid)->update($save_data);
                    }
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