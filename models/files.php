<?php

class FilePath extends ORM_Model {

	public $id;
	public $resource;
	public $link_count;

	static $_sql = array(
		'id'=>'INT(9) PRIMARY KEY AUTO_INCREMENT',
		'resource' =>'VARCHAR(255) UNIQUE',
		'link_count'=>'INT(9) NOT NULL',
	);

	static $has_many = array(
		'files' => array('File'=>'res_id'),
	);
}

class File extends ORM_Model {

	public $id;
	public $name;

	public $resource;
	public $res_id;

	public $title;
	public $description;
	public $hash;
	public $mime;
	public $size;

	public $order_num;

	static $has_one = array(
		'resource' => array('FilePath' => 'res_id'),
	);

	static $_sql = array(
		'id' => 'MEDIUMINT(11) PRIMARY KEY AUTO_INCREMENT',
		'name' => 'varchar(255)',// unique',
		'order_num' => 'int(9)',
//		'resource' => '',
		'res_id' => 'int(9) FOREIGN KEY REFERENCES filepaths (id)',
		'hash' => 'varchar(64)',	
		'mime' => 'varchar(255)',
		'size' => 'int(9)',
		'title' => 'VARCHAR(1024)',
		'description'=>'TEXT',
	);
	static $_form = array(
		'field' => 'text required',
		'file' => 'file',
		'order_num' => 'hidden',
		'resource' => 'text required',
		'res_id' => '',
	);
	static $_auto = array(
		'path' => true,
		'href' => true,
	);
	public function path_auto() {
		return is_string($this->resource) ? $this->resource : $this->resource->resource;
	}
	public function href_auto() {
		return File::Work_Dir() . '/'. $this->path . '/' . $this->name;
	}

	/*
	 * Bad code below 
	 */

	private $TABLE_NAME = "#__files";
	private $TABLE_RES = "#__filepaths";

	private $SECONDARY_KEY = "resource";
	private $SECONDARY_LINK = "path";
	private $UNIQUE_NAME = "name";

	static function WORK_DIR($set = null) {
		static $dir;
		if ($set) {
			$dir = $set;
		} else if (!$dir) {
			$dir = _work_dir();
			$dir = rtrim($dir, '/');
		}
		return $dir;
	}

	public function FIXTURE() { 
		db_set("TRUNCATE TABLE ".$this->TABLE_NAME.";", NULL);
		db_set("TRUNCATE TABLE ".$this->TABLE_RES.";", NULL);
	}
/*
	public function GET($id) {
		if (!$id) return FALSE;
		$res = null;

		$doc = db_get1('SELECT T.*, R.resource FROM '.$this->TABLE_NAME. ' T'.
		' JOIN '.$this->TABLE_RES.' R ON T.res_id = R.id '.
		' WHERE T.'.$this->PRIMARY_KEY." = ?", $id);

		if (!$doc) return FALSE;

		$c = get_class($this);
		$obj = new $c;

		foreach ($doc as $prop=>$k)
			$obj->$prop = $k;

		$id_name = $this->PRIMARY_KEY;
		$res_name = $this->SECONDARY_KEY;

		if ($res) $obj->$res_name = $res['resource'];

		return $obj;
	}
*/
	private function rdir($apath, $base='') {
		if (!$apath) throw new Exception("Must provide a path");
		//$base = $this->dir;
		$path = $base;
		foreach ($apath as $dir) {
			$path .= '/'.$dir;
			//_debug_log("Checking PATH $path \n");
			if (!is_dir($path)) {
				_debug_log("Making directory ".$path);
				mkdir($path);
				chmod($path, 0777);
			}
		}
	}
	private function file_extension($file) {
		$basename = basename($file);
		$arr = preg_split('#\.#', $basename);
		if (sizeof($arr) < 2) return false;
		return $arr[sizeof($arr)-1];
	}

