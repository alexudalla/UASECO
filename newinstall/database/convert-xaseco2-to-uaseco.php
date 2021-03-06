<?php
/*
 * Database converter
 * ~~~~~~~~~~~~~~~~~~
 * » Converts a XAseco2/1.03 database to a UASECO database.
 *
 * » Usage Linux
 *   ~~~~~~~~~~~
 *   /path/to/php -d max_execution_time=0 -d memory_limit=-1 ./newinstall/database/convert-xaseco2-to-uaseco.php [GAMEMODE]
 *
 * » Usage Windows
 *   ~~~~~~~~~~~~~
 *   \path\to\php.exe -d max_execution_time=0 -d memory_limit=-1 .\newinstall\database\convert-xaseco2-to-uaseco.php [GAMEMODE]
 *
 * ----------------------------------------------------------------------------------
 * Author:	undef.de
 * Date:	2017-05-08
 * Copyright:	2014 - 2017 by undef.de
 * ----------------------------------------------------------------------------------
 *
 * LICENSE: This program is free software: you can redistribute it and/or modify
 * it under the terms of the GNU General Public License as published by
 * the Free Software Foundation, either version 3 of the License, or
 * (at your option) any later version.
 *
 * This program is distributed in the hope that it will be useful,
 * but WITHOUT ANY WARRANTY; without even the implied warranty of
 * MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
 * GNU General Public License for more details.
 *
 * You should have received a copy of the GNU General Public License
 * along with this program.  If not, see <http://www.gnu.org/licenses/>.
 *
 * ----------------------------------------------------------------------------------
 *
 */


	// Include required classes
	require_once('includes/core/baseclass.class.php');
	require_once('includes/core/xmlparser.class.php');
	require_once('includes/core/database.class.php');


	// Define process settings
	date_default_timezone_set(@date_default_timezone_get());
	setlocale(LC_NUMERIC, 'C');
	mb_internal_encoding('UTF-8');


	$config_file = 'config/UASECO.xml';
	$convert = new Converter($config_file, (int)str_replace(array('[',']'), '', $argv[1]));


	// Connect to database
	$convert->connectDatabase();

	// Setup database tables
	$convert->checkDatabaseStructure();

	// Convert the tables
	$convert->convertPlayers();
	$convert->convertMaps();
	$convert->convertRecords();
	$convert->convertRsKarma();
	$convert->convertRsRank();
	$convert->convertRsTimes();

	$convert->console('Successfully converted the XAseco2 database.');


/*
#///////////////////////////////////////////////////////////////////////#
#									#
#///////////////////////////////////////////////////////////////////////#
*/
class Converter {
	public $settings;
	public $parser;
	public $db;

	public $gamemodes = array(
		1	=> 'Rounds',
		2	=> 'TimeAttack',
		3	=> 'Team',
		4	=> 'Laps',
		5	=> 'Cup',
	);

	/*
	#///////////////////////////////////////////////////////////////////////#
	#									#
	#///////////////////////////////////////////////////////////////////////#
	*/

	public function __construct ($config_file, $gamemode) {
		$this->parser = new XmlParser();

		$this->console('Trying to parse "'. $config_file .'"...');
		if ($settings = $this->parser->xmlToArray($config_file, true, true)) {
			// read the XML structure into an array
			$settings = $settings['SETTINGS'];

			// Read <mysql> settings and apply them
			$this->settings['dbms']['host'] = $settings['DBMS'][0]['HOST'][0];
			$this->settings['dbms']['login'] = $settings['DBMS'][0]['LOGIN'][0];
			$this->settings['dbms']['password'] = $settings['DBMS'][0]['PASSWORD'][0];
			$this->settings['dbms']['database'] = $settings['DBMS'][0]['DATABASE'][0];
			$this->settings['dbms']['table_prefix'] = $settings['DBMS'][0]['TABLE_PREFIX'][0];
			if (empty($this->settings['dbms']['table_prefix'])) {
				$this->settings['dbms']['table_prefix'] = 'uaseco_';
			}
			$this->console('...done!');
		}
		else {
			$this->console('Can not read "'. $config_file .'", make sure this file exists!');
			exit();
		}


		// Check for given Gamemode
		if ($gamemode >= 1 && $gamemode <= 5) {
			$this->console('Using Gamemode "'. $this->gamemodes[$gamemode] .'" for records and times!');
			$this->settings['gamemode'] = $gamemode;
		}
		else {
			$this->console('No correct Gamemode ID given, please read "readme.txt" first and try again!');
			exit();
		}
	}

	/*
	#///////////////////////////////////////////////////////////////////////#
	#									#
	#///////////////////////////////////////////////////////////////////////#
	*/

