<?php
declare(strict_types=1);


/**
 * Nextcloud - Social Support
 *
 * This file is licensed under the Affero General Public License version 3 or
 * later. See the COPYING file.
 *
 * @author Maxence Lange <maxence@artificial-owl.com>
 * @copyright 2018, Maxence Lange <maxence@artificial-owl.com>
 * @license GNU AGPL version 3 or any later version
 *
 * This program is free software: you can redistribute it and/or modify
 * it under the terms of the GNU Affero General Public License as
 * published by the Free Software Foundation, either version 3 of the
 * License, or (at your option) any later version.
 *
 * This program is distributed in the hope that it will be useful,
 * but WITHOUT ANY WARRANTY; without even the implied warranty of
 * MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
 * GNU Affero General Public License for more details.
 *
 * You should have received a copy of the GNU Affero General Public License
 * along with this program.  If not, see <http://www.gnu.org/licenses/>.
 *
 */


namespace OCA\Social\Service\ActivityPub\Activity;


use daita\MySmallPhpTools\Exceptions\MalformedArrayException;
use Exception;
use OCA\Social\Db\FollowsRequest;
use OCA\Social\Exceptions\ActorDoesNotExistException;
use OCA\Social\Exceptions\CacheActorDoesNotExistException;
use OCA\Social\Exceptions\FollowDoesNotExistException;
use OCA\Social\Exceptions\FollowSameAccountException;
use OCA\Social\Exceptions\InvalidOriginException;
use OCA\Social\Exceptions\InvalidResourceException;
use OCA\Social\Exceptions\Request410Exception;
use OCA\Social\Exceptions\RequestException;
use OCA\Social\Exceptions\SocialAppConfigException;
use OCA\Social\Exceptions\UrlCloudException;
use OCA\Social\Model\ActivityPub\ACore;
use OCA\Social\Model\ActivityPub\Activity\Accept;
use OCA\Social\Model\ActivityPub\Activity\Follow;
use OCA\Social\Model\ActivityPub\Activity\Reject;
use OCA\Social\Model\ActivityPub\Activity\Undo;
use OCA\Social\Model\ActivityPub\OrderedCollection;
use OCA\Social\Model\ActivityPub\Actor\Person;
use OCA\Social\Model\InstancePath;
use OCA\Social\Service\ActivityPub\ICoreService;
use OCA\Social\Service\ActivityPub\Actor\PersonService;
use OCA\Social\Service\ActivityService;
use OCA\Social\Service\ConfigService;
use OCA\Social\Service\ImportService;
use OCA\Social\Service\MiscService;


class FollowService implements ICoreService {


	/** @var FollowsRequest */
	private $followsRequest;

	/** @var PersonService */
	private $personService;

	/** @var ActivityService */
	private $activityService;

	/** @var ConfigService */
	private $configService;

	/** @var MiscService */
	private $miscService;


	/** @var string */
	private $viewerId = '';


	/**
	 * NoteService constructor.
	 *
	 * @param FollowsRequest $followsRequest
	 * @param PersonService $personService
	 * @param ActivityService $activityService
	 * @param ConfigService $configService
	 * @param MiscService $miscService
	 */
	public function __construct(
		FollowsRequest $followsRequest, PersonService $personService,
		ActivityService $activityService, ConfigService $configService,
		MiscService $miscService
	) {
		$this->followsRequest = $followsRequest;
		$this->personService = $personService;
		$this->activityService = $activityService;
		$this->configService = $configService;
		$this->miscService = $miscService;
	}


	/**
	 * @param string $viewerId
	 */
	public function setViewerId(string $viewerId) {
		$this->viewerId = $viewerId;
		$this->followsRequest->setViewerId($viewerId);
	}

	public function getViewerId(): string {
		return $this->viewerId;
	}


	/**
	 * @param Person $actor
	 * @param string $account
	 *
	 * @throws ActorDoesNotExistException
	 * @throws RequestException
	 * @throws SocialAppConfigException
	 * @throws CacheActorDoesNotExistException
	 * @throws InvalidResourceException
	 * @throws UrlCloudException
	 * @throws FollowSameAccountException
	 */
	public function followAccount(Person $actor, string $account) {
		$remoteActor = $this->personService->getFromAccount($account);
		if ($remoteActor->getId() === $actor->getId()) {
			throw new FollowSameAccountException("Don't follow yourself, be your own lead");
		}

		$follow = new Follow();
		$follow->setUrlCloud($this->configService->getCloudAddress());
		$follow->generateUniqueId();
		$follow->setActorId($actor->getId());
		$follow->setObjectId($remoteActor->getId());
		$follow->setFollowId($remoteActor->getFollowers());

		try {
			$this->followsRequest->getByPersons($actor->getId(), $remoteActor->getId());
		} catch (FollowDoesNotExistException $e) {
			$this->followsRequest->save($follow);
			// TODO - Remove this auto-accepted.
			$this->followsRequest->accepted($follow);

			$follow->addInstancePath(
				new InstancePath(
					$remoteActor->getInbox(), InstancePath::TYPE_INBOX, InstancePath::PRIORITY_TOP
				)
			);
			$this->activityService->request($follow);
		}
	}