	public $LAST_ERROR;

/* --- */
	public function upload($file, $vpath) {

		$url = $vpath;

		if (!is_array($file)) throw new Exception("Argument 1 must be php $_FILES element");
		if ($file['error']) return 'PHP Upload error #'.$file['error'];

		$local_path = $file['tmp_name'];
		$name = $file['name'];

		$root = File::work_dir();
		$path = $root .'/' . $url;

		$this->name = $name;
		$this->title = $name;
		$this->resource = ORM::Collection('FilePath', array('resource'=>$vpath))->one();
		$this->path = $vpath;
		$this->hash = md5_file($local_path);
		$this->size = filesize($local_path);
		$this->mime = ($file['type'] ? $file['type'] : $this->ms_mime($local_path) );

		$new_id = $this->insert();		

		if (!$new_id) {
			$this->LAST_ERROR = "resource $url is untaggable";
			return FALSE;
		}

		/* MAKE directory no matter what */
		if (!is_array($vpath)) $vpath = preg_split('#/|\.#', $vpath, -1, PREG_SPLIT_NO_EMPTY);
		$this->rdir($vpath, $root);

		/* Verify directory exists */
		if (!is_dir($path)) {
			$this->LAST_ERROR = "directory $path doesn't exist";
			$this->REMOVE($new_id);
			return FALSE;
		}

		$full_name = rtrim($url,'/') . '/'. $name;
		$neopath = $root .'/'. ltrim($full_name,'/');		

		if (!@move_uploaded_file($local_path, $neopath)) {
			$this->LAST_ERROR = "uploaded file can't be moved";
			$this->REMOVE($new_id);
			return FALSE;
		}

		return $new_id;
	
	}

	public function insert($fields=null) {
		//ORM::Wrap($this);
		if (!$this->resource) $this->resource = $this->path;
		$id = $this->WRITE($this, $fields);//parent::insert();
		return $id;
	}

	public function update($fields=null) {
		ORM::Wrap($this);
		$ok = $this->WRITE($this, $fields);//parent::update();
		return $ok;
	}

	private function WRITE($obj, $fields=null) {

		$key = $this->SECONDARY_LINK;
		$obj_res = $obj->$key;
		$key = null;

		$obj_unique = NULL;
		if (isset($this->UNIQUE_NAME)) {
			$key = $this->UNIQUE_NAME;
			$obj_unique = $obj->$key;
		}

		$res = db_get1('SELECT id FROM '.$this->TABLE_RES. ' WHERE '.$this->SECONDARY_KEY.' = ?', $obj_res);
		if ($res) $res_id = $res['id'];
		else      $res_id = db_set("INSERT INTO ".$this->TABLE_RES. " (resource) VALUES (?)", $obj->resource);

		if (!$res_id) return FALSE;

		$obj->res_id = $res_id;

		$item_id = NULL;
		if ($obj_unique) {

			$item = db_get1('SELECT '.$this->reflection()->primary.' AS id FROM '.$this->TABLE_NAME. ' WHERE '.$this->UNIQUE_NAME.' = ? AND res_id = ?', $obj_unique, (int)$obj->res_id);

			if ($item) {
				
				$item_id = $item['id'];
			
				$obj->identify($item_id);
				
			}

		} 
		if (!$item_id) {

			$item_id = ORM::Insert($this, $fields);

		} else {
		
			ORM::Update($this, $fields);		

			return $item_id; //dont ++link_count		
		}

		if (!$item_id) return FALSE;

		db_set('UPDATE '.$this->TABLE_RES.' SET link_count = link_count + 1 WHERE id = ?', $res_id);

		return $item_id; 
	}

	public function RMLIST($path) {
	
		$res = db_get1("SELECT id FROM ".$this->TABLE_RES." WHERE ".$this->SECONDARY_KEY." = ?", $path);
		if (!$res) return FALSE;

		$res_id = $res['id'];
		db_set("DELETE FROM ".$this->TABLE_RES. " WHERE id = ?", $res_id);

		$files = db_getCol("SELECT file FROM ".$this->TABLE_NAME." WHERE res_id = ?", $res_id);
		db_set("DELETE FROM ".$this->TABLE_NAME . " WHERE res_id = ?", $res_id);

		if (!$files) return TRUE;

		$root = WC::WORKDIR();
		$path = $root .'/' . $path . '/';		
	
		foreach ($files as $file) {
			$rpath = $path . $file;
			@unlink($rpath);
		}
		@rmdir($path);

		return TRUE;	
	}

	public function delete() {
		return $this->remove($this->id());
	}

	private function REMOVE($id) {

		$resCol = db_getCol('SELECT res_id FROM '.$this->TABLE_NAME.' WHERE id = ?', $id);
		if (!$resCol) return FALSE;
		$res_id = $resCol[0];

		db_set('DELETE FROM '.$this->TABLE_NAME.' WHERE '.$this->reflection()->primary.' = ?', $id);

		db_set('UPDATE '.$this->TABLE_RES.' SET link_count = link_count - 1 WHERE id = ?', $res_id);
		db_set('DELETE FROM '.$this->TABLE_RES.' WHERE id = ? AND link_count = 0', $res_id);

		return TRUE; 
	}


