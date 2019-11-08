<?php

namespace Budabot\User\Modules;

use Budabot\Core\xml;
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
	 */
	public $moduleName;

	/** @Inject */
	public $chatBot;

	/** @Inject */
	public $text;

	/**
	 * @var Budabot\Core\AsyncHttp $http The global http client
	 * @Inject
	 */
	public $http;

	/**
	 * @var Budabot\Core\SettingsManager $settingsManager The global setting manager
	 * @Inject
	 */
	public $settingManager;

	/** @var int 1 if a GSP show is currently running, otherwise 0 */
	protected $showRunning = 0;

	/** @var string The name of the currently running show or empty if none */
	protected $showName = "";

	/** @var string Location of the currently running show or empty if none */
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
	 * Announce if a new show has just started
	 *
	 * @param StdClass $gspResponse The response from the stream information of GSP
	 * @return void
	 */
	public function checkAndAnnounceIfShowStarted($gspResponse) {
		$data = @json_decode($gspResponse->body);
		$allInformationPresent = $data !== false
			&& isset($data->live)
			&& is_integer($data->live)
			&& isset($data->name)
			&& isset($data->info);
		if (!$allInformationPresent) {
			return;
		}
		if ($data->live === $this->showRunning) {
			return;
		}
		$this->showRunning = $data->live;
		$this->showName = $data->name;
		$this->showLocation = $data->info;
		if (!$data->live) {
			return;
		}
		$msg = $this->getNotificationMessage();
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
	 * Create a message with information about what's currently playing on GSP
	 *
	 * @param string $gspResponse The JSON-string with  stream information
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
		$songs = array();
		$song = array_shift($data->history);
		$msg = sprintf(
			"Currently playing on GSP: <highlight>%s<end> - <highlight>%s<end>",
			$song->artist,
			$song->title
		);
		if (isset($song->duration) && $song->duration > 0) {
			$startTime = DateTime::createFromFormat("Y-m-d*H:i:sT", $song->date)->setTimezone(new DateTimeZone("UTC"));
			$time = DateTime::createFromFormat("Y-m-d*H:i:sT", $data->date)->setTimezone(new DateTimeZone("UTC"));
			$diff = $time->diff($startTime, true);
			$msg .= " [".$diff->format("%i:%S")."/".$this->msToTime($song->duration)."]";
		}
		foreach ($data->history as $song) {
			$time = DateTime::createFromFormat("Y-m-d*H:i:sT", $song->date)->setTimezone(new DateTimeZone("UTC"));
			$info = sprintf(
				"%s   <highlight>%s<end> - %s [%s]",
				$time->format("H:i:s"),
				$song->artist,
				$song->title,
				$this->msToTime($song->duration)
			);
			$songs[] = $info;
		}
		$msg .= " - ".$this->text->makeBlob(
			"last songs",
			join("\n", $songs),
			"Last played songs (all times in UTC)",
		).$this->renderTuneIn($data);
		return $msg;
	}
}
