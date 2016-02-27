<?php

namespace Waca\Pages;

use Ban;
use Exception;
use Logger;
use Request;
use SessionAlert;
use User;
use Waca\Exceptions\ApplicationLogicException;
use Waca\SecurityConfiguration;
use Waca\Tasks\InternalPageBase;
use Waca\WebRequest;

class PageBan extends InternalPageBase
{
	/**
	 * Main function for this page, when no specific actions are called.
	 */
	protected function main()
	{
		$this->setHtmlTitle('Bans');

		$bans = Ban::getActiveBans(null, $this->getDatabase());

		$this->assign("activebans", $bans);
		$this->setTemplate("bans/banlist.tpl");
	}

	/**
	 * Entry point for the ban set action
	 */
	protected function set()
	{
		$this->setHtmlTitle('Bans');

		// dual-mode action
		if (WebRequest::wasPosted()) {
			$this->handlePostMethodForSetBan();
		}
		else {
			$this->handleGetMethodForSetBan();
		}
	}

	/**
	 * Entry point for the ban remove action
	 */
	protected function remove()
	{
		$this->setHtmlTitle('Bans');

		$ban = $this->getBanForUnban();

		// dual mode
		if (WebRequest::wasPosted()) {
			$unbanReason = WebRequest::postString('unbanreason');
			if ($unbanReason === null || trim($unbanReason) === "") {
				throw new ApplicationLogicException("No unban reason specified");
			}

			$database = $this->getDatabase();
			$ban->setActive(0);
			$ban->save();

			Logger::unbanned($database, $ban, $unbanReason);

			SessionAlert::quick("Disabled ban.");
			$this->getNotificationHelper->unbanned($ban, $unbanReason);

			$this->redirect('bans');
		}
		else {
			$this->assign("ban", $ban);
			$this->setTemplate("bans/unban.tpl");
		}
	}

	/**
	 * Sets up the security for this page. If certain actions have different permissions, this should be reflected in
	 * the return value from this function.
	 *
	 * If this page even supports actions, you will need to check the route
	 *
	 * @return SecurityConfiguration
	 * @category Security-Critical
	 */
	protected function getSecurityConfiguration()
	{
		// display of bans is allowed for any user, but setting and removing bans is admin-only.
		switch ($this->getRouteName()) {
			case "main":
				return SecurityConfiguration::internalPage();
			default:
				return SecurityConfiguration::adminPage();
		}
	}

	/**
	 * @throws ApplicationLogicException
	 */
	private function getBanDuration()
	{
		$duration = WebRequest::postString('duration');
		if ($duration === "other") {
			$duration = strtotime(WebRequest::postString('otherduration'));

			if (!$duration) {
				throw new ApplicationLogicException('Invalid ban time');
			}
			elseif (time() > $duration) {
				throw new ApplicationLogicException('Ban time has already expired!');
			}

			return $duration;
		}
		elseif ($duration === "-1") {
			$duration = -1;

			return $duration;
		}
		else {
			$duration = WebRequest::postInt('duration') + time();

			return $duration;
		}
	}

	/**
	 * @param string $type
	 * @param string $target
	 *
	 * @throws ApplicationLogicException
	 */
	private function validateBanType($type, $target)
	{
		switch ($type) {
			case 'IP':
				$this->validateIpBan($target);

				return;
			case 'Name':
				// No validation needed here.
				return;
			case 'EMail':
				$this->validateEmailBanTarget($target);

				return;
			default:
				throw new ApplicationLogicException("Unknown ban type");
		}
	}

