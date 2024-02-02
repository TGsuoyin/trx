<?php
namespace App\Task;

use App\Model\Telegram\FmsWalletTradeList;
use App\Model\Telegram\FmsRechargeOrder;
use App\Model\Telegram\TelegramBotUser;
use App\Model\Telegram\TelegramBot;
use App\Library\Log;

class HandleRechargeOrder
{
    public function execute()
    { 
        try {
            //['1' => '待充值','7' => '金额无对应订单','8' => '充值中','9' => '充值成功','4' => '充值失败','2' => '人工禁止','3' => '找不到用户'];
            $data = FmsWalletTradeList::from('fms_wallet_trade_list as a')
                ->where('a.process_status',1)
                ->select('a.rid','a.transferfrom_address','a.amount','a.coin_name','a.tx_hash')
                ->limit(100)
                ->get();

            if($data->count() > 0){
                foreach ($data as $k => $v) {
                    $time = nowDate();
                    
                    //匹配金额
                    $res = FmsRechargeOrder::where('recharge_coin_name',$v->coin_name)->where('need_pay_price',$v->amount)->where('status',0)->first();
                    
                    if(empty($res)){
                        $save_data = [];
                        $save_data['process_status'] = 7;  //金额无对应套餐
                        $save_data['process_comments'] = '金额无对应套餐';      //处理备注  
                        $save_data['process_time'] = $time;      //处理时间
                        FmsWalletTradeList::where('rid',$v->rid)->update($save_data);
                        continue;
                    }
                    
                    //查找用户
                    $botUser = TelegramBotUser::where('bot_rid',$res['bot_rid'])->where('tg_uid',$res['recharge_tg_uid'])->first();
                    
                    if(empty($botUser)){
                        $save_data = [];
                        $save_data['process_status'] = 3;  //找不到用户
                        $save_data['process_comments'] = '找不到用户';      //处理备注  
                        $save_data['process_time'] = $time;      //处理时间
                        FmsWalletTradeList::where('rid',$v->rid)->update($save_data);
                        continue;
                    }
                    
                    $save_data = [];
                    $save_data['status'] = 1;
                    $save_data['complete_time'] = $time;
                    $save_data['tx_hash'] = $v->tx_hash;
                    FmsRechargeOrder::where('rid',$res['rid'])->update($save_data);
                    
                    $save_data = [];
                    $save_data['cash_trx'] = $botUser['cash_trx'] + ($v->coin_name == 'trx' ?$res['recharge_pay_price']:0);
                    $save_data['cash_usdt'] = $botUser['cash_usdt'] + ($v->coin_name == 'usdt' ?$res['recharge_pay_price']:0);
                    $save_data['total_recharge_trx'] = $botUser['total_recharge_trx'] + ($v->coin_name == 'trx' ?$res['recharge_pay_price']:0);
                    $save_data['total_recharge_usdt'] = $botUser['total_recharge_usdt'] + ($v->coin_name == 'usdt' ?$res['recharge_pay_price']:0);
                    TelegramBotUser::where('rid',$botUser['rid'])->update($save_data);

                    $save_data = [];
                    $save_data['process_status'] = 9;      //充值成功
                    $save_data['process_comments'] = '充值成功';      //处理备注  
                    $save_data['process_time'] = $time;      //处理时间
                    $save_data['recharge_order_rid'] = $res['rid'];
                    FmsWalletTradeList::where('rid',$v->rid)->update($save_data);
                    
                    $bot = TelegramBot::where('rid',$res['bot_rid'])->first();
                    if(!empty($bot)){
                        $replytext = "✅您的充值订单已成功！\n"
                                    ."➖➖➖➖➖➖➖➖\n"
                                    ."<b>订单号：</b>".$v->rid."\n"
                                    ."<b>充值币种：</b>".$v->coin_name."\n"
                                    ."<b>充值金额：</b>".$res['recharge_pay_price']."\n";
                                 
                        //通知用户
                        $sendmessageurl = 'https://api.telegram.org/bot'.$bot['bot_token'].'/sendMessage?chat_id='.$res['recharge_tg_uid'].'&text='.urlencode($replytext).'&parse_mode=HTML';
                            
                        Get_Pay($sendmessageurl);
                    }
                }

            }else{
                // $this->log('shanduibonus','----------没有数据----------');
            }
        }catch (\Exception $e){
            // $this->log('shanduibonus','----------任务执行报错，请联系管理员。报错原因：----------'.$e->getMessage());
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