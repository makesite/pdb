<?php

require_once APP_DIR.'/models/'.'_files.php';

class PagePicture extends File {

	public $page_id;
	public $priority = 0;

	static $_sql = array(
		'page_id' => 'MEDIUMINT(11) FOREIGN KEY REFERENCES pages(id)',
		'priority' => 'SMALLINT(2) NOT NULL',//TFile has "order_num"
	);
	static $belongs_to = array(
		'page' => array('Page', 'page_id', 'id'),
	);

	static $_auto = array(
		'class' => false,
		'thumbUrl' => false,
		'adminEmbed' => false,
	//	'teaserUrl' => false,
	//	'teaserx2Url' => false,
	);

	static $imgprofile = array(
		'thumb' => array(
			'w'=>400,
			'h'=>340,
			'crop'=>2,
			'filter'=>null,
			'format'=>'jpg',
		),
		'admin_list_thumb' => array(
			'w'=>64,
			'h'=>64,
			'crop'=>1,
			'filter'=>null,
			'format'=>'jpg',
		),
		'subpage_list_thumb' => array(
			'w'=>266,
			'h'=>132,
			'crop'=>1,
			'filter'=>null,
			'format'=>'jpg',
		),
	);
	public function getUrl($profile_name='', $mode='') {
		if (!isset($this->path)) return '';
		//if (!$this->path) return ''; //if inheriting from TFile, path might be already set..

		if ($profile_name) {
			if (!isset(PagePicture::$imgprofile[$profile_name])) return '';
			$profile = PagePicture::$imgprofile[$profile_name];
			$url = $this->asThumb($profile['w'], $profile['h'], $profile['crop'], $profile['filter'], $profile['format']);
			if (isset($profile['forcemode']) && !$mode) $mode = $profile['forcemode'];
		} else {
			$url = $this->href;
		}

		if ($mode == 'css') {
			return "background-image: url('" . $url . "');";
		}
		return $url;
	}

	public function filename_filter($str) {
		return File::makeslug($str, FALSE, FALSE);
	}

	public function class_auto() {
		return '';
	}
	public function thumbUrl_auto() {
		return $this->getUrl('thumb');
	}
	public function adminEmbed_auto() {
		return '{{File `'.$this->name.'`}}';
	}


}
?>
