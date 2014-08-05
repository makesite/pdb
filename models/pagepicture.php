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
		'page' => array('Article', 'page_id', 'id'),
	);

	static $_auto = array(
		'class' => false,
		'thumbUrl' => false,
		'adminEmbed' => false,
	//	'teaserUrl' => false,
	//	'teaserx2Url' => false,
	);

	static $THUMB_W = 225;
	static $THUMB_H = 170;
	static $LARGE_W = 776;
	static $LARGE_H = 420;

	public function filename_filter($str) {
		return File::makeslug($str, FALSE, FALSE);
	}

	public function class_auto() {
		return '';
	}
	public function adminEmbed_auto() {
		return '{{File `'.$this->name.'`}}';
	}

	public function largeUrl_auto() {
		if (!isset($this->path)) return '';
		//if (!$this->path) return ''; //if inheriting from TFile, path might be already set..
		return $this->asThumb(PagePicture::$LARGE_W, PagePicture::$LARGE_H, 2, null, 'jpg');
	}

	public function thumbUrl_auto() {
		if (!isset($this->path)) return '';
		//if (!$this->path) return ''; //if inheriting from TFile, path might be already set..
		return $this->asThumb(PagePicture::$THUMB_W, PagePicture::$THUMB_H, 0, null, 'jpg');
	}

	public function greyLargeUrl_auto() {
		if (!isset($this->path)) return '';
		//if (!$this->path) return ''; //if inheriting from TFile, path might be already set..
		return $this->asThumb(PagePicture::$LARGE_W, PagePicture::$LARGE_H, 2, 'greyscale', 'jpg');
	}

	public function greyThumbUrl_auto() {
		if (!isset($this->path)) return '';
		//if (!$this->path) return ''; //if inheriting from TFile, path might be already set..
		return $this->asThumb(PagePicture::$THUMB_W, PagePicture::$THUMB_H, 0, 'greyscale', 'jpg');
	}


}
?>