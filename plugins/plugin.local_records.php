<?php
/*
 * Plugin: Local Records
 * ~~~~~~~~~~~~~~~~~~~~~
 * » Saves record into a local database.
 * » Based upon plugin.localdatabase.php from XAseco2/1.03 written by Xymph and others
 *
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

	// Start the plugin
	$_PLUGIN = new PluginLocalRecords();

/*
#///////////////////////////////////////////////////////////////////////#
#									#
#///////////////////////////////////////////////////////////////////////#
*/

class PluginLocalRecords extends Plugin {
	public $settings;
	public $records;


	/*
	#///////////////////////////////////////////////////////////////////////#
	#									#
	#///////////////////////////////////////////////////////////////////////#
	*/

	public function __construct () {

		$this->setAuthor('undef.de');
		$this->setCoAuthors('aca');
		$this->setVersion('1.0.1');
		$this->setBuild('2019-09-23');
		$this->setCopyright('2014 - 2019 by undef.de');
		$this->setDescription(new Message('plugin.local_records', 'plugin_description'));

		$this->addDependence('PluginCheckpoints',	Dependence::REQUIRED,	'1.0.0', null);

		$this->registerEvent('onSync',			'onSync');
		$this->registerEvent('onLoadingMap',		'onLoadingMap');
		$this->registerEvent('onUnloadingMap',		'onUnloadingMap');
		$this->registerEvent('onBeginMap',		'onBeginMap');
		$this->registerEvent('onEndMapRanking',		'onEndMapRanking');
		$this->registerEvent('onPlayerConnect',		'onPlayerConnect');
		$this->registerEvent('onPlayerDisconnect',	'onPlayerDisconnect');
		$this->registerEvent('onPlayerFinish',		'onPlayerFinish');
		$this->registerEvent('onPlayerWins',		'onPlayerWins');
	}

	/*
	#///////////////////////////////////////////////////////////////////////#
	#									#
	#///////////////////////////////////////////////////////////////////////#
	*/

	public function onSync ($aseco) {

		$aseco->console('[LocalRecords] Load config file [config/local_records.xml]');
		if (!$settings = $aseco->parser->xmlToArray('config/local_records.xml', true, true)) {
			trigger_error('[LocalRecords] Could not read/parse config file [config/local_records.xml]!', E_USER_ERROR);
		}
		$settings = $settings['SETTINGS'];
		unset($settings['SETTINGS']);

		// Display records in game?
		$this->settings['display'] = $aseco->string2bool($settings['DISPLAY'][0]);

		// Show records in message window?
		$this->settings['recs_in_window'] = $aseco->string2bool($settings['RECS_IN_WINDOW'][0]);

		// Set highest record still to be displayed
		$this->settings['max_records'] = (int)$settings['MAX_RECORDS'][0];

		// Set highest record still to be displayed
		$this->settings['limit'] = (int)$settings['LIMIT'][0];

		// Set minimum number of records to be displayed
		$this->settings['show_min_recs'] = $settings['SHOW_MIN_RECS'][0];

		// Show records before start of map?
		$this->settings['show_recs_before'] = $settings['SHOW_RECS_BEFORE'][0];

		// Show records after end of map?
		$this->settings['show_recs_after'] = $settings['SHOW_RECS_AFTER'][0];

		// Show records range?
		$this->settings['show_recs_range'] = $aseco->string2bool($settings['SHOW_RECS_RANGE'][0]);

		// Initiate records list
		$this->records = new RecordList($this->settings['max_records']);
	}

	/*
	#///////////////////////////////////////////////////////////////////////#
	#									#
	#///////////////////////////////////////////////////////////////////////#
	*/

