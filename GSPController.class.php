<?php

namespace Budabot\User\Modules;

use DateTime;
use DateTimeZone;

/**
 * Authors:
 *	- Nadyita (RK5)
 *
 * @Instance
 *
 * Commands this controller contains:
 *	@DefineCommand(
 *		command     = 'radio',
 *		accessLevel = 'all',
 *		description = 'List what is currently playing on GridStream',
 *		help        = 'radio.txt'
 *	)
 */
class GSPController {

	/**
	 * Name of the module.
	 * Set automatically by module loader.
	 * @var string $moduleName
	 */
	public $moduleName;

	/**
	 * @var \Budabot\Core\Budabot $chatBot
	 * @Inject
	 */
	public $chatBot;

	/**
	 * @var \Budabot\Core\Text $text
	 * @Inject
	 */
	public $text;

	/**
	 * @var \Budabot\Core\Http $http
	 * @Inject
	 */
	public $http;

	/**
	 * @var \Budabot\Core\SettingManager $settingManager
	 * @Inject
	 */
	public $settingManager;

	/**
	 * 1 if a GSP show is currently running, otherwise 0
	 * @var int $showRunning
	 */
	protected $showRunning = 0;

	/**
	 * The name of the currently running show or empty if none
	 * @var string $showName
	 */
	protected $showName = "";

	/**
	 * Location of the currently running show or empty if none
	 * @var string $showLocation
	 */
	protected $showLocation = "";

	const GSP_URL = 'https://gsp.torontocast.stream/streaminfo/';

	/**
	 * @Setup
	 * This handler is called on bot startup.
	 */
	public function setup() {
		$this->settingManager->add(
			$this->moduleName,
			'gsp_channels',
			'Channels to display shows starting',
			'edit',
			'text',
			'both',
			'guild;priv;both',
			'',
			'mod',
			'radio.txt'
		);
		$this->settingManager->add(
			$this->moduleName,
			"gsp_show_logon",
			"Show on logon if there is a running GSP show",
			"edit",
			"options",
			"1",
			"Yes;No",
			"1;0"
		);
	}

	/**
	 * @Event("timer(1min)")
	 * @Description("Check if GSP show is running")
	 */
	public function checkIfShowRunning() {
		$response = $this->http
				->get(static::GSP_URL)
				->withTimeout(10)
				->withCallback(function($response) use ($sendto) {
					$this->checkAndAnnounceIfShowStarted($response);
				});
	}

	/**
	 * Create a message about the currently running GSP show
	 *
	 * @return string "GSP is now running XZY - more info"
	 */
	public function getNotificationMessage() {
		$msg = sprintf(
			"GSP is now running <highlight>%s<end>. Location: <highlight>%s<end>.",
			$this->showName,
			$this->showLocation
		);
		return $msg;
	}

	/**
	 * Test if all needed data for the current show is present and valid
	 *
	 * @param StdClass $show The response from the stream information of GSP
	 * @return boolean true if we received good data from GSP, false otherwise
	 */
	protected function isAllShowInformationPresent($show) {
		$allShowInformationPresent = $show !== false
			&& isset($show->live)
			&& is_integer($show->live)
			&& isset($show->name)
			&& isset($show->info);
		return $allShowInformationPresent;
	}

	/**
	 * Check if the GSP changed to live, changed name or location
	 *
	 * @param StdClass $show The response from the stream information of GSP
	 * @return boolean true if something changed, false otherwise
	 */
	protected function hasShowInformationChanged($show) {
		$informationChanged = $show->live !== $this->showRunning
			|| $show->name !== $this->showName
			|| $show->info !== $this->showLocation;
		return $informationChanged;
	}

	/**
	 * Announce if a new show has just started
	 *
	 * @param StdClass $gspResponse The response from the stream information of GSP
	 * @return void
	 */
	public function checkAndAnnounceIfShowStarted($gspResponse) {
		$data = @json_decode($gspResponse->body);
		if ( !$this->isAllShowInformationPresent($data) ) {
			return;
		}
		if ($data->live && strlen($data->name) && strlen($data->info)) {
			$data->live = 1;
		} else {
			$data->live = 0;
		}
		if (!$this->hasShowInformationChanged($data)) {
			return;
		}

		$this->showRunning = $data->live;
		$this->showName = $data->name;
		$this->showLocation = $data->info;
		if (!$data->live) {
			return;
		}
		$specialDelimiter = "<yellow>---------------------<end>";
		$msg = sprintf(
			"\n%s\n%s\n%s",
			$specialDelimiter,
			$this->getNotificationMessage(),
			$specialDelimiter
		);
		$this->announceShow($msg);
	}

	/**
	 * Announce a show on all configured channels
	 *
	 * @param string $msg The message to announce
	 * @return void
	 */
	protected function announceShow($msg) {
		if ($this->settingManager->get('gsp_channels') === "priv") {
			$this->chatBot->sendPrivate($msg, true);
		} elseif ($this->settingManager->get('gsp_channels') === "guild") {
			$this->chatBot->sendGuild($msg, true);
		} elseif ($this->settingManager->get('gsp_channels') === "both") {
			$this->chatBot->sendPrivate($msg, true);
			$this->chatBot->sendGuild($msg, true);
		}
	}

