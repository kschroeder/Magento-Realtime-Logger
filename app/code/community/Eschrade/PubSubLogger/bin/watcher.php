<?php

$paths = explode(DIRECTORY_SEPARATOR, __DIR__);

$paths = array_slice($paths, 0, -5);
$path = implode(DIRECTORY_SEPARATOR, $paths).DIRECTORY_SEPARATOR.'Mage.php';
require_once $path;

Mage::app();
$opts = new Zend_Console_Getopt(
  array(
    'all-events'    			=> 'Listen for all events',
  	'enable-blocks'				=> 'Enable block events (overrides all-events)',
  	'enable-models'				=> 'Enable model events (overrides all-events)',
  	'events=s'					=> 'Comma-separated list of events to listen for (overides any settings)',
  	'resolve-classes'			=> 'Look up the class based off of group class names',
  	'hide-event-data'			=> 'Hides the event data when events are turned on',
  	'hide-event-observers'		=> 'Hides the event observers when events are turned on',
  	'delay-messages=i'			=> 'Delay messages by X milliseconds (default 75ms) - Makes it easier to follow the log',
  	'no-color'					=> 'Turns off color coding of elapsed times',
  	'show-request-id'			=> 'Show the unique ID for each request',
  	'show-sql-s'				=> 'Show the SQL in real time.  Optionally add a preg_match() filter',
  	'show-sql-summary'			=> 'Show the SQL summary at the end of the request',
  	'help'						=> 'This help page'
  )
);

try {
	$opts->parse();
} catch (Zend_Console_Getopt_Exception $e) {
	echo "\n" . $e->getMessage() . "\n\n";	
	$opts->setArguments(array());
}
if ($opts->getOption('help')) {
	echo "\nThis script is used to connect to the front end of the Eschrade_PubSubLogger utility.\n";
	echo "\nNote that if you are using the File cache backend you may need to clear it from /tmp if ";
	echo "the user you are running this script under does not have permission in {magento}/var/cache.\n\n";
	if (!extension_loaded('pcntl')) {
		echo "Note: that the pcntl extension is not loaded and so the script will not send a logging";
		echo " shutdown command if you hit CTRL-C.  Install the pcntl extension if you would like this behavior (preferred).\n\n";
	}
	echo $opts->getUsageMessage();
	echo "\n";
	return;
}

$config = array(
	Eschrade_PubSubLogger_Model_Observer::CONFIG_LISTENER_EXISTS 		=> 1,
	Eschrade_PubSubLogger_Model_Observer::CONFIG_EVENT_LOG_ALL	 		=> (int)$opts->getOption('all-events'),
	Eschrade_PubSubLogger_Model_Observer::CONFIG_EVENT_IGNORE_BLOCKS	=> (int)!$opts->getOption('enable-blocks'),
	Eschrade_PubSubLogger_Model_Observer::CONFIG_EVENT_IGNORE_MODELS	=> (int)!$opts->getOption('enable-models'),
	Eschrade_PubSubLogger_Model_Observer::CONFIG_PROFILE_SQL			=> (int)$opts->getOption('show-sql'),
	Eschrade_PubSubLogger_Model_Observer::CONFIG_SQL_SUMMARY			=> 0
);

$handler = new Logger_Handler($config, $opts);
if (extension_loaded('pcntl')) {
	$cb = array('Logger_Handler', 'shutdown');
	pcntl_signal(SIGTERM, $cb);
	pcntl_signal(SIGHUP,  $cb);
	pcntl_signal(SIGUSR1, $cb);
	$pid = posix_getpid();
// 	posix_kill($pid, SIGUSR1);
// 	pcntl_signal(SIGINT, array('Logger_Handler', 'shutdown'));;
// 	pcntl_signal(SIGTERM, array('Logger_Handler', 'shutdown'));
}

register_shutdown_function(array('Logger_Handler', 'shutdown'));
$events = explode(',', $opts->getOption('events'));
$events = array_diff($events, array('')); // remove blank event options
if ($opts->getOption('all-events')) {
	$events[] = '';
}
foreach ($events as $event) {
	$handler->addEventListener($event);
}
$handler->run();


