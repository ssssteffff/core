<?php
/**
 * @author Vincent Petry <pvince81@owncloud.com>
 *
 * @copyright Copyright (c) 2016, ownCloud, Inc.
 * @license AGPL-3.0
 *
 * This code is free software: you can redistribute it and/or modify
 * it under the terms of the GNU Affero General Public License, version 3,
 * as published by the Free Software Foundation.
 *
 * This program is distributed in the hope that it will be useful,
 * but WITHOUT ANY WARRANTY; without even the implied warranty of
 * MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE. See the
 * GNU Affero General Public License for more details.
 *
 * You should have received a copy of the GNU Affero General Public License, version 3,
 * along with this program.  If not, see <http://www.gnu.org/licenses/>
 *
 */

namespace OCA\Files_Sharing\Tests;

use OCA\Files_Sharing\MountProvider;
use OCP\Files\Storage\IStorageFactory;
use OCP\IConfig;
use OCP\ILogger;
use OCP\IUser;
use OCP\Share\IShare;
use OCP\Share\IManager;
use OCP\Files\Mount\IMountPoint;

/**
 * @group DB
 */
class MountProviderTest extends \Test\TestCase {

	/** @var MountProvider */
	private $provider;

	/** @var IConfig|\PHPUnit_Framework_MockObject_MockObject */
	private $config;

	/** @var IUser|\PHPUnit_Framework_MockObject_MockObject */
	private $user;

	/** @var IStorageFactory|\PHPUnit_Framework_MockObject_MockObject */
	private $loader;

	/** @var ILogger | \PHPUnit_Framework_MockObject_MockObject */
	private $logger;

	public function setUp() {
		parent::setUp();

		$this->config = $this->getMock('OCP\IConfig');
		$this->user = $this->getMock('OCP\IUser');
		$this->loader = $this->getMock('OCP\Files\Storage\IStorageFactory');
		$this->loader->expects($this->any())
			->method('getInstance')
			->will($this->returnCallback(function($mountPoint, $class, $arguments) {
				$storage = $this->getMockBuilder('OC\Files\Storage\Shared')
					->disableOriginalConstructor()
					->getMock();
				$storage->expects($this->any())
					->method('getShare')
					->will($this->returnValue($arguments['share']));
				return $storage;
			}));
		$this->logger = $this->getMock('\OCP\ILogger');
		$this->logger->expects($this->never())
			->method('error');

		$this->provider = $this->getMockBuilder('OCA\Files_Sharing\MountProvider')
			->setMethods(['getItemsSharedWithUser'])
			->setConstructorArgs([$this->config, $this->logger])
			->getMock();
	}

	private function makeMockShare($id, $nodeId, $owner = 'user2', $target = null, $permissions = 31, $shareType) {
		return [
			'id' => $id,
			'uid_owner' => $owner,
			'share_type' => $shareType,
			'item_type' => 'file',
			'file_target' => $target,
			'file_source' => $nodeId,
			'item_target' => null,
			'item_source' => $nodeId,
			'permissions' => $permissions,
			'stime' => time(),
			'token' => null,
			'expiration' => null,
		];
	}

	/**
	 * Tests excluding shares from the current view. This includes:
	 * - shares that were opted out of (permissions === 0)
	 * - shares with a group in which the owner is already in
	 */
	public function testExcludeShares() {
		$userShares = [
			$this->makeMockShare(1, 100, 'user2', '/share2', 0, \OCP\Share::SHARE_TYPE_USER), 
			$this->makeMockShare(2, 100, 'user2', '/share2', 31, \OCP\Share::SHARE_TYPE_USER),
		];

		$groupShares = [
			$this->makeMockShare(3, 100, 'user2', '/share2', 0, \OCP\Share::SHARE_TYPE_GROUP), 
			$this->makeMockShare(4, 100, 'user2', '/share4', 31, \OCP\Share::SHARE_TYPE_GROUP), 
			$this->makeMockShare(5, 100, 'user1', '/share4', 31, \OCP\Share::SHARE_TYPE_GROUP), 
		];

		$this->user->expects($this->any())
			->method('getUID')
			->will($this->returnValue('user1'));

		$allShares = array_merge($userShares, $groupShares);

		$this->provider->expects($this->once())
			->method('getItemsSharedWithUser')
			->with('user1')
			->will($this->returnValue($allShares));

		$mounts = $this->provider->getMountsForUser($this->user, $this->loader);

		$this->assertCount(2, $mounts);
		$this->assertInstanceOf('OCA\Files_Sharing\SharedMount', $mounts[0]);
		$this->assertInstanceOf('OCA\Files_Sharing\SharedMount', $mounts[1]);

		$mountedShare1 = $mounts[0]->getShare();

		$this->assertEquals('2', $mountedShare1['id']);
		$this->assertEquals('user2', $mountedShare1['uid_owner']);
		$this->assertEquals(100, $mountedShare1['file_source']);
		$this->assertEquals('/share2', $mountedShare1['file_target']);
		$this->assertEquals(31, $mountedShare1['permissions']);

		$mountedShare2 = $mounts[1]->getShare();
		$this->assertEquals('4', $mountedShare2['id']);
		$this->assertEquals('user2', $mountedShare2['uid_owner']);
		$this->assertEquals(100, $mountedShare2['file_source']);
		$this->assertEquals('/share4', $mountedShare2['file_target']);
		$this->assertEquals(31, $mountedShare2['permissions']);
	}

