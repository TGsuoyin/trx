<?php
namespace App\Task;

use App\Model\Premium\PremiumWalletTradeList;
use App\Model\Premium\PremiumPlatformPackage;
use App\Model\Premium\PremiumPlatformOrder;
use App\Model\Premium\PremiumPlatform;
use App\Service\RsaServices;
use App\Library\Log;

class HandleTgPremium
{
    public function execute()
    { 
        try {
            $data = PremiumWalletTradeList::from('premium_wallet_trade_list as a')
                ->join('premium_platform as b','a.transferto_address','b.receive_wallet')
                ->where('a.process_status',1)
                ->where('a.coin_name','usdt')
                ->select('a.rid','a.transferfrom_address','a.amount','b.rid as premium_platform_rid','b.platform_cookie','b.platform_hash','b.status','b.platform_name','b.platform_phrase','a.platform_order_rid')
                ->limit(100)
                ->get();

            if($data->count() > 0){
                foreach ($data as $k => $v) {
                    $time = nowDate();
                    
                    if($v->platform_order_rid){
                        $res = PremiumPlatformOrder::where('rid',$v->platform_order_rid)->where('status',1)->first();
                    }else{
                        //匹配金额
                        $res = PremiumPlatformOrder::where('premium_platform_rid',$v->premium_platform_rid)->where('need_pay_usdt',$v->amount)->where('status',0)->first();
                    }
                    
                    if(empty($res)){
                        $save_data = [];
                        $save_data['process_status'] = 7;  //金额无对应订单
                        $save_data['process_comments'] = '金额无对应订单';      //处理备注  
                        $save_data['process_time'] = $time;      //处理时间
                        $save_data['premium_platform_rid'] = $v->premium_platform_rid;
                        PremiumWalletTradeList::where('rid',$v->rid)->update($save_data);
                        continue;
                    }else{
                        $save_data = [];
                        $save_data['status'] = 1;  //待开通
                        PremiumPlatformOrder::where('rid',$res['rid'])->update($save_data);
                    }

                    if($v->status == 1){
                        $save_data = [];
                        $save_data['process_status'] = 6;  //会员平台未启用
                        $save_data['process_comments'] = '会员平台未启用';      //处理备注  
                        $save_data['process_time'] = $time;      //处理时间
                        $save_data['premium_platform_rid'] = $v->premium_platform_rid;
                        $save_data['premium_package_rid'] = $res['premium_platform_package_rid'];
                        PremiumWalletTradeList::where('rid',$v->rid)->update($save_data);
                        continue;
                    }
                    
                    if(empty($v->platform_cookie) || empty($v->platform_hash) || empty($v->platform_phrase)){
                        $save_data = [];
                        $save_data['process_status'] = 5;  //会员平台未配置正确
                        $save_data['process_comments'] = '会员平台未配置cookie或hash或助记词';      //处理备注  
                        $save_data['process_time'] = $time;      //处理时间
                        $save_data['premium_platform_rid'] = $v->premium_platform_rid;
                        $save_data['premium_package_rid'] = $res['premium_platform_package_rid'];
                        PremiumWalletTradeList::where('rid',$v->rid)->update($save_data);
                        continue;
                    }
                    
                    $rsa_services = new RsaServices();
                    
                    $signstr = $rsa_services->privateDecrypt($v->platform_cookie);
                    
                    if(empty($signstr)){
                        $save_data = [];
                        $save_data['process_status'] = 5;  //会员平台未配置正确
                        $save_data['process_comments'] = '会员平台未配置cookie';      //处理备注  
                        $save_data['process_time'] = $time;      //处理时间
                        $save_data['premium_platform_rid'] = $v->premium_platform_rid;
                        $save_data['premium_package_rid'] = $res['premium_platform_package_rid'];
                        PremiumWalletTradeList::where('rid',$v->rid)->update($save_data);
                        continue;
                    }
                    
                    $phrase = $rsa_services->privateDecrypt($v->platform_phrase);
                    
                    if(empty($phrase)){
                        $save_data = [];
                        $save_data['process_status'] = 5;  //会员平台未配置正确
                        $save_data['process_comments'] = '会员平台未配置助记词';      //处理备注  
                        $save_data['process_time'] = $time;      //处理时间
                        $save_data['premium_platform_rid'] = $v->premium_platform_rid;
                        $save_data['premium_package_rid'] = $res['premium_platform_package_rid'];
                        PremiumWalletTradeList::where('rid',$v->rid)->update($save_data);
                        continue;
                    }
                    
                    $this->log('tgpremium',$v->rid.'：下单中，直接开始第二步');
                    
                    $save_data = [];
                    $save_data['process_status'] = 8;      //下单中
                    $save_data['process_comments'] = '下单中';      //处理备注  
                    $save_data['process_time'] = $time;      //处理时间
                    $save_data['premium_platform_rid'] = $v->premium_platform_rid;
                    $save_data['premium_package_rid'] = $res['premium_platform_package_rid'];
                    $save_data['platform_order_rid'] = $res['rid'];
                    PremiumWalletTradeList::where('rid',$v->rid)->update($save_data);
                    
                    //goto标志,用于获取payload解码失败重试
                    $runCount = 0;
                    runagain:
                    
                    if($runCount >= 3){
                        $save_data = [];
                        $save_data['process_status'] = 4;  //下单失败
                        $save_data['process_comments'] = '执行了3次,获取payload解码失败';      //处理备注  
                        $save_data['process_time'] = $time;      //处理时间
                        PremiumWalletTradeList::where('rid',$v->rid)->update($save_data);
                        continue;
                    }
                        
                    //第二步 创建ton支付订单
                    $order = curl_post_https("https://fragment.com/api?hash=".$v->platform_hash ,"recipient=".$res['recipient']."&months=".$res['premium_package_month']."&method=initGiftPremiumRequest", null, $signstr);
                    $json = json_decode($order,true);
                    $this->log('tgpremium',$v->rid.'：第二步返回：'.$order);
                    $this->log('tgpremium',$v->rid.'：第二步返回：'.$json);
                    
                    if(empty($json['req_id'])){
                        $save_data = [];
                        $save_data['process_status'] = 4;  //下单失败
                        $save_data['process_comments'] = '第二步创建ton支付订单失败';      //处理备注  
                        $save_data['process_time'] = $time;      //处理时间
                        PremiumWalletTradeList::where('rid',$v->rid)->update($save_data);
                        continue;
                    }else{
                        $req_id = $json['req_id']; //获得订单号
                        $amount = $json['amount'];
                        
                        //第三步 确认支付订单  
                        $confirmOrder = curl_post_https("https://fragment.com/api?hash=".$v->platform_hash, "id=".$req_id."&show_sender=1&method=getGiftPremiumLink", null, $signstr);
                        $json = json_decode($confirmOrder,true);
                        $this->log('tgpremium',$v->rid.'：第三步返回：'.$confirmOrder);
                        $this->log('tgpremium',$v->rid.'：第三步返回：'.$json);
                        
                        if(empty($json['ok'])){
                            $save_data = [];
                            $save_data['process_status'] = 4;  //下单失败
                            $save_data['process_comments'] = '第三步确认ton支付订单失败';      //处理备注  
                            $save_data['process_time'] = $time;      //处理时间
                            PremiumWalletTradeList::where('rid',$v->rid)->update($save_data);
                            continue;
                        }else{
                            $qr_link = $json['qr_link']; //获得支付地址（自己生成二维码） 任何TON钱包扫这个二维码支付就可以自动开通会员，当然这是手动模式了，这里自动模式用不到
                            $expire = time() + $json['expire_after'];
                            
                            //第四步 解码订单数据 并调用TON接口 实现自动支付从而实现自动开通会员
                            $decodeOrder = curl_get_https("https://fragment.com/tonkeeper/rawRequest?id=".$req_id."&qr=1");
                            $json = json_decode($decodeOrder,true);
                            $this->log('tgpremium',$v->rid.'：第四步返回：'.$decodeOrder);
                            $this->log('tgpremium',$v->rid.'：第四步返回：'.$json);
                            
                            if(empty($json['body']['params']['messages'])){
                                $save_data = [];
                                $save_data['process_status'] = 4;    //下单失败
                                $save_data['process_comments'] = '第四步解码ton支付订单失败';      //处理备注  
                                $save_data['process_time'] = $time;      //处理时间
                                PremiumWalletTradeList::where('rid',$v->rid)->update($save_data);
                                continue;
                            }else{
                                // $money = base64_decode($json['body']['params']['messages'][0]['amount']); //最终支付金额(精度9) 也就是 amount * 1000000000
                                $money = bcdiv($json['body']['params']['messages'][0]['amount'], 1000000000, 2);
                                $base32 = base64_decode($json['body']['params']['messages'][0]['payload']); //不是完整正确的解码  
                                $this->log('tgpremium',$v->rid.'：解析第四步payload：'.$base32);
                                $base32 = explode("#",$base32);
                                
                                //base64_decode解码后的数据,有时候有乱码,目前正常情况该值均为8位数
                                $base32_1 = preg_replace('/[^A-Za-z0-9]/', '', $base32[1]);
                                if(mb_strlen($base32_1) != 8){
                                    $runCount = $runCount + 1;
                                    goto runagain;
                                }
                                
                                //最终(支付网关)订单数据 需要传递给golang 支付网关，#用%23代替，不然go获取参数获取不了#后的内容
                                if($res['premium_package_month'] == 12){
                                    $base32 = "Telegram Premium for 1 year Ref%23".$base32_1;
                                }else{
                                    $base32 = "Telegram Premium for ".$res['premium_package_month']." months Ref%23".$base32_1;
                                }
                                
                                //第5步 由于只找到JAVA C++ GOlang的SDK，没有找到PHP版本的,所以这里使用GOlang网关(只负责Ton支付业务)
                                //这里面这个TON钱包地址就是fragment官方开会员的固定收款钱包地址,这个地址不能改
                                $raw = '{
                                    "EQBAjaOyi2wGWlk-EDkSabqqnF-MrrwMadnwqrurKpkla9nE": "'.$money.'"  
                                }';
                                
                                //取go的接口域名
                                $ton_url = configDataDictionary()['ton_url']['url'];
                                //发起支付
                                $lastres = curl_get_https($ton_url."/sendTransactions?send_mode=1&phrase=".$phrase."&comment=".$base32,"Content-Type:application/json",$raw);
                                $this->log('tgpremium',$v->rid.'：第五步请求comment：'.$base32);
                                $this->log('tgpremium',$v->rid.'：第五步请求金额：'.$money);
                                $this->log('tgpremium',$v->rid.'：第五步(最后)返回1：'.$lastres);
                                $this->log('tgpremium',$v->rid.'：第五步(最后)返回2：'.json_decode($lastres,true));
                                
                                if(empty($lastres)){
                                    // $save_data = [];
                                    // $save_data['process_status'] = 4;      //下单失败
                                    // $save_data['process_comments'] = '下单失败,最后接口请求空';      //处理备注  
                                    // $save_data['process_time'] = $time;      //处理时间
                                    // PremiumWalletTradeList::where('rid',$v->rid)->update($save_data);
                                    // continue;
                                    
                                    $save_data = [];
                                    $save_data['status'] = 2;
                                    $save_data['complete_time'] = $time;
                                    $save_data['tx_hash'] = '最后交易返回空,看是否支付成功';
                                    $save_data['req_id'] = $req_id;
                                    $save_data['expire_after'] = date('Y-m-d H:i:s', $expire);
                                    $save_data['qr_link'] = $qr_link;
                                    $save_data['amount'] = $money;
                                    $save_data['base32'] = $base32;
                                    PremiumPlatformOrder::where('rid',$res['rid'])->update($save_data);
                                    
                                    $save_data = [];
                                    $save_data['process_status'] = 9;      //下单成功
                                    $save_data['process_comments'] = 'SUCCESS';      //处理备注  
                                    $save_data['process_time'] = $time;      //处理时间
                                    $save_data['tg_notice_status_send'] = 'N';      //重新通知
                                    
                                    PremiumWalletTradeList::where('rid',$v->rid)->update($save_data);
                                    continue;
                                }else{
                                    $lastres = json_decode($lastres,true);
                                    if(isset($lastres['txHash'])){
                                        $save_data = [];
                                        $save_data['status'] = 2;
                                        $save_data['complete_time'] = $time;
                                        $save_data['tx_hash'] = $lastres['txHash'];
                                        $save_data['req_id'] = $req_id;
                                        $save_data['expire_after'] = date('Y-m-d H:i:s', $expire);
                                        $save_data['qr_link'] = $qr_link;
                                        $save_data['amount'] = $money;
                                        $save_data['base32'] = $base32;
                                        PremiumPlatformOrder::where('rid',$res['rid'])->update($save_data);
                                        
                                        $save_data = [];
                                        $save_data['process_status'] = 9;      //下单成功
                                        $save_data['process_comments'] = 'SUCCESS';      //处理备注  
                                        $save_data['process_time'] = $time;      //处理时间
                                        $save_data['tg_notice_status_send'] = 'N';      //重新通知
                                        
                                        PremiumWalletTradeList::where('rid',$v->rid)->update($save_data);
                                        continue;
                                    }else{
                                        $msg = $lastres['error'] ??'未知,请查看go服务日志';
                                        $save_data = [];
                                        $save_data['process_status'] = 4;      //下单失败
                                        $save_data['process_comments'] = $msg;      //处理备注  
                                        $save_data['process_time'] = $time;      //处理时间
                                        PremiumWalletTradeList::where('rid',$v->rid)->update($save_data);
                                        continue;
                                    }
                                }
                            }
                        }
                    }
                }

            }else{
                // $this->log('tgpremium','----------没有数据----------');
            }
        }catch (\Exception $e){
            // $this->log('tgpremium','----------任务执行报错，请联系管理员。报错原因：----------'.$e->getMessage());
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