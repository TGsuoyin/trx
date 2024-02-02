<?php
namespace App\Task;

use App\Model\Premium\PremiumPlatformOrder;
use App\Model\Telegram\FmsRechargeOrder;
use App\Service\RsaServices;
use App\Library\Log;

class CancelUnpaidOrder
{
    public function execute()
    { 
        try {
            //取消会员订单
            $data = PremiumPlatformOrder::from('premium_platform_order as a')
                    ->Join('telegram_bot as b','a.bot_rid','b.rid')
                    ->where('a.status',0)
                    ->where('a.expire_time','<=',nowDate())
                    ->select('a.rid','a.expire_time','b.bot_token','a.status','a.buy_tg_uid','a.premium_tg_username','a.premium_package_month','a.need_pay_usdt')
                    ->limit(20)->get();
            
            if($data->count() > 0){
                foreach ($data as $k => $v) {
                    $time = nowDate();
                    //再次校验过期时间小于当前时间,直接改为已失效并通知用户
                    if($v->expire_time <= $time && $v->status == 0){
                        $save_data = [];
                        $save_data['status'] = 3;
                        $save_data['update_time'] = $time;
                        PremiumPlatformOrder::where('rid',$v->rid)->update($save_data);
                        
                        $replytext = "您的会员订单已过期，请重新发起：\n"
                                    ."➖➖➖➖➖➖➖➖\n"
                                    ."<b>订单号：</b>".$v->rid."\n"
                                    ."<b>开通会员用户名：</b>".$v->premium_tg_username."\n"
                                    ."<b>开通会员月份：</b>".$v->premium_package_month."\n"
                                    ."<b>应支付USDT：</b>".$v->need_pay_usdt."\n\n"
                                    ."<b>请勿继续支付该订单！如已支付请联系客服！</b>";
                             
                        //通知用户
                        $sendmessageurl = 'https://api.telegram.org/bot'.$v->bot_token.'/sendMessage?chat_id='.$v->buy_tg_uid.'&text='.urlencode($replytext).'&parse_mode=HTML';
                            
                        Get_Pay($sendmessageurl);
                    }
                }
            }else{
                // $this->log('cancelunpaidorder','----------没有数据----------');
            }
            
            //取消充值订单
            $data = FmsRechargeOrder::from('fms_recharge_order as a')
                    ->Join('telegram_bot as b','a.bot_rid','b.rid')
                    ->where('a.status',0)
                    ->where('a.expire_time','<=',nowDate())
                    ->select('a.rid','a.expire_time','b.bot_token','a.status','a.recharge_tg_uid','a.need_pay_price','a.recharge_coin_name','a.recharge_pay_price')
                    ->limit(20)->get();
            
            if($data->count() > 0){
                foreach ($data as $k => $v) {
                    $time = nowDate();
                    //再次校验过期时间小于当前时间,直接改为已失效并通知用户
                    if($v->expire_time <= $time && $v->status == 0){
                        $save_data = [];
                        $save_data['status'] = 2;
                        $save_data['update_time'] = $time;
                        FmsRechargeOrder::where('rid',$v->rid)->update($save_data);
                        
                        $replytext = "⚠️您的充值订单已过期，请重新发起：\n"
                                    ."➖➖➖➖➖➖➖➖\n"
                                    ."<b>订单号：</b>".$v->rid."\n"
                                    ."<b>充值币种：</b>".$v->recharge_coin_name."\n"
                                    ."<b>充值金额：</b>".$v->recharge_pay_price."\n"
                                    ."<b>应支付金额：</b>".$v->need_pay_price."\n\n"
                                    ."<b>请勿继续支付该订单！如已支付请联系客服！</b>";
                             
                        //通知用户
                        $sendmessageurl = 'https://api.telegram.org/bot'.$v->bot_token.'/sendMessage?chat_id='.$v->recharge_tg_uid.'&text='.urlencode($replytext).'&parse_mode=HTML';
                            
                        Get_Pay($sendmessageurl);
                    }
                }
            }else{
                // $this->log('cancelunpaidorder','----------没有数据----------');
            }
        }catch (\Exception $e){
            // $this->log('cancelunpaidorder','----------任务执行报错，请联系管理员。报错原因：----------'.$e->getMessage());
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