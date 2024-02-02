<?php


namespace App\Library\HttpRequest;


/**
 * Requests
 *
 * @author      he xiang <ihexiang@163.com>
 * @version     1.0.0
 */
class Client
{


    /**
     * GET method
     *
     * @var string
     * */
    const GET = 'GET';


    /**
     * POST method
     *
     * @var string
     * */
    const POST = 'POST';


    private function __construct()
    {

    }



    /**
     * get 请求方法
     *
     * @param string $url
     * @param array $headers
     * @param array $options
     * @return Response
     * */
    public static function get(string $url, array $headers = [], array $options = [])
    {
        return self::request($url,self::GET,$headers,[],$options);
    }



    /**
     * post 请求方法
     *
     * @param string $url
     * @param array $headers
     * @param array $data
     * @param array $options
     * @return Response
     * */
    public static function post(string $url, array $headers = [], array $data = [], array $options = [])
    {
        return self::request($url,self::POST,$headers,$data,$options);
    }



    /**
     * 请求方法
     *
     * @param string $url
     * @param string $method
     * @param array $headers
     * @param array $data
     * @param array $options
     * @return Response
     * */
    private static function request(string $url, string $method, array $headers = [], array $data = [], array $options = [])
    {

        $url_info = parse_url($url);
        $domain = $url_info['host'];
        $is_https = strtolower($url_info['scheme']) == 'https';
        $port = $is_https ? 443 : 80;
        $client = new \Swoole\Coroutine\Http\Client($url_info['host'], $port, $is_https);

        $sets = [];
        $sets['timeout'] = isset($options['timeout']) ? intval($options['timeout']) : -1;

        //HTTPS
        if($is_https){
            $sets['ssl_host_name'] = $domain;
        }

        $content_type = '';
        if($headers){
            foreach ($headers as $key=>$val){
                $tempKey = strtolower($key);
                if($tempKey == 'content-type'){
                    $content_type = $val;
                }
            }
        }

        $client->set($sets);
        $client->setHeaders($headers);

        //设置cookies
        if(isset($options['cookies'])){
            $client->setCookies($options['cookies']);
        }

        //拼装path
        $path = isset($url_info['path']) ? strval($url_info['path']) : '';
        if(isset($url_info['query'])){
            $path .= '?'.$url_info['query'];
        }
        if(isset($url_info['fragment'])){
            $path .= '#'.$url_info['fragment'];
        }

        if($method == self::GET){
            $client->get($path);
        }elseif ($method == self::POST){

            $postContent = $data;
            //json
            if(stripos($content_type,'json') !== false){
                $postContent = json_encode($data);
            }

            $client->post($path,$postContent);
        }

        $response = new Response([
            'errCode'=>$client->errCode,
            'errMsg'=>$client->errMsg,
            'body'=>strval($client->getBody()),
            'statusCode'=>intval($client->getStatusCode()),
            'cookies'=>$client->getCookies(),
            'headers'=>$client->getHeaders(),
        ]);

        // $response = [
        //     'errCode'=>$client->errCode,
        //     'errMsg'=>$client->errMsg,
        //     'body'=>strval($client->getBody()),
        //     'statusCode'=>intval($client->getStatusCode()),
        //     'cookies'=>$client->getCookies(),
        //     'headers'=>$client->getHeaders(),
        // ];

        $client->close();
        return $response;

    }



}