	public function mergeSharesDataProvider() {
		// note: the user in the specs here is the shareOwner not recipient
		// the recipient is always "user1"
		return [
			// #0: share as outsider with "group1" and "user1" with same permissions
			[
				[
					[1, 100, 'user2', '/share2', 31], 
				],
				[
					[2, 100, 'user2', '/share2', 31], 
				],
				[
					// combined, user share has higher priority
					['1', 100, 'user2', '/share2', 31],
				],
			],
			// #1: share as outsider with "group1" and "user1" with different permissions
			[
				[
					[1, 100, 'user2', '/share', 31], 
				],
				[
					[2, 100, 'user2', '/share', 15], 
				],
				[
					// use highest permissions
					['1', 100, 'user2', '/share', 31],
				],
			],
			// #2: share as outsider with "group1" and "group2" with same permissions
			[
				[
				],
				[
					[1, 100, 'user2', '/share', 31], 
					[2, 100, 'user2', '/share', 31], 
				],
				[
					// combined, first group share has higher priority
					['1', 100, 'user2', '/share', 31],
				],
			],
			// #3: share as outsider with "group1" and "group2" with different permissions
			[
				[
				],
				[
					[1, 100, 'user2', '/share', 31], 
					[2, 100, 'user2', '/share', 15], 
				],
				[
					// use higher permissions
					['1', 100, 'user2', '/share', 31],
				],
			],
			// #4: share as insider with "group1"
			[
				[
				],
				[
					[1, 100, 'user1', '/share', 31], 
				],
				[
					// no received share since "user1" is the sharer/owner
				],
			],
			// #5: share as insider with "group1" and "group2" with different permissions
			[
				[
				],
				[
					[1, 100, 'user1', '/share', 31], 
					[2, 100, 'user1', '/share', 15], 
				],
				[
					// no received share since "user1" is the sharer/owner
				],
			],
			// #6: share as outside with "group1", recipient opted out
			[
				[
				],
				[
					[1, 100, 'user2', '/share', 0], 
				],
				[
					// no received share since "user1" opted out
				],
			],
		];
	}

	/**
	 * Tests merging shares.
	 *
	 * Happens when sharing the same entry to a user through multiple ways,
	 * like several groups and also direct shares at the same time.
	 *
	 * @dataProvider mergeSharesDataProvider
	 *
	 * @param array $userShares array of user share specs
	 * @param array $groupShares array of group share specs
	 * @param array $expectedShares array of expected supershare specs
	 */
	public function testMergeShares($userShares, $groupShares, $expectedShares) {
		$userShares = array_map(function($shareSpec) {
			return $this->makeMockShare($shareSpec[0], $shareSpec[1], $shareSpec[2], $shareSpec[3], $shareSpec[4], \OCP\Share::SHARE_TYPE_USER);
		}, $userShares);
		$groupShares = array_map(function($shareSpec) {
			return $this->makeMockShare($shareSpec[0], $shareSpec[1], $shareSpec[2], $shareSpec[3], $shareSpec[4], \OCP\Share::SHARE_TYPE_GROUP);
		}, $groupShares);

		$allShares = array_merge($userShares, $groupShares);

		$this->user->expects($this->any())
			->method('getUID')
			->will($this->returnValue('user1'));

		$this->provider->expects($this->once())
			->method('getItemsSharedWithUser')
			->with('user1')
			->will($this->returnValue($allShares));

		$mounts = $this->provider->getMountsForUser($this->user, $this->loader);

		$this->assertCount(count($expectedShares), $mounts);

		foreach ($mounts as $index => $mount) {
			$expectedShare = $expectedShares[$index];
			$this->assertInstanceOf('OCA\Files_Sharing\SharedMount', $mount);

			// supershare
			$share = $mount->getShare();

			$this->assertEquals($expectedShare[0], $share['id']);
			$this->assertEquals($expectedShare[1], $share['file_source']);
			$this->assertEquals($expectedShare[2], $share['uid_owner']);
			$this->assertEquals($expectedShare[3], $share['file_target']);
			$this->assertEquals($expectedShare[4], $share['permissions']);
		}
	}
}

