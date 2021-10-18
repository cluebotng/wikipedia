<?php

namespace Wikipedia;

/*
 * Copyright (C) 2015 Jacobi Carter and Chris Breneman
 *
 * This file is part of ClueBot's Wikipedia API.
 *
 * ClueBot's Wikipedia API is free software: you can redistribute it and/or modify
 * it under the terms of the GNU General Public License as published by
 * the Free Software Foundation, either version 2 of the License, or
 * (at your option) any later version.
 *
 * ClueBot's Wikipedia API is distributed in the hope that it will be useful,
 * but WITHOUT ANY WARRANTY; without even the implied warranty of
 * MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
 * GNU General Public License for more details.
 *
 * You should have received a copy of the GNU General Public License
 * along with ClueBot's Wikipedia API.  If not, see <http://www.gnu.org/licenses/>.
 */

class Http
{
    public $postfollowredirs;
    public $getfollowredirs;
    private $ch;
    private $uid;
    private $logger;

    /**
     * Our constructor function.  This just does basic cURL initialization.
     *
     * @param $logger Instance of the Monolog interface.
     * @param $proxyhost Hostname of HTTP proxy to use.
     * @param $proxyport Port number of HTTP proxy to use.
     * @param $proxytype Implmentation of proxy to use.
     **/
    public function __construct(
        $logger = null,
        $proxyhost = null,
        $proxyport = null,
        $proxytype = null
    ) {
        $this->ch = curl_init();
        $this->uid = dechex(rand(0, 99999999));
        curl_setopt($this->ch, CURLOPT_COOKIEJAR, '/tmp/cluebot.wikipedia.http.cookies.' . $this->uid . '.dat');
        curl_setopt($this->ch, CURLOPT_COOKIEFILE, '/tmp/cluebot.wikipedia.http.cookies.' . $this->uid . '.dat');
        curl_setopt($this->ch, CURLOPT_MAXCONNECTS, 100);
        curl_setopt($this->ch, CURLOPT_USERAGENT, 'ClueBot/2.0');
        curl_setopt($this->ch, CURLOPT_ENCODING, '');
        curl_setopt($this->ch, CURLOPT_FORBID_REUSE, 1);
        curl_setopt($this->ch, CURLOPT_FRESH_CONNECT, 1);

        if (isset($proxyhost) and isset($proxyport) and ($proxyport != null) and ($proxyhost != null)) {
            curl_setopt($this->ch, CURLOPT_PROXYTYPE, isset($proxytype) ? $proxytype : CURLPROXY_HTTP);
            curl_setopt($this->ch, CURLOPT_PROXY, $proxyhost);
            curl_setopt($this->ch, CURLOPT_PROXYPORT, $proxyport);
        }

        $this->postfollowredirs = 0;
        $this->getfollowredirs = 1;
        $this->logger = $logger;
    }

    /**
     * Post to a URL.
     *
     * @param $url The URL to post to.
     * @param $data The post-data to post, should be an array of key => value pairs.
     *
     * @return Data retrieved from the POST request.
     **/
    public function post($url, $data)
    {
        $time = microtime(true);
        curl_setopt($this->ch, CURLOPT_URL, $url);
        curl_setopt($this->ch, CURLOPT_FOLLOWLOCATION, $this->postfollowredirs);
        curl_setopt($this->ch, CURLOPT_MAXREDIRS, 10);
        curl_setopt($this->ch, CURLOPT_HEADER, 0);
        curl_setopt($this->ch, CURLOPT_RETURNTRANSFER, 1);
        curl_setopt($this->ch, CURLOPT_TIMEOUT, 30);
        curl_setopt($this->ch, CURLOPT_CONNECTTIMEOUT, 10);
        curl_setopt($this->ch, CURLOPT_FRESH_CONNECT, 1);
        curl_setopt($this->ch, CURLOPT_FORBID_REUSE, 1);
        curl_setopt($this->ch, CURLOPT_POST, 1);
        curl_setopt($this->ch, CURLOPT_POSTFIELDS, $data);
        curl_setopt($this->ch, CURLOPT_HTTPHEADER, array('Expect:'));
        curl_setopt($this->ch, CURLOPT_IPRESOLVE, CURL_IPRESOLVE_V4); // Damian added this
        $data = curl_exec($this->ch);

        if ($this->logger !== null) {
            $this->logger->addDebug('POST: ' . $url .
                                    ' (' . (microtime(true) - $time) . ' s)' .
                                    ' (' . strlen($data) . " b)");
        }
        return $data;
    }

    /**
     * Get a URL.
     *
     * @param $url The URL to get.
     *
     * @return Data retrieved from the GET request.
     **/
    public function get($url)
    {
        $time = microtime(true);
        curl_setopt($this->ch, CURLOPT_URL, $url);
        curl_setopt($this->ch, CURLOPT_FOLLOWLOCATION, $this->getfollowredirs);
        curl_setopt($this->ch, CURLOPT_MAXREDIRS, 10);
        curl_setopt($this->ch, CURLOPT_HEADER, 0);
        curl_setopt($this->ch, CURLOPT_RETURNTRANSFER, 1);
        curl_setopt($this->ch, CURLOPT_TIMEOUT, 30);
        curl_setopt($this->ch, CURLOPT_CONNECTTIMEOUT, 10);
        curl_setopt($this->ch, CURLOPT_HTTPGET, 1);
        $data = curl_exec($this->ch);

        if ($this->logger !== null) {
            $this->logger->addDebug('GET: ' . $url .
                                    ' (' . (microtime(true) - $time) . ' s)' .
                                    '(' . strlen($data) . ' b) (' .
            curl_getinfo($this->ch, CURLINFO_HTTP_CODE) . " code)");
        }
        return $data;
    }

    /**
     * Unserialize an object & emit any warnings to logging.
     *
     * @param $response Raw response from `get` or `post`.
     *
     * @return Data decoded.
     **/
    public function unserialize($response)
    {
        if ($this->logger !== null) {
            $this->logger->addDebug('Decoding response: ' . $response);
        }

        $response = unserialize($response);

        if ($this->logger !== null) {
            // Handle errors
            if (array_key_exists('error', $response)) {
                $caller = (new \Exception())->getTrace()[1]['function'];
                $this->logger->addError($caller . ' API Error: ' .
                                        var_dump($response['error']));
            }

            // Handle warnings
            if (array_key_exists('warnings', $response)) {
                $caller = (new \Exception())->getTrace()[1]['function'];
                $this->logger->addWarning($caller . ' API Warnings: ' .
                                          var_dump($response['warnings']));
            }
        }

        return $response;
    }

    /**
     * Our destructor.  Cleans up cURL and unlinks temporary files.
     **/
    public function __destruct()
    {
        curl_close($this->ch);
        @unlink('/tmp/cluebot.wikipedia.http.cookies.' . $this->uid . '.dat');
    }
}