	public function onPlayerConnect ($aseco, $player) {

		// If there's a record on current map
		$cur_record = $this->records->getRecord(0);
		if ($cur_record !== false && $cur_record->score > 0) {
			// set message to the current record
			$message = new Message('plugin.local_records', 'record_current');
			$message->addPlaceholders($aseco->stripStyles($aseco->server->maps->current->name),
				$aseco->formatTime($cur_record->score),
				$aseco->stripStyles($cur_record->player->nickname)
			);
		}
		else {
			// If there should be no record to display
			// display a no-record message
			$message = new Message('plugin.local_records', 'record_none');
			$message->addPlaceholders($aseco->stripStyles($aseco->server->maps->current->name));
		}

		// Bail out immediately on unsupported gamemodes
		if ($aseco->server->gameinfo->mode === Gameinfo::CHASE) {
			return;
		}

		// Show top-8 & records of all online players before map
		if (($this->settings['show_recs_before'] & 2) === 2) {
			$this->show_maprecs($aseco, $player->login, 1, 0);
		}
		else if (($this->settings['show_recs_before'] & 1) === 1) {
			// Or show original record message			
			$message->sendChatMessage($player->login);
		}
	}

	/*
	#///////////////////////////////////////////////////////////////////////#
	#									#
	#///////////////////////////////////////////////////////////////////////#
	*/

	public function onPlayerDisconnect ($aseco, $player) {

		// Ignore fluke disconnects with empty logins
		if ($player->login === '') {
			return;
		}

		// Update player
		$query = "
		UPDATE `%prefix%players` SET
			`LastVisit` = NOW(),
			`TimePlayed` = `TimePlayed` + ". $player->getTimeOnline() ."
		WHERE `Login` = ". $aseco->db->quote($player->login) .";
		";

		$result = $aseco->db->query($query);
		if (!$result) {
			trigger_error('[LocalRecords] Could not update disconnecting player! ('. $aseco->db->errmsg() .')'. CRLF .'sql = '. $query, E_USER_WARNING);
		}
	}

	/*
	#///////////////////////////////////////////////////////////////////////#
	#									#
	#///////////////////////////////////////////////////////////////////////#
	*/

	public function onLoadingMap ($aseco, $map) {

		// Bail out immediately on unsupported gamemodes
		if ($aseco->server->gameinfo->mode === Gameinfo::CHASE) {
			$aseco->console('[LocalRecords] Unsupported gamemode, records ignored!');
			return;
		}

		// on relay, ignore master server's map
		if ($aseco->server->isrelay) {
			return;
		}

		// Load all current local records for current Map
		$query = "
		SELECT
			`m`.`MapId`,
			`r`.`Score`,
			`p`.`Nickname`,
			`p`.`Login`,
			`r`.`Date`,
			`r`.`Checkpoints`
		FROM `%prefix%maps` AS `m`
		LEFT JOIN `%prefix%records` AS `r` ON `r`.`MapId` = `m`.`MapId`
		LEFT JOIN `%prefix%players` AS `p` ON `r`.`PlayerId` = `p`.`PlayerId`
		WHERE `m`.`Uid` = ". $aseco->db->quote($map->uid) ."
		AND `r`.`GamemodeId` = '". $aseco->server->gameinfo->mode ."'
		ORDER BY `r`.`Score` ASC, `r`.`Date` ASC
		LIMIT ". ($this->records->getMaxRecords() ? $this->records->getMaxRecords() : 50) .";
		";

		$result = $aseco->db->query($query);
		if ($result) {
			// map found?
			if ($result->num_rows > 0) {
				// Get each record
				while ($record = $result->fetch_array(MYSQLI_ASSOC)) {

					// create record object
					$record_item = new Record();
					$record_item->score = $record['Score'];
					$record_item->checkpoints = ($record['Checkpoints'] !== '' ? explode(',', $record['Checkpoints']) : array());
					$record_item->new = false;

					// create a player object to put it into the record object
					$player_item = new Player();
					$player_item->nickname = $record['Nickname'];
					$player_item->login = $record['Login'];
					$record_item->player = $player_item;

					// add the map information to the record object
					$record_item->map_id = $map->id;

					// add the created record to the list
					$this->records->addRecord($record_item);
				}
				$aseco->releaseEvent('onLocalRecordsLoaded', $this->records);
				// log records when debugging is set to true
				//if ($aseco->debug) $aseco->console('onLoadingMap records:' . CRLF . print_r($this->records, true));
			}
			$result->free_result();
		}
		else {
			trigger_error('[LocalRecords] Could not get map info! ('. $aseco->db->errmsg() .')'. CRLF .'sql = '. $query, E_USER_WARNING);
		}


		// Check for relay server
		if (!$aseco->server->isrelay) {
			// Check if record exists on new map
			$cur_record = $this->records->getRecord(0);
			if ($cur_record !== false && $cur_record->score > 0) {
				$score = $cur_record->score;

				// Log console message of current record
				$aseco->console('[LocalRecords] Current record on Map [{1}] is [{2}] and held by Player [{3}]',
					$aseco->stripStyles($map->name, false),
					$aseco->formatTime($cur_record->score),
					$aseco->stripStyles($cur_record->player->login, false)
				);

				// Replace parameters
				$message = new Message('plugin.local_records', 'record_current');
				$message->addPlaceholders($aseco->stripStyles($map->name),
					$aseco->formatTime($cur_record->score),
					$aseco->stripStyles($cur_record->player->nickname)
				);
			}
			else {
				$score = 0;

				// Log console message of no record
				$aseco->console('[LocalRecords] Currently no record on [{1}]',
					$aseco->stripStyles($map->name, false)
				);

				// Replace parameters
				$message = new Message('plugin.local_records', 'record_none');
				$message->addPlaceholders($aseco->stripStyles($map->name));
			}
			$aseco->releaseEvent('onLocalRecordBestLoaded', $score);


			// If no maprecs, show the original record message to all players
			if (($this->settings['show_recs_before'] & 1) === 1) {
				if (($this->settings['show_recs_before'] & 4) === 4) {
					$aseco->releaseEvent('onSendWindowMessage', array($message->finish('en', false), false));
				}
				else {
					$message->sendChatMessage();
				}
			}
		}
	}