	public function convertPlayers () {
		$players = array();

		$continents[0] = '';
		$continents[1] = 'EU';
		$continents[2] = 'AF';
		$continents[3] = 'AS';
		$continents[4] = 'ME';
		$continents[5] = 'NA';
		$continents[6] = 'SA';
		$continents[7] = 'OC';

		$this->console('Converting table `players`, `players_extra`...');
		$query = "
		SELECT
			`pe`.`PlayerId`,
			`p`.`Login`,
			`p`.`NickName`,
			`p`.`Continent`,
			`p`.`Nation`,
			`p`.`UpdatedAt`,
			`p`.`Wins`,
			`p`.`TimePlayed`,
			`pe`.`Cps`,
			`pe`.`DediCps`,
			`pe`.`Donations`,
			`pe`.`Panels`,
			`pe`.`PanelBG`
		FROM `players` AS `p`
		LEFT JOIN `players_extra` AS `pe` ON `pe`.`PlayerId` = `p`.`Id`;
		";
		$result = $this->db->query($query);
		if ($result) {
			if ($result->num_rows > 0) {
				while ($row = $result->fetch_array(MYSQLI_ASSOC)) {
					$players[] = $row;
				}
			}
			$result->free_result();

			// Insert the Players into the new table `%prefix%players`
			$this->console(' > Working on table `players`...');
			$count['added'] = 0;
			$count['skipped'] = 0;
			foreach ($players as $row) {

				$query = "
				INSERT INTO `%prefix%players` (
					`PlayerId`,
					`Login`,
					`Nickname`,
					`Continent`,
					`Nation`,
					`LastVisit`,
					`Wins`,
					`Donations`,
					`TimePlayed`
				)
				VALUES (
					". $this->db->quote($row['PlayerId']) .",
					". $this->db->quote($row['Login']) .",
					". $this->db->quote($row['NickName']) .",
					". $this->db->quote($continents[$row['Continent']]) .",
					". $this->db->quote($row['Nation']) .",
					". $this->db->quote($row['UpdatedAt']) .",
					". $this->db->quote($row['Wins']) .",
					". $this->db->quote($row['Donations']) .",
					". $this->db->quote($row['TimePlayed']) ."
				);
				";
				$result = $this->db->query($query);
				if (!$result) {
//					$this->console('Could not insert Player "'. $row['Login'] .'": '. $this->db->errmsg() );
					$count['skipped'] += 1;
				}
				else {
					$count['added'] += 1;
				}
			}
			$this->db->commit();
			$this->console(' ...added '. $this->formatNumber($count['added']) .', skipped '. $this->formatNumber($count['skipped']) .' Players.');


			$this->console(' > Working on table `players_extra`...');
			$count['added'] = 0;
			$count['skipped'] = 0;
			foreach ($players as $row) {

				// Setup the stored settings for each plugin
				$settings = array(
					'PluginCheckpoints' => array(
						'LocalCheckpointTracking'	=> $row['Cps'],
						'DedimaniaCheckpointTracking'	=> $row['DediCps'],
					),
					'PluginPanels' => array(
						'Panels'			=> $row['Panels'],
						'PanelBG'			=> $row['PanelBG'],
					),
				);

				foreach ($settings as $plugin => $entries) {
					foreach ($entries as $key => $value) {
						$query = "
						INSERT INTO `%prefix%settings` (
							`Plugin`,
							`PlayerId`,
							`Key`,
							`Value`
						)
						VALUES (
							". $this->db->quote($plugin) .",
							". $this->db->quote($row['PlayerId']) .",
							". $this->db->quote($key) .",
							". $this->db->quote(serialize($value)) ."
						)
						ON DUPLICATE KEY UPDATE
							`Value` = VALUES(`Value`);
						";
						$result = $this->db->query($query);
						if (!$result) {
//							$this->console('Could not insert setting for Player "'. $row['Login'] .'": '. $this->db->errmsg() );
							$count['skipped'] += 1;
						}
						else {
							$count['added'] += 1;
						}
					}
				}
			}
			$this->db->commit();
			$this->console(' ...added '. $this->formatNumber($count['added']) .', skipped '. $this->formatNumber($count['skipped']) .' settings.');

		}
		else {
			$this->console('ERROR: No entries found in `players` and `players_extra`!');
		}


		// Check for Records-Eyepiece columns
		$this->console('Checking for Records-Eyepiece data in `players_extra`...');
		$players = array();
		$query = "
		SELECT
			`PlayerId`,
			`MostFinished`,
			`MostRecords`,
			`RoundPoints`,
			`TeamPoints`,
			`Visits`,
			`WinningPayout`
		FROM `players_extra`;
		";
		$result = $this->db->query($query);
		if ($result) {
			if ($result->num_rows > 0) {
				while ($row = $result->fetch_array(MYSQLI_ASSOC)) {
					$players[] = $row;
				}

				// Add required columns
				$this->db->query('ALTER TABLE `%prefix%players` ADD `MostFinished` MEDIUMINT(3) UNSIGNED DEFAULT "0" COMMENT "Added by plugin.records_eyepiece.php", ADD INDEX (`MostFinished`);');
				$this->db->query('ALTER TABLE `%prefix%players` ADD `MostRecords` MEDIUMINT(3) UNSIGNED DEFAULT "0" COMMENT "Added by plugin.records_eyepiece.php", ADD INDEX (`MostRecords`);');
				$this->db->query('ALTER TABLE `%prefix%players` ADD `RoundPoints` MEDIUMINT(3) UNSIGNED DEFAULT "0" COMMENT "Added by plugin.records_eyepiece.php", ADD INDEX (`RoundPoints`);');
				$this->db->query('ALTER TABLE `%prefix%players` ADD `TeamPoints` MEDIUMINT(3) UNSIGNED DEFAULT "0" COMMENT "Added by plugin.records_eyepiece.php", ADD INDEX (`TeamPoints`);');
				$this->db->query('ALTER TABLE `%prefix%players` ADD `WinningPayout` MEDIUMINT(3) UNSIGNED DEFAULT "0" COMMENT "Added by plugin.records_eyepiece.php", ADD INDEX (`WinningPayout`);');
			}
			$result->free_result();

			foreach ($players as $row) {
				$query = "
				UPDATE `%prefix%players`
				SET
					`Visits` = ". $this->db->quote($row['Visits']) .",
					`MostFinished` = ". $this->db->quote($row['MostFinished']) .",
					`MostRecords` = ". $this->db->quote($row['MostRecords']) .",
					`RoundPoints` = ". $this->db->quote($row['RoundPoints']) .",
					`TeamPoints` = ". $this->db->quote($row['TeamPoints']) .",
					`WinningPayout` = ". $this->db->quote($row['WinningPayout']) ."
				WHERE `PlayerId` = ". $this->db->quote($row['PlayerId']) ."
				LIMIT 1;
				";
				$result = $this->db->query($query);
//				if (!$result) {
//					$this->console('Could not update Player "'. $row['PlayerId'] .'": '. $this->db->errmsg() );
//				}
			}
			$this->db->commit();
			$this->console('...finished converting Records-Eyepiece entries.');
		}
		else {
			$this->console('...no entries found from Records-Eyepiece.');
		}
	}

