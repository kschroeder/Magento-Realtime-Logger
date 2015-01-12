<?php

class Eschrade_PubSubLogger_Model_Observer
{

	const SYSTEM_CONFIG_ENABLED 		= 'dev/pubsub_logger/enable';
	const SYSTEM_CONFIG_PASSTHRU 		= 'dev/pubsub_logger/passthru';
	const SYSTEM_CONFIG_ENDPOINT 		= 'dev/pubsub_logger/endpoint';
	const CONFIG_EVENT_ENABLED 			= 'enable_event_logger';
	const CONFIG_EVENT_LOG_ALL 			= 'event_log_all';
	const CONFIG_EVENT_IGNORE_BLOCKS  	= 'ignore_blocks';
	const CONFIG_EVENT_IGNORE_MODELS  	= 'ignore_models';
	const CONFIG_LISTENER_EXISTS 	 	= 'listener_exists';
	const CONFIG_EVENT_LIST	 		 	= 'event_list';
	const CONFIG_PROFILE_SQL 		 	= 'profile_sql';
	const CONFIG_SQL_SUMMARY 		 	= 'sql_summary';
	const EVENT_ELAPSED					= '____elapsed_event_time';
	
	protected $_eventsPublished = array();
	protected $_startTime;
	protected $_config;
	protected $_lastEventTimestamp;
	protected $_fullActionName;
	protected $_uniqId;

	public function __construct()
	{
		$this->_uniqId = uniqid();
	}
	
	public function getDateTimeString()
	{
		$t = microtime(true);
		$micro = sprintf("%06d",($t - floor($t)) * 1000000);
		$d = new DateTime( date('Y-m-d H:i:s.'.$micro, $t) );
		
		return $d->format("Y-m-d H:i:s.u");
	}
	
	public function getConfigSetting($name)
	{
		if (isset($this->_config[$name])) {
			return $this->_config[$name];
		}
		return false;
	}
	
	public function setFullActionName(Varien_Event_Observer $event)
	{
		$this->_fullActionName = $event->getControllerAction()->getFullActionName();
	}
	
	public function publish($queue, $message)
	{
		$redis = Mage::getSingleton('eschrade_pslogger/redis')->getRedisClient();
		$message = array('request' => $this->_uniqId, 'data' => $message);
		$message = json_encode($message);
		$redis->publish($queue, $message);
	}
	
	public function attachToAllEvents(Varien_Event_Observer $event)
	{
		if (!Mage::getStoreConfigFlag(self::SYSTEM_CONFIG_ENABLED)) {
			return;
		}
			
		$redis = Mage::getSingleton('eschrade_pslogger/redis')->getRedisClient();
		$this->_config = $redis->hGetAll(Mage::getStoreConfig(self::SYSTEM_CONFIG_ENDPOINT) . '_config');
		if (!$this->getConfigSetting(self::CONFIG_LISTENER_EXISTS)) {
			return;
		}
		
		if ($this->getConfigSetting(self::CONFIG_PROFILE_SQL) || $this->getConfigSetting(self::CONFIG_SQL_SUMMARY)) {
			$profiler = Mage::getSingleton('eschrade_pslogger/profiler');
			$profiler->setObserver($this);
			$connections = Mage::getSingleton('core/resource')->getConnections();
			foreach ($connections as $connection) {
				/* @var $connection Varien_Db_Adapter_Pdo_Mysql */
				$dbProfiler = $connection->getProfiler();
				if (!$dbProfiler instanceof Eschrade_PubSubLogger_Model_Profiler) {
					$dbProfiler = $profiler;
					$connection->setProfiler($profiler);
				}
			} 
		}
		
		$this->_startTime = microtime(true);
		$this->publish(
			Mage::getStoreConfig(
					self::SYSTEM_CONFIG_ENDPOINT
			) . '_pslogger_message',
			'Request Started: ('
			 . Mage::app()->getRequest()->getRequestUri()
			 . ') '
			 . $this->getDateTimeString()
		);
		register_shutdown_function(array($this, 'shutdown'));
	
		$eventNames = array();
		if ($this->getConfigSetting(self::CONFIG_EVENT_ENABLED)) {
			if ($this->getConfigSetting(self::CONFIG_EVENT_LOG_ALL)) {
				$type = get_class(Mage::getConfig()->getNode('global'));
				$areas = array('global', 'frontend', 'adminhtml');
				
				foreach ($areas as $area) {
					$events = Mage::getConfig()->getNode("{$area}/events");
					if ($events) {
						foreach ($events->children() as $eventName => $config) {
							$eventNames[$eventName] = 1;
						}
					}
				}
			}
	
			$customEventListeners = $this->getConfigSetting(self::CONFIG_EVENT_LIST);
			if ($customEventListeners) {
				$customEventListeners = explode(',', $customEventListeners);
				foreach ($customEventListeners as $event) {
					$eventNames[$event] = 1;
				}
			}
			$node = Mage::getConfig()->getNode('global/events');
			foreach (array_keys($eventNames) as $event) {
				$node->{$event}->observers->pubsub_logger_hook->class = 'eschrade_pslogger/observer';
				$node->{$event}->observers->pubsub_logger_hook->method = 'logEvent';
			}
		
			$this->_lastEventTimestamp = microtime(true);
		}
	}
	
