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

class HttpTest extends \PHPUnit\Framework\TestCase
{
    public function testGet()
    {
        $http = new Http();
        $ret = $http->get('https://en.wikipedia.org/w/api.php?action=query&meta=tokens&format=json');
        $data = json_decode($ret);
        $this->assertEquals('+\\', $data->{'query'}->{'tokens'}->{'csrftoken'});
    }

    public function testPost()
    {
        $http = new Http();
        $ret = $http->post('https://en.wikipedia.org/w/api.php?action=query&meta=tokens&format=json', null);
        $data = json_decode($ret);
        $this->assertEquals('+\\', $data->{'query'}->{'tokens'}->{'csrftoken'});
    }

    public function testLogger()
    {
        $logger = new \Monolog\Logger('wikipedia');

        $http = new Http($logger);
        $ret = $http->post('https://en.wikipedia.org/w/api.php?action=query&meta=tokens&format=json', null);
        $data = json_decode($ret);
        $this->assertEquals('+\\', $data->{'query'}->{'tokens'}->{'csrftoken'});
    }
}