	/*
	#///////////////////////////////////////////////////////////////////////#
	#									#
	#///////////////////////////////////////////////////////////////////////#
	*/

	public function convertMaps () {
		$maps = array();
		$autor_logins = array();

		$this->console('Converting table `maps`...');
		$query = "
		SELECT
			`Id`,
			`Uid`,
			`Name`,
			`Author`,
			`Environment`
		FROM `maps`;
		";
		$result = $this->db->query($query);
		if ($result) {
			if ($result->num_rows > 0) {
				while ($row = $result->fetch_array(MYSQLI_ASSOC)) {
					$maps[] = $row;
					$autor_logins[$row['Author']] = 0;
				}
			}
			$result->free_result();


			// Insert the AuthorLogins into the new table `%prefix%authors`
			foreach ($autor_logins as $login => $value) {
				$query = "
				INSERT INTO `%prefix%authors` (
					`Login`
				)
				VALUES (
					". $this->db->quote($login) ."
				);
				";
				$result = $this->db->query($query);
				if ($result) {
					$autor_logins[$login] = $this->db->lastid();
				}
//				else {
//					$this->console('Could not insert author "'. $login .'": '. $this->db->errmsg() );
//				}
			}


			// Insert the Maps into the new table `%prefix%maps`
			$count['added'] = 0;
			$count['skipped'] = 0;
			foreach ($maps as $row) {
				$query = "
				INSERT INTO `%prefix%maps` (
					`MapId`,
					`Uid`,
					`Name`,
					`AuthorId`,
					`Environment`
				)
				VALUES (
					". $this->db->quote($row['Id']) .",
					". $this->db->quote($row['Uid']) .",
					". $this->db->quote($row['Name']) .",
					". $this->db->quote($autor_logins[$row['Author']]) .",
					". $this->db->quote($row['Environment']) ."
				);
				";
				$result = $this->db->query($query);
				if (!$result) {
					$this->console('Could not insert map "'. $row['Name'] .'": '. $this->db->errmsg() );
					$count['skipped'] += 1;
				}
				else {
					$count['added'] += 1;
				}
			}
			$this->db->commit();
			$this->console(' ...added '. $this->formatNumber($count['added']) .', skipped '. $this->formatNumber($count['skipped']) .' Maps.');
		}
		else {
			$this->console('ERROR: No entries found in `maps`!');
		}
	}

	/*
	#///////////////////////////////////////////////////////////////////////#
	#									#
	#///////////////////////////////////////////////////////////////////////#
	*/

	public function convertRecords () {

		$this->console('Converting table `records`...');
		$query = "
		SELECT
			`MapId`,
			`PlayerId`,
			`Score`,
			`Date`,
			`Checkpoints`
		FROM `records`;
		";
		$result = $this->db->query($query);
		if ($result) {
			if ($result->num_rows > 0) {
				$count['added'] = 0;
				$count['skipped'] = 0;
				while ($row = $result->fetch_array(MYSQLI_ASSOC)) {

					// Insert the records into the new table `%prefix%records`
					$insert = "
					INSERT INTO `%prefix%records` (
						`MapId`,
						`PlayerId`,
						`GamemodeId`,
						`Date`,
						`Score`,
						`Checkpoints`
					)
					VALUES (
						". $this->db->quote($row['MapId']) .",
						". $this->db->quote($row['PlayerId']) .",
						". $this->db->quote($this->settings['gamemode']) .",
						". $this->db->quote($row['Date']) .",
						". $this->db->quote($row['Score']) .",
						". $this->db->quote($row['Checkpoints']) ."
					);
					";
					$insert_result = $this->db->query($insert);
					if (!$insert_result) {
//						$this->console('Could not insert record: '. $this->db->errmsg() );
						$count['skipped'] += 1;
					}
					else {
						$count['added'] += 1;
					}
				}
				$this->db->commit();
				$this->console(' ...added '. $this->formatNumber($count['added']) .', skipped '. $this->formatNumber($count['skipped']) .' Records'. ($count['skipped'] > 0 ? ', because Map not found in Database' : '') .'.');
			}
			$result->free_result();
		}
		else {
			$this->console('ERROR: No entries found in `records`!');
		}
	}