	/*
	#///////////////////////////////////////////////////////////////////////#
	#									#
	#///////////////////////////////////////////////////////////////////////#
	*/

	public function onUnloadingMap ($aseco, $map) {

		// Reset record list
		$this->records->clear();
	}

	/*
	#///////////////////////////////////////////////////////////////////////#
	#									#
	#///////////////////////////////////////////////////////////////////////#
	*/

	public function onBeginMap ($aseco, $response) {

		// Bail out immediately on unsupported gamemodes
		if ($aseco->server->gameinfo->mode === Gameinfo::CHASE) {
			return;
		}

		// Show top-8 & records of all online players before map
		if (($this->settings['show_recs_before'] & 2) === 2) {
			$this->show_maprecs($aseco, false, 1, $this->settings['show_recs_before']);
		}
	}

	/*
	#///////////////////////////////////////////////////////////////////////#
	#									#
	#///////////////////////////////////////////////////////////////////////#
	*/

	public function onEndMapRanking ($aseco, $map) {

		// Bail out immediately on unsupported gamemodes
		if ($aseco->server->gameinfo->mode === Gameinfo::CHASE) {
			return;
		}

		// Show top-8 & all new records after map
		if (($this->settings['show_recs_after'] & 2) === 2) {
			$this->show_maprecs($aseco, false, 3, $this->settings['show_recs_after']);
		}
		else if (($this->settings['show_recs_after'] & 1) === 1) {
			// fall back on old top-5
			if ($this->records->count() === 0) {
				// display a no-new-record message
				$message = new Message('plugin.local_records', 'ranking_none');
				$message->addPlaceholders($aseco->stripStyles($aseco->server->maps->current->name),
					new Message('plugin.local_records', 'timing_after')
				);
			}
			else {
				// Display new records set up this round
				$message = new Message('plugin.local_records', 'ranking');
				
				$rec_msgs = array();
				$separator = LF;
				// Go through each record
				for ($i = 0; $i < 5; $i++) {
					$cur_record = $this->records->getRecord($i);

					// If the record is set create its message
					if ($cur_record !== false && $cur_record->score > 0) {
						$msg = new Message('plugin.local_records', 'ranking_record_new');
						$msg->addPlaceholders($separator,
							$i+1,
							$aseco->stripStyles($cur_record->player->nickname),
							$aseco->formatTime($cur_record->score)
						);
						$rec_msgs[] = $msg;
						$separator = ', ';
					}
				}
				$message->addPlaceholders($aseco->stripStyles($aseco->server->maps->current->name),
					new Message('plugin.local_records', 'timing_after'),
					$rec_msgs
				);
			}

			// Show ranking message to all players
			if (($this->settings['show_recs_after'] & 4) === 4) {
				$aseco->releaseEvent('onSendWindowMessage', array($message->finish('en', false), true));
			}
			else {
				$message->sendChatMessage();
			}
		}
	}