	/**
	 * @Event("logOn")
	 * @Description("Sends gsp show message on logon")
	 */
	public function gspShowLogonEvent($eventObj) {
		$sender = $eventObj->sender;
		if (
			!$this->chatBot->isReady()
			|| !$this->settingManager->get('gsp_show_logon')
			|| !$this->showRunning
		) {
			return;
		}
		$msg = $this->getNotificationMessage();
		$this->chatBot->sendTell($msg, $sender);
	}


	/**
	 * @HandlesCommand("radio")
	 * @Matches("/^radio$/i")
	 */
	public function radioCommand($message, $channel, $sender, $sendto, $args) {
		$response = $this->http
				->get(static::GSP_URL)
				->withTimeout(5)
				->withCallback(function($response) use ($sendto) {
					$msg = $this->renderPlaylist($response);
					$sendto->reply($msg);
				});
	}

	/**
	 * Convert GSP milliseconds into a human readable time like 6:53
	 *
	 * @param int $milliSecs The duration in milliseconds
	 * @return string The time in a format 6:53 or 0:05
	 */
	public function msToTime($milliSecs) {
		if ($milliSecs < 3600000) {
			return preg_replace('/^0/', '', gmdate("i:s", round($milliSecs/1000)));
		}
		return gmdate("G:i:s", round($milliSecs/1000));
	}

	/**
	 * Render a blob how players can tune into GSP
	 *
	 * @param StdClass $data The JSON information about the GSP stream
	 * @return string The string with a link to the tune-in options
	 */
	public function renderTuneIn($data) {
		if (!isset($data->stream) || !is_array($data->stream) || !count($data->stream)) {
			return '';
		}
		$streams = array();
		foreach ($data->stream as $stream) {
			$streams[] = sprintf(
				"%s: %s",
				$stream->id,
				$this->text->makeChatcmd("tune in", "/start ".$stream->url)
			);
		}
		return " - ".$this->text->makeBlob("tune in", join("\n", $streams), "Choose your stream quality");
	}

	/**
	 * Get an array of song descriptions
	 *
	 * @param StdClass[] $history The history (playlist) as an aray of songs
	 * @return string[] Rendered song information about the playlist
	 */
	protected function getPlaylistInfos($history) {
		$songs = array();
		foreach ($history as $song) {
			$time = DateTime::createFromFormat("Y-m-d*H:i:sT", $song->date)->setTimezone(new DateTimeZone("UTC"));
			$info = sprintf(
				"%s   <highlight>%s<end> - %s",
				$time->format("H:i:s"),
				$song->artist,
				$song->title,
			);
			if (isset($song->duration) && $song->duration > 0) {
				$info .= " [".$this->msToTime($song->duration)."]";
			}
			$songs[] = $info;
		}
		return $songs;
	}

	/**
	 * Get information about the currently running GSP show
	 *
	 * @param StdClass $data The global GSP information
	 * @return string A string with information about the currenly running GSP show
	 */
	protected function getShowInfos($data) {
		if ($data->live !== 1 || !isset($data->name) || !strlen($data->name)) {
			return "";
		}
		$showInfos = "Current show: <highlight>".$data->name."<end>\n";
		if (isset($data->info) && strlen($data->info)) {
			$showInfos .= "Location: <highlight>".$data->info."<end>\n";
		}
		$showInfos .= "\n";
		return $showInfos;
	}

	/**
	 * Get a line describing what GSP is currently playing
	 *
	 * @param StdClass $data The global GSP information
	 * @param StdClass $song Information about the current song
	 * @return string A line with the currently played title
	 */
	public function getCurrentlyPlaying($data, $song) {
		$msg = sprintf(
			"Currently playing on %s: <highlight>%s<end> - <highlight>%s<end>",
			($data->live === 1 && $data->name) ? "<yellow>".$data->name."<end>" : "GSP",
			$song->artist,
			$song->title
		);
		if (isset($song->duration) && $song->duration > 0) {
			$startTime = DateTime::createFromFormat("Y-m-d*H:i:sT", $song->date)->setTimezone(new DateTimeZone("UTC"));
			$time = DateTime::createFromFormat("Y-m-d*H:i:sT", $data->date)->setTimezone(new DateTimeZone("UTC"));
			$diff = $time->diff($startTime, true);
			$msg .= " [".$diff->format("%i:%S")."/".$this->msToTime($song->duration)."]";
		}
		return $msg;
	}

	/**
	 * Create a message with information about what's currently playing on GSP
	 *
	 * @param string $gspResponse The JSON-string with stream information
	 * @return string Information what is currently being played on GSP
	 */
	public function renderPlaylist($gspResponse) {
		$data = @json_decode($gspResponse->body);
		if ($data === false) {
			return "GSP seems to have problems with their service. Please try again later.";
		}
		if (!isset($data->history) || !is_array($data->history)) {
			return "GSP currently doesn't supply a playlist.";
		}
		if (empty($data->history)) {
			return "GSP is currently not playing any music.";
		}
		$song = array_shift($data->history);
		$currentlyPlaying = $this->getCurrentlyPlaying($data, $song);

		$songs = $this->getPlaylistInfos($data->history);
		$showInfos = $this->getShowInfos($data);
		$lastSongsPage = $this->text->makeBlob(
			"last songs",
			$showInfos.join("\n", $songs),
			"Last played songs (all times in UTC)",
		);
		$msg = $currentlyPlaying." - ".$lastSongsPage.$this->renderTuneIn($data);
		return $msg;
	}
}
