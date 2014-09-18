<?php
/**
 * @file
 * Simple Storify Class.
 * More infor: http://storify.com/
 * This library has been inspired by the work of
 * Benjamin J. Balter( ben.balter.com | ben@balter.com )
 *
 * @since  1.0
 *
 * @author Pol Dell'Aiera ( drupol | drupol@about.me )
 */

namespace Storify\Main;

use Guzzle;
use Monolog\Logger;
use Monolog\Handler\StreamHandler;


class Storify {

  public $storifyUrl = 'https://storify.com';
  public $createUrl = 'https://storify.com/create';

  public $callbackQueryArg = 'callback';
  public $permalinkQueryArg = 'storyPermalink';

  // Regex to parse Storify URLs.
  public $storifyUrlRegex = '#^https://(www\.)?storify.com/([A-Za-z0-9_]+)/([A-Z0-9-]+)(/)?$#i';

  // Embed URL, %1$s is username, %2$s is story slug.
  public $storifyEmbedUrl = 'https://storify.com/%1$s/%2$s.js?header=false&sharing=false&border=false';

  // Edit story URL, %1$s is username, %2$s is story slug.
  public $storifyEditUrl = 'https://storify.com/%1$s/%2$s/edit';

  // Story URL, %1$s is username, %2$s is story slug.
  public $storifyStoryUrl = 'https://storify.com/%1$s/%2$s';

  // URL to story's json data, %1$s is username, %2$s is story slug.
  public $storifyJsonUrl = 'https://api.storify.com/v1/stories/%1$s/%2$s';

  // URL to HTML version of the story.
  public $storifyMinimalUrl = 'https://storify.com/%1$s/%2$s/minimal';

  // What elements to retrieve when getting story metadata.
  public $storyMetadata = array('title', 'description', 'status', 'thumbnail', 'shortlink');

  private $user = NULL;
  private $slug = NULL;
  private $isValidated = FALSE;

  private $logger;
  private $logfile;
  private $logger_level;

  static $instance;

  /**
   * Class constructor.
   */
  function __construct($argument = NULL) {
    self::$instance = &$this;

    $this->setLogConfig(LOGGER::DEBUG, 'php://stderr');

    if ($argument != NULL) {
      $this->setStory($argument);
    }
  }

  /**
   * Set the story.
   *
   * @param array|string|object $argument
   *   The argument containing the story variables.
   */
  function setStory($argument) {
    $this->user = $user = NULL;
    $this->slug = $slug = NULL;
    $this->isValidated = FALSE;

    if (is_array($argument)) {
      $user = isset($argument['user']) ? $argument['user'] : NULL;
      $slug = isset($argument['slug']) ? $argument['slug'] : NULL;
    }

    if (is_object($argument)) {
      $user = isset($argument->user) ? $argument->user : NULL;
      $slug = isset($argument->slug) ? $argument->slug : NULL;
    }

    if (is_string($argument)) {
      if (preg_match($this->storifyUrlRegex, $argument, $matches)) {
        list($host, $user, $slug) = explode('/', str_replace('http://', '', $argument));
      }
    }

    if ($this->isValid($user, $slug)) {
      $this->user = $user;
      $this->slug = $slug;
    }
  }

  /**
   * Validate the user and slug.
   * Returns true if they are good, false if not.
   *
   * @param string $user
   *   The username.
   * @param string $slug
   *   The slug, title of the story in the url.
   *
   * @return bool
   *   True if the story is validated, false if not.
   */
  function isValid($user = NULL, $slug = NULL) {
    if ($this->isValidated) {
//      return TRUE;
    }

    $user = (empty($user)) ? $this->user : $user;
    $slug = (empty($slug)) ? $this->slug : $slug;

    if (empty($user) || empty($slug)) {
      $this->isValidated = FALSE;
      return FALSE;
    }

    if (!preg_match($this->storifyUrlRegex, sprintf($this->storifyStoryUrl, $user, $slug), $matches)) {
      $this->isValidated = FALSE;
      return FALSE;
    }

    if ($result = $this->query(sprintf($this->storifyJsonUrl, $user, $slug))) {
      $this->isValidated = TRUE;
      return TRUE;
    } else {
      $this->isValidated = FALSE;
      return FALSE;
    }
  }

  /**
   * Returns the story informations.
   *
   * @return bool|object
   *   Return false if failed or the Story data in an object.
   */
  function getStoryData() {
    if (!$this->isValid()) {
      return FALSE;
    }

    $result = json_decode($this->query($this->getUrl('json')));

    if ($this->isRequestOk($result)) {
      return $result->content;
    }

    return FALSE;
  }

