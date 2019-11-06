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
	public $text;

	/** @Inject */
	public $http;

	/** @Inject */
	public $settingManager;


	/**
	 * @HandlesCommand("radio")
	 * @Matches("/^radio$/i")
	 */
	public function radioCommand($message, $channel, $sender, $sendto, $args) {
		$url = 'https://gsp.torontocast.stream/streaminfo/';
		$response = $this->http->get($url)->withCallback(function($response) use ($sendto) {
			$msg = $this->renderPlaylist($response);
			$sendto->reply($msg);
		});
	}

	public function msToTime($milliSecs) {
		if ($milliSecs < 3600000) {
			return preg_replace('/^0/', '', gmdate("i:s", round($milliSecs/1000)));
		}
		return gmdate("G:i:s", round($milliSecs/1000));
	}

        public function renderTuneIn($data) {
		if (!isset($data->stream) || !is_array($data->stream) || !count($data->stream)) {
			return '';
		}
		$streams = array();
		foreach ($data->stream as $stream) {
			$streams[] = sprintf("%s: %s",
				$stream->id,
				$this->text->makeChatcmd("tune in", "/start ".$stream->url)
			);
		}
		return " - ".$this->text->makeBlob("tune in", join("\n", $streams), "Choose your stream quality");
        }

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
		$msg = sprintf("Currently playing on GSP: <highlight>%s<end> - <highlight>%s<end>",
			$song->artist, $song->title);
		if (isset($song->duration) && $song->duration > 0) {
			$startTime = DateTime::createFromFormat("Y-m-d*H:i:sT", $song->date)->setTimezone(new DateTimeZone("UTC"));
			$time = DateTime::createFromFormat("Y-m-d*H:i:sT", $data->date)->setTimezone(new DateTimeZone("UTC"));
                        $diff = $time->diff($startTime, true);
			$msg .= " [".$diff->format("%i:%S")."/".$this->msToTime($song->duration)."]";
		}
		foreach ($data->history as $song) {
			$time = DateTime::createFromFormat("Y-m-d*H:i:sT", $song->date)->setTimezone(new DateTimeZone("UTC"));
			$info = sprintf("%s   <highlight>%s<end> - %s [%s]",
				$time->format("H:i:s"), $song->artist, $song->title,
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
