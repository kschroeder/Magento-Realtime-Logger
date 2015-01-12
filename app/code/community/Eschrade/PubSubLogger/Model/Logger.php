<?php

class Eschrade_PubSubLogger_Model_Logger extends Zend_Log_Writer_Stream
{

	protected $_redis;
	
	public function __construct($streamOrUrl = null, $mode = null)
	{
		if($streamOrUrl) {
			parent::__construct($streamOrUrl, $mode);
		}		
	}

	/**
	 * @return Credis_Client
	 */
	
	public function getConnection()
	{
		if (!$this->_redis instanceof Eschrade_PubSubLogger_Model_Redis) {
			$this->_redis = Mage::getSingleton('eschrade_pslogger/redis')->getRedisClient();
		}
		return $this->_redis;
	}
	
	protected function _write($event)
	{
		if (Mage::getStoreConfigFlag(Eschrade_PubSubLogger_Model_Observer::SYSTEM_CONFIG_ENABLED)) {
			$message = $this->_formatter->format($event);
			$this->logDirect($message);
			if (Mage::getStoreConfigFlag(Eschrade_PubSubLogger_Model_Observer::SYSTEM_CONFIG_PASSTHRU)) {
				return parent::_write($event);
			}
		} else {
			return parent::_write($event);	
		}
	}
	
	public function logDirect($message)
	{
		$this->getConnection()->publish(
			Mage::getStoreConfig(Eschrade_PubSubLogger_Model_Observer::SYSTEM_CONFIG_ENDPOINT),
			$message
		);
		
	}
	
}