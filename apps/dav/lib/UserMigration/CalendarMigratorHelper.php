<?php

declare(strict_types=1);

/**
 * @copyright 2022 Christopher Ng <chrng8@gmail.com>
 *
 * @author Christopher Ng <chrng8@gmail.com>
 *
 * @license GNU AGPL version 3 or any later version
 *
 * This program is free software: you can redistribute it and/or modify
 * it under the terms of the GNU Affero General Public License as
 * published by the Free Software Foundation, either version 3 of the
 * License, or (at your option) any later version.
 *
 * This program is distributed in the hope that it will be useful,
 * but WITHOUT ANY WARRANTY; without even the implied warranty of
 * MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE. See the
 * GNU Affero General Public License for more details.
 *
 * You should have received a copy of the GNU Affero General Public License
 * along with this program. If not, see <http://www.gnu.org/licenses/>.
 *
 */

namespace OCA\DAV\UserMigration;

use OCA\DAV\CalDAV\CalDavBackend;
use OCA\DAV\CalDAV\ICSExportPlugin\ICSExportPlugin;
use OCA\DAV\CalDAV\Plugin as CalDAVPlugin;
use OCA\DAV\Connector\Sabre\Server as SabreDavServer;
use OCP\Calendar\ICalendar;
use OCP\Calendar\IManager as ICalendarManager;
use OCP\IUser;
use Sabre\DAV\Exception\BadRequest;
use Sabre\VObject\Component as VObjectComponent;
use Sabre\VObject\Component\VCalendar;
use Sabre\VObject\UUIDUtil;

class CalendarMigratorHelper {

	private CalDavBackend $calDavBackend;

	private ICalendarManager $calendarManager;

	// ICSExportPlugin is injected to use the mergeObjects() method and is not to be used as a SabreDAV server plugin
	private ICSExportPlugin $icsExportPlugin;

	private SabreDavServer $sabreDavServer;

	public function __construct(
		CalDavBackend $calDavBackend,
		ICalendarManager $calendarManager,
		ICSExportPlugin $icsExportPlugin,
		SabreDavServer $sabreDavServer,
	) {
		$this->calDavBackend = $calDavBackend;
		$this->calendarManager = $calendarManager;
		$this->icsExportPlugin = $icsExportPlugin;
		$this->sabreDavServer = $sabreDavServer;
	}

	/**
	 * @return array<int, array{name: string, data: string}>
	 *
	 * @throws CalendarMigratorException
	 */
	public function getCalendarExports(IUser $user): array {
		$userId = $user->getUID();
		$principalUri = CalendarMigrator::USERS_URI_ROOT . $userId;

		return array_values(array_filter(array_map(
			function (ICalendar $calendar) use ($userId) {
				try {
					return $this->getCalendarExportData($userId, $calendar);
				} catch (CalendarMigratorException $e) {
					throw new CalendarMigratorException();
				} catch (InvalidCalendarException $e) {
					// Invalid (e.g. deleted) calendars are not to be exported
				}
			},
			$this->calendarManager->getCalendarsForPrincipal($principalUri),
		)));
	}