	/*
	#///////////////////////////////////////////////////////////////////////#
	#									#
	#///////////////////////////////////////////////////////////////////////#
	*/

	public function onPlayerFinish ($aseco, $finish_item) {

		// Bail out immediately on unsupported gamemodes
		if ($aseco->server->gameinfo->mode === Gameinfo::CHASE) {
			return;
		}

		// If no actual finish, bail out immediately
		if ($finish_item->score === 0) {
			return;
		}

//		// In Laps mode on real PlayerFinish event, bail out too
//		if ($aseco->server->gameinfo->mode === Gameinfo::LAPS && !$finish_item->new) {
//			return;
//		}

		$player = $aseco->server->players->getPlayerByLogin($finish_item->player_login);

		// reset lap 'Finish' flag & add checkpoints
		$recordfinish = new Record();
		$recordfinish->new = false;

		// drove a new record?
		// go through each of the XX records
		for ($i = 0; $i < $this->records->getMaxRecords(); $i++) {
			$cur_record = $this->records->getRecord($i);

			// if player's time/score is better, or record isn't set (thanks eyez)
			if ($cur_record === false || $finish_item->score < $cur_record->score) {

				// does player have a record already?
				$cur_rank = -1;
				$cur_score = 0;
				for ($rank = 0; $rank < $this->records->count(); $rank++) {
					$rec = $this->records->getRecord($rank);

					if ($rec->player->login === $player->login) {

						// new record worse than old one
						if ($finish_item->score > $rec->score) {
							return;
						}
						else {
							// new record is better than or equal to old one
							$cur_rank = $rank;
							$cur_score = $rec->score;
							break;
						}
					}
				}

				$finish_time = $aseco->formatTime($finish_item->score);

				if ($cur_rank !== -1) {  // player has a record in topXX already

					// compute difference to old record
					$diff = $cur_score - $finish_item->score;
					$sec = floor($diff/1000);
					$ths = $diff - ($sec * 1000);


					// update record if improved
					if ($diff > 0) {
						// Build a record object with the current finish information
						$recordfinish->player		= $player;
						$recordfinish->score		= $finish_item->score;
						$recordfinish->checkpoints	= (isset($aseco->plugins['PluginCheckpoints']->checkpoints[$player->login]) ? $aseco->plugins['PluginCheckpoints']->checkpoints[$player->login]->current['cps'] : array());
						$recordfinish->date		= strftime('%Y-%m-%d %H:%M:%S');
						$recordfinish->new		= true;
						$recordfinish->map_id		= $aseco->server->maps->current->id;

						$this->records->setRecord($cur_rank, $recordfinish);
					}

					// player moved up in LR list
					if ($cur_rank > $i) {

						// move record to the new position
						$this->records->moveRecord($cur_rank, $i);

						// do a player improved his/her LR rank message
						$message = new Message('plugin.local_records', 'record_new_rank');
						$message->addPlaceholders($aseco->stripStyles($player->nickname),
							$i + 1,
							$finish_time,
							$cur_rank + 1,
							'-'. $aseco->formatTime($diff)
						);

						// show chat message to all or player
						if ($this->settings['display']) {
							if ($i < $this->settings['limit']) {
								if ($this->settings['recs_in_window']) {
									$aseco->releaseEvent('onSendWindowMessage', array($message->finish('en', false), false));
								}
								else {
									$message->sendChatMessage();
								}
							}
							else {
								$message->sendChatMessage($player->login);
							}
						}

					}
					else {

						if ($diff === 0) {
							// do a player equaled his/her record message
							$message = new Message('plugin.local_records', 'record_equal');
							$message->addPlaceholders($aseco->stripStyles($player->nickname),
								$cur_rank + 1,
								$finish_time
							);
						}
						else {
							// do a player secured his/her record message
							$message = new Message('plugin.local_records', 'record_new');
							$message->addPlaceholders($aseco->stripStyles($player->nickname),
								$i + 1,
								$finish_time,
								$cur_rank + 1,
								'-'. $aseco->formatTime($diff)
							);
						}

						// show chat message to all or player
						if ($this->settings['display']) {
							if ($i < $this->settings['limit']) {
								if ($this->settings['recs_in_window']) {
									$aseco->releaseEvent('onSendWindowMessage', array($message->finish('en', false), false));
								}
								else {
									$message->sendChatMessage();
								}
							}
							else {
								$message->sendChatMessage($player->login);
							}
						}
					}
				}
				else {  // player hasn't got a record yet

					// Build a record object with the current finish information
					$recordfinish->player		= $player;
					$recordfinish->score		= $finish_item->score;
					$recordfinish->checkpoints	= (isset($aseco->plugins['PluginCheckpoints']->checkpoints[$player->login]) ? $aseco->plugins['PluginCheckpoints']->checkpoints[$player->login]->current['cps'] : array());
					$recordfinish->date		= strftime('%Y-%m-%d %H:%M:%S');
					$recordfinish->new		= true;
					$recordfinish->map_id		= $aseco->server->maps->current->id;

					// insert new record at the specified position
					$this->records->addRecord($recordfinish, $i);

					// do a player drove first record message
					$message = new Message('plugin.local_records', 'record_first');
					$message->addPlaceholders($aseco->stripStyles($player->nickname),
						$i + 1,
						$finish_time
					);

					// show chat message to all or player
					if ($this->settings['display']) {
						if ($i < $this->settings['limit']) {
							if ($this->settings['recs_in_window']) {
								$aseco->releaseEvent('onSendWindowMessage', array($message->finish('en', false), false));
							}
							else {
								$message->sendChatMessage();
							}
						}
						else {
							$message->sendChatMessage($player->login);
						}
					}
				}

				// log records when debugging is set to true
				//if ($aseco->debug) $aseco->console('onPlayerFinish records:' . CRLF . print_r($this->records, true));

				// insert and log a new local record (not an equalled one)
				if ($recordfinish->new) {
					$this->insertRecord($recordfinish);

					// Log record message in console
					$aseco->console('[LocalRecords] Player [{1}] finished with [{2}] and took the {3}. Local Record!',
						$player->login,
						$aseco->formatTime($finish_item->score),
						$i+1
					);

					// Throw 'local record' event
					$finish_item->position = $i + 1;
					$aseco->releaseEvent('onLocalRecord', $recordfinish);
				}

				// Got the record, now stop!
				return;
			}
		}
	}

