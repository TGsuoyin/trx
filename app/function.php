<?php
use App\Library\Redis;
use App\Library\Log;
use Hyperf\DbConnection\Db;

/**
 * 下一天
 * @param $data 日期格式 2022-04-05
 * @return false|string
 */
function nextDay($data)
{
    return date('Y-m-d',strtotime('+1 day', strtotime($data)));
}

/*
 * 把十位时间戳转换为日期格式
 */
function nowDate()
{
    return date('Y-m-d H:i:s');
}

/*
 * 获取十三位时间戳
 */
function thirteenTime(){
    return floor(microtime(true)*1000);
}

/*
 * 十三位时间戳转换为日期格式
 */
function nowDateThirteen($timess){
    return date('Y-m-d H:i:s', floor($timess / 1000));
}

if (!function_exists('getTree')){
    function getTree($data, $pId=0)
    {
        $tree = array();
        foreach ($data as $k => $v) {
            if ($v->parentId == $pId) { //父亲找到儿子
                $v->children = getTree($data, $v->id);
                $tree[] = $v;
                //unset($data[$k]);
            }
        }
        return $tree;
    }
}

/**
    * 把数字1-1亿换成汉字表述，如：123->一百二十三
    * @param [num] $num [数字]
    * @return [string] [string]
    */
if (!function_exists('numToWord')) {
    function numToWord($num)
    {
        $chiNum = array('零', '一', '二', '三', '四', '五', '六', '七', '八', '九');
        $chiUni = array('','十', '百', '千', '万', '亿', '十', '百', '千');

        $chiStr = '';

        $num_str = (string)$num;

        $count = strlen($num_str);
        $last_flag = true; //上一个 是否为0
        $zero_flag = true; //是否第一个
        $temp_num = null; //临时数字

        $chiStr = '';//拼接结果
        if ($count == 2) {//两位数
            $temp_num = $num_str[0];
            $chiStr = $temp_num == 1 ? $chiUni[1] : $chiNum[$temp_num].$chiUni[1];
            $temp_num = $num_str[1];
            $chiStr .= $temp_num == 0 ? '' : $chiNum[$temp_num];
        }else if($count > 2){
            $index = 0;
            for ($i=$count-1; $i >= 0 ; $i--) {
                $temp_num = $num_str[$i];
                if ($temp_num == 0) {
                    if (!$zero_flag && !$last_flag ) {
                        $chiStr = $chiNum[$temp_num]. $chiStr;
                        $last_flag = true;
                    }
                }else{
                    $chiStr = $chiNum[$temp_num].$chiUni[$index%9] .$chiStr;
                    $zero_flag = false;
                    $last_flag = false;
                }
                $index ++;
            }
        }else{
            $chiStr = $chiNum[$num_str[0]];
        }
        return $chiStr;
    }
}

if (!function_exists('createNo')) {
    function createNo($prefix = '')
    {
        return $prefix . date('YmdHis') . sprintf("%03d", rand(1, 99));
    }
}

if (!function_exists('llog')) {
    /**
     * 记入日志
     * @param $log_title [日志路径]
     * @param $message [内容，不支持数组]
     * @param $remarks [备注]
    */
    function llog($log_title,$message,$remarks='info'){
        Log::get($remarks,$log_title)->info($message);
    }
}

if (!function_exists('transDate')) {
    function transDate($data)
    {
        return date('Y-m-d H:i:s', strtotime($data));
    }
}

if (!function_exists('complete_user_pic')) {
    function complete_user_pic($val) {
        if ($val == '') {
            return env('DEFAULT_USER_PIC');
        } else {
            if (strpos('http', $val) === false) {
                return env('OSS_URL') . $val;
            }
        }


    }
}

if (!function_exists('createOrderNo')) {
    function createOrderNo()
    {
        return date('YmdHis') . sprintf("%03d", rand(1, 999));
    }
}

if (!function_exists('genKey')) {
    function genKey($key_length){
        $str = 'abcdefghijklmnopqrstuvwxyzABCDEFGHIJKLMNOPQRSTUVWXYZ0123456789';
        $len = strlen($str)-1;
        $key = '';
        for ($i=0; $i<$key_length; $i++){
            $num = mt_rand(0, $len);
            $key .= $str[$num];
        }
        return $key;
    }
}

