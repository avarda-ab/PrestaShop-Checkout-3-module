<?php
/**
 * Copyright (C) 2019 Petr Hucik <petr@getdatakick.com>
 *
 * NOTICE OF LICENSE
 *
 * This source file is subject to the Academic Free License (AFL 3.0)
 * that is bundled with this package in the file LICENSE.txt.
 * It is also available through the world-wide-web at this URL:
 * http://opensource.org/licenses/afl-3.0.php
 * If you did not receive a copy of the license and are unable to
 * obtain it through the world-wide-web, please send an email
 * to license@getdatakick.com so we can send you a copy immediately.
 *
 * @author    Petr Hucik <petr@getdatakick.com>
 * @copyright 2017-2019 Petr Hucik
 * @license   http://opensource.org/licenses/afl-3.0.php  Academic Free License (AFL 3.0)
 */

namespace AvardaPayments;

class Fetch
{
    private static $mode = null;
    private $url;
    private $method;
    private $body;
    private $headers = array();

    public static function detectMode()
    {
        if (is_null(self::$mode)) {
            if (function_exists('curl_init')) {
                self::$mode = 'curl';
            } else if (in_array(@ini_get('allow_url_fopen'), array('On', 'on', '1'))) {
                self::$mode = 'fopen';
            } else {
                self::$mode = 'none';
            }
        }
        return self::$mode;
    }

    /**
     * Fetch constructor.
     * @param $url
     * @param string $method
     * @param null $body
     */
    public function __construct($url, $method = 'GET', $body = null)
    {
        $this->url = $url;
        $this->setMethod($method);
        $this->setBody($body);
    }

    /**
     * @param $method
     * @return $this
     */
    public function setMethod($method)
    {
        $this->method = strtoupper($method);
        return $this;
    }

    /**
     * @param $body
     * @return $this
     */
    public function setBody($body)
    {
        if (!is_null($body)) {
            if (is_string($body)) {
                $this->body = $body;
            } else if (is_array($body)) {
                $this->setHeader('Content-Type', 'application/json');
                $this->body = json_encode($body);
            }
        } else {
            $this->body = '';
        }
        return $this;
    }

    /**
     * @param $header
     * @param $value
     * @return $this
     */
    public function setHeader($header, $value)
    {
        $this->headers[$header] = "$header: $value";
        return $this;
    }

    /**
     * @return string
     * @throws AvardaException
     */
    public function execute()
    {
        self::detectMode();
        
        if (self::$mode === 'curl') {
            return $this->curl();
        }
        if (self::$mode === 'fopen') {
            return $this->fopen();
        }
        return false;
    }

    /**
     * @return resource
     */
    private function getStreamContext()
    {
        $header = implode($this->headers, "\r\n");
        $data = array(
            'http' => array(
                'header' => $header,
                'method' => strtoupper($this->method),
                'content' => $this->body
            )
        );
        return @stream_context_create($data);
    }

    /**
     * @return string
     * @throws AvardaException
     */
    public function fopen()
    {
        $responseArray = [];
        
        $streamContext = $this->getStreamContext();
        $ret = @file_get_contents($this->url, false, $streamContext);
        // if API returns error, we can't get it with file_get_contents
        if ($ret === false) {
            $error = error_get_last();
            throw new AvardaException($error['message']);
        }
        
        if (is_array($http_response_header)) {
            //strip HTTP from the start
            $httpHeader = substr($http_response_header[0], 9);
            //get only the status digit for our error comparison
            preg_match("/\d+/", $httpHeader, $matches);
            $httpStatus = $matches[0];
        } else {
            $httpStatus = 0;
        }

        $responseArray = [
            'httpStatus' => $httpStatus,
            'response' => (string)$ret, 
        ];

        return $responseArray;
    }

    /**
     * @return string
     * @throws AvardaException
     */
    public function curl()
    {
        $responseArray = [];
        $curl = @curl_init();
        try {
            @curl_setopt($curl, CURLOPT_CUSTOMREQUEST, $this->method);
            @curl_setopt($curl, CURLOPT_URL, $this->url);
            @curl_setopt($curl, CURLOPT_POSTFIELDS, $this->body);
            @curl_setopt($curl, CURLOPT_CONNECTTIMEOUT, 20);
            @curl_setopt($curl, CURLOPT_SSL_VERIFYPEER, 0);
            @curl_setopt($curl, CURLOPT_FOLLOWLOCATION, true);
            @curl_setopt($curl, CURLOPT_HTTPHEADER, array_values($this->headers));
            @curl_setopt($curl, CURLOPT_RETURNTRANSFER, 1);
            $res = @curl_exec($curl);
            $httpResponse = curl_getinfo($curl, CURLINFO_HTTP_CODE);
            if ($res === false) {
                throw new AvardaException(@curl_error($curl));
            }

            $responseArray = [
                'httpStatus' => $httpResponse,
                'response' => (string)$res, 
            ];
            return $responseArray;
        } finally {
            @curl_close($curl);
        }
    }
}
