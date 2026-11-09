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

class Api
{
    public $apiurl = 'https://en.wikipedia.org/w/api.php';
    private $http;
    private $user;
    private $pass;
    private $assert_auth;
    private $logger;
    private const BAD_AUTH_CODES = ['badtoken', 'assertuserfailed', 'notloggedin'];

    /**
     * This is our constructor.
     *
     * @param $http Instance of the HTTP interface.
     * @param $logger Instance of the Monolog interface.
     **/
    public function __construct($http = null, $logger = null)
    {
        if ($http !== null) {
            $this->http = $http;
        } else {
            // This may overwrite existing cookies with paralell usage
            if ($logger !== null) {
                $logger->warning('No HTTP instance passed, creating a new one');
            }
            $this->http = new \Wikipedia\Http($logger);
        }
        $this->logger = $logger;
    }

    /**
     * Check whether a decoded API response indicates we are no longer authenticated.
     *
     * @param $response Decoded API response.
     *
     * @return bool True if the response indicates a lost session.
     **/
    private function isAuthError($response)
    {
        return isset($response['error']['code']) && in_array($response['error']['code'], self::BAD_AUTH_CODES, true);
    }

    /**
     * Attempt to re-establish a lost session using the credentials from the last login() call.
     *
     * @return bool True on success, false on failure (or if we were never logged in).
     **/
    private function reauthenticate()
    {
        if (!$this->user) {
            return false;
        }

        if ($this->logger !== null) {
            $this->logger->debug('Lost authentication as ' . $this->user . ', re-authenticating');
        }

        return $this->login($this->user, $this->pass, $this->assert_auth);
    }

    /**
     * Perform a GET request, decode it, and transparently re-authenticate & retry once
     * if the response indicates our session was lost.
     *
     * @param $url The URL to get.
     * @param $retry Should requests be re-tried if an auth error occurs.
     *
     * @return Decoded API response.
     **/
    private function requestGet($url, $retry = true)
    {
        $requestUrl = $url;
        if ($this->assert_auth) {
            $requestUrl .= '&assert=user';
        }

        $x = $this->http->get($requestUrl);
        $x = $this->http->unserialize($x);

        if ($retry && $this->isAuthError($x) && $this->reauthenticate()) {
            return $this->requestGet($url, false);
        }

        return $x;
    }

    /**
     * Builds the query string to continue a previous request.
     *
     * @param $continue The `continue` structure from a previous response.  Null means from the beginning.
     *
     * @return Query string to append to the request.
     **/
    private function continueParams($continue)
    {
        $append = '';
        if ($continue != null) {
            foreach ($continue as $key => $value) {
                $append .= '&' . urlencode($key) . '=' . urlencode($value);
            }
        }
        return $append;
    }

    /**
     * Check the state of the user's run page
     *
     * @return bool True on success, false on failure.
     **/
    private function allowedToRun()
    {
        if (!$this->user) {
            return false;
        }

        $ret = $this->revisions(
            'User:' . $this->user . '/Run',
            1,
            'older',
            true,
            null,
            true,
            false,
            false
        );

        if (
            is_array($ret) && count($ret) > 0 && array_key_exists(0, $ret)
            && array_key_exists('slots', $ret[0])
            && array_key_exists('main', $ret[0]['slots'])
            && array_key_exists('*', $ret[0]['slots']['main'])
        ) {
            return preg_match(
                '/(yes|enable|true)/iS',
                $ret[0]['slots']['main']['*']
            );
        }

        return false;
    }

