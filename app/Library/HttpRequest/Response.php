<?php


namespace App\Library\HttpRequest;

/**
 * Requests
 *
 * @author      he xiang <ihexiang@163.com>
 * @version     1.0.0
 */
class Response
{



    private $errCode = 0;
    private $errMsg = '';
    private $statusCode = 0;
    private $body = '';
    private $cookies = [];
    private $headers = [];


    /**
     * 响应类构造方法
     *
     * @param  array $datas 参数为[该类的成员属性名=>值[,..]]
     * */
    public function __construct(array $datas = [])
    {
        if ($datas){
            foreach ($datas as $key=>$val){
                if(isset($this->$key)){
                    $this->$key = $val;
                }
            }
        }
    }


    /**
     * 获取 err code
     *
     * @return int
     * */
    public function getErrCode(){
        return intval($this->errCode);
    }



    /**
     * 获取 err msg
     *
     * @return string
     * */
    public function getErrMsg(){
        return strval($this->errMsg);
    }



    /**
     * 获取 status code
     *
     * @return int
     * */
    public function getStatusCode(){
        return intval($this->statusCode);
    }



    /**
     * 获取 body
     *
     * @return string
     * */
    public function getBody(){
        return $this->body;
    }



    /**
     * 获取 cookies
     *
     * @return array
     * */
    public function getCookies(){
        return $this->cookies;
    }



    /**
     * 获取 headers
     *
     * @return array
     * */
    public function getHeaders(){
        return $this->headers;
    }







}