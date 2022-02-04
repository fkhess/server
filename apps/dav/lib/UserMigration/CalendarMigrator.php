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

use function Safe\fopen;
use OC\Files\Filesystem;
use OC\Files\View;
use OCA\DAV\AppInfo\Application;
use OCA\DAV\CalDAV\CalDavBackend;
use OCA\DAV\CalDAV\ICSExportPlugin\ICSExportPlugin;
use OCA\DAV\CalDAV\Plugin as CalDAVPlugin;
use OCA\DAV\Connector\Sabre\CachingTree;
use OCA\DAV\Connector\Sabre\ExceptionLoggerPlugin;
use OCA\DAV\Connector\Sabre\Server as SabreDavServer;
use OCA\DAV\RootCollection;
use OCP\Calendar\IManager as ICalendarManager;
use OCP\IConfig;
use OCP\IL10N;
use OCP\IUser;
use Psr\Log\LoggerInterface;
use Sabre\VObject\Component\VCalendar;
use Sabre\VObject\Reader as VObjectReader;
use Safe\Exceptions\FilesystemException;
use Symfony\Component\Console\Output\OutputInterface;

class CalendarMigrator {

	private CalDavBackend $calDavBackend;

	private ICalendarManager $calendarManager;

	// ICSExportPlugin is injected to use the mergeObjects() method and is not to be used as a SabreDAV server plugin
	private ICSExportPlugin $icsExportPlugin;

	private SabreDavServer $sabreDavServer;

	private IConfig $config;

	private LoggerInterface $logger;

	private IL10N $l10n;

	private CalendarMigratorHelper $helper;

	public const USERS_URI_ROOT = 'principals/users/';

	public const FILENAME_EXT = '.ics';

	public function __construct(
		ICalendarManager $calendarManager,
		CalDavBackend $calDavBackend,
		ICSExportPlugin $icsExportPlugin,
		IConfig $config,
		LoggerInterface $logger,
		IL10N $l10n
	) {
		$this->calendarManager = $calendarManager;
		$this->calDavBackend = $calDavBackend;
		$this->icsExportPlugin = $icsExportPlugin;
		$this->config = $config;
		$this->logger = $logger;
		$this->l10n = $l10n;

		$root = new RootCollection();
		$this->sabreDavServer = new SabreDavServer(new CachingTree($root));
		$this->sabreDavServer->addPlugin(new CalDAVPlugin());
		$this->sabreDavServer->addPlugin(new ExceptionLoggerPlugin(Application::APP_ID, \OC::$server->getLogger()));

		$this->helper = new CalendarMigratorHelper(
			$calDavBackend,
			$calendarManager,
			$icsExportPlugin,
			$this->sabreDavServer,
		);
	}

	/**
	 * @throws CalendarMigratorException
	 */
	protected function writeExport(IUser $user, string $data, string $destDir, string $filename, OutputInterface $output): void {
		$userId = $user->getUID();

		// setup filesystem
		// Requesting the user folder will set it up if the user hasn't logged in before
		\OC::$server->getUserFolder($userId);
		Filesystem::initMountPoints($userId);

		$view = new View();

		if ($view->file_put_contents("$destDir/$filename", $data) === false) {
			throw new CalendarMigratorException('Could not export calendar');
		}

		$output->writeln("<info>✅ Exported calendar of <$userId> into $destDir/$filename</info>");
	}

	public function export(IUser $user, OutputInterface $output): void {
		$userId = $user->getUID();

		try {
			$calendarExports = $this->helper->getCalendarExports($user, $this->calDavBackend);
		} catch (CalendarMigratorException $e) {
			$output->writeln("<error>Error exporting <$userId> calendars</error>");
		}

		if (empty($calendarExports)) {
			$output->writeln("<info>User <$userId> has no calendars to export</info>");
			throw new CalendarMigratorException();
		}

		foreach ($calendarExports as ['name' => $name, 'data' => $data]) {
			// Set filename to sanitized calendar name appended with the date
			$filename = preg_replace('/[^a-zA-Z0-9-_ ]/um', '', $name) . '-' . date('Y-m-d') . CalendarMigrator::FILENAME_EXT;

			$this->writeExport(
				$user,
				$data,
				// TESTING directory does not automatically get created so just write to user directory, this will be put in a zip with all other user_migration data
				// "/$userId/export/$appId",
				"/$userId",
				$filename,
				$output,
			);
		}
	}

	/**
	 * @throws FilesystemException
	 * @throws CalendarMigratorException
	 */
	public function import(IUser $user, string $srcDir, string $filename, OutputInterface $output): void {
		$userId = $user->getUID();

		try {
			/** @var VCalendar $vCalendar */
			$vCalendar = VObjectReader::read(
				fopen("$srcDir/$filename", 'r'),
				VObjectReader::OPTION_FORGIVING,
			);
		} catch (FilesystemException $e) {
			throw new FilesystemException("Failed to read file: \"$srcDir/$filename\"");
		}

		$problems = $vCalendar->validate();

		if (empty($problems)) {
			$splitFilename = explode('-', $filename, 2);
			if (empty($splitFilename)) {
				$output->writeln("<error>Invalid filename, filename must be of the format: \"<calendar_name>-YYYY-MM-DD" . CalendarMigrator::FILENAME_EXT . "\"</error>");
				throw new CalendarMigratorException();
			}

			$this->helper->importCalendar(
				$user,
				$this->helper->generateCalendarUri($user, reset($splitFilename)),
				$vCalendar,
				$this->calDavBackend
			);
			$vCalendar->destroy();

			$output->writeln("<info>✅ Imported calendar \"$filename\" to account of <$userId></info>");
			throw new CalendarMigratorException();
		}

		throw new CalendarMigratorException("Invalid iCalendar data in $srcDir/$filename");
	}
}