    /**
     * Retrieves metadata about a user's contributions.
     *
     * @param $user Username whose contributions we want to retrieve.
     * @param $count Number of entries to return.  (Default 50)
     * @param[in,out] $continue Where to continue enumerating if part of a larger, split request.
     *
     * @param $dir Which direction to enumerate from, "older" or "newer".  (Default 'older')
     *
     * @return Associative array of contributions metadata.
     **/
    public function usercontribs($user, $count = 50, &$continue = null, $dir = 'older')
    {
        $append = $this->continueParams($continue);
        $x = $this->requestGet(
            $this->apiurl . '?action=query&format=json&list=usercontribs&ucuser=' .
            urlencode($user) . '&uclimit=' . urlencode($count) . '&ucdir=' . urlencode($dir) . $append
        );

        $continue = $x['continue'] ?? null;

        return $x['query']['usercontribs'] ?? [];
    }

    /**
     * Enumerate all backlinks to a page.
     *
     * @param $page Page to search for backlinks to.
     * @param $count Number of backlinks to list.  (Default 500)
     * @param[in,out] $continue Where to start enumerating from.  This is automatically filled in.  (Default null)
     *
     * @param $filter Whether or not to include redirects.  Acceptible values are 'all', 'redirects', and 'nonredirects'
     *
     * @return Associative array of backlink metadata.
     **/
    public function backlinks($page, $count = 500, &$continue = null, $filter = null)
    {
        $append = $this->continueParams($continue);
        if ($filter != null) {
            $append .= '&blfilterredir=' . urlencode($filter);
        }

        $x = $this->requestGet(
            $this->apiurl . '?action=query&list=backlinks&bltitle=' .
            urlencode($page) . '&format=json&bllimit=' . $count . $append
        );

        $continue = $x['continue'] ?? null;

        return $x['query']['backlinks'] ?? [];
    }

    /**
     * Gets a list of transcludes embedded in a page.
     *
     * @param $page Page to look for transcludes in.
     * @param $count Number of transcludes to list.  (Default 500)
     * @param[in,out] $continue Where to start enumerating from.  This is automatically filled in.  (Default null)
     *
     * @return Associative array of transclude metadata.
     **/
    public function embeddedin($page, $count = 500, &$continue = null)
    {
        $append = $this->continueParams($continue);

        $x = $this->requestGet(
            $this->apiurl . '?action=query&list=embeddedin&eititle=' .
            urlencode($page) . '&format=json&eilimit=' . $count . $append
        );

        $continue = $x['continue'] ?? null;

        return $x['query']['embeddedin'] ?? [];
    }

    /**
     * Gets a list of pages with a common prefix.
     *
     * @param $prefix Common prefix to search for.
     * @param $namespace Numeric namespace to filter on.  (Default 0)
     * @param $count Number of pages to list.  (Default 500)
     * @param[in,out] $continue Where to start enumerating from.  This is automatically filled in.  (Default null)
     *
     * @return Associative array of page metadata.
     **/
    public function listprefix($prefix, $namespace = 0, $count = 500, &$continue = null)
    {
        $append = $this->continueParams($continue);
        $append .= '&apnamespace=' . urlencode($namespace);

        $x = $this->requestGet(
            $this->apiurl . '?action=query&list=allpages&apprefix=' .
            urlencode($prefix) . '&format=json&aplimit=' . $count . $append
        );

        $continue = $x['continue'] ?? null;

        return $x['query']['allpages'] ?? [];
    }