	/**
	 * @return array{name: string, data: string}
	 *
	 * @throws CalendarMigratorException
	 * @throws InvalidCalendarException
	 */
	public function getCalendarExportData(string $userId, ICalendar $calendar): array {
		$calendarId = $calendar->getKey();
		$calendarInfo = $this->calDavBackend->getCalendarById($calendarId);

		if (!empty($calendarInfo)) {
			$uri = $calendarInfo['uri'];
			$path = CalDAVPlugin::CALENDAR_ROOT . "/$userId/$uri";

			// NOTE implementation below based on \Sabre\CalDAV\ICSExportPlugin::httpGet()

			$properties = $this->sabreDavServer->getProperties($path, [
				'{DAV:}resourcetype',
				'{DAV:}displayname',
				'{http://sabredav.org/ns}sync-token',
				'{DAV:}sync-token',
				'{http://apple.com/ns/ical/}calendar-color',
			]);

			// Filter out invalid (e.g. deleted) calendars
			if (!isset($properties['{DAV:}resourcetype']) || !$properties['{DAV:}resourcetype']->is('{' . CalDAVPlugin::NS_CALDAV . '}calendar')) {
				throw new InvalidCalendarException();
			}

			// NOTE implementation below based on \Sabre\CalDAV\ICSExportPlugin::generateResponse()

			$calDataProp = '{' . CalDAVPlugin::NS_CALDAV . '}calendar-data';
			$calendarNode = $this->sabreDavServer->tree->getNodeForPath($path);
			$nodes = $this->sabreDavServer->getPropertiesForPath($path, [$calDataProp], 1);

			$blobs = [];
			foreach ($nodes as $node) {
				if (isset($node[200][$calDataProp])) {
					$blobs[$node['href']] = $node[200][$calDataProp];
				}
			}
			unset($nodes);

			$mergedCalendar = $this->icsExportPlugin->mergeObjects(
				$properties,
				$blobs,
			);

			return [
				'name' => $calendarNode->getName(),
				'data' => $mergedCalendar->serialize(),
			];
		}

		throw new CalendarMigratorException();
	}


	/**
	 * Generate a non-conflicting uri by suffixing the initial uri for a principal,
	 * if it does not conflict then the original initial uri is returned
	 */
	public function generateCalendarUri(IUser $user, string $initialCalendarUri): string {
		$userId = $user->getUID();
		$principalUri = CalendarMigrator::USERS_URI_ROOT . $userId;
		$calendarUri = $initialCalendarUri;

		$existingCalendarUris = array_map(
			fn (ICalendar $calendar) => $calendar->getUri(),
			$this->calendarManager->getCalendarsForPrincipal($principalUri),
		);

		$acc = 1;
		while (in_array($calendarUri, $existingCalendarUris, true)) {
			$calendarUri = $initialCalendarUri . "-$acc";
			++$acc;
		}

		return $calendarUri;
	}

	/**
	 * @throws CalendarMigratorException
	 */
	public function importCalendar(IUser $user, string $calendarUri, VCalendar $vCalendar): void {
		$userId = $user->getUID();
		$principalUri = CalendarMigrator::USERS_URI_ROOT . $userId;

		//  Implementation below based on https://github.com/nextcloud/cdav-library/blob/9b67034837fad9e8f764d0152211d46565bf01f2/src/models/calendarHome.js#L151

		// Create calendar
		$calendarId = $this->calDavBackend->createCalendar($principalUri, $calendarUri, [
			'{DAV:}displayname' => (string)$vCalendar->{'X-WR-CALNAME'},
			'{http://apple.com/ns/ical/}calendar-color' => (string)$vCalendar->{'X-APPLE-CALENDAR-COLOR'},
			'components' => implode(
				',',
				array_reduce(
					$vCalendar->getComponents(),
					function (array $carryComponents, VObjectComponent $component) {
						if (!in_array($component->name, $carryComponents, true)) {
							$carryComponents[] = $component->name;
						}
						return $carryComponents;
					},
					[],
				)
			),
		]);

		// Add data to the created calendar e.g. VEVENT, VTODO
		foreach ($vCalendar->getBaseComponents() as $vObject) {
			// TODO add more data based on https://github.com/nextcloud/calendar-js/blob/main/src/parsers/icalendarParser.js#L187
			$calendarData = implode(
				"\n",
				[
					'BEGIN:' . $vCalendar->name,
					trim($vObject->serialize()),
					'END:' . $vCalendar->name,
				]
			);

			try {
				$this->calDavBackend->createCalendarObject(
					$calendarId,
					UUIDUtil::getUUID() . CalendarMigrator::FILENAME_EXT,
					$calendarData,
					CalDavBackend::CALENDAR_TYPE_CALENDAR,
				);
			} catch (BadRequest $e) {
				// Rollback creation of calendar on error
				$this->calDavBackend->deleteCalendar($calendarId, true);
			}
		}
	}
}
