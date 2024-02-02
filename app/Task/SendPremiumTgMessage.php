<?php
namespace App\Task;

use App\Model\Premium\PremiumWalletTradeList;
use App\Library\Log;

class SendPremiumTgMessage
{
    public function execute()
    { 
        try {
            $data = PremiumWalletTradeList::from('premium_wallet_trade_list as a')
                    ->leftJoin('premium_platform as b','a.premium_platform_rid','b.rid')
                    ->leftJoin('telegram_bot as c','b.bot_rid','c.rid')
                    ->leftJoin('premium_platform_package as d','a.premium_package_rid','d.rid')
                    ->where('a.tg_notice_status_receive','N')
                    ->orWhere('a.tg_notice_status_send','N')
                    ->select('a.rid','a.tx_hash','a.transferfrom_address','a.coin_name','a.amount','a.process_status','a.tg_notice_status_receive','a.tg_notice_status_send','b.tg_notice_obj_receive','b.tg_notice_obj_send',
                             'c.bot_token','b.receive_wallet','d.package_name','c.bot_admin_username','c.bot_username')
                    ->limit(5)
                    ->get();

            if($data->count() > 0){
                foreach ($data as $k => $v) {
                    if(empty($v->bot_token)){
                        $save_data = [];
                        $save_data['tg_notice_status_receive'] = 'Y';
                        $save_data['tg_notice_status_send'] = 'Y';
                        PremiumWalletTradeList::where('rid',$v->rid)->update($save_data);
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

                    //['6' => '会员平台未启用','7' => '金额无对应订单','8' => '下单中','9' => '下单成功','1' => '待下单','5' => '会员平台未配置正确','4' => '下单失败','2' => '人工禁止'];
                    //接收的通知,某些状态才通知
                    if($v->tg_notice_status_receive == 'N' && in_array($v->process_status, [1,8,9]) && !empty($v->tg_notice_obj_receive) && $v->tg_notice_obj_receive != ''){
                        $replytext = "👑有新的开通会员：\n"
                                    ."➖➖➖➖➖➖➖➖\n"
                                    ."转入交易哈希：<code>".$v->tx_hash."</code>\n"
                                    ."转入钱包地址：<code>".$v->transferfrom_address."</code>\n"
                                    ."转入币名：".$v->coin_name."\n"
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
                            $replytext = "👑<b>新的会员订单成功</b> \n"
                                    ."认准24小时自动购买会员地址(点击复制)：<code>".$v->receive_wallet."</code>\n"
                                    ."➖➖➖➖➖➖➖➖\n"
                                    ."<b>购买套餐</b>：".$v->package_name ."\n"
                                    ."<b>支付地址</b>：".mb_substr($v->transferfrom_address,0,8).'****'.mb_substr($v->transferfrom_address,-8,8) ."\n\n"
                                    ."<b>会员已经到账！</b>\n"
                                    ."私聊机器人可继续购买Telegram会员！\n"
                                    ."➖➖➖➖➖➖➖➖";
                                    
                        //内联按钮
                        $keyboard = [
                            'inline_keyboard' => [
                                [
                                    ['text' => '👨联系客服', 'url' => 'https://t.me/'.mb_substr($v->bot_admin_username,1)],
                                    ['text' => '👑购买会员', 'url' => 'https://t.me/'.$v->bot_username]
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
                    
                    //['6' => '会员平台未启用','7' => '金额无对应订单','8' => '下单中','9' => '下单成功','1' => '待下单','5' => '会员平台未配置正确','4' => '下单失败','2' => '人工禁止'];
                    //某些状态直接改为Y,其他状态不改,避免还没闪兑成功发不出去通知
                    }elseif(in_array($v->process_status, [2,6,7,5,4])){
                        $notice_send = 'Y';
                        $notice_receive = 'Y';
                    }
                    
                    if($notice_send == 'Y' || $notice_receive = 'Y'){
                        $save_data = [];
                        $save_data['tg_notice_status_receive'] = $notice_receive == 'Y' ? 'Y' : $v->tg_notice_status_receive;
                        $save_data['tg_notice_status_send'] = $notice_send == 'Y' ? 'Y' : $v->tg_notice_status_send;
                        PremiumWalletTradeList::where('rid',$v->rid)->update($save_data);
                    }
                }
            }
            
        }catch (\Exception $e){
            // $this->log('sendtransittgmessage','----------任务执行报错，请联系管理员。报错原因：----------'.$e->getMessage());
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