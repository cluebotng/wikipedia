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

class ApiTest extends \PHPUnit\Framework\TestCase
{
    public function testUserContributions()
    {
        $api = new Api();
        $ret = $api->usercontribs('ClueBot NG');
        $this->assertEquals(50, count($api->usercontribs('ClueBot NG', 50)));
    }

    public function testHttpInstancel()
    {
        $http = new Http();

        $api = new Api($http);
        $ret = $api->usercontribs('ClueBot NG');
        $this->assertEquals(50, count($api->usercontribs('ClueBot NG', 50)));
    }

    public function testLoggerInstancel()
    {
        $logger = new \Monolog\Logger('wikipedia');

        $api = new Api(null, $logger);
        $ret = $api->usercontribs('ClueBot NG');
        $this->assertEquals(50, count($api->usercontribs('ClueBot NG', 50)));
    }

    public function testAllowedToRun()
    {
        $api = new Api();

        // We are not logged in, so this is always false
        $this->assertFalse($api->allowedToRun());
    }

    public function testUserLoggedOut()
    {
        $api = new Api();

        // We are not logged in, so this is always false
        $this->assertFalse($api->loggedin());
    }
}