	public function ms_mime($path, $suggested='') {
		if (function_exists('mime_content_type')) {
			$mtype = mime_content_type($path);
	  	}
		else if (function_exists('finfo_file')) {
			$finfo = finfo_open(FILEINFO_MIME);
			$mtype = finfo_file($finfo, $path);
			finfo_close($finfo);  
	  	}
	  	else $mtype = $suggested;
	  	if (preg_match('/\.png$/i', $path)) $mtype = 'image/png';
	  	if (preg_match('/(\.jpg|\.jpeg)$/i', $path)) $mtype = 'image/jpeg';
	  	if (preg_match('/\.gif$/i', $path)) $mtype = 'image/gif';
		if ($mtype == '') $mtype = "application/force-download";
		return $mtype;
	}

	public function fastMime($path) {
		$m = $this->ms_mime($path);
		if (substr($m, -3) == 'ogg' || substr($path, -3) == 'ogg') return 'audio';
		if (substr($path, -4) == 'webm') return 'video';
		if (substr($m, 0, 5) == 'image') return 'img';
		return 'text';
	}	
	
	public function asPreview() {
		$root = File::work_dir();
		
		$path = $root . '/' . $this->resource . '/'. $this->name;
		$base = '';
		//$base = $_SERVER['SITE_URL'];
		//$base = str_replace("admin/","", $base);

		$m = $this->fastMime($path);
		if ($m == 'audio')
			return "<audio src='".$base.$path."' controls /></audio>"; 
		if ($m == 'video')
			return "<video src='".$base.$path."' controls /></video>"; 
		if ($m == 'img')
			return "<img src='".$base.$path."' />";

		return '';
	}

	public function asThumb($max_w = 320, $max_h = 240) {
		$root = File::work_dir();

		$folder = 'thumbs_'.$max_w.'x'.$max_h.'/';

		if (!isset($this->path)) throw new Exception("Undefined property: File::\$path");

		$path = $root . '/' . $this->path . '/'. $this->name;
		$thumb_dir = $folder . $this->path . '/';
		$t_path = $root . '/'. $folder . $this->path . '/'. $this->name;

		//$t_path = str_replace('//', '/', $t_path);

		$extn = $this->file_extension($this->name);

		if (!file_exists($t_path)) {

			$thumb_path = preg_split('#/|\.#', $thumb_dir, -1, PREG_SPLIT_NO_EMPTY);
			$this->rdir($thumb_path, $root);
			$this->make_thumb($path, $t_path, $extn, $max_w, $max_h);

		}

		return $t_path;
	}
	
	private function make_thumb($from, $to, $extn, $force_x = 120, $force_y = 80, $eforce = 0) {
		switch ($extn) {
			case 'jpg':
			case 'jpeg':
				$img = imagecreatefromjpeg($from);
				break;
			case 'png':
				$img = imagecreatefrompng($from);
				break;
			case 'gif':
				if (function_exists('imagecreatefromgif')) {
					$img = imagecreatefromgif($from);
				}
				else $eforce = 0;
				break;
			default:
				return;
		}

		$w = imagesx($img);
		$h = imagesy($img);

		$ret = 0;

		if ($h <= $force_y && $w <= $force_x)
		{
			$im = $img;
			if (!$eforce) {
				copy($from,$to);
				return;
			}
		}
		else {
			if ($h > $w) {
				$theight = $force_y;
				$twidth = round($theight * $w / $h);
			}
			else if ($w >= $force_x) {
				$twidth = $force_x;
				$theight = round($twidth * $h / $w);
			}
			if ($theight > $force_y) {
				$theight = $force_y;
				$twidth = round($theight * $w / $h);
			}
			$off_y = 0;
			if ($force_x == $force_y) {
				if ($theight < $force_y) {
					$off_y = 0;	//			($force_y - $theight) / 4;
					$theight = $force_y; 
				}
			}
			$im = imagecreatetruecolor($twidth, $theight);
			$color = imagecolorallocate($im, 255, 255, 255);
			imagefilledrectangle($im, 0,0, $twidth, $theight, $color);
			//imagecopyresized($im, $img, 0, 0, 0, 0, $twidth, $theight, $w, $h);
			imagecopyresampled($im, $img, 0, $off_y, 0, 0, $twidth, $theight, $w, $h);		
		}

		switch ($extn) {
			case 'jpg':
			case 'jpeg':
				imagejpeg($im, $to);
				break;
			case 'png':
				imagepng($im, $to);
				break;
			case 'gif':
				imagegif($im, $to);
				break;
		}
	}	

}


?>