	/**
	 * Handles the POST method on the set action
	 *
	 * @throws ApplicationLogicException
	 * @throws Exception
	 */
	private function handlePostMethodForSetBan()
	{
		$reason = WebRequest::postString('banreason');
		$target = WebRequest::postString('target');

		// Checks whether there is a reason entered for ban.
		if ($reason === null || trim($reason) === "") {
			throw new ApplicationLogicException('You must specify a ban reason');
		}

		// Checks whether there is a target entered to ban.
		if ($target === null || trim($target) === "") {
			throw new ApplicationLogicException('You must specify a target to be banned');
		}

		// Validate ban duration
		$duration = $this->getBanDuration();

		// Validate ban type & target for that type
		$type = WebRequest::postString('type');
		$this->validateBanType($type, $target);

		$database = $this->getDatabase();

		if (count(Ban::getActiveBans($target, $database)) > 0) {
			throw new ApplicationLogicException('This target is already banned!');
		}

		$ban = new Ban();
		$ban->setDatabase($database);
		$ban->setActive(1);
		$ban->setType($type);
		$ban->setTarget($target);
		$ban->setUser(User::getCurrent($database)->getId());
		$ban->setReason($reason);
		$ban->setDuration($duration);

		$ban->save();

		Logger::banned($database, $ban, $reason);

		$this->getNotificationHelper()->banned($ban);
		SessionAlert::quick('Ban has been set.');

		$this->redirect('bans');
	}

	/**
	 * Handles the GET method on the set action
	 */
	protected function handleGetMethodForSetBan()
	{
		$this->setTemplate('bans/banform.tpl');

		$banType = WebRequest::getString('type');
		$banTarget = WebRequest::getInt('request');

		$database = $this->getDatabase();

		// if the parameters are null, skip loading a request.
		if ($banType === null
			|| !in_array($banType, array('IP', 'Name', 'EMail'))
			|| $banTarget === null
			|| $banTarget === 0
		) {
			$this->assign('bantarget', '');
			$this->assign('bantype', '');

			return;
		}

		// Set the ban type, which the user has indicated.
		$this->assign('bantype', $banType);

		// Attempt to resolve the correct target
		/** @var Request $request */
		$request = Request::getById($banTarget, $database);
		if ($request === false) {
			$this->assign('bantarget', '');

			return;
		}

		$realTarget = '';
		switch ($banType) {
			case 'EMail':
				$realTarget = $request->getEmail();
				break;
			case 'IP':
				$xffProvider = $this->getXffTrustProvider();
				$realTarget = $xffProvider->getTrustedClientIp($request->getIp(), $request->getForwardedIp());
				break;
			case 'Name':
				$realTarget = $request->getName();
				break;
		}

		$this->assign('bantarget', $realTarget);
	}

	/**
	 * Validates an IP ban target
	 *
	 * @param string $target
	 *
	 * @throws ApplicationLogicException
	 */
	private function validateIpBan($target)
	{
		$squidIpList = $this->getSiteConfiguration()->getSquidList();

		if (filter_var($target, FILTER_VALIDATE_IP) === false) {
			throw new ApplicationLogicException('Invalid target - IP address expected.');
		}

		if (in_array($target, $squidIpList)) {
			throw new ApplicationLogicException("This IP address is on the protected list of proxies, and cannot be banned.");
		}
	}

	/**
	 * Validates an email address as a ban target
	 *
	 * @param string $target
	 *
	 * @throws ApplicationLogicException
	 */
	private function validateEmailBanTarget($target)
	{
		if (filter_var($target, FILTER_VALIDATE_EMAIL) !== $target) {
			throw new ApplicationLogicException('Invalid target - email address expected.');
		}
	}

	/**
	 * @return Ban
	 * @throws ApplicationLogicException
	 */
	private function getBanForUnban()
	{
		$banId = WebRequest::getInt('id');
		if ($banId === null || $banId === 0) {
			throw new ApplicationLogicException("The ban ID appears to be missing. This is probably a bug.");
		}

		$ban = Ban::getActiveId($banId, $this->getDatabase());

		if ($ban === false) {
			throw new ApplicationLogicException("The specified ban is not currently active, or doesn't exist.");
		}

		return $ban;
	}
}