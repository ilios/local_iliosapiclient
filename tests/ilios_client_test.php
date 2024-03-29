<?php
// This file is part of Moodle - http://moodle.org/
//
// Moodle is free software: you can redistribute it and/or modify
// it under the terms of the GNU General Public License as published by
// the Free Software Foundation, either version 3 of the License, or
// (at your option) any later version.
//
// Moodle is distributed in the hope that it will be useful,
// but WITHOUT ANY WARRANTY; without even the implied warranty of
// MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
// GNU General Public License for more details.
//
// You should have received a copy of the GNU General Public License
// along with Moodle.  If not, see <http://www.gnu.org/licenses/>.

namespace local_iliosapiclient;

use basic_testcase;
use curl;
use DateTime;
use Firebase\JWT\JWT;
use moodle_exception;
use PHPUnit\Framework\Constraint\StringContains;
use PHPUnit\Framework\MockObject\MockObject;

/**
 * Test coverage for ilios_client class.
 *
 * @package    local_iliosapiclient
 * @category   test
 * @covers \local_iliosapiclient\ilios_client
 * @copyright  The Regents of the University of California
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
final class ilios_client_test extends basic_testcase {

    /**
     * Ilios base URL.
     */
    public const ILIOS_BASE_URL = 'http://localhost';

    /**
     * @var MockObject The cURL client mock.
     */
    protected MockObject $curlmock;

    /**
     * @var ilios_client The Ilios API client under test.
     */
    protected ilios_client $iliosclient;

    /**
     * {@inheritdoc}
     */
    protected function setUp(): void {
        parent::setUp();
        $this->curl_mock = $this->createMock(curl::class);
        $this->ilios_client = new ilios_client(self::ILIOS_BASE_URL, $this->curl_mock);
    }

    /**
     * {@inheritdoc}
     */
    protected function tearDown(): void {
        unset($this->ilios_client);
        unset($this->curl_mock);
        parent::tearDown();
    }

    /**
     * Tests get() method with default args.
     *
     * @return void
     * @throws moodle_exception
     */
    public function test_get_with_default_arguments(): void {
        $accesstoken = $this->create_access_token();
        $data = [['id' => 100, 'title' => 'lorem ipsum'], ['id' => 101, 'title' => 'foo bar']];
        $this->curl_mock->expects($this->once())->method('resetHeader');
        $this->curl_mock->expects($this->once())->method('setHeader')->with(['X-JWT-Authorization: Token ' . $accesstoken]);
        $this->curl_mock->expects($this->once())
            ->method('get')
            ->with(self::ILIOS_BASE_URL . '/api/v3/courses?limit=1000&offset=0')
            ->willReturn(json_encode(['courses' => $data]));
        $result = $this->ilios_client->get($accesstoken, 'courses');
        $this->assertCount(2, $result);
        $this->assertEquals(100, $result[0]->id);
        $this->assertEquals('lorem ipsum', $result[0]->title);
        $this->assertEquals(101, $result[1]->id);
        $this->assertEquals('foo bar', $result[1]->title);
    }

    /**
     * Tests get() method with non-default args.
     *
     * @return void
     * @throws moodle_exception
     */
    public function test_get_with_non_default_arguments(): void {
        $accesstoken = $this->create_access_token();
        $data = [[]];
        $this->curl_mock->expects($this->once())
            ->method('get')
            ->with(
                self::ILIOS_BASE_URL .
                        '/api/v3/courses?limit=3000&offset=0&filters[zip]=1&filters[zap][]=a&filters[zap][]=b&order_by[title]=DESC'
            )
            ->willReturn(json_encode(['courses' => $data]));
        $this->ilios_client->get($accesstoken, 'courses', ['zip' => '1', 'zap' => ['a', 'b']], ['title' => 'DESC'], 3000);
    }

    /**
     * Tests that get() fails if the response cannot be JSON-decoded.
     *
     * @return void
     * @throws moodle_exception
     */
    public function test_get_fails_on_garbled_response(): void {
        $accesstoken = $this->create_access_token();
        $data = 'g00bleG0bble';
        $this->expectException(moodle_exception::class);
        $this->expectExceptionMessage('Failed to decode response.');
        $this->curl_mock->expects($this->once())->method('get')->willReturn($data);
        $this->ilios_client->get($accesstoken, 'courses');
    }

    /**
     * Tests that get() fails if the response is empty.
     *
     * @return void
     * @throws moodle_exception
     */
    public function test_get_fails_on_empty_response(): void {
        $accesstoken = $this->create_access_token();
        $data = '';
        $this->expectException(moodle_exception::class);
        $this->expectExceptionMessage('Empty response.');
        $this->curl_mock->expects($this->once())->method('get')->willReturn($data);
        $this->ilios_client->get($accesstoken, 'courses');
    }

    /**
     * Tests that get() fails if the response contains errors.
     *
     * @return void
     * @throws moodle_exception
     */
    public function test_get_fails_on_error_response(): void {
        $accesstoken = $this->create_access_token();
        $data = ['errors' => ['something went wrong']];
        $this->expectException(moodle_exception::class);
        $this->expectExceptionMessage('The API responded with the following error: something went wrong.');
        $this->curl_mock->expects($this->once())->method('get')->willReturn(json_encode($data));
        $this->ilios_client->get($accesstoken, 'courses');
    }

    /**
     * Tests that get() fails if the response contains code/message pairs in the response.
     *
     * @return void
     * @throws moodle_exception
     */
    public function test_get_fails_on_code_and_message_response(): void {
        $accesstoken = $this->create_access_token();
        $data = ['code' => 403, 'message' => 'VERBOTEN!'];
        $this->expectException(moodle_exception::class);
        $this->expectExceptionMessage('Request failed. The API responded with the code: 403 and message: VERBOTEN!.');
        $this->curl_mock->expects($this->once())->method('get')->willReturn(json_encode($data));
        $this->ilios_client->get($accesstoken, 'courses');
    }

    /**
     * Tests that get() fails if the given access token is expired.
     *
     * @dataProvider expired_token_provider
     * @param string $accesstoken The API access token.
     * @return void
     */
    public function test_get_fails_with_expired_token(string $accesstoken): void {
        $this->expectException(moodle_exception::class);
        $this->expectExceptionMessage('API token is expired.');
        $this->ilios_client->get($accesstoken, 'does_not_matter');
    }

    /**
     * Tests that get() fails if the given access token is empty.
     *
     * @dataProvider empty_token_provider
     * @param string $accesstoken The API access token.
     * @return void
     */
    public function test_get_fails_with_empty_token(string $accesstoken): void {
        $this->expectException(moodle_exception::class);
        $this->expectExceptionMessage('API token is empty.');
        $this->ilios_client->get($accesstoken, 'does_not_matter');
    }

    /**
     * Tests that get() fails if the given access token cannot be JSON-decoded.
     *
     * @dataProvider corrupted_token_provider
     * @param string $accesstoken The API access token.
     * @return void
     */
    public function test_get_fails_with_corrupted_token(string $accesstoken): void {
        $this->expectException(moodle_exception::class);
        $this->expectExceptionMessage('Failed to decode API token.');
        $this->ilios_client->get($accesstoken, 'does_not_matter');
    }

    /**
     * Tests that get() fails if the given access token has the wrong number of segments.
     *
     * @dataProvider invalid_token_provider
     * @param string $accesstoken The API access token.
     * @return void
     */
    public function test_get_fails_with_invalid_token(string $accesstoken): void {
        $this->expectException(moodle_exception::class);
        $this->expectExceptionMessage('API token has an incorrect number of segments.');
        $this->ilios_client->get($accesstoken, 'does_not_matter');
    }

    /**
     * Tests get_by_id() method.
     *
     * @return void
     * @throws moodle_exception
     */
    public function test_get_by_id(): void {
        $accesstoken = $this->create_access_token();
        $data = [['id' => 100, 'title' => 'lorem ipsum']];
        $this->curl_mock->expects($this->once())->method('resetHeader');
        $this->curl_mock->expects($this->once())->method('setHeader')->with(['X-JWT-Authorization: Token ' . $accesstoken]);
        $this->curl_mock->expects($this->once())
            ->method('get')
            ->with(self::ILIOS_BASE_URL . '/api/v3/courses?filters[id]=100')
            ->willReturn(json_encode(['courses' => $data]));
        $result = $this->ilios_client->get_by_id($accesstoken, 'courses', 100);
        $this->assertEquals(100, $result->id);
        $this->assertEquals('lorem ipsum', $result->title);
    }

    /**
     * Tests get_by_id() method with empty results.
     *
     * @return void
     * @throws moodle_exception
     * /
     * @return void
     * @throws moodle_exception
     */
    public function test_get_by_id_with_empty_results(): void {
        $accesstoken = $this->create_access_token();
        $data = [];
        $this->curl_mock->expects($this->once())->method('get')->willReturn(json_encode(['courses' => $data]));
        $result = $this->ilios_client->get_by_id($accesstoken, 'courses', 100);
        $this->assertNull($result);
    }

    /**
     * Tests get_by_id() method with non-numeric ID as input.
     *
     * @return void
     * @throws moodle_exception
     */
    public function test_get_by_id_with_non_numeric_id(): void {
        $result = $this->ilios_client->get_by_id('lorem_ipsum', 'does_not_matter', 'a');
        $this->assertNull($result);
    }

    /**
     * Tests that get_by_id() fails if the response cannot be JSON-decoded.
     *
     * @return void
     * @throws moodle_exception
     */
    public function test_get_by_id_fails_on_garbled_response(): void {
        $accesstoken = $this->create_access_token();
        $data = 'g00bleG0bble';
        $this->expectException(moodle_exception::class);
        $this->expectExceptionMessage('Failed to decode response.');
        $this->curl_mock->expects($this->once())->method('get')->willReturn($data);
        $this->ilios_client->get_by_id($accesstoken, 'courses', 100);
    }

    /**
     * Tests that get_by_id() fails if the response is empty.
     *
     * @return void
     * @throws moodle_exception
     */
    public function test_get_by_id_fails_on_empty_response(): void {
        $accesstoken = $this->create_access_token();
        $data = '';
        $this->expectException(moodle_exception::class);
        $this->expectExceptionMessage('Empty response.');
        $this->curl_mock->expects($this->once())->method('get')->willReturn($data);
        $this->ilios_client->get_by_id($accesstoken, 'courses', 100);
    }

    /**
     * Tests that get_by_id() fails if the response contains errors.
     *
     * @return void
     * @throws moodle_exception
     */
    public function test_get_by_id_fails_on_error_response(): void {
        $accesstoken = $this->create_access_token();
        $data = ['errors' => ['something went wrong']];
        $this->expectException(moodle_exception::class);
        $this->expectExceptionMessage('The API responded with the following error: something went wrong.');
        $this->curl_mock->expects($this->once())->method('get')->willReturn(json_encode($data));
        $this->ilios_client->get_by_id($accesstoken, 'courses', 100);
    }

    /**
     * Tests that get_by_id() fails if the response contains code/message pairs in the response.
     *
     * @return void
     * @throws moodle_exception
     */
    public function test_get_by_id_fails_on_code_and_message_response(): void {
        $accesstoken = $this->create_access_token();
        $data = ['code' => 403, 'message' => 'VERBOTEN!'];
        $this->expectException(moodle_exception::class);
        $this->expectExceptionMessage('Request failed. The API responded with the code: 403 and message: VERBOTEN!.');
        $this->curl_mock->expects($this->once())->method('get')->willReturn(json_encode($data));
        $this->ilios_client->get_by_id($accesstoken, 'courses', 100);
    }

    /**
     * Tests that get_by_id() fails if the given access token is expired.
     *
     * @dataProvider expired_token_provider
     * @param string $accesstoken The API access token.
     * @return void
     */
    public function test_get_by_id_fails_with_expired_token(string $accesstoken): void {
        $this->expectException(moodle_exception::class);
        $this->expectExceptionMessage('API token is expired.');
        $this->ilios_client->get_by_id($accesstoken, 'does_not_matter', 100);
    }

    /**
     * Tests that get_by_id() fails if the given access token is empty.
     *
     * @dataProvider empty_token_provider
     * @param string $accesstoken The API access token.
     * @return void
     */
    public function test_get_by_id_fails_with_empty_token(string $accesstoken): void {
        $this->expectException(moodle_exception::class);
        $this->expectExceptionMessage('API token is empty.');
        $this->ilios_client->get_by_id($accesstoken, 'does_not_matter', 100);
    }

    /**
     * Tests that get_by_id() fails if the given access token cannot be JSON-decoded.
     *
     * @dataProvider corrupted_token_provider
     * @param string $accesstoken The API access token.
     * @return void
     */
    public function test_get_by_id_fails_with_corrupted_token(string $accesstoken): void {
        $this->expectException(moodle_exception::class);
        $this->expectExceptionMessage('Failed to decode API token.');
        $this->ilios_client->get_by_id($accesstoken, 'does_not_matter', 100);
    }

    /**
     * Tests that get() fails if the given access token has the wrong number of segments.
     *
     * @dataProvider invalid_token_provider
     * @param string $accesstoken The API access token.
     * @return void
     */
    public function test_get_by_id_fails_with_invalid_token(string $accesstoken): void {
        $this->expectException(moodle_exception::class);
        $this->expectExceptionMessage('API token has an incorrect number of segments.');
        $this->ilios_client->get_by_id($accesstoken, 'does_not_matter', 100);
    }

    /**
     * Tests get_by_ids() method.
     *
     * @return void
     * @throws moodle_exception
     */
    public function test_get_by_ids(): void {
        $accesstoken = $this->create_access_token();
        $data = [['id' => 100, 'title' => 'lorem ipsum'], ['id' => 101, 'title' => 'foo bar']];
        $this->curl_mock->expects($this->once())->method('resetHeader');
        $this->curl_mock->expects($this->once())->method('setHeader')->with(['X-JWT-Authorization: Token ' . $accesstoken]);
        $this->curl_mock->expects($this->once())
            ->method('get')
            ->with(self::ILIOS_BASE_URL . '/api/v3/courses?filters[id]=100')
            ->willReturn(json_encode(['courses' => $data]));
        $result = $this->ilios_client->get_by_ids($accesstoken, 'courses', 100);
        $this->assertCount(2, $result);
        $this->assertEquals(100, $result[0]->id);
        $this->assertEquals('lorem ipsum', $result[0]->title);
        $this->assertEquals(101, $result[1]->id);
        $this->assertEquals('foo bar', $result[1]->title);
    }

    /**
     * Tests get_by_ids() method in batch mode.
     *
     * @return void
     * @throws moodle_exception
     */
    public function test_get_by_ids_in_batch_mode(): void {
        $accesstoken = $this->create_access_token();
        $ids = range(1, 120);
        $data1 = [['id' => 1, 'title' => 'foo']];
        $data2 = [['id' => 52, 'title' => 'bar'], ['id' => 55, 'title' => 'bier']];
        $data3 = [['id' => 111, 'title' => 'baz']];
        $this->curl_mock->expects($this->exactly(3))
            ->method('get')
            ->with(new StringContains('limit=50'))
            ->willReturn(
                json_encode(['courses' => $data1]),
                json_encode(['courses' => $data2]),
                json_encode(['courses' => $data3]),
            );
        $result = $this->ilios_client->get_by_ids($accesstoken, 'courses', $ids, 50);
        $this->assertCount(4, $result);
        $this->assertEquals(1, $result[0]->id);
        $this->assertEquals(52, $result[1]->id);
        $this->assertEquals(55, $result[2]->id);
        $this->assertEquals(111, $result[3]->id);
    }

    /**
     * Tests get_by_ids() method with non-array input.
     *
     * @return void
     * @throws moodle_exception
     */
    public function test_get_by_ids_with_non_numeric_non_array_input(): void {
        $accesstoken = $this->create_access_token();
        $result = $this->ilios_client->get_by_ids($accesstoken, 'courses', 'abc');
        $this->assertEquals([], $result);
    }

    /**
     * Tests get_by_ids() with empty results.
     *
     * @return void
     * @throws moodle_exception
     */
    public function test_get_by_ids_with_empty_results(): void {
        $accesstoken = $this->create_access_token();
        $data = [];
        $this->curl_mock->expects($this->once())->method('get')->willReturn(json_encode(['courses' => $data]));
        $result = $this->ilios_client->get_by_ids($accesstoken, 'courses', [100]);
        $this->assertEquals([], $result);
    }

    /**
     * Tests that get_by_ids() fails if the response cannot be JSON-decoded.
     *
     * @return void
     * @throws moodle_exception
     */
    public function test_get_by_ids_fails_on_garbled_response(): void {
        $accesstoken = $this->create_access_token();
        $data = 'g00bleG0bble';
        $this->expectException(moodle_exception::class);
        $this->expectExceptionMessage('Failed to decode response.');
        $this->curl_mock->expects($this->once())->method('get')->willReturn($data);
        $this->ilios_client->get_by_ids($accesstoken, 'courses', [100]);
    }

    /**
     * Tests that get_by_ids() fails if the response is empty.
     *
     * @return void
     * @throws moodle_exception
     */
    public function test_get_by_ids_fails_on_empty_response(): void {
        $accesstoken = $this->create_access_token();
        $data = '';
        $this->expectException(moodle_exception::class);
        $this->expectExceptionMessage('Empty response.');
        $this->curl_mock->expects($this->once())->method('get')->willReturn($data);
        $this->ilios_client->get_by_ids($accesstoken, 'courses', [100]);
    }

    /**
     * Tests that get_by_ids() fails if the response contains errors.
     *
     * @return void
     * @throws moodle_exception
     */
    public function test_get_by_ids_fails_on_error_response(): void {
        $accesstoken = $this->create_access_token();
        $data = ['errors' => ['something went wrong']];
        $this->expectException(moodle_exception::class);
        $this->expectExceptionMessage('The API responded with the following error: something went wrong.');
        $this->curl_mock->expects($this->once())->method('get')->willReturn(json_encode($data));
        $this->ilios_client->get_by_ids($accesstoken, 'courses', [100]);
    }

    /**
     * Tests that get_by_ids() fails if the response contains code/message pairs in the response.
     *
     * @return void
     * @throws moodle_exception
     */
    public function test_get_by_ids_fails_on_code_and_message_response(): void {
        $accesstoken = $this->create_access_token();
        $data = ['code' => 403, 'message' => 'VERBOTEN!'];
        $this->expectException(moodle_exception::class);
        $this->expectExceptionMessage('Request failed. The API responded with the code: 403 and message: VERBOTEN!.');
        $this->curl_mock->expects($this->once())->method('get')->willReturn(json_encode($data));
        $this->ilios_client->get_by_ids($accesstoken, 'courses', [100]);
    }

    /**
     * Tests that get_by_ids() fails if the given access token is expired.
     *
     * @dataProvider expired_token_provider
     * @param string $accesstoken The API access token.
     * @return void
     */
    public function test_get_by_ids_fails_with_expired_token(string $accesstoken): void {
        $this->expectException(moodle_exception::class);
        $this->expectExceptionMessage('API token is expired.');
        $this->ilios_client->get_by_ids($accesstoken, 'does_not_matter', 100);
    }

    /**
     * Tests that get_by_ids() fails if the given access token is empty.
     *
     * @dataProvider empty_token_provider
     * @param string $accesstoken The API access token.
     * @return void
     */
    public function test_get_by_ids_fails_with_empty_token(string $accesstoken): void {
        $this->expectException(moodle_exception::class);
        $this->expectExceptionMessage('API token is empty.');
        $this->ilios_client->get_by_ids($accesstoken, 'does_not_matter', 100);
    }

    /**
     * Tests that get_by_ids() fails if the given access token cannot be JSON-decoded.
     *
     * @dataProvider corrupted_token_provider
     * @param string $accesstoken The API access token.
     * @return void
     */
    public function test_get_by_ids_fails_with_corrupted_token(string $accesstoken): void {
        $this->expectException(moodle_exception::class);
        $this->expectExceptionMessage('Failed to decode API token.');
        $this->ilios_client->get_by_ids($accesstoken, 'does_not_matter', 100);
    }

    /**
     * Tests that get() fails if the given access token has the wrong number of segments.
     *
     * @dataProvider invalid_token_provider
     * @param string $accesstoken The API access token.
     * @return void
     */
    public function test_get_by_ids_fails_with_invalid_token(string $accesstoken): void {
        $this->expectException(moodle_exception::class);
        $this->expectExceptionMessage('API token has an incorrect number of segments.');
        $this->ilios_client->get_by_ids($accesstoken, 'does_not_matter', 100);
    }

    /**
     * Returns empty access tokens.
     *
     * @return array[]
     */
    public static function empty_token_provider(): array {
        return [
                [''],
                ['   '],
        ];
    }

    /**
     * Returns "corrupted" access tokens.
     *
     * @return array[]
     */
    public static function corrupted_token_provider(): array {
        return [
                ['AAAAA.BBBBB.CCCCCC'], // Has the right number of segments, but bunk payload.
        ];
    }

    /**
     * Returns access tokens with invalid numbers of segments.
     *
     * @return array[]
     */
    public static function invalid_token_provider(): array {
        return [
                ['AAAA'], // Not enough segments.
                ['AAAA.BBBBB'], // Still not enough.
                ['AAAA.BBBBB.CCCCC.DDDDD'], // Too many segments.
        ];
    }

    /**
     * Returns expired access tokens.
     *
     * @return array[]
     */
    public static function expired_token_provider(): array {
        $key = 'doesnotmatterhere';
        $payload = ['exp' => (new DateTime('-2 days'))->getTimestamp()];
        $jwt = JWT::encode($payload, $key, 'HS256');
        return [
                [$jwt],
        ];
    }

    /**
     * Creates and returns an un-expired JWT, to be used as access token.
     * This token will pass client-side token validation.
     *
     * @return string
     */
    protected function create_access_token(): string {
        $key = 'doesnotmatterhere';
        $payload = ['exp' => (new DateTime('10 days'))->getTimestamp()];
        return JWT::encode($payload, $key, 'HS256');
    }
}
