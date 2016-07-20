<?php
/**
 * @author Morris Jobke <hey@morrisjobke.de>
 * @author Robin Appelman <icewind@owncloud.com>
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

namespace OCA\Files_Sharing;

use OC\Files\Filesystem;
use OC\User\NoUserException;
use OCP\Files\Config\IMountProvider;
use OCP\Files\Storage\IStorageFactory;
use OCP\IConfig;
use OCP\ILogger;
use OCP\IUser;

class MountProvider implements IMountProvider {
	/**
	 * @var \OCP\IConfig
	 */
	protected $config;

	/**
	 * @var ILogger
	 */
	protected $logger;

	/**
	 * @param \OCP\IConfig $config
	 * @param ILogger $logger
	 */
	public function __construct(IConfig $config, ILogger $logger) {
		$this->config = $config;
		$this->logger = $logger;
	}

	/**
	 * Return items shared with user
	 *
	 * @internal
	 */
	public function getItemsSharedWithUser($uid) {
		// only here to make it mockable/testable
		return \OCP\Share::getItemsSharedWithUser('file', $uid);
	}

	/**
	 * Get all mountpoints applicable for the user and check for shares where we need to update the etags
	 *
	 * @param \OCP\IUser $user
	 * @param \OCP\Files\Storage\IStorageFactory $storageFactory
	 * @return \OCP\Files\Mount\IMountPoint[]
	 */
	public function getMountsForUser(IUser $user, IStorageFactory $storageFactory) {
		$shares = $this->getItemsSharedWithUser($user->getUID());
		$shares = array_filter($shares, function ($share) {
			return $share['permissions'] > 0;
		});

		$superShares = $this->buildSuperShares($shares);

		$mounts = [];
		foreach ($superShares as $share) {
			try {
				$mounts[] = new SharedMount(
					'\OC\Files\Storage\Shared',
					$mounts,
					[
						'user' => $user->getUID(),
						// parent share
						'superShare' => $share[0],
						// children/component of the superShare
						'groupedShares' => $share[1],
					],
					$storageFactory
				);
			} catch (\Exception $e) {
				$this->logger->logException($e);
				$this->logger->error('Error while trying to create shared mount');
			}
		}

		// array_filter removes the null values from the array
		return array_filter($mounts);
	}

	/**
	 * Groups shares by path (nodeId) and target path
	 *
	 * @param \OCP\Share\IShare[] $shares
	 * @return \OCP\Share\IShare[][] array of grouped shares, each element in the
	 * array is a group which itself is an array of shares
	 */
	private function groupShares(array $shares) {
		$tmp = [];

		foreach ($shares as $share) {
			if (!isset($tmp[$share['file_source'])) {
				$tmp[$share['file_source']];
			}
			$tmp[$share['file_source']][$share['file_target']][] = $share;
		}

		$result = [];
		foreach ($tmp as $tmp2) {
			foreach ($tmp2 as $item) {
				$result[] = $item;
			}
		}

		return $result;
	}

	/**
	 * Build super shares (virtual share) by grouping them by node id and target,
	 * then for each group compute the super share and return it along with the matching
	 * grouped shares. The most permissive permissions are used based on the permissions
	 * of all shares within the group.
	 *
	 * @param \OCP\Share\IShare[] $allShares
	 * @return array Tuple of [superShare, groupedShares]
	 */
	private function buildSuperShares(array $allShares) {
		$result = [];

		$groupedShares = $this->groupShares($allShares);

		/** @var \OCP\Share\IShare[] $shares */
		foreach ($groupedShares as $shares) {
			if (count($shares) === 0) {
				continue;
			}

			// compute super share based on first entry of the group
			$superShare = [
				'id' => $shares[0]['id'],
				'uid_owner' => $shares[0]['uid_owner'],
				'share_type' => $shares[0]['share_type'],
				'item_type' => $shares[0]['item_type'],
				'file_target' => $shares[0]['file_target'],
				'file_source' => $shares[0]['file_source'],
				'item_target' => null,
				'item_source' => $shares[0]['item_source'],
				'expiration' => null,
				'stime' => $shares[0]['stime'],
			];

			// use most permissive permissions
			$permissions = 0;
			foreach ($shares as $share) {
				$permissions |= $share['permissions'];
			}

			$superShare['permissions'] = $permissions;

			$result[] = [$superShare, $shares];
		}

		return $result;
	}
}