	/*
	#///////////////////////////////////////////////////////////////////////#
	#									#
	#///////////////////////////////////////////////////////////////////////#
	*/

	public function onPlayerWins ($aseco, $player) {

		// Bail out immediately on unsupported gamemodes
		if ($aseco->server->gameinfo->mode === Gameinfo::CHASE) {
			return;
		}

		$query = "
		UPDATE `%prefix%players` SET
			`Wins` = ". $player->getWins() ."
		WHERE `Login` = ". $aseco->db->quote($player->login) .";
		";

		$result = $aseco->db->query($query);
		if (!$result) {
			trigger_error('[LocalRecords] Could not update winning player! ('. $aseco->db->errmsg() .')'. CRLF .'sql = '. $query, E_USER_WARNING);
		}
	}

	/*
	#///////////////////////////////////////////////////////////////////////#
	#									#
	#///////////////////////////////////////////////////////////////////////#
	*/

	public function insertRecord ($record) {
		global $aseco;

		$cps = implode(',', $record->checkpoints);

		// Insert new record or update existing
		$query = "
		INSERT INTO `%prefix%records` (
			`MapId`,
			`PlayerId`,
			`GamemodeId`,
			`Date`,
			`Score`,
			`Checkpoints`
		)
		VALUES (
			". $record->map_id .",
			". $record->player->id .",
			". $aseco->server->gameinfo->mode .",
			". $aseco->db->quote(date('Y-m-d H:i:s', time() - date('Z'))) .",
			". $record->score .",
			". $aseco->db->quote($cps) ."
		)
		ON DUPLICATE KEY UPDATE
			`Date` = VALUES(`Date`),
			`Score` = VALUES(`Score`),
			`Checkpoints` = VALUES(`Checkpoints`);
		";

		$result = $aseco->db->query($query);
		if (!$result) {
			trigger_error('[LocalRecords] Could not insert/update record! ('. $aseco->db->errmsg() .')'. CRLF .'sql = '. $query, E_USER_WARNING);
		}
	}

