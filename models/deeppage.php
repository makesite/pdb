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
		'id'    	=> 'MEDIUMINT(9) PRIMARY KEY AUTO_INCREMENT',
		'title' 	=> 'VARCHAR(1024)',
		'description'	=> 'TEXT',
		'slug'  	=> 'VARCHAR(255)',
		'parent_id'	=> 'MEDIUMINT(9) NOT NULL',
		'on_menu'	=> 'SMALLINT(2)',
		'subtitle'	=> 'VARCHAR(1024)',
		'intro'		=> 'TEXT',
		'priority'	=> 'MEDIUMINT(9) NOT NULL',
	);

	static $_form = array(
		'title' => 'text `Название`',
		'description' => 'textarea `Содержание`',
		'priority' => 'number `Приоритет`',
		'slug' => 'text `имя-в-URL`',
		'on_menu' => 'radio `Меню`',
		'onmenuHTML' => false,
		'menuCLASS' => false,
		'adminHref' => false,
		'pictures' => '',
		'href'=> '',
		'teaserIdHTML' => false,
		'idHTML' => false,
		'parent_id' => 'select `Категория` @teaser',
		'subtitle' => 'text `Субтитул` @teaser',
		'intro' => 'textarea `Минитекст` @teaser',
	);

	static $has_one = array(
		//'parent' => array('DeepPage', 'id', 'parent_id'),
	);

	static $has_many = array(
		'pictures' => array('PagePicture'),
		//'children' => array('DeepPage', 'id', 'parent_id'),
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
		array('name' => 'None', 'value' => '0', 'selected' => '1'),
		array('name' => 'Main', 'value' => '1', 'selected' => '0'),
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
		if ($this->slug) return $this->slug;
		return 'page/'.$this->id;
	}
	public function onmenuHTML_auto() {
		if (!isset($this->on_menu_peers[$this->on_menu])) return '✗'; 
		$peer = $this->on_menu_peers[$this->on_menu];
		return $peer['name'] . " меню";
		//return $this->on_menu ? '✔' : '✗';
	}

	public function coverUrl_auto() {
		if (isset($this->pictures[0])) {
			//return $this->pictures[0]->asThumb(60, 60, TRUE, 'greyscale');//small photo
			return $this->pictures[0]->thumbUrl_auto();
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
		$peers[] = array('name' => '(Нет)', 'value' => '0', 'selected' => 
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

	static public function GetAll() {
		static $cache = null;
		if (!$cache) {
			$cache = ORM::Collection(get_called_class(), null, 1);
		}
		return $cache;
	}
/*
	static function SetTeaser($obj) {
		$query = 'UPDATE '.ORM::getTable('Article').' SET is_teaser = 0 WHERE is_teaser = ? AND id <> ?';
		$sql[$query] = array( $obj->is_teaser, $proj->id );

		$db = ORM::getDB();
		$db->run($sql);

		return true;
	}
*/
}

?>