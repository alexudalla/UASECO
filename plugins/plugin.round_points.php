<?php
/*
 * Plugin: Round Points
 * ~~~~~~~~~~~~~~~~~~~~
 * » Allows setting common and custom Rounds points systems.
 * » Based upon plugin.rpoints.php from XAseco2/1.03 written by Xymph
 *
 * ----------------------------------------------------------------------------------
 * Author:	undef.de
 * Date:	2015-06-16
 * Copyright:	2014 - 2015 by undef.de
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
 * Dependencies:
 *  - includes/core/window.class.php
 *  - plugins/plugin.modescript_handler.php
 *
 */

	// Start the plugin
	$_PLUGIN = new PluginRoundPoints();

/*
#///////////////////////////////////////////////////////////////////////#
#									#
#///////////////////////////////////////////////////////////////////////#
*/

class PluginRoundPoints extends Plugin {
	public $rounds_points	= array();
	public $config		= array();

	/*
	#///////////////////////////////////////////////////////////////////////#
	#									#
	#///////////////////////////////////////////////////////////////////////#
	*/

	public function __construct () {

		$this->setVersion('1.0.0');
		$this->setAuthor('undef.de');
		$this->setDescription('Allows setting common and custom Rounds points systems.');

		$this->addDependence('PluginModescriptHandler',	Dependence::REQUIRED,	'1.0.0',	null);

		$this->registerEvent('onSync',			'onSync');

		$this->registerChatCommand('setrpoints',	'chat_setrpoints',	'Sets custom Rounds points (see: /setrpoints help)',	Player::ADMINS);
		$this->registerChatCommand('rpoints',		'chat_rpoints',		'Shows current Rounds points system.',			Player::PLAYERS);
	}

	/*
	#///////////////////////////////////////////////////////////////////////#
	#									#
	#///////////////////////////////////////////////////////////////////////#
	*/

	public function onSync ($aseco) {

		// Read Configuration
		if (!$this->config = $aseco->parser->xmlToArray('config/round_points.xml', true, true)) {
			trigger_error('[RoundPoints] Could not read/parse config file "config/round_points.xml"!', E_USER_ERROR);
		}
		$this->config = $this->config['SETTINGS'];


		// Setup points systems
		foreach ($this->config['POINTS_SYSTEMS'][0]['SYSTEM'] as $system) {
			$this->rounds_points[$system['ID'][0]] = array(
				'id'		=> $system['ID'][0],
				'label'		=> $system['LABEL'][0],
				'points'	=> array_map('intval', explode(',', $system['POINTS'][0])),
				'limit'		=> $system['LIMIT'][0],
			);
		}


		// Setup only if Gamemode is "Rounds", "Team" or "Cup"
		if ($aseco->server->gameinfo->mode == Gameinfo::ROUNDS || $aseco->server->gameinfo->mode == Gameinfo::TEAM || $aseco->server->gameinfo->mode == Gameinfo::CUP) {

			// Set configured default rounds points system
			$system = $this->config['DEFAULT_SYSTEM'][0];

			// Set original points system
			$points = array('10', '6', '4', '3', '2', '1');

			if (array_key_exists($system, $this->rounds_points)) {

				// Convert int to string
				$points = $this->rounds_points[$system]['points'];
				foreach ($points as &$num) {
					settype($num, 'string');
				}
				unset($num);

				try {
					// Set new custom points
					$aseco->client->query('TriggerModeScriptEventArray', 'Rounds_SetPointsRepartition', $points);
					$aseco->console('[RoundPoints] Setup default rounds points: "{1}" -> {2}',
						$this->rounds_points[$system]['label'],
						implode(',', $this->rounds_points[$system]['points'])
					);

					// Setup limits
					$aseco->server->gameinfo->rounds['PointsLimit'] = (int)$this->rounds_points[$system]['limit'];
					$aseco->plugins['PluginModescriptHandler']->setupModescriptSettings();
				}
				catch (Exception $exception) {
					$aseco->console('[RoundPoints] Invalid given rounds points: {1}, Error: {2}', $system, $exception->getMessage());
				}

			}
			else if ($system == '') {
				try {
					$aseco->client->query('TriggerModeScriptEventArray', 'Rounds_SetPointsRepartition', $points);
				}
				catch (Exception $exception) {
					$aseco->console('[RoundPoints] Setting modescript default rounds points: {1} Error: {2}', $points, $exception->getMessage());
				}
			}
			else {
				$aseco->console('[RoundPoints] Unknown rounds points: {1}', $system);
			}


			// Convent string (string are required by 'Rounds_SetPointsRepartition') back to int
			foreach ($points as &$num) {
				settype($num, 'int');
			}
			if ($aseco->server->gameinfo->mode == Gameinfo::ROUNDS) {
				$aseco->server->gameinfo->rounds['PointsRepartition'] = $points;
				if ($aseco->settings['developer']['log_events']['common'] == true) {
					$aseco->console('[Event] Points Repartition Loaded');
				}
				$aseco->releaseEvent('onPointsRepartitionLoaded', $points);
			}
			else if ($aseco->server->gameinfo->mode == Gameinfo::TEAM) {
				$aseco->server->gameinfo->team['PointsRepartition'] = $points;
				if ($aseco->settings['developer']['log_events']['common'] == true) {
					$aseco->console('[Event] Points Repartition Loaded');
				}
				$aseco->releaseEvent('onPointsRepartitionLoaded', $points);
			}
			else if ($aseco->server->gameinfo->mode == Gameinfo::CUP) {
				$aseco->server->gameinfo->cup['PointsRepartition'] = $points;
				if ($aseco->settings['developer']['log_events']['common'] == true) {
					$aseco->console('[Event] Points Repartition Loaded');
				}
				$aseco->releaseEvent('onPointsRepartitionLoaded', $points);
			}
		}
	}