class Logger_Handler
{
	
	protected static $_redis;
	protected $_config;
	protected $_queues = array();
	protected $_logQueue;
	protected $_mainQueue;
	protected $_eventQueue;
	protected $_opts;
	protected $_delay = 75;
	protected $_cols;
	protected $_elapsedColors = array(
		'0.5'	=> "\033[0;30;41m",
		'0.05'	=> "\033[0;30;43m",
		'0'		=> "\033[0;32m"
	);
	protected $_showSql = false;
	protected $_sqlRegex = false;
	
	public function __construct(array $config, Zend_Console_Getopt $opts)
	{
		if (!self::$_redis) {
			self::$_redis = Mage::getSingleton('eschrade_pslogger/redis')->getRedisClient();
		}
		$this->_config = $config;
		$this->_opts = $opts;
		if ($this->_opts->getOption('delay-messages')) {
			$this->_delay = $this->_opts->getOption('delay-messages');
		}
		$this->_cols = exec('tput cols');
		$showSql = $this->_opts->getOption('show-sql');
		$this->_showSql = (bool)$this->_opts->getOption('show-sql');
		if (!is_bool($this->_opts->getOption('show-sql'))) {
			$this->_sqlRegex = $this->_opts->getOption('show-sql');
		}
	}
	
	public function addEventListener($event)
	{
		$this->_queues[] = $event;
	}
	
	public function run()
	{

		$queues = array();
		foreach ($this->_queues as $queue) {
			$queues[] = $this->getEndPoint('events_' . $queue . '*');
			$this->_config[Eschrade_PubSubLogger_Model_Observer::CONFIG_EVENT_ENABLED] = 1;
		}
		if (isset($this->_config[Eschrade_PubSubLogger_Model_Observer::CONFIG_EVENT_ENABLED])) {
			$this->_config[Eschrade_PubSubLogger_Model_Observer::CONFIG_EVENT_LIST] = implode(',', $this->_queues);
		}
		$queues[] = $this->getEndPoint();
		$queues[] = $this->getEndPoint('pslogger_message');
		if ($this->_showSql) {
			$queues[] = $this->getEndPoint('pslogger_sql');
			$this->_config[Eschrade_PubSubLogger_Model_Observer::CONFIG_PROFILE_SQL] = 1;
		}
		if ($this->_opts->getOption('show-sql-summary')) {
			$this->_config[Eschrade_PubSubLogger_Model_Observer::CONFIG_SQL_SUMMARY] = 1;
			$queues[] = $this->getEndPoint('pslogger_sql_summary');
		}
		
		foreach ($this->_config as $key => $value) {
			self::$_redis->hSet($this->getEndPoint('config'), $key, $value);
		}
		while (true) {
			try {
				self::$_redis->pSubscribe($queues, array($this, 'processMessage'));
			} catch (CredisException $e) {
				
			}
		} 
	}
	
	public function processMessage($redis, $channel, $queue, $message)
	{
		$requestIdLength = 0;
		$message = json_decode($message, true);
		if ($this->_opts->getOption('show-request-id')) {
			echo "{$message['request']} ";
			$requestIdLength = strlen($message['request']) + 1;
		}
		$message = $message['data'];
		if ($queue == $this->getEndPoint()) {
			echo $message."\n";
		} else if (strpos($queue, $this->getEndPoint('events')) === 0) {
			$this->_outputEvent($queue, $message, $requestIdLength);
		} else if ($queue == $this->getEndPoint('pslogger_message')) {
			$this->_logMessage($message);
		} else if ($queue == $this->getEndPoint('pslogger_sql')) {
			if ($this->_logSql($message)) {
				return; // bypass the delay if the regex doesn't match
			}
			
		} else if ($queue == $this->getEndPoint('pslogger_sql_summary')) {
			$this->_logSqlSummary($message);
		}
		time_nanosleep(0, $this->_delay * 1000000);
	}
	
