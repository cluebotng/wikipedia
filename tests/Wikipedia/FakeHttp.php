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

/**
 * A test double for Http which replays a fixed queue of raw JSON responses,
 * so Api's retry-on-lost-session behaviour can be exercised deterministically
 * without hitting the live API.
 **/
class FakeHttp extends Http
{
    private $responses;
    public $calls = [];

    public function __construct(array $responses)
    {
        parent::__construct();
        $this->responses = $responses;
    }

    public function get($url)
    {
        $this->calls[] = ['GET', $url];
        return array_shift($this->responses);
    }

    public function post($url, $data)
    {
        $this->calls[] = ['POST', $url, $data];
        return array_shift($this->responses);
    }
}