	/*
	#///////////////////////////////////////////////////////////////////////#
	#									#
	#///////////////////////////////////////////////////////////////////////#
	*/

	public function convertRsKarma () {

		$this->console('Converting table `rs_karma`...');
		$query = "
		SELECT
			`MapId`,
			`PlayerId`,
			`Score`
		FROM `rs_karma`;
		";
		$result = $this->db->query($query);
		if ($result) {
			if ($result->num_rows > 0) {
				$count['added'] = 0;
				$count['skipped'] = 0;
				while ($row = $result->fetch_array(MYSQLI_ASSOC)) {

					// Insert the karma into the new table `%prefix%ratings`
					$insert = "
					INSERT INTO `%prefix%ratings` (
						`MapId`,
						`PlayerId`,
						`Date`,
						`Score`
					)
					VALUES (
						". $this->db->quote($row['MapId']) .",
						". $this->db->quote($row['PlayerId']) .",
						". $this->db->quote(date('Y-m-d H:i:s', time() - date('Z'))) .",
						". $this->db->quote($row['Score']) ."
					);
					";
					$insert_result = $this->db->query($insert);
					if (!$insert_result) {
//						$this->console('Could not insert karma: '. $this->db->errmsg() );
						$count['skipped'] += 1;
					}
					else {
						$count['added'] += 1;
					}
				}
				$this->db->commit();
				$this->console(' ...added '. $this->formatNumber($count['added']) .', skipped '. $this->formatNumber($count['skipped']) .' Karma votes'. ($count['skipped'] > 0 ? ', because Map not found in Database' : '') .'.');
			}
			$result->free_result();
		}
		else {
			$this->console('ERROR: No entries found in `rs_karma`!');
		}
	}

	/*
	#///////////////////////////////////////////////////////////////////////#
	#									#
	#///////////////////////////////////////////////////////////////////////#
	*/

	public function convertRsRank () {

		$this->console('Converting table `rs_rank`...');
		$query = "
		SELECT
			`PlayerId`,
			`Avg`
		FROM `rs_rank`;
		";
		$result = $this->db->query($query);
		if ($result) {
			if ($result->num_rows > 0) {
				$count['added'] = 0;
				$count['skipped'] = 0;
				while ($row = $result->fetch_array(MYSQLI_ASSOC)) {

					// Insert the rank into the new table `%prefix%rankings`
					$insert = "
					INSERT INTO `%prefix%rankings` (
						`PlayerId`,
						`Average`
					)
					VALUES (
						". $this->db->quote($row['PlayerId']) .",
						". $this->db->quote($row['Avg']) ."
					);
					";
					$insert_result = $this->db->query($insert);
					if (!$insert_result) {
//						$this->console('Could not insert rank: '. $this->db->errmsg() );
						$count['skipped'] += 1;
					}
					else {
						$count['added'] += 1;
					}
				}
				$this->db->commit();
				$this->console(' ...added '. $this->formatNumber($count['added']) .', skipped '. $this->formatNumber($count['skipped']) .' Player ranks'. ($count['skipped'] > 0 ? ', because Map not found in Database' : '') .'.');
			}
			$result->free_result();
		}
		else {
			$this->console('ERROR: No entries found in `rs_rank`!');
		}
	}

	/*
	#///////////////////////////////////////////////////////////////////////#
	#									#
	#///////////////////////////////////////////////////////////////////////#
	*/

	public function convertRsTimes () {

		$this->console('Converting table `rs_times`...');
		$this->console('NOTE: Adding only times into new table, from Maps they are also in the database!');
		$query = "
		SELECT
			`MapId`,
			`PlayerId`,
			`Score`,
			`Date`,
			`Checkpoints`
		FROM `rs_times`;
		";
		$result = $this->db->query($query);
		if ($result) {
			if ($result->num_rows > 0) {
				$count['added'] = 0;
				$count['skipped'] = 0;
				while ($row = $result->fetch_array(MYSQLI_ASSOC)) {

					// Insert the times into the new table `%prefix%times`
					$insert = "
					INSERT INTO `%prefix%times` (
						`MapId`,
						`PlayerId`,
						`GamemodeId`,
						`Date`,
						`Score`,
						`Checkpoints`
					)
					VALUES (
						". $this->db->quote($row['MapId']) .",
						". $this->db->quote($row['PlayerId']) .",
						". $this->db->quote($this->settings['gamemode']) .",
						". $this->db->quote(date('Y-m-d H:i:s', $row['Date'] - date('Z'))) .",
						". $this->db->quote($row['Score']) .",
						". $this->db->quote($row['Checkpoints']) ."
					);
					";
					$insert_result = $this->db->query($insert);
					if (!$insert_result) {
//						$this->console('Could not insert time: '. $this->db->errmsg() );
						$count['skipped'] += 1;
					}
					else {
						$count['added'] += 1;
					}
				}
				$this->db->commit();
				$this->console(' ...added '. $this->formatNumber($count['added']) .', skipped '. $this->formatNumber($count['skipped']) .' Times'. ($count['skipped'] > 0 ? ', because Map not found in Database' : '') .'.');
			}
			$result->free_result();
		}
		else {
			$this->console('ERROR: No entries found in `rs_times`!');
		}
	}

	/*
	#///////////////////////////////////////////////////////////////////////#
	#									#
	#///////////////////////////////////////////////////////////////////////#
	*/