	/*
	#///////////////////////////////////////////////////////////////////////#
	#									#
	#///////////////////////////////////////////////////////////////////////#
	*/

	public function removeRecord ($aseco, $mapid, $playerid, $recno) {

		// remove record
		$query = "
		DELETE FROM `%prefix%records`
		WHERE `MapId` = ". $mapid ."
		AND `PlayerId` = ". $playerid .";
		";

		$result = $aseco->db->query($query);
		if (!$result) {
			trigger_error('[LocalRecords] Could not remove record! ('. $aseco->db->errmsg() .')'. CRLF .'sql = '. $query, E_USER_WARNING);
		}

		// remove record from specified position
		$this->records->deleteRecord($recno);

		// check if fill up is needed
		if ($this->records->count() === ($this->records->getMaxRecords() - 1)) {
			// get max'th time
			$query = "
			SELECT DISTINCT
				`PlayerId`,
				`Score`
			FROM `%prefix%times` AS `t1`
			WHERE `MapId` = ". $mapid ."
			AND `Score` = (
				SELECT
					MIN(`t2`.`Score`)
				FROM `%prefix%times` AS `t2`
				WHERE `MapId` = ". $mapid ."
				AND `t1`.`PlayerId` = `t2`.`PlayerId`
			)
			ORDER BY `Score`, `Date`
			LIMIT ". ($this->records->getMaxRecords() - 1) .",1;
			";

			$result = $aseco->db->query($query);
			if ($result) {
	 			if ($result->num_rows === 1) {
					$timerow = $result->fetch_object();

					// get corresponding date/time & checkpoints
					$query2 = "
					SELECT
						`Date`,
						`Checkpoints`
					FROM `%prefix%times`
					WHERE `MapId` = ". $mapid ."
					AND `PlayerId` = ". $timerow->PlayerId ."
					ORDER BY `Score`, `Date`
					LIMIT 1;
					";

					$result2 = $aseco->db->query($query2);
					$timerow2 = $result2->fetch_object();
					$result2->free_result();

					// insert/update new max'th record
					$query2 = "
					INSERT INTO `%prefix%records` (
						`MapId`,
						`PlayerId`,
						`GamemodeId`,
						`Date`,
						`Score`,
						`Checkpoints`
					)
					VALUES (
						". $mapid . ",
						". $timerow->PlayerId .",
						". $timerow->GamemodeId .",
						". $aseco->db->quote($timerow2->Date) .",
						". $timerow->Score .",
						". $aseco->db->quote($timerow2->Checkpoints) ."
					)
					ON DUPLICATE KEY UPDATE
						`Date` = VALUES(`Date`),
						`Score` = VALUES(`Score`),
						`Checkpoints` = VALUES(`Checkpoints`);
					";

					$result2 = $aseco->db->query($query2);
					if (!$result2) {
						trigger_error('[LocalRecords] Could not insert/update record! ('. $aseco->db->errmsg() .')'. CRLF .'sql = '. $query, E_USER_WARNING);
					}

					// get player info
					$query2 = "
					SELECT
						*
					FROM `%prefix%players`
					WHERE `PlayerId` = ". $timerow->PlayerId .";
					";
					$result2 = $aseco->db->query($query2);
					$playrow = $result2->fetch_array(MYSQLI_ASSOC);
					$result2->free_result();

					// create record object
					$record_item = new Record();
					$record_item->score = $timerow->Score;
					$record_item->checkpoints = ($timerow2->Checkpoints !== '' ? explode(',', $timerow2->Checkpoints) : array());
					$record_item->new = false;

					// create a player object to put it into the record object
					$player_item = new Player();
					$player_item->nickname = $playrow['Nickname'];
					$player_item->login = $playrow['Login'];
					$record_item->player = $player_item;

					// add the map information to the record object
					$record_item->map_id = $aseco->server->maps->current->id;

					// add the created record to the list
					$this->records->addRecord($record_item);
				}
			}
			$result->free_result();
		}
	}

