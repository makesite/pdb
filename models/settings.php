<?php

class Setting extends ORM_Model {

	public $id;
	public $name;
	public $value;
	public $hints;

	static $_sql = array(
		'id' => 'MEDIUMINT(11) NOT NULL AUTO_INCREMENT PRIMARY KEY',
		'name' => 'VARCHAR(255) NOT NULL UNIQUE',
		'value' => 'TEXT NOT NULL',
		'hints' => 'VARCHAR(255) NOT NULL',
	);

	static function Get($item = null, $default = null, $raw = false) {
		static $config = null;
		static $settings = null;
		if ($config == null) {
			$settings = ORM::Collection('Setting');
			$config = array();
			foreach ($settings as $setting) {
				$config[$setting->name] = $setting->value;
			}
		}
		if ($raw === true) {
			return $settings;
		}
		if ($item == null) {
			return $config;
		}
		if (isset($config[$item])) {
			return $config[$item];
		} else if ($default) {
			$sett = new Setting();
			$sett->name = $item;
			$sett->value = $default;
			$sett->hints = '';
			$sett->save();
			return $default;
		}
		return null;
	}
}

?>