	public function connectDatabase () {

		$this->console('Try to connect to MySQL server on "'. $this->settings['dbms']['host'] .'" with database "'. $this->settings['dbms']['database'] .'", login "'. $this->settings['dbms']['login'] .'" and password "'. $this->settings['dbms']['password'] .'"...');
		$settings = array(
			'host'			=> $this->settings['dbms']['host'],
	                'login'			=> $this->settings['dbms']['login'],
	                'password'		=> $this->settings['dbms']['password'],
			'database'		=> $this->settings['dbms']['database'],
			'table_prefix'		=> $this->settings['dbms']['table_prefix'],
			'autocommit'		=> false,			// NO AUTOCOMMIT!
			'charset'		=> 'utf8mb4',
			'collate'		=> 'utf8mb4_unicode_ci',
			'debug'			=> false,
		);

		// Connect
		$this->db = new Database($settings);
		$this->console('...connection established successfully!');
	}

	/*
	#///////////////////////////////////////////////////////////////////////#
	#									#
	#///////////////////////////////////////////////////////////////////////#
	*/

	public function checkDatabaseStructure () {

		// Create tables
		$this->console('Checking database structure:');

		// Create tables
		$this->console('> Checking table `'. $this->settings['dbms']['table_prefix'] .'authors`');
		$query = "
		CREATE TABLE IF NOT EXISTS `%prefix%authors` (
		  `AuthorId` mediumint(3) unsigned AUTO_INCREMENT,
		  `Login` varchar(64) COLLATE 'utf8mb4_unicode_ci' DEFAULT '',
		  `Nickname` varchar(100) COLLATE 'utf8mb4_unicode_ci' DEFAULT '',
		  `Zone` varchar(256) COLLATE 'utf8mb4_unicode_ci' DEFAULT '',
		  `Continent` varchar(2) COLLATE 'utf8mb4_unicode_ci' DEFAULT '',
		  `Nation` varchar(3) COLLATE 'utf8mb4_unicode_ci' DEFAULT '',
		  PRIMARY KEY (`AuthorId`),
		  UNIQUE KEY `Login` (`Login`),
		  KEY `Continent` (`Continent`),
		  KEY `Nation` (`Nation`)
		) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci AUTO_INCREMENT=1;
		";
		$this->db->query($query);


		$this->console('> Checking table `'. $this->settings['dbms']['table_prefix'] .'maphistory`');
		$query = "
		CREATE TABLE IF NOT EXISTS `%prefix%maphistory` (
		  `MapId` mediumint(3) unsigned NOT NULL,
		  `Date` datetime DEFAULT '1970-01-01 00:00:00',
		  KEY `MapId` (`MapId`),
		  KEY `Date` (`Date`)
		) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
		";
		$this->db->query($query);


		$this->console('> Checking table `'. $this->settings['dbms']['table_prefix'] .'maps`');
		$query = "
		CREATE TABLE IF NOT EXISTS `%prefix%maps` (
		  `MapId` mediumint(3) UNSIGNED AUTO_INCREMENT,
		  `Uid` varchar(27) COLLATE 'utf8mb4_unicode_ci' DEFAULT '',
		  `Filename` text COLLATE 'utf8mb4_unicode_ci',
		  `Name` varchar(100) COLLATE 'utf8mb4_unicode_ci' DEFAULT '',
		  `Comment` text COLLATE 'utf8mb4_unicode_ci',
		  `AuthorId` mediumint(3) unsigned DEFAULT '0',
		  `AuthorScore` int(4) unsigned DEFAULT '0',
		  `AuthorTime` int(4) unsigned DEFAULT '0',
		  `GoldTime` int(4) unsigned DEFAULT '0',
		  `SilverTime` int(4) unsigned DEFAULT '0',
		  `BronzeTime` int(4) unsigned DEFAULT '0',
		  `Environment` varchar(10) COLLATE 'utf8mb4_unicode_ci' DEFAULT '',
		  `Mood` enum('unknown','Sunrise','Day','Sunset','Night') COLLATE 'utf8mb4_unicode_ci' NOT NULL,
		  `Cost` mediumint(3) unsigned DEFAULT '0',
		  `Type` varchar(32) COLLATE 'utf8mb4_unicode_ci' DEFAULT '',
		  `Style` varchar(32) COLLATE 'utf8mb4_unicode_ci' DEFAULT '',
		  `MultiLap` enum('false','true') COLLATE 'utf8mb4_unicode_ci' NOT NULL,
		  `NbLaps` tinyint(1) unsigned DEFAULT '0',
		  `NbCheckpoints` tinyint(1) unsigned DEFAULT '0',
		  `Validated` enum('null','false','true') COLLATE 'utf8mb4_unicode_ci' NOT NULL,
		  `ExeVersion` varchar(16) COLLATE 'utf8mb4_unicode_ci' DEFAULT '',
		  `ExeBuild` varchar(32) COLLATE 'utf8mb4_unicode_ci' DEFAULT '',
		  `ModName` varchar(64) COLLATE 'utf8mb4_unicode_ci' DEFAULT '',
		  `ModFile` varchar(256) COLLATE 'utf8mb4_unicode_ci' DEFAULT '',
		  `ModUrl` text COLLATE 'utf8mb4_unicode_ci',
		  `SongFile` varchar(256) COLLATE 'utf8mb4_unicode_ci' DEFAULT '',
		  `SongUrl` text COLLATE 'utf8mb4_unicode_ci',
		  PRIMARY KEY (`MapId`),
		  UNIQUE KEY `Uid` (`Uid`),
		  KEY `AuthorId` (`AuthorId`),
		  KEY `AuthorScore` (`AuthorScore`),
		  KEY `AuthorTime` (`AuthorTime`),
		  KEY `GoldTime` (`GoldTime`),
		  KEY `SilverTime` (`SilverTime`),
		  KEY `BronzeTime` (`BronzeTime`),
		  KEY `Environment` (`Environment`),
		  KEY `Mood` (`Mood`),
		  KEY `MultiLap` (`MultiLap`),
		  KEY `NbLaps` (`NbLaps`),
		  KEY `NbCheckpoints` (`NbCheckpoints`)
		) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci AUTO_INCREMENT=1;
		";
		$this->db->query($query);


		$this->console('> Checking table `'. $this->settings['dbms']['table_prefix'] .'players`');
		$query = "
		CREATE TABLE IF NOT EXISTS `%prefix%players` (
		  `PlayerId` mediumint(3) unsigned AUTO_INCREMENT,
		  `Login` varchar(64) COLLATE 'utf8mb4_unicode_ci' DEFAULT '',
		  `Nickname` varchar(100) COLLATE 'utf8mb4_unicode_ci' DEFAULT '',
		  `Zone` varchar(256) COLLATE 'utf8mb4_unicode_ci' DEFAULT '',
		  `Continent` varchar(2) COLLATE 'utf8mb4_unicode_ci' DEFAULT '',
		  `Nation` varchar(3) COLLATE 'utf8mb4_unicode_ci' DEFAULT '',
		  `LastVisit` datetime DEFAULT '1970-01-01 00:00:00',
		  `Visits` mediumint(3) unsigned DEFAULT '0',
		  `Wins` mediumint(3) unsigned DEFAULT '0',
		  `Donations` mediumint(3) unsigned DEFAULT '0',
		  `TimePlayed` int(4) unsigned DEFAULT '0',
		  PRIMARY KEY (`PlayerId`),
		  UNIQUE KEY `Login` (`Login`),
		  KEY `Continent` (`Continent`),
		  KEY `Nation` (`Nation`),
		  KEY `LastVisit` (`LastVisit`),
		  KEY `Visits` (`Visits`),
		  KEY `Wins` (`Wins`),
		  KEY `Donations` (`Donations`),
		  KEY `TimePlayed` (`TimePlayed`)
		) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci AUTO_INCREMENT=1;
		";
		$this->db->query($query);


		$this->console('> Checking table `'. $this->settings['dbms']['table_prefix'] .'playlist`');
		$query = "
		CREATE TABLE IF NOT EXISTS `%prefix%playlist` (
		  `Timestamp` decimal(17,3) unsigned DEFAULT '0.000',
		  `MapId` mediumint(3) unsigned DEFAULT '0',
		  `PlayerId` mediumint(3) unsigned DEFAULT '0',
		  `Method` enum('select','vote','pay','add') COLLATE 'utf8mb4_unicode_ci' DEFAULT 'select',
		  KEY `Timestamp` (`Timestamp`),
		  KEY `MapId` (`MapId`),
		  KEY `PlayerId` (`PlayerId`),
		  KEY `Method` (`Method`)
		) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
		";
		$this->db->query($query);


		$this->console('> Checking table `'. $this->settings['dbms']['table_prefix'] .'rankings`');
		$query = "
		CREATE TABLE IF NOT EXISTS `%prefix%rankings` (
		  `PlayerId` mediumint(3) unsigned DEFAULT '0',
		  `Average` int(4) unsigned DEFAULT '0',
		  PRIMARY KEY (`PlayerId`)
		) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
		";
		$this->db->query($query);


		$this->console('> Checking table `'. $this->settings['dbms']['table_prefix'] .'ratings`');
		$query = "
		CREATE TABLE IF NOT EXISTS `%prefix%ratings` (
		  `MapId` mediumint(3) unsigned DEFAULT '0',
		  `PlayerId` mediumint(3) unsigned DEFAULT '0',
		  `Date` timestamp DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
		  `Score` tinyint(1) signed DEFAULT '0',
		  PRIMARY KEY (`MapId`,`PlayerId`),
		  KEY `MapId` (`MapId`),
		  KEY `PlayerId` (`PlayerId`),
		  KEY `Date` (`Date`),
		  KEY `Score` (`Score`)
		) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
		";
		$this->db->query($query);


		$this->console('> Checking table `'. $this->settings['dbms']['table_prefix'] .'records`');
		$query = "
		CREATE TABLE IF NOT EXISTS `%prefix%records` (
		  `MapId` mediumint(3) unsigned DEFAULT '0',
		  `PlayerId` mediumint(3) unsigned DEFAULT '0',
		  `GamemodeId` tinyint(1) unsigned DEFAULT '0',
		  `Date` datetime DEFAULT '1970-01-01 00:00:00',
		  `Score` int(4) unsigned DEFAULT '0',
		  `Checkpoints` text COLLATE 'utf8mb4_unicode_ci',
		  PRIMARY KEY (`MapId`,`PlayerId`,`GamemodeId`),
		  KEY `MapId` (`MapId`),
		  KEY `PlayerId` (`PlayerId`),
		  KEY `GamemodeId` (`GamemodeId`),
		  KEY `Date` (`Date`),
		  KEY `Score` (`Score`)
		) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
		";
		$this->db->query($query);


		$this->console('> Checking table `'. $this->settings['dbms']['table_prefix'] .'settings`');
		$query = "
		CREATE TABLE IF NOT EXISTS `%prefix%settings` (
		  `Plugin` varchar(64) COLLATE 'utf8mb4_unicode_ci' NOT NULL,
		  `PlayerId` mediumint(3) unsigned DEFAULT '0',
		  `Key` varchar(64) COLLATE 'utf8mb4_unicode_ci' NOT NULL,
		  `Value` text COLLATE 'utf8mb4_unicode_ci',
		  PRIMARY KEY (`Plugin`,`PlayerId`,`Key`),
		  KEY `PlayerId` (`PlayerId`)
		) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
		";
		$this->db->query($query);


		$this->console('> Checking table `'. $this->settings['dbms']['table_prefix'] .'times`');
		$query = "
		CREATE TABLE IF NOT EXISTS `%prefix%times` (
		  `MapId` mediumint(3) unsigned DEFAULT '0',
		  `PlayerId` mediumint(3) unsigned DEFAULT '0',
		  `GamemodeId` tinyint(1) unsigned DEFAULT '0',
		  `Date` datetime DEFAULT '1970-01-01 00:00:00',
		  `Score` int(4) unsigned DEFAULT '0',
		  `Checkpoints` text COLLATE 'utf8mb4_unicode_ci',
		  PRIMARY KEY (`MapId`,`PlayerId`,`GamemodeId`,`Score`),
		  KEY `MapId` (`MapId`),
		  KEY `PlayerId` (`PlayerId`),
		  KEY `GamemodeId` (`GamemodeId`),
		  KEY `Date` (`Date`),
		  KEY `Score` (`Score`)
		) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
		";
		$this->db->query($query);


		// Check for main tables
		$tables = array();
		$result = $this->db->query('SHOW TABLES;');
		if ($result) {
			while ($row = $result->fetch_row()) {
				$tables[] = $row[0];
			}
			$result->free_result();
		}

		$check_step2 = array();
		$check_step2['authors']		= in_array($this->settings['dbms']['table_prefix'] .'authors', $tables);
		$check_step2['maphistory']	= in_array($this->settings['dbms']['table_prefix'] .'maphistory', $tables);
		$check_step2['maps']		= in_array($this->settings['dbms']['table_prefix'] .'maps', $tables);
		$check_step2['players']		= in_array($this->settings['dbms']['table_prefix'] .'players', $tables);
		$check_step2['playlist']	= in_array($this->settings['dbms']['table_prefix'] .'playlist', $tables);
		$check_step2['rankings']	= in_array($this->settings['dbms']['table_prefix'] .'rankings', $tables);
		$check_step2['ratings']		= in_array($this->settings['dbms']['table_prefix'] .'ratings', $tables);
		$check_step2['records']		= in_array($this->settings['dbms']['table_prefix'] .'records', $tables);
		$check_step2['settings']	= in_array($this->settings['dbms']['table_prefix'] .'settings', $tables);
		$check_step2['times']		= in_array($this->settings['dbms']['table_prefix'] .'times', $tables);
		if (!$check_step2['authors'] && !$check_step2['maphistory'] && !$check_step2['maps'] && !$check_step2['players'] && !$check_step2['playlist'] && !$check_step2['rankings'] && !$check_step2['ratings'] && !$check_step2['records'] && !$check_step2['settings'] && !$check_step2['times']) {
			trigger_error('[Database] Table structure incorrect, automatic setup failed!', E_USER_ERROR);
		}


		$this->console(' > Adding foreign key constraints for table `'. $this->settings['dbms']['table_prefix'] .'maphistory`');
		$query = "
		ALTER TABLE `%prefix%maphistory`
		  ADD CONSTRAINT `%prefix%maphistory_ibfk_1` FOREIGN KEY (`MapId`) REFERENCES `%prefix%maps` (`MapId`) ON DELETE CASCADE ON UPDATE CASCADE;
		";
		$result = $this->db->query($query);
		if (!$result) {
			trigger_error('Failed to add required foreign key constraints for table `'. $this->settings['dbms']['table_prefix'] .'maphistory` '. $this->db->errmsg(), E_USER_ERROR);
		}


		$this->console(' > Adding foreign key constraints for table `'. $this->settings['dbms']['table_prefix'] .'maps`');
		$query = "
		ALTER TABLE `%prefix%maps`
		  ADD CONSTRAINT `%prefix%maps_ibfk_1` FOREIGN KEY (`AuthorId`) REFERENCES `%prefix%authors` (`AuthorId`);
		";
		$result = $this->db->query($query);
		if (!$result) {
			trigger_error('Failed to add required foreign key constraints for table `'. $this->settings['dbms']['table_prefix'] .'maps` '. $this->db->errmsg(), E_USER_ERROR);
		}


		$this->console(' > Adding foreign key constraints for table `'. $this->settings['dbms']['table_prefix'] .'playlist`');
		$query = "
		ALTER TABLE `%prefix%playlist`
		  ADD CONSTRAINT `%prefix%playlist_ibfk_1` FOREIGN KEY (`MapId`) REFERENCES `%prefix%maps` (`MapId`) ON DELETE CASCADE ON UPDATE CASCADE,
		  ADD CONSTRAINT `%prefix%playlist_ibfk_2` FOREIGN KEY (`PlayerId`) REFERENCES `%prefix%players` (`PlayerId`) ON DELETE CASCADE ON UPDATE CASCADE;
		";
		$result = $this->db->query($query);
		if (!$result) {
			trigger_error('Failed to add required foreign key constraints for table `'. $this->settings['dbms']['table_prefix'] .'playlist` '. $this->db->errmsg(), E_USER_ERROR);
		}


		$this->console(' > Adding foreign key constraints for table `'. $this->settings['dbms']['table_prefix'] .'rankings`');
		$query = "
		ALTER TABLE `%prefix%rankings`
		  ADD CONSTRAINT `%prefix%ranks_ibfk_1` FOREIGN KEY (`PlayerId`) REFERENCES `%prefix%players` (`PlayerId`) ON DELETE CASCADE ON UPDATE CASCADE;
		";
		$result = $this->db->query($query);
		if (!$result) {
			trigger_error('Failed to add required foreign key constraints for table `'. $this->settings['dbms']['table_prefix'] .'rankings` '. $this->db->errmsg(), E_USER_ERROR);
		}


		$this->console(' > Adding foreign key constraints for table `'. $this->settings['dbms']['table_prefix'] .'ratings`');
		$query = "
		ALTER TABLE `%prefix%ratings`
		  ADD CONSTRAINT `%prefix%ratings_ibfk_2` FOREIGN KEY (`PlayerId`) REFERENCES `%prefix%players` (`PlayerId`) ON DELETE CASCADE ON UPDATE CASCADE,
		  ADD CONSTRAINT `%prefix%ratings_ibfk_1` FOREIGN KEY (`MapId`) REFERENCES `%prefix%maps` (`MapId`) ON DELETE CASCADE ON UPDATE CASCADE;
		";
		$result = $this->db->query($query);
		if (!$result) {
			trigger_error('Failed to add required foreign key constraints: '. $this->db->errmsg(), E_USER_ERROR);
		}


		$this->console(' > Adding foreign key constraints for table `'. $this->settings['dbms']['table_prefix'] .'records`');
		$query = "
		ALTER TABLE `%prefix%records`
		  ADD CONSTRAINT `%prefix%records_ibfk_2` FOREIGN KEY (`PlayerId`) REFERENCES `%prefix%players` (`PlayerId`) ON DELETE CASCADE ON UPDATE CASCADE,
		  ADD CONSTRAINT `%prefix%records_ibfk_1` FOREIGN KEY (`MapId`) REFERENCES `%prefix%maps` (`MapId`) ON DELETE CASCADE ON UPDATE CASCADE;
		";
		$result = $this->db->query($query);
		if (!$result) {
			trigger_error('Failed to add required foreign key constraints: '. $this->db->errmsg(), E_USER_ERROR);
		}


		$this->console(' > Adding foreign key constraints for table `'. $this->settings['dbms']['table_prefix'] .'settings`');
		$query = "
		ALTER TABLE `%prefix%settings`
		  ADD CONSTRAINT `%prefix%settings_ibfk_1` FOREIGN KEY (`PlayerId`) REFERENCES `%prefix%players` (`PlayerId`) ON DELETE CASCADE ON UPDATE CASCADE;
		";
		$result = $this->db->query($query);
		if (!$result) {
			trigger_error('Failed to add required foreign key constraints for table `'. $this->settings['dbms']['table_prefix'] .'settings` '. $this->db->errmsg(), E_USER_ERROR);
		}


		$this->console(' > Adding foreign key constraints for table `'. $this->settings['dbms']['table_prefix'] .'times`');
		$query = "
		ALTER TABLE `%prefix%times`
		  ADD CONSTRAINT `%prefix%times_ibfk_2` FOREIGN KEY (`PlayerId`) REFERENCES `%prefix%players` (`PlayerId`) ON DELETE CASCADE ON UPDATE CASCADE,
		  ADD CONSTRAINT `%prefix%times_ibfk_1` FOREIGN KEY (`MapId`) REFERENCES `%prefix%maps` (`MapId`) ON DELETE CASCADE ON UPDATE CASCADE;
		";
		$result = $this->db->query($query);
		if (!$result) {
			trigger_error('Failed to add required foreign key constraints for table `'. $this->settings['dbms']['table_prefix'] .'times` '. $this->db->errmsg(), E_USER_ERROR);
		}


		$query = "
		SET FOREIGN_KEY_CHECKS=1;
		";
		$result = $this->db->query($query);
		if (!$result) {
			trigger_error('Failed to enable foreign key checks: '. $this->db->errmsg(), E_USER_ERROR);
		}

		$this->console('...successfully done!');
	}

	/*
	#///////////////////////////////////////////////////////////////////////#
	#									#
	#///////////////////////////////////////////////////////////////////////#
	*/

	public function formatNumber ($number, $decimals = 0, $dec_point = '.', $thousands_sep = ',') {
		return number_format($number, $decimals, $dec_point, $thousands_sep);
	}

	/*
	#///////////////////////////////////////////////////////////////////////#
	#									#
	#///////////////////////////////////////////////////////////////////////#
	*/

	public function console ($message) {
		echo '['. date('Y-m-d H:i:s', time() - date('Z')) .'] ['. get_class() .'] '. $message ."\r\n";
	}
}

?>