	/*
	#///////////////////////////////////////////////////////////////////////#
	#									#
	#///////////////////////////////////////////////////////////////////////#
	*/

	public function chat_rpoints ($aseco, $login, $chat_command, $chat_parameter) {

		// Get custom points
		$points = array();
		if ($aseco->server->gameinfo->mode == Gameinfo::ROUNDS) {
			$points = $aseco->server->gameinfo->rounds['PointsRepartition'];
		}
		else if ($aseco->server->gameinfo->mode == Gameinfo::TEAM) {
			$points = $aseco->server->gameinfo->team['PointsRepartition'];
		}
		else if ($aseco->server->gameinfo->mode == Gameinfo::CUP) {
			$points = $aseco->server->gameinfo->cup['PointsRepartition'];
		}

		// search for known points system
		$system = false;
		foreach ($this->rounds_points as $rpoints) {
			if ($points == $rpoints['points']) {
				$system = $rpoints['label'];
				break;
			}
		}

		// check for results
		if (empty($points)) {
			$message = $aseco->formatText($this->config['MESSAGES'][0]['NO_RPOINTS'][0], '');
		}
		else {
			if ($system !== false) {
				$message = $aseco->formatText($this->config['MESSAGES'][0]['RPOINTS_NAMED'][0],
					'',
					$system,
					'',
					implode(',', $points)
				);
			}
			else {
				$message = $aseco->formatText($this->config['MESSAGES'][0]['RPOINTS_NAMELESS'][0],
					'',
					implode(',', $points)
				);
			}
		}
		$aseco->sendChatMessage($message, $login);
	}

	/*
	#///////////////////////////////////////////////////////////////////////#
	#									#
	#///////////////////////////////////////////////////////////////////////#
	*/

