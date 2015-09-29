<?php

class DeepPage extends ORM_Model {
	public $id;

	public $title;
	public $description;

	public $slug;
	public $parent_id;
	public $on_menu;
	public $subtitle;
	public $intro;

	public $priority;

	static $table_name = "pages";
	static $_sql = array(
		'id'            	=> 'MEDIUMINT(9) PRIMARY KEY AUTO_INCREMENT',
		'parent_id'     	=> 'INT(9) FOREIGN KEY REFERENCES pages(id) NOT NULL',
		'title@lang'    	=> 'VARCHAR(1024)',
		'description@lang'	=> 'TEXT',
		'slug'          	=> 'VARCHAR(255)',
		'on_menu'       	=> 'SMALLINT(2)',
		'subtitle@lang' 	=> 'VARCHAR(1024)',
		'intro@lang'    	=> 'TEXT',
		'priority'      	=> 'MEDIUMINT(9) NOT NULL',
		'meta_keywords@lang'	=> 'VARCHAR(1024)',
		'meta_description@lang'	=> 'VARCHAR(1024)',
	);

	static $_columns = array(
		'title' => '',
		'onmenuHTML' => '',
		'num_subpagesHTML' => '',
	);
	static $_form = array(
		'title@lang'    	=> 'text `Title`',
		'description@lang'	=> 'textarea `Content`',
		'priority'      	=> 'number `Priority`',
		'slug'          	=> 'text `Slug`',
		'on_menu'       	=> 'radio `Menu`',
		'onmenuHTML'    	=> false,
		'menuCLASS'     	=> false,
		'adminHref'     	=> false,
		'pictures'      	=> '',
		'href'          	=> '',
		'teaserIdHTML'  	=> false,
		'idHTML'        	=> false,
		'parent_id'     	=> 'select `Category` @teaser',
		'parent'        	=> false,
		'subtitle@lang' 	=> false,//'text `Subtitle` @teaser',
		'intro@lang'    	=> false,//'textarea `Intro text` @teaser',
		'meta_keywords@lang'   	=> 'text `SEO Keywords` @teaser',
		'meta_description@lang'	=> 'textarea `SEO Description` @teaser',
		'subpages'      	=> false,
	);

	static $belongs_to = array(
		'parent' => array('DeepPage', 'parent_id', 'id'),
	);

	static $has_many = array(
		'pictures' => array('PagePicture'),
		'subpages' => array('DeepPage', 'parent_id', 'id'),
	);

	static $_auto = array(
		'title' => false,
		'href' => false,
		'adminHref' => 'page/*',
		'onmenuHTML' => false,
		'menuCLASS' => '',
		'idHTML' => false,
		'teaserIdHTML' => false,
		'intro' => false,
		'on_menu' => true,
		'parent_id_peers' => true,
	);

	public $on_menu_peers = array(
		array('name' => 'None', 'value' => '0', 'selected' => null),
		array('name' => 'Main', 'value' => '1', 'selected' => null),
	);
	public function on_menu_auto() {
		foreach ($this->on_menu_peers as $i=>$peer) {
			if ($peer['value'] == $this->on_menu) {
				$this->on_menu_peers[$i]['selected'] = 1;
			} else {
				$this->on_menu_peers[$i]['selected'] = 0;
			}
		}
		return $this->on_menu;
	}

	public function parent_id_filter($input) {
		return !is_numeric($input) ? 0 : (int)$input;
	}
	public function priority_filter($input) {
		return !is_numeric($input) ? 0 : (int)$input;
	}
	public function slug_filter($input) {
		if (!$input) $input = $this->title;
		return File::makeslug($input, TRUE);
	}

	public function title_auto() {
		if (!$this->title) return '('.get_called_class().'-'.$this->id.')';
		return $this->title;
	}
	public function idHTML_auto() {
		if ($this->slug) return 'page-'.$this->slug;
		return 'page_'.$this->id;
	}
	public function href_auto() {
		if (isset($this->parent)) { }
		if ($this->slug) return $this->slug;
		return 'page/'.$this->id;
	}
	public function onmenuHTML_auto() {
		if (!isset($this->on_menu_peers[$this->on_menu])) return '✗'; 
		$peer = $this->on_menu_peers[$this->on_menu];
		//return $peer['name'] . ($this->on_menu ? " menu" : "");
		return $this->on_menu ? '✔' : '✗';
	}

	public function coverUrl_auto() {
		if (isset($this->pictures[0])) {
			//return $this->pictures[0]->asThumb(60, 60, TRUE, 'greyscale');//small photo
			return $this->pictures[0]->getUrl('admin_list_thumb');
		}
		return '';
	}

	public function intro_auto() {
		if ($this->intro) return $this->intro;
		else return mb_substr($this->description, 0, 950);
	}

	public function parent_id_peers_auto() {
		$c = get_called_class();
		$pages = $c::GetAll();
		$peers = array();
		$peers[] = array('name' => '(None)', 'value' => '0', 'selected' =>
			(0 == $this->parent_id ? 1 : 0)
		);
		foreach ($pages as $page) {
			if ($page->id == $this->id) continue;
			$peers[] = array('name' => $page->title, 'value' => $page->id, 'selected' =>
				($page->id == $this->parent_id ? 1 : 0)
			);
		}
		return $peers;
	}

	/* Not so auto, as this is intensive */
	public function loadSubPages() {
		$this->subpages->order_by('priority');
		$this->subpages->load();
		$this->num_subpages = $this->subpages->count();
		$this->num_subpagesHTML = $this->num_subpages;
		if (!$this->num_subpagesHTML) $page->num_subpagesHTML='';
		$this->subpagesTXT = '';
		foreach ($this->subpages as $subpage) {
			$this->subpagesTXT .= $subpage->title . "; \n";
		}
	}

	static function isChildOf($page, $other_page) {
		while ($page) {
			if ($page->parent_id == $other_page->id) return true;
			$page = $page->parent;
		}
		return false;
	}

	static public function GetAll() {
		static $cache = null;
		if (!$cache) {
			$cache = ORM::Collection(get_called_class(), null, 1);
		}
		return $cache;
	}
}

?>
