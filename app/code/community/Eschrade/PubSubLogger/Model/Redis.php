<?php

class Eschrade_PubSubLogger_Model_Redis
{
	
	const CONFIG_HOSTNAME 		= 'global/resources/redis/hostname';
	const CONFIG_PORT 			= 'global/resources/redis/port';
	const CONFIG_TIMEOUT 		= 'global/resources/redis/timeout';
	const CONFIG_PERSISTENT 	= 'global/resources/redis/persistent';
	const CONFIG_DB 			= 'global/resources/redis/db';
	const CONFIG_PASSWORD 		= 'global/resources/redis/password';
	
	protected static $_redis;
	
	public function getRedisClient()
	{
		if (!self::$_redis instanceof Credis_Client) {
			self::$_redis = new Credis_Client(
				Mage::getConfig()->getNode(self::CONFIG_HOSTNAME), // These two need to be here either way
				Mage::getConfig()->getNode(self::CONFIG_PORT),
				$this->_getConfig(self::CONFIG_TIMEOUT, null),
				$this->_getConfig(self::CONFIG_PERSISTENT, ''),
				$this->_getConfig(self::CONFIG_DB, 0),
				$this->_getConfig(self::CONFIG_PASSWORD, null)
			);
		}
		return self::$_redis;
	}
	
	protected function _getConfig($node, $default)
	{
		$val = Mage::getConfig()->getNode($node);
		if ($val === false) {
			return $default;
		}
		return $val;
	}
	
}