<?php
namespace App\Task;

use App\Model\Energy\EnergyAiTrusteeship;
use App\Library\Log;
use Hyperf\Utils\Coroutine\Concurrent;

class GetAiTrusteeshipWalletResource
{
    public function execute()
    { 
        try {
            $data = EnergyAiTrusteeship::from('energy_ai_trusteeship as a')
                    ->Join('telegram_bot as b','a.bot_rid','b.rid')
                    ->Join('energy_platform_bot as c','a.bot_rid','c.bot_rid')
                    ->where('a.status',0)
                    ->where('c.is_open_ai_trusteeship','Y')
                    ->whereRaw('length(t_a.wallet_addr) = 34')
                    ->select('a.*','b.bot_token')
                    ->get();
            
            if($data->count() > 0){
                //协程数量
                $concurrent = new Concurrent(5);
                
                foreach ($data as $k => $v) {
                    $concurrent->create(function () use ($v) {
                        sleep(1); //不容易被api限制
                        $url = 'https://apilist.tronscanapi.com/api/accountv2?address='.$v['wallet_addr'];
                        
                        $api_key = config('apikey.tronapikey');
                        $apikeyrand = $api_key[array_rand($api_key)];
                        $heders = [
                            "TRON-PRO-API-KEY:".$apikeyrand
                        ];
                        
                        $res = Get_Pay($url,null,$heders);
                        
                        if(empty($res)){
                            //为空则什么都不处理
                        }else{
                            $res = json_decode($res,true);
                            if(isset($res['bandwidth'])){
                                //只处理激活的地址,未激活不能代理
                                $active = $res['activated'] ?"Y":"N";
                                if($active == 'Y'){
                                    $bandwidth = $res['bandwidth']['freeNetRemaining'] + $res['bandwidth']['netRemaining'];
                                    $energy = $res['bandwidth']['energyRemaining'];
                                    
                                    //低于最低值的时候,则需要下单
                                    if($energy < $v['min_energy_quantity'] && $v['is_buy'] == 'N'){
                                        $updatedata['is_buy'] = 'Y';
                                    }
                                    $updatedata['current_bandwidth_quantity'] = $bandwidth;
                                    $updatedata['current_energy_quantity'] = $energy;
                                    EnergyAiTrusteeship::where('rid',$v['rid'])->update($updatedata);
                                }
                            }
                        }
                    });
                }
            }
            
        }catch (\Exception $e){
            $this->log('energyplatformbalance','----------任务执行报错，请联系管理员。报错原因：----------'.$e->getMessage());
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