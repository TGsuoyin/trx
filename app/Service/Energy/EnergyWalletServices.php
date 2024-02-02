<?php

namespace App\Service\Energy;

use App\Model\Energy\EnergyPlatformBot;
use App\Library\Log;
use Hyperf\DbConnection\Db;

class EnergyWalletServices
{
    /**
     * 获取能量钱包列表
     * @param $type [0.读取 1.更新]
    */
    public function getList($type=0){
        $res = EnergyPlatformBot::select('rid','receive_wallet','status','get_tx_time')->where('status',0)->whereRaw('length(receive_wallet) = 34')->orderBy('rid')->get();

        $data = array();
        if($res->count() > 0){
            $res = $res->toArray();
            foreach ($res as $key => $v) {
                $data[$v['rid']] = $v;
            }
        }

        return $res;
    }

    /**
     * 获取收款钱包ID和名称列表 [key为ID value为名称]
     * @param $type [0.列表格式1 1.列表格式2 2.列表格式3]
    */
    public function IDList($type=0){
        $data = $this->getList();
        $list = [];
        if(!empty($data)){
            switch ($type) {
                case 1:
                    foreach ($data as $k => $v) {
                        $list[$k] = $v['receive_wallet'];
                    }
                    break;
                case 2:
                    // key为钱包地址
                    foreach ($data as $k => $v) {
                        $list[$v['receive_wallet']] = $v;
                    }
                    break;
                case 3:
                    // 定时任务过滤状态
                    foreach ($data as $k => $v) {
                        if(in_array($v['status'],[0,2])){
                            $list[] = $v;
                        }
                    }
                    break;
                
                default:
                    foreach ($data as $k => $v) {
                    $list[] = [
                            'rid' => $v['rid'],
                            'receive_wallet' => $v['receive_wallet'],
                        ];
                    }
                    break;
            }
        }

        return $list;
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