	/**
	 * @param Person $actor
	 * @param string $account
	 *
	 * @throws CacheActorDoesNotExistException
	 * @throws InvalidResourceException
	 * @throws RequestException
	 * @throws SocialAppConfigException
	 * @throws UrlCloudException
	 */
	public function unfollowAccount(Person $actor, string $account) {
		$remoteActor = $this->personService->getFromAccount($account);

		try {
			$follow = $this->followsRequest->getByPersons($actor->getId(), $remoteActor->getId());
			$this->followsRequest->delete($follow);
		} catch (FollowDoesNotExistException $e) {
		}
	}


	/**
	 * @param Person $actor
	 *
	 * @return Person[]
	 */
	public function getFollowers(Person $actor): array {
		return $this->followsRequest->getFollowersByActorId($actor->getId());
	}


	/**
	 * @param Person $actor
	 *
	 * @return OrderedCollection
	 */
	public function getFollowersCollection(Person $actor): OrderedCollection {
		$collection = new OrderedCollection();
		$collection->setId($actor->getFollowers());
		$collection->setTotalItems(20);
		$collection->setFirst('...');

		return $collection;
	}


	/**
	 * @param Person $actor
	 *
	 * @return Person[]
	 */
	public function getFollowing(Person $actor): array {
		return $this->followsRequest->getFollowingByActorId($actor->getId());
	}


	/**
	 * @param Person $actor
	 *
	 * @return OrderedCollection
	 */
	public function getFollowingCollection(Person $actor): OrderedCollection {
		$collection = new OrderedCollection();
//		$collection->setId($actor->getFollowers());
//		$collection->setTotalItems(20);
//		$collection->setFirst('...');

		return $collection;
	}


	/**
	 * @param ACore $item
	 * @param ImportService $importService
	 */
	public function processResult(ACore $item, ImportService $importService) {
	}


	/**
	 * @param Follow $follow
	 */
	public function confirmFollowRequest(Follow $follow) {
		try {
			$remoteActor = $this->personService->getFromId($follow->getActorId());

			$accept = new Accept();
			// TODO: improve the generation of the Id
			$accept->setId($follow->getObjectId() . '#accepts/follows/' . rand(1000, 100000000));
			$accept->setActorId($follow->getObjectId());
			$accept->setObject($follow);

			$accept->addInstancePath(
				new InstancePath(
					$remoteActor->getInbox(), InstancePath::TYPE_INBOX, InstancePath::PRIORITY_TOP
				)
			);

			$follow->setParent($accept);

			$this->activityService->request($accept);
			$this->followsRequest->accepted($follow);
		} catch (Exception $e) {
		}
	}


	/**
	 * This method is called when saving the Follow object
	 *
	 * @param ACore $follow
	 * @param ImportService $importService
	 *
	 * @throws InvalidResourceException
	 * @throws RequestException
	 * @throws SocialAppConfigException
	 * @throws UrlCloudException
	 * @throws InvalidOriginException
	 * @throws Request410Exception
	 * @throws MalformedArrayException
	 */
	public function processIncomingRequest(ACore $follow, ImportService $importService) {
		/** @var Follow $follow */
		$follow->checkOrigin($follow->getActorId());

		try {
			$knownFollow = $this->followsRequest->getByPersons(
				$follow->getActorId(), $follow->getObjectId()
			);

			if ($knownFollow->getId() === $follow->getId() && !$knownFollow->isAccepted()) {
				$this->confirmFollowRequest($follow);
			}
		} catch (FollowDoesNotExistException $e) {
			$actor = $this->personService->getFromId($follow->getObjectId());
			if ($actor->isLocal()) {
				$follow->setFollowId($actor->getFollowers());
				$this->followsRequest->save($follow);
				$this->confirmFollowRequest($follow);
			}
		}

//		} else {
	}


	/**
	 * @param ACore $activity
	 * @param ACore $item
	 *
	 * @throws InvalidOriginException
	 */
	public function activity(Acore $activity, ACore $item) {
		/** @var Follow $item */
		if ($activity->getType() === Undo::TYPE) {
			$activity->checkOrigin($item->getActorId());
			$this->followsRequest->deleteByPersons($item);
		}

		if ($activity->getType() === Reject::TYPE) {
			$activity->checkOrigin($item->getObjectId());
			$this->followsRequest->deleteByPersons($item);
		}

		if ($activity->getType() === Accept::TYPE) {
			$activity->checkOrigin($item->getObjectId());
			$this->followsRequest->accepted($item);
		}
	}


	/**
	 * @param ACore $item
	 */
	public function save(ACore $item) {
	}


	/**
	 * @param ACore $item
	 */
	public function delete(ACore $item) {
	}

}