  /**
   * Retrieves story metadata.
   *
   * @return bool|array
   *   Return false or the Story's metadata in an array.
   */
  function getStoryMetadata() {
    if (!$this->isValid()) {
      return FALSE;
    }

    if ($data = $this->getStoryData()) {
      $output = array();

      foreach ($this->storyMetadata as $meta) {
        $output[$meta] = (isset($data->$meta)) ? $data->$meta : NULL;
      }
      $output['user'] = $this->user;
      $output['slug'] = $this->slug;

      return $output;
    }

    return FALSE;
  }

  /**
   * Retrieves HTML version of story.
   *
   * @return bool|string
   *   Return false or the story's HTML.
   */
  function getStoryHtml() {
    if (!$this->isValid()) {
      return FALSE;
    }

    $result = $this->query($this->getUrl('minimal'));

    // We must find a way to check if the code == 200.
    if (!empty($result)) {
      return $result;
    }

    return FALSE;
  }

  /**
   * Returns the url.
   *
   * @return bool|string
   *   Return false or the URL.
   */
  function getUrl($type = 'story') {
    if (!$this->isValid()) {
      return FALSE;
    }

    $variable_fragment[] = 'storify';
    $variable_fragment[] = ucfirst(strtolower($type));
    $variable_fragment[] = 'Url';

    $variable = implode('', $variable_fragment);

    if (isset($this->$variable)) {
      return sprintf($this->$variable, $this->user, $this->slug);
    }
    return FALSE;
  }

  /**
   * Get the username of the story.
   *
   * @return false|string
   *   Return false or the username.
   */
  function getUser() {
    if (!$this->isValid()) {
      return FALSE;
    }

    return $this->user;
  }

  /**
   * Get the slug of the story.
   *
   * @return bool|string
   *   Return false or the slug.
   */
  function getSlug() {
    if (!$this->isValid()) {
      return FALSE;
    }

    return $this->slug;
  }

  /**
   * Performs an HTTP request.
   *
   * @param string $query
   *   The url to fetch.
   *
   * @return string
   *   The result.
   */
  public function query($url) {

    $client = new Guzzle\Service\Client($url, array(
      'ssl.certificate_authority' => FALSE,
    ));

    $request = $client->get($url);
    $this->logger->AddInfo("Request " . $request->getUrl());

    try {
      $response = $request->send();
      $output = $response->getBody(TRUE);
      $this->logger->AddDebug("Response: ". $output);
    } catch (Guzzle\Http\Exception\BadResponseException $e) {
      $this->logger->addAlert("Something is wrong.");
      return FALSE;
    }

    if (!is_object($request)) {
      $this->logger->addAlert("Something is wrong.");
      return FALSE;
    }

    /*
    if (!$response->getHeader('Content-Type')->hasValue('application/json;charset=UTF-8')) {
      $this->logger->addAlert("The Content-Type header is wrong.");
      $this->logger->addAlert($output);
      return FALSE;
    }
    */

    return $output;
  }

  /**
   * This function just returns the data.
   * You easily plug in a cache system.
   *
   * @param mixed $data
   *   The data to cache.
   * @param string $query
   *   The identifier.
   *
   * @return mixed
   *   Returns the data.
   */
  function cache($data, $query) {
    return $data;
  }

  /**
   * @param $file
   * @return bool
   */
  public function setLogFile($file) {
    if (!is_writable($file) && $file != 'php://stderr') {
      $file = sys_get_temp_dir() . '/Storify.log';
    }

    $this->logfile = $file;
    return TRUE;
  }

  /**
   * Set logger level.
   *  DEBUG => 100
   *  INFO => 200
   *  WARNING => 300
   *  ERROR => 400
   *  CRITICAL => 500
   *  ALERT => 550
   *
   * @param int $level Logger level.
   * @param string $file Optional file.
   */
  public function setLogConfig($level, $file = NULL) {
    $levels = array(
      LOGGER::DEBUG,
      LOGGER::INFO,
      LOGGER::WARNING,
      LOGGER::ERROR,
      LOGGER::CRITICAL,
      LOGGER::ALERT
    );

    $this->setLogFile($file);

    if (!in_array($level, $levels)) {
      $level = Logger::ALERT;
    }

    $streamhandler = new StreamHandler($this->logfile, $level);

    if (isset($this->logger_level)) {
      $this->logger->popHandler();
    } else {
      $this->logger = new Logger('Storify');
    }

    $this->logger->pushHandler($streamhandler);
    $this->logger_level = $level;
    $this->logger->AddInfo("Setting logfile to: " . $this->logfile);
    $this->logger->AddInfo("Setting log level to: " . $this->logger_level . "(".LOGGER::getLevelName($this->logger_level).")");
  }


}