if (!function_exists('complete_pic')) {
    function complete_pic($val) {
        // $url = $remote == 'server' ? env('APP_URL') : env('API_URL');
        if ($val != '' && strpos($val, 'http') === false) {
            return env('OSS_URL') . $val;
        }
        return $val;
    }
}

/**
 * 系统数据字典表
 * @param $type [0.读取 1.更新]
*/
function configDictionary($type=0){
    $prefix = env('REDIS_PREFIX','swapbot_');
    if($type == 1){
        Redis::del($prefix.'DictionaryData');
    }else{
        $data = Redis::get($prefix.'DictionaryData');
    }

    if(empty($data)){
        $res = Db::table('sys_data_dictionary')->select('dic_key','dic_value','dic_name')->get()->map(function ($value){
            return (array)$value;
        })->toArray();
        $data= array();
        foreach ($res as $key => $v) {
            $data[$v['dic_key']][$v['dic_value']] = $v['dic_name'];
        }

        Redis::setex($prefix.'DictionaryData',86400,serialize($data));
    }else{
        $data = unserialize($data);
    }
    return $data;
}

/**
 * 系统配置
 * @param $type [0.读取 1.更新]
*/
function configDataDictionary($type=0){
    $prefix = env('REDIS_PREFIX','swapbot_');
    if($type == 1){
        Redis::del($prefix.'ConfigData');
    }else{
        $data = Redis::get($prefix.'ConfigData');
    }

    if(empty($data)){
        $res = Db::table('sys_config')->select('config_key','config_val')->get()->map(function ($value){
            return (array)$value;
        })->toArray();
        $data= array();
        foreach ($res as $key => $v) {
            $data[$v['config_key']] = json_decode($v['config_val'],true);
        }
        Redis::setex($prefix.'ConfigData',86400,serialize($data));
        
    }else{
        $data = unserialize($data);
    }
    return $data;
}

/**
 * 获取Redis
 * @param $field  字段
 */
function getRedis($field){
    $prefix = env('REDIS_PREFIX','swapbot_');
    return Redis::get($prefix.$field);
}

/**
 * 存储Redis
 * @param $field  字段
 * @param $data  值
 */
function setRedis($field,$data){
    $prefix = env('REDIS_PREFIX','swapbot_');
    return Redis::set($prefix.$field,$data);
}

/**
 * 删除Redis
 * @param $data  值
 */
function deleteRedis($data){
    $prefix = env('REDIS_PREFIX','swapbot_');
    return Redis::del($prefix.$data);
}

/**
 * API请求
 * @param $url  链接
 * @param $data  参数
 */
function Get_Pay($url, $data = null, array $heders = [], $time=0)
{
    $ch = curl_init();
    curl_setopt($ch, CURLOPT_URL, $url);
    curl_setopt($ch, CURLOPT_HEADER, 0);
    curl_setopt($ch, CURLOPT_TIMEOUT, $time);          //单位 秒，也可以使用
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, 1);
    curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false); //这个是重点,规避ssl的证书检查。
    curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, FALSE); // 跳过host验证
    if(!empty($data)){
        curl_setopt($ch, CURLOPT_POSTFIELDS, $data);
    }
    if(!empty($heders)){
        curl_setopt($ch, CURLOPT_HTTPHEADER, $heders);
    }
    $content = curl_exec($ch);
    curl_close($ch);
    return $content;
}

/**
 * post请求
 * @param $url  string api链接
 * @param $url  array 参数
 * @param $time_out int 超时时间
 */
function post_url(string $url, array $data = [],$time_out = 5)
{
    $data = json_encode($data);
    $headerArray = array("Content-type: application/json;charset='utf-8'");
    $curl = curl_init();
    curl_setopt($curl, CURLOPT_URL, $url);
    curl_setopt($curl, CURLOPT_CONNECTTIMEOUT , $time_out);
    curl_setopt($curl, CURLOPT_SSL_VERIFYPEER, 0);
    curl_setopt($curl, CURLOPT_SSL_VERIFYHOST, 0);
    curl_setopt($curl, CURLOPT_POST, 1);
    curl_setopt($curl, CURLOPT_POSTFIELDS, $data);
    curl_setopt($curl, CURLOPT_HTTPHEADER, $headerArray);
    curl_setopt($curl, CURLOPT_RETURNTRANSFER, 1);
    $output = curl_exec($curl);
    curl_close($curl);
    return json_decode($output,true);
}