	public function chat_setrpoints ($aseco, $login, $chat_command, $chat_parameter) {

		// Get Player object
		$player = $aseco->server->players->getPlayer($login);

		if ($chat_parameter == 'help') {
			$data = array();
			$data[] = array('/setrpoints help',		'Displays this help information');
			$data[] = array('/setrpoints list',		'Displays available points systems');
			$data[] = array('/setrpoints show',		'Shows current points system');
			$data[] = array('/setrpoints xxx',		'Sets custom points system labelled xxx');
			$data[] = array('/setrpoints X,Y,...,Z',	'Sets custom points system with specified values;');
			$data[] = array('',				'X,Y,...,Z must be decreasing integers and there');
			$data[] = array('',				'must be at least two values with no spaces');
			$data[] = array('/setrpoints off',		'Disables custom points system');

			// Setup settings for Window
			$settings_title = array(
				'icon'	=> 'Icons64x64_1,TrackInfo',
			);
			$settings_heading = array(
				'textcolors'	=> array('FF5F', 'FFFF'),
			);
			$settings_columns = array(
				'columns'	=> 1,
				'widths'	=> array(30, 70),
				'textcolors'	=> array('FF5F', 'FFFF'),
				'heading'	=> array('Command', 'Description'),
			);

			$window = new Window();
			$window->setLayoutTitle($settings_title);
			$window->setLayoutHeading($settings_heading);
			$window->setColumns($settings_columns);
			$window->setContent('Help for /setrpoints', $data);
			$window->send($player, 0, false);
		}
		else if ($chat_parameter == 'list') {
			$data = array();
			foreach ($this->rounds_points as $tag => $points) {
				$data[] = array($tag, $points[0], implode(', ', $points[1]) .', ...');
			}

			// Setup settings for Window
			$settings_title = array(
				'icon'	=> 'Icons128x32_1,RT_Rounds',
			);
			$settings_heading = array(
				'textcolors'	=> array('FF5F', 'FFFF'),
			);
			$settings_columns = array(
				'columns'	=> 1,
				'widths'	=> array(10, 20, 70),
				'textcolors'	=> array('FF5F', 'FF5F', 'FFFF'),
				'heading'	=> array('Label', 'System', 'Distribution'),
			);

			$window = new Window();
			$window->setLayoutTitle($settings_title);
			$window->setLayoutHeading($settings_heading);
			$window->setColumns($settings_columns);
			$window->setContent('Currently available Rounds points systems', $data);
			$window->send($player, 0, false);
		}
		else if ($chat_parameter == 'show') {
			// Get custom points
			$points = array();
			if ($aseco->server->gameinfo->mode == Gameinfo::ROUNDS) {
				$points = $aseco->server->gameinfo->rounds['PointsRepartition'];
			}
			else if ($aseco->server->gameinfo->mode == Gameinfo::TEAM) {
				$points = $aseco->server->gameinfo->team['PointsRepartition'];
			}
			else if ($aseco->server->gameinfo->mode == Gameinfo::CUP) {
				$points = $aseco->server->gameinfo->cup['PointsRepartition'];
			}

			// Search for known points system
			$system = false;
			foreach ($this->rounds_points as $rpoints) {
				if ($points == $rpoints[1]) {
					$system = $rpoints[0];
					break;
				}
			}

			// Check for results
			if (empty($points)) {
				$message = $aseco->formatText($this->config['MESSAGES'][0]['NO_RPOINTS'][0], '{#admin}');
			}
			else {
				if ($system) {
					$message = $aseco->formatText($this->config['MESSAGES'][0]['RPOINTS_NAMED'][0],
						'{#admin}',
						$system,
						'{#admin}',
						implode(',', $points)
					);
				}
				else {
					$message = $aseco->formatText($this->config['MESSAGES'][0]['RPOINTS_NAMELESS'][0],
						'{#admin}',
						implode(',', $points)
					);
				}
			}
			$aseco->sendChatMessage($message, $login);
		}
		else if ($chat_parameter == 'off') {

			// Set original points system
			$points = array('10', '6', '4', '3', '2', '1');

			try {
				$aseco->client->query('TriggerModeScriptEventArray', 'Rounds_SetPointsRepartition', $points);
			}
			catch (Exception $exception) {
				$aseco->console('[RoundPoints] Setting modescript default rounds points: {1} Error: {2}', $points, $exception->getMessage());
			}

			// log console message
			$aseco->console('[RoundPoints] [{1}] disabled custom points', $login);

			// show chat message
			$message = $aseco->formatText('{#server}» {#admin}{1}$z$s{#admin} disables custom rounds points',
				$player->nickname
			);
			$aseco->sendChatMessage($message);
		}
		else if (preg_match('/^\d+,[\d,]*\d+$/', $chat_parameter)) {
			// Set new custom points as array of ints
			$points = array_map('intval', explode(',', $chat_parameter));

			try {
				// Set new custom points
				$aseco->client->query('TriggerModeScriptEventArray', 'Rounds_SetPointsRepartition', $points);
				$aseco->console('[RoundPoints] [{1}] set new custom points: {2}', $login, $points);
			}
			catch (Exception $exception) {
				$aseco->console('[RoundPoints] Invalid given rounds points: {1}, Error: {2}', $points, $exception->getMessage());
			}

			// Show chat message
			$message = $aseco->formatText('{#server}» {#admin}{1}$z$s{#admin} sets custom rounds points: {#highlite}{2},...',
				$player->nickname,
				$chat_parameter
			);
			$aseco->sendChatMessage($message);

		}
		else if (array_key_exists(strtolower($chat_parameter), $this->rounds_points)) {

			$system = strtolower($chat_parameter);

			// Convert int to string
			$points = $this->rounds_points[strtolower($system)]['points'];
			foreach ($points as &$num) {
				settype($num, 'string');
			}
			unset($num);

			try {
				// Set new custom points
				$aseco->client->query('TriggerModeScriptEventArray', 'Rounds_SetPointsRepartition', $points);
				$aseco->console('[RoundPoints] [{1}] set new custom points [{2}]',
					$login,
					$this->rounds_points[$system][0]
				);

				// Setup limits
				$aseco->server->gameinfo->rounds['PointsLimit'] = (int)$this->rounds_points[$system]['limit'];
				$aseco->plugins['PluginModescriptHandler']->setupModescriptSettings();
			}
			catch (Exception $exception) {
				$aseco->console('[RoundPoints] Invalid given rounds points: {1}, Error: {2}', $system, $exception->getMessage());
			}

			// Show chat message
			$message = $aseco->formatText('{#server}» {#admin}{1}$z$s{#admin} sets rounds points to {#highlite}{2}{#admin}: {#highlite}{3},...',
				$player->nickname,
				$this->rounds_points[$system][0],
				implode(',', $this->rounds_points[$system][1])
			);
			$aseco->sendChatMessage($message);
		}
		else {
			$message = '{#server}» {#error}Unknown points system {#highlite}$i '. strtoupper($chat_parameter) .'$z$s {#error}!';
			$aseco->sendChatMessage($message, $login);
		}
	}
}

?>