    /**
     * Edits a page.
     *
     * @param $page Page name to edit.
     * @param $data Data to post to page.
     * @param $summary Edit summary to use.
     * @param $minor Whether or not to mark edit as minor.  (Default false)
     * @param $bot Whether or not to mark edit as a bot edit.  (Default true)
     * @param $wpStarttime Time in MW TS format of beginning of edit.  (Default now)
     * @param $wpEdittime Time in MW TS format of last edit to that page.  (Default correct)
     * @param $checkrun Verify the user's /Run page state (Default true)
     * @param $retry Retry once if we've lost our session.  (Default true, internal use only)
     *
     * @return bool True on success, false on failure.
     **/
    public function edit(
        $page,
        $data,
        $summary = '',
        $minor = false,
        $bot = true,
        $wpStarttime = null,
        $wpEdittime = null,
        $checkrun = true,
        $retry = true
    ) {
        $wpq = new \Wikipedia\Query($this->http, $this->logger);
        $wpq->queryurl = str_replace('api.php', 'query.php', $this->apiurl);

        if ($checkrun === true && !$this->allowedToRun()) {
            if ($this->logger !== null) {
                $this->logger->warning('Run page prevented edit (' . $page . ')');
            }
            return false;
        }

        $params = array(
            'action' => 'edit',
            'format' => 'json',
            'title' => $page,
            'text' => $data,
            'token' => $this->gettoken($page),
            'summary' => $summary,
            ($minor ? 'minor' : 'notminor') => '1',
        );

        if ($bot) {
            $params['bot'] = '1';
        }

        if ($wpStarttime !== null) {
            $params['starttimestamp'] = $wpStarttime;
        }
        if ($wpEdittime !== null) {
            $params['basetimestamp'] = $wpEdittime;
        }
        if ($this->assert_auth) {
            $params['assert'] = 'user';
        }

        $x = $this->http->post($this->apiurl, $params);
        $x = $this->http->unserialize($x);

        if (isset($x['edit']['result']) && $x['edit']['result'] == 'Success') {
            return true;
        }

        if ($this->isAuthError($x)) {
            if ($retry && $this->reauthenticate()) {
                return $this->edit($page, $data, $summary, $minor, $bot, $wpStarttime, $wpEdittime, $checkrun, false);
            }
            return false;
        }

        return false;
    }

    /**
     * This function returns the CSRF token for a certain page.
     *
     * @param $title Page to get the tokens for.
     *
     * @return A CSRF token for the page
     **/
    private function gettoken($title)
    {
        $x = $this->requestGet(
            $this->apiurl . '?format=json' .
            '&action=query&meta=tokens&type=csrf&titles=' .
            urlencode($title)
        );

        return $x['query']['tokens']['csrftoken'] ?? null;
    }

    /**
     * This function returns a login token.
     *
     * @return A login token
     **/
    private function getLoginToken()
    {
        $x = $this->http->get($this->apiurl . '?format=json' .
                              '&action=query&meta=tokens&type=login');
        $x = $this->http->unserialize($x);

        return $x['query']['tokens']['logintoken'] ?? null;
    }


    /**
     * This function takes a username and password and logs you into wikipedia.
     *
     * @param $user Username to login as.
     * @param $pass Password that corrisponds to the username.
     * @param $assert_auth Assert that we are logged in for requests.
     **/
    public function login($user, $pass, $assert_auth = true)
    {
        $this->user = $user;
        $this->pass = $pass;
        $this->assert_auth = $assert_auth;
        $x = $this->http->post(
            $this->apiurl . '?action=login&format=json',
            array('lgname' => $user,
                  'lgpassword' => $pass,
                  'lgtoken' => $this->getLoginToken())
        );

        $x = $this->http->unserialize($x);

        return isset($x['login']['result']) && $x['login']['result'] == 'Success';
    }

    /**
     * Check if we are currently authenticated.
     *
     * This function checks for either anonymous,
     * where $this->login has never been called,
     * or authenticated, where $this->login set
     * the username to check for.
     **/
    public function loggedin()
    {
        $x = $this->http->get($this->apiurl .
                              '?action=query&meta=userinfo&format=json');
        $x = $this->http->unserialize($x);

        if (!isset($x['query']['userinfo'])) {
            return false;
        }

        if (!$this->user) {
            return !array_key_exists('anon', $x['query']['userinfo']);
        }

        return ($x['query']['userinfo']['name'] ?? null) === $this->user;
    }

