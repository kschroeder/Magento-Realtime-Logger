<?php

class Eschrade_PubSubLogger_Model_Profiler extends Zend_Db_Profiler
{
	
	protected $_observer;
	protected $_types = array();
	
	public function setObserver(Eschrade_PubSubLogger_Model_Observer $observer)
	{
		$this->_observer = $observer;
		$this->setEnabled(true);
	}
	
	public function getTypes()
	{
		$types = array();
		foreach ($this->_types as $type => $count) {
			switch ($type) {
				case self::INSERT:
					$types['INSERT'] = $count;
					break;
				case self::SELECT:
					$types['SELECT'] = $count;
					break;
				case self::UPDATE:
					$types['UPDATE'] = $count;
					break;
				case self::DELETE:
					$types['DELETE'] = $count;
					break;
				case self::CONNECT:
					$types['CONNECT'] = $count;
					break;
				case self::TRANSACTION:
					$types['TRANSACTION'] = $count;
					break;
			}
		}
		return $types;
	}
	
	public function queryEnd($queryId)
	{
		$result = parent::queryEnd($queryId);
		if ($result == self::STORED) {
			
			$profile = $this->getLastQueryProfile();
			/* @var $profile Zend_Db_Profiler_Query */
			if (!isset($this->_types[$profile->getQueryType()])) {
				
				$this->_types[$profile->getQueryType()] = 0;
			}
			$this->_types[$profile->getQueryType()]++;
			$query = $profile->getQuery();
			$data = array(
				'query'		=> $query,
				'elapsed'	=> $profile->getElapsedSecs(),
				'params'	=> $profile->getQueryParams()
			);
			$data = json_encode($data);
			$queue = Mage::getStoreConfig(Eschrade_PubSubLogger_Model_Observer::SYSTEM_CONFIG_ENDPOINT) . '_pslogger_sql';
			$this->_observer->publish($queue, $data);
		}
	}

	
}