	public function shutdown()
	{
		if ($this->getConfigSetting(self::CONFIG_EVENT_ENABLED)) {
			if ($this->getConfigSetting(self::CONFIG_SQL_SUMMARY)) {
				$data = Mage::getSingleton('eschrade_pslogger/profiler')->getTypes();
				$data = json_encode($data);
				$this->publish(
					Mage::getStoreConfig(
						self::SYSTEM_CONFIG_ENDPOINT
					) . '_pslogger_sql_summary',
					$data
				);
			}
			$this->sendMessage('Request Finished: ' . $this->getDateTimeString());
			if ($this->_fullActionName) {
				$this->sendMessage('Full Action Name: ' . $this->_fullActionName);
				
			}
			$elapsed = microtime(true) - $this->_startTime;
			$this->sendMessage('Elapsed Time: ' . $elapsed);
		}
	}
	
	public function sendMessage($message)
	{
		$this->publish(
			Mage::getStoreConfig(
				self::SYSTEM_CONFIG_ENDPOINT
			) . '_pslogger_message',
			$message
		);
	}
	
	protected function _renderLoggedEvent($value)
	{
		if (is_object($value)) {
			return get_class($value);
		} else if (is_array($value)) {
			$values = array();
			foreach ($value as $k => $v) {
				$values[$k] = $this->_renderLoggedEvent($v);
			}
			return implode(',', $values);
		} else {
			return $value;
		}
		
	}
	
	public function logEvent(Varien_Event_Observer $event)
	{
		$eventName = $event->getEvent()->getName();
		if ($this->getConfigSetting(self::CONFIG_EVENT_IGNORE_BLOCKS)
				&& (strpos($eventName, 'block_html') !== false || strpos($eventName, 'to_html') !== false)) {
			return;
		} else if ($this->getConfigSetting(self::CONFIG_EVENT_IGNORE_MODELS)
			&& strpos($eventName, 'model_') === 0) {
			return;
		}
		
		$areas = array('global');
		$layout = Mage::getSingleton('core/layout');
		/* @var $layout Mage_Core_Model_Layout */
		$currentArea = $layout->getArea();
		if ($currentArea) {
			$areas[] = $currentArea;
		}
		
		$observers = array();
		foreach ($areas as $area) {
			$node = Mage::getConfig()->getNode("{$area}/events/{$eventName}/observers");
			if ($node) {
				foreach ($node->children() as $observer => $config) {
					if ($observer == 'pubsub_logger_hook') continue;
					if (!isset($observers[$observer])) {
						$observers[$observer] = array('callbacks' => array(), 'data' => array());
						foreach ($event->getData() as $key => $value) {
							if ($key == 'event') continue;
							$observers[$observer]['data'][$key] = $this->_renderLoggedEvent($value);
						}
					}
					
					$observers[$observer]['callbacks'][] = array(
						'class'		=> (string)$config->class,
						'method'	=> (string)$config->method,
// 						'disabled'	=> isset($config->disabled)?$config->disabled:0,
// 						'type'		=> isset($config->type)?$config->type:'singleton',
					);
				}
			}
		}
		$observers[self::EVENT_ELAPSED] = microtime(true) - $this->_lastEventTimestamp;
		
		$this->publish(
			Mage::getStoreConfig(
				self::SYSTEM_CONFIG_ENDPOINT
			) . '_events_' . $eventName,
			json_encode($observers)
		);
		$this->_lastEventTimestamp = microtime(true);
	}
	
	

}