    /**
     * Rollback an edit.
     *
     * @param $title Title of page to rollback.
     * @param $user Username of last edit to the page to rollback.
     * @param $reason Edit summary to use for rollback.
     * @param $token Rollback token.  If not given, it will be fetched.  (Default null)
     * @param $checkrun Verify the user's /Run page state (Default true)
     * @param $retry Retry once if we've lost our session.  (Default true, internal use only)
     **/
    public function rollback($title, $user, $reason, $token = null, $checkrun = true, $retry = true)
    {
        if ($checkrun === true && !$this->allowedToRun()) {
            if ($this->logger !== null) {
                $this->logger->warning('Run page prevented rollback of ' .
                                          $title . ' (' . $user . ')');
            }
            return false;
        }

        $x = $this->requestGet($this->apiurl . '?action=query&meta=tokens&type=rollback&format=json');

        $token = $x['query']['tokens']['rollbacktoken'] ?? null;

        $params = array(
            'action' => 'rollback',
            'format' => 'json',
            'title' => $title,
            'user' => $user,
            'summary' => $reason,
            'token' => $token,
        );
        if ($this->assert_auth) {
            $params['assert'] = 'user';
        }

        if ($this->logger !== null) {
            $this->logger->debug('Posting to API: ' . var_export($params, true));
        }

        $x = $this->http->post($this->apiurl, $params);
        $x = $this->http->unserialize($x);

        if ($this->isAuthError($x)) {
            if ($retry && $this->reauthenticate()) {
                return $this->rollback($title, $user, $reason, null, $checkrun, false);
            }
            return false;
        }

        return (isset($x['rollback']['summary']) and isset($x['rollback']['revid']) and $x['rollback']['revid'])
            ? true
            : false;
    }

    /**
     * Returns revision data (meta and/or actual).
     *
     * @param $page Page for which to return revision data for.
     * @param $count Number of revisions to return. (Default 1)
     * @param $dir Direction to start enumerating multiple revisions from, "older" or "newer". (Default 'older')
     * @param $content Whether to return actual revision content, true or false.  (Default false)
     * @param $revid Revision ID to start at.  (Default null)
     * @param $wait Whether or not to wait a few seconds for the specific revision to become available.  (Default true)
     * @param $dieonerror Whether or not to kill the process with an error if an error occurs.  (Default false)
     * @param $redirects Whether or not to follow redirects.  (Default false)
     *
     * @return Associative array of revision data.
     **/
    public function revisions(
        $page,
        $count = 1,
        $dir = 'older',
        $content = false,
        $revid = null,
        $wait = true,
        $dieonerror = true,
        $redirects = false
    ) {
        $x = $this->requestGet(
            $this->apiurl . '?action=query&prop=revisions&rvslots=main&titles=' .
            urlencode($page) . '&rvlimit=' . urlencode($count) . '&rvprop=timestamp|ids|user|comment' .
            (($content) ? '|content' : '') . '&format=json&meta=userinfo&rvdir=' . urlencode($dir) .
            (($revid !== null) ? '&rvstartid=' . urlencode($revid) : '') .
            (($redirects == true) ? '&redirects' : '')
        );

        if ($revid !== null) {
            $found = false;
            if (!isset($x['query']['pages']) or !is_array($x['query']['pages'])) {
                if ($dieonerror == true) {
                    die('No such page.' . "\n");
                } else {
                    return false;
                }
            }
            foreach ($x['query']['pages'] as $data) {
                if (!isset($data['revisions']) or !is_array($data['revisions'])) {
                    if ($dieonerror == true) {
                        die('No such page.' . "\n");
                    } else {
                        return false;
                    }
                }
                foreach ($data['revisions'] as $data2) {
                    if ($data2['revid'] == $revid) {
                        $found = true;
                    }
                }
                unset($data, $data2);
                break;
            }

            if ($found == false) {
                if ($wait == true) {
                    sleep(1);

                    return $this->revisions($page, $count, $dir, $content, $revid, false, $dieonerror);
                } else {
                    if ($dieonerror == true) {
                        die('Revision error.' . "\n");
                    }
                }
            }
        }

        if (!isset($x['query']['pages']) || !is_array($x['query']['pages'])) {
            return [];
        }
        foreach (array_values($x['query']['pages']) as $data) {
            return $data['revisions'] ?? [];
        }
        return [];
    }
}