	/*
	#///////////////////////////////////////////////////////////////////////#
	#									#
	#///////////////////////////////////////////////////////////////////////#
	*/

	public function getPersonalBest ($login, $mapid) {
		global $aseco;

		$pb = array();

		// Find ranked record
		$found = false;
		for ($i = 0; $i < $this->records->getMaxRecords(); $i++) {
			if (($rec = $this->records->getRecord($i)) !== false) {
				if ($rec->player->login === $login) {
					$pb['time'] = $rec->score;
					$pb['rank'] = $i + 1;
					$found = true;
					break;
				}
			}
			else {
				break;
			}
		}

		if (!$found) {

			// find unranked time/score
			$query = "
			SELECT
				`Score`
			FROM `%prefix%times`
			WHERE `PlayerId` = ". $aseco->server->players->getPlayerIdByLogin($login) ."
			AND `MapId` = ". $mapid ."
			AND `GamemodeId` = '". $aseco->server->gameinfo->mode ."'
			ORDER BY `Score` ASC
			LIMIT 1;
			";

			$res = $aseco->db->query($query);
			if ($res) {
				if ($res->num_rows > 0) {
					$row = $res->fetch_object();
					$pb['time'] = $row->Score;
					$pb['rank'] = '$nUNRANKED$m';
				}
				else {
					$pb['time'] = 0;
					$pb['rank'] = '$nNONE$m';
				}
				$res->free_result();
			}
		}
		return $pb;
	}

	/*
	#///////////////////////////////////////////////////////////////////////#
	#									#
	#///////////////////////////////////////////////////////////////////////#
	*/

