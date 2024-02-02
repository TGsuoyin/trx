<?php
declare(strict_types=1);

namespace App\Controller\Api;
use App\Controller\AbstractController;
use App\Library\Redis;

class ConfigController extends AbstractController
{
    // 清除定时任务缓存
    public function clearTiming()
    {
    	$data = Redis::keys('framework/crontab*');
        if(!empty($data)){
            Redis::del($data);
        }
    	return $this->responseApi(200,'success');
    }
    
    // 检查是否启动
    public function checkStatus()
    {
        return $this->responseApi(200,'success');
    	
    }
}
