<?php

namespace App\Service\Transit;

use App\Model\Transit\TransitWallet;

class TransitWalletServices
{
    /**
     * 获取收款钱包列表
     * @param $type [0.读取 1.更新]
    */
    public function getList($type=0){
        $res = TransitWallet::select('rid','chain_type','receive_wallet','send_wallet','send_wallet_privatekey','status','tg_notice_obj_receive','tg_notice_obj_send','get_tx_time')->where('status',0)->whereRaw('length(receive_wallet) = 34')->orderBy('rid')->get();

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
}