	/*
	 * Universal function to generate list of records for current map.
	 * Called by chat_newrecs, chat_liverecs, endMap & beginMap (uaseco.php).
	 * Show to a player if $login defined, otherwise show to all players.
	 * $mode = 0 (only new), 1 (top-8 & online players at start of map),
	 *         2 (top-6 & online during map), 3 (top-8 & new at end of map)
	 * In modes 1/2/3 the last ranked record is also shown
	 * top-8 is configurable via 'show_min_recs'; top-6 is show_min_recs-2
	 */
	public function show_maprecs ($aseco, $login, $mode, $window) {
		
		$records = array();  
		$separator = LF. '$n';// use narrow font
		// check for records
		if (($total = $this->records->count()) === 0) {
			$totalnew = -1;
		}
		else {
			// check whether to show range
			if ($this->settings['show_recs_range']) {
				// get the first & last ranked records
				$first	= $this->records->getRecord(0);
				$last	= $this->records->getRecord($total-1);

				// compute difference between records
				$diff = $last->score - $first->score;
				$sec = floor($diff/1000);
				$ths = $diff - ($sec * 1000);
			}

			// get list of online players
			$players = array();
			foreach ($aseco->server->players->player_list as $pl) {
				$players[] = $pl->login;
			}

			// collect new records and records by online players
			$totalnew = 0;

			// go through each record
			for ($i = 0; $i < $total; $i++) {
				$cur_record = $this->records->getRecord($i);

				// if the record is new create its message
				if ($cur_record->new) {
					$totalnew++;
					$record_msg = new Message('plugin.local_records', 'ranking_record_new_on');
					$record_msg->addPlaceholders($separator,
						$i + 1,
						$aseco->stripStyles($cur_record->player->nickname),
						$aseco->formatTime($cur_record->score)
					);
					$records[]= $record_msg;
					$separator = ', ';
				}
				else {
					// check if player is online
					if (in_array($cur_record->player->login, $players)) {
						$record_msg = new Message('plugin.local_records', 'ranking_record_on');
						$record_msg->addPlaceholders($separator,
							$i + 1,
							$aseco->stripStyles($cur_record->player->nickname),
							$aseco->formatTime($cur_record->score)
						);

						if ($mode !== 0 && $i === $total-1) {
							// check if last ranked record
							$records[]= $record_msg;
							$separator = ', ';
						}
						else if ($mode === 1 || $mode === 2) {
							// check if always show (start of/during map)
							$records[]= $record_msg;
							$separator = ', ';
						}
						else {
							// show record if < show_min_recs (end of map)
							if ($mode === 3 && $i < $this->settings['show_min_recs']) {
								$records[]= $record_msg;
								$separator = ', ';
							}
						}
					}
					else {
						$record_msg = new Message('plugin.local_records', 'ranking_record');
						$record_msg->addPlaceholders($separator,
							$i + 1,
							$aseco->stripStyles($cur_record->player->nickname),
							$aseco->formatTime($cur_record->score)
						);

						if ($mode !== 0 && $i === $total-1) {
							// check if last ranked record
							$records[]= $record_msg;
						}
						else if (($mode === 2 && $i < $this->settings['show_min_recs']-2) || (($mode === 1 || $mode === 3) && $i < $this->settings['show_min_recs'])) {
							// show offline record if < show_min_recs-2 (during map)
							// show offline record if < show_min_recs (start/end of map)
							$records[]= $record_msg;
						}
					}
				}
			}
		}

		// define wording of the ranking message
		switch ($mode) {
			case 0:
				$timing_txt = 'timing_during';
				break;
			case 1:
				$timing_txt = 'timing_before';
				break;
			case 2:
				$timing_txt = 'timing_during';
				break;
			case 3:
				$timing_txt = 'timing_after';
				break;
		}
		$timing = new Message('plugin.local_records', $timing_txt);
		
		
		$name = $aseco->stripStyles($aseco->server->maps->current->name);
		if (isset($aseco->server->maps->current->mx->error) && $aseco->server->maps->current->mx->error === '') {
			$name = '$l[http://' . $aseco->server->maps->current->mx->prefix .
			        '.mania-exchange.com/tracks/view/'.
			        $aseco->server->maps->current->mx->id .']'. $name .'$l';
		}

		// define the ranking message
		if ($totalnew > 0) {
			$message = new Message('plugin.local_records', 'ranking_new');
			$message->addPlaceholders($name,
				$timing,
				$totalnew,
				$records
			);
		}
		else if ($totalnew === 0 && !empty($records)) {
			// check whether to show range
			if ($this->settings['show_recs_range']) {
				$message = new Message('plugin.local_records', 'ranking_range');
				$message->addPlaceholders($name,
					$timing,
					sprintf("%d.%03d", $sec, $ths),
					$records
				);
			}
			else {
				$message = new Message('plugin.local_records', 'ranking');
				$message->addPlaceholders($name,
					$timing,
					$records
				);
			}
		}
		else if ($totalnew === 0 && empty($records)) {
			$message = new Message('plugin.local_records', 'ranking_no_new');
			$message->addPlaceholders($name,
				$timing
			);
		}
		else {
			// $totalnew === -1
			$message = new Message('plugin.local_records', 'ranking_none');
			$message->addPlaceholders($name,
				$timing
			);
		}

		// show to player or all
		if ($login) {
			$message->sendChatMessage($login);
		}
		else {
			if (($window & 4) === 4) {
				$aseco->releaseEvent('onSendWindowMessage', array($message->finish('en', false), ($mode === 3)));
			}
			else {
				$message->sendChatMessage();
			}
		}
	}
}

?>