	protected function _logMessage($message)
	{
		if (strpos($message, 'Request Started') === 0) {
			// Allows us to resize the terminal in between requests without using ncurses
			$this->_cols = exec('tput cols');
		}
		
		echo "** {$message}\n";
	}
	
	protected function _logSqlSummary($message)
	{
		$data = json_decode($message);
		$parts = array();
		foreach ($data as $key => $value) {
			$parts[] = "{$key}: {$value}";
		}
		$message = implode(', ', $parts);
		echo "SQL Summary: $message\n";
	}
	
	protected function _logSql($message)
	{
		if ($this->_showSql) {
			$data = json_decode($message, true);
			if ($this->_sqlRegex && !preg_match("/{$this->_sqlRegex}/", $data['query'])) {
				return true;
			}
			echo "SQL: {$data['query']}\n";
			if ($data['params']) {
				echo "Params: \n";
				foreach ($data['params'] as $name => $value) {
					echo "\t{$name}: {$value}\n";
				}
			}
			echo "\nelapsed: {$data['elapsed']}\n\n";
		}
		return false;
	}
	
	protected function _outputEvent($queue, $message, $requestIdLength)
	{
		$data = json_decode($message, true);
			
		$output = 'Event: ';
		$startPos = strlen($this->getEndPoint('events')) + 1;
		$output .= substr($queue, $startPos);
		if (isset($data[Eschrade_PubSubLogger_Model_Observer::EVENT_ELAPSED])) {
			$color = '';
			if (!$this->_opts->getOption('no-color')) {
				foreach ($this->_elapsedColors as $limit => $control) {
					if ($data[Eschrade_PubSubLogger_Model_Observer::EVENT_ELAPSED] > $limit) {
						$color = $control;
						break;
					}
				}
			}
			$elapsed = $color . "elapsed: {$data[Eschrade_PubSubLogger_Model_Observer::EVENT_ELAPSED]}s";
			$elapsed = substr($elapsed, 0, 20 + strlen($color));
			$repeat = $this->_cols - (strlen($elapsed) + strlen($output) + $requestIdLength + 1 - strlen($color));
			if ($repeat < 1) $repeat = 1;
			$elapsed = str_repeat(' ',  $repeat). $elapsed;
			$output .= $elapsed;
			if (!$this->_opts->getOption('no-color')) {
				$output .= "\033[0;30m";
			}
		}
		
		echo "{$output}\n";
		$output = '';
		foreach ($data as $event => $info) {
		if ($event == Eschrade_PubSubLogger_Model_Observer::EVENT_ELAPSED) continue;
		if (!$this->_opts->getOption('hide-event-observers')) {
			$output .= "\nEvent Listener: {$event}";
			$output .= "\n";
			if (isset($info['callbacks']) && count($info['callbacks'])) {
				foreach ($info['callbacks'] as $callback) {
					foreach ($callback as $key => $data) {
						if ($key == 'class' && $this->_opts->getOption('resolve-classes')) {
							$data = Mage::getConfig()->getModelClassName($data);
						}
						$output .= "\t{$key}: {$data}\n";
					}
					$output .= "\n";
				}
			}
		}
		if (!$this->_opts->getOption('hide-event-data')) {
			if (isset($info['data']) && count($info['data'])) {
				$output .= "Event Data: \n";
					foreach ($info['data'] as $key => $value) {
							$output .= "\t{$key}: {$value}\n";
					}
				}
			}
		}
						
		if ($output) {
			echo $output;
			echo "\n";
		}
	}
	
	public static function shutdown()
	{
		echo "Shutting down...\n";
		if (self::$_redis instanceof Credis_Client) {
			self::$_redis->hSet(
				$this->getEndPoint('config'),
				Eschrade_PubSubLogger_Model_Observer::CONFIG_LISTENER_EXISTS,
				0
			);
		}
		exit;
	}
	
	function getEndPoint($name = null) {
		if ($name) {
			$name = '_' . $name;
		}
		return Mage::getStoreConfig(Eschrade_PubSubLogger_Model_Observer::SYSTEM_CONFIG_ENDPOINT) . $name;
	}
}