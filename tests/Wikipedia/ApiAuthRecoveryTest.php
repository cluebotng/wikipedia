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

class ApiAuthRecoveryTest extends \PHPUnit\Framework\TestCase
{
    private const LOGIN_TOKEN = '{"query":{"tokens":{"logintoken":"logintoken+\\\\"}}}';
    private const LOGIN_SUCCESS = '{"login":{"result":"Success"}}';
    private const LOGIN_FAILED = '{"login":{"result":"Failed"}}';
    private const ASSERT_USER_FAILED =
        '{"error":{"code":"assertuserfailed","info":"You are no longer logged in, ' .
        'so the action could not be completed. Please log in again."}}';

    public function testReadCallRecoversFromLostSession()
    {
        $http = new FakeHttp([
            self::LOGIN_TOKEN,
            self::LOGIN_SUCCESS,
            self::ASSERT_USER_FAILED,
            self::LOGIN_TOKEN,
            self::LOGIN_SUCCESS,
            '{"query":{"usercontribs":[{"title":"Foo"}]}}',
        ]);
        $api = new Api($http);

        $this->assertTrue($api->login('ClueBot III', 'pass'));
        $continue = null;
        $this->assertEquals([['title' => 'Foo']], $api->usercontribs('ClueBot III', 50, $continue));
        $this->assertEquals(6, count($http->calls));
    }

    public function testEditRecoversFromLostSessionAndRefetchesToken()
    {
        $http = new FakeHttp([
            self::LOGIN_TOKEN,
            self::LOGIN_SUCCESS,
            '{"query":{"tokens":{"csrftoken":"csrf1+\\\\"}}}',
            self::ASSERT_USER_FAILED,
            self::LOGIN_TOKEN,
            self::LOGIN_SUCCESS,
            '{"query":{"tokens":{"csrftoken":"csrf2+\\\\"}}}',
            '{"edit":{"result":"Success"}}',
        ]);
        $api = new Api($http);

        $this->assertTrue($api->login('ClueBot III', 'pass'));
        $this->assertTrue($api->edit('Some Page', 'new text', 'summary', false, true, null, null, false));

        // Both attempts must fetch a fresh token rather than reuse the one from the lost session.
        $this->assertEquals('GET', $http->calls[2][0]);
        $this->assertStringContainsString('meta=tokens&type=csrf', $http->calls[2][1]);
        $this->assertEquals('GET', $http->calls[6][0]);
        $this->assertStringContainsString('meta=tokens&type=csrf', $http->calls[6][1]);
        $this->assertEquals(8, count($http->calls));
    }

    public function testEditGivesUpIfReauthenticationFails()
    {
        $http = new FakeHttp([
            self::LOGIN_TOKEN,
            self::LOGIN_SUCCESS,
            '{"query":{"tokens":{"csrftoken":"csrf1+\\\\"}}}',
            self::ASSERT_USER_FAILED,
            self::LOGIN_TOKEN,
            self::LOGIN_FAILED,
        ]);
        $api = new Api($http);

        $this->assertTrue($api->login('ClueBot III', 'pass'));
        $this->assertFalse($api->edit('Some Page', 'new text', 'summary', false, true, null, null, false));

        // No further calls once re-authentication itself fails.
        $this->assertEquals(6, count($http->calls));
    }

    public function testEditDoesNotRetryMoreThanOnce()
    {
        $http = new FakeHttp([
            self::LOGIN_TOKEN,
            self::LOGIN_SUCCESS,
            '{"query":{"tokens":{"csrftoken":"csrf1+\\\\"}}}',
            self::ASSERT_USER_FAILED,
            self::LOGIN_TOKEN,
            self::LOGIN_SUCCESS,
            '{"query":{"tokens":{"csrftoken":"csrf2+\\\\"}}}',
            self::ASSERT_USER_FAILED,
        ]);
        $api = new Api($http);

        $this->assertTrue($api->login('ClueBot III', 'pass'));
        $this->assertFalse($api->edit('Some Page', 'new text', 'summary', false, true, null, null, false));

        // Re-authentication succeeded both times, but the write kept failing - we must give up
        // after one retry rather than recurse forever.
        $this->assertEquals(8, count($http->calls));
    }

    public function testUnrelatedErrorsAreNotTreatedAsAuthLoss()
    {
        $http = new FakeHttp([
            self::LOGIN_TOKEN,
            self::LOGIN_SUCCESS,
            '{"error":{"code":"protectedpage","info":"This page has been protected."}}',
        ]);
        $api = new Api($http);

        $this->assertTrue($api->login('ClueBot III', 'pass'));
        $continue = null;
        $this->assertEquals([], $api->usercontribs('ClueBot III', 50, $continue));

        // No re-login attempt for a non-auth error.
        $this->assertEquals(3, count($http->calls));
    }
}