/**
 * post请求
 * @param $url  string api链接
 * @param $data  array 参数
 * @param $time_out int 超时时间
 */
function post_multi(string $url, array $data = [],$time_out = 5)
{
    $headerArray = array("Content-Type: multipart/form-data;charset='utf-8'");
    $curl = curl_init();
    curl_setopt($curl, CURLOPT_URL, $url);
    curl_setopt($curl, CURLOPT_CONNECTTIMEOUT , $time_out);
    curl_setopt($curl, CURLOPT_SSL_VERIFYPEER, 0);
    curl_setopt($curl, CURLOPT_SSL_VERIFYHOST, 0);
    curl_setopt($curl, CURLOPT_POST, 1);
    curl_setopt($curl, CURLOPT_POSTFIELDS, $data);
    curl_setopt($curl, CURLOPT_HTTPHEADER, $headerArray);
    curl_setopt($curl, CURLOPT_RETURNTRANSFER, 1);
    $output = curl_exec($curl);
    curl_close($curl);
    return json_decode($output,true);
}

/**
 * API请求2
 * @param $url  链接
 * @param $data  参数
 */
function curl_post_https($url,$data,$headers=null,$cookie=null){ // 模拟提交数据函数
    $curl = curl_init(); // 启动一个CURL会话
    curl_setopt($curl, CURLOPT_URL, $url); // 要访问的地址
    curl_setopt($curl, CURLOPT_SSL_VERIFYPEER, 0); // 对认证证书来源的检查
    curl_setopt($curl, CURLOPT_SSL_VERIFYHOST, 2); // 从证书中检查SSL加密算法是否存在
    // curl_setopt($curl, CURLOPT_USERAGENT, $_SERVER['HTTP_USER_AGENT']); // 模拟用户使用的浏览器
    // curl_setopt($curl, CURLOPT_FOLLOWLOCATION, 1); // 使用自动跳转
    // curl_setopt($curl, CURLOPT_AUTOREFERER, 1); // 自动设置Referer
    if(!empty($headers)){
        curl_setopt($curl, CURLOPT_HTTPHEADER, $headers);//设置请求头
    }
    if(!empty($cookie)){
        curl_setopt($curl, CURLOPT_COOKIE, $cookie); // 带上COOKIE请求
    } 
    curl_setopt($curl, CURLOPT_POST, 1); // 发送一个常规的Post请求
    curl_setopt($curl, CURLOPT_POSTFIELDS, $data); // Post提交的数据包
    curl_setopt($curl, CURLOPT_TIMEOUT, 10); // 设置超时限制防止死循环
    curl_setopt($curl, CURLOPT_HEADER, 0); // 显示返回的Header区域内容
    curl_setopt($curl, CURLOPT_RETURNTRANSFER, 1); // 获取的信息以文件流的形式返回
    $tmpInfo = curl_exec($curl); // 执行操作
    curl_close($curl); // 关闭CURL会话
    return $tmpInfo; // 返回数据
}

/**
 * API请求3
 * @param $url  链接
 * @param $data  参数
 */
function curl_get_https($url,$headers=null,$raw=null,$time=6){
    $curl = curl_init(); 
    curl_setopt($curl, CURLOPT_URL, $url);
    curl_setopt($curl, CURLOPT_TIMEOUT, $time);
    curl_setopt($curl, CURLOPT_HEADER, 0);
    if(!empty($headers)){
        curl_setopt($curl, CURLOPT_HTTPHEADER, $headers);//设置请求头
    }
    curl_setopt($curl, CURLOPT_RETURNTRANSFER, 1);
    curl_setopt($curl, CURLOPT_SSL_VERIFYPEER, false); // 跳过证书检查
    curl_setopt($curl, CURLOPT_SSL_VERIFYHOST, 2);  // 从证书中检查SSL加密算法是否存在
    if($raw){
        curl_setopt($curl, CURLOPT_POSTFIELDS, $raw); // Post提交的数据包 
    }
    $tmpInfo = curl_exec($curl);     //返回api的json对象
    curl_close($curl);
    return $tmpInfo;   
}

// 获取域名
function getyuming(){
    $http_type = ((isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] == 'on') || (isset($_SERVER['HTTP_X_FORWARDED_PROTO']) && $_SERVER['HTTP_X_FORWARDED_PROTO'] == 'https')) ? 'https://' : 'http://';

    return env('APP_URL') ?? $http_type;
}


function array_replace_key_by_column(array $lists, $key = ''){

    $datas = [];
    if(is_array($lists) && $lists){
        foreach ($lists as $item){
            $datas[$item[$key]] = $item;
        }
    }
    return $datas;
}

/**
 * 添加后台管理员操作日志
 * @param  $opt_ref_sn [操作对象ID]
*/
function addAdminOptLog($opt_ref_sn=''){
    Db::table('sys_admin_opt_log')->insert([
        'admin_id' => auth('admin')->id(),     //管理用户ID
        'opt_module' => request()->path(),       //操作模块
        'opt_ref_sn' => $opt_ref_sn,       //操作对象ID
        'opt_time' => nowDate(),     //操作时间
        'opt_ip' => request()->getClientIp(),       //操作ip
    ]);
}

/**
 * 获取Redis数组key
 * @param $data  值
 */
function getKeyRedis(){
    $prefix = env('REDIS_PREFIX','swapbot_');
    return Redis::keys($prefix."*");
}

/**
 * 获取最后一位数字
 * @param $str  字符串
 */
function descNumberOne($str){
    $str = strrev($str);        //倒序
    $data = '';
    for ($i=0; $i < strlen($str); $i++) { 
        if(is_numeric($str[$i])){
            $data = $str[$i];
            break;
        }
    }
    return $data;
}

/**
 * 字符串位数不足在前面补0
 * @param $str
 * @param int $bit
 * @return string
 */
function fill0($str, $bit=64){
    if(!strlen($str)) return "";
    $str_len = strlen($str);
    $zero = '';
    for($i=$str_len; $i<$bit; $i++){
        $zero .= "0";
    }
    $real_str = $zero . $str;
    return $real_str;
}

/**
 * 转为十六进制
 * @param  string|number  $value 十进制的数
 * @param  boolean $mark  是否加0x头
 * @return string
 */
function decToHex($value, $mark = true)
{
    $hexvalues = [
        '0','1','2','3','4','5','6','7',
        '8','9','a','b','c','d','e','f'
    ];
    $hexval = '';
    while($value != '0') {
        $hexval = $hexvalues[bcmod($value, '16')] . $hexval;
        $value = bcdiv($value, '16', 0);
    }

    return ($mark ? '0x' . $hexval : $hexval);
}

/**
 * 数组转json
 * @param $array
 * @return false|string
 */
function jsonEncode($array){
    return json_encode($array, JSON_UNESCAPED_UNICODE);
}

/**
 * json转数组
 * @param $json
 * @return mixed
 */
function jsonDecode($json){
    return json_decode($json, true);
}

/**
 * 高精度计算相除
 * @param $price [金额]
 * @param $number [多少个0]
*/
function calculationExcept($price,$number){
    $multiple = 1;          //倍数
    if($number > 0){
        for ($i=0; $i < $number; $i++) { 
            $multiple = $multiple.'0';
        }
        $multiple = (int)$multiple;
    }
    $data = bcdiv($price,$multiple,$number);

    if(strpos(floatval($data),'-') == false){
        // 精度未失效
        $data = floatval($data);
    }else{
        // 精度失效
        $data = rtrim(rtrim($data, '0'), '.');
    }

    return $data;
}

/**
 * 处理金额
 * @param $amount [金额]
*/
function handleAmount($amount){
    return $amount * 10 ** 6;
}

/**
 * 转为十进制
 * @param  string $number 十六进制的数
 * @return string
 */
function hexToDec($number)
{
    // 如果有0x去除它
    $number = remove0x(strtolower($number));
    $decvalues = [
        '0' => '0', '1' => '1', '2' => '2',
        '3' => '3', '4' => '4', '5' => '5',
        '6' => '6', '7' => '7', '8' => '8',
        '9' => '9', 'a' => '10', 'b' => '11',
        'c' => '12', 'd' => '13', 'e' => '14',
        'f' => '15'];
    $decval = '0';
    $number = strrev($number);
    for($i = 0; $i < strlen($number); $i++) {
        $decval = bcadd(bcmul(bcpow('16', $i, 0), $decvalues[$number[$i]]), $decval);
    }
    return $decval;
}

/**
 * 如果有0x去除它
 * @param $value
 * @return false|string
 */
function remove0x($value)
{
    if (strtolower(substr($value, 0, 2)) == '0x') {
        return substr($value, 2);
    }
    return $value;
}