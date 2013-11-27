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
		'files' => array('TFile'=>'res_id'),
	);
}

class TFile extends File {

	public $resource;
	public $res_id;
	public $order_num;

	static $_sql = array(
		'res_id' => 'int(9) FOREIGN KEY REFERENCES filepaths(id)',
		'order_num'=>'int(9) NOT NULL',
		'path' => false,
	);
	static $has_one = array(
		'resource' => array('FilePath' => 'res_id'),
	);
	static $_auto = array(
		'path' => false,
		'href' => false,
	);
	public function path_auto() {
		if (isset($this->path) && $this->path) return $this->path;
		if (!isset($this->resource)) return '';//throw new Exception("NO WAY".print_trace(debug_backtrace()));
		return is_string($this->resource) ? $this->resource : $this->resource->resource;
	}

	/*
	 * Bad code below 
	 */
	protected $TABLE_NAME = "#__tfiles";
	private $TABLE_RES = "#__filepaths";

	private $SECONDARY_KEY = "resource";
	private $SECONDARY_LINK = "path";
	private $UNIQUE_NAME = "name";
	private $UNIQUE_KEY = "id";

	public function FIXTURE() { 
		db_set("TRUNCATE TABLE ".$this->TABLE_NAME.";", NULL);
		db_set("TRUNCATE TABLE ".$this->TABLE_RES.";", NULL);
	}
/* --- */
	public function upload($file, $vpath, $allow_duplicates = FALSE) {
		$this->order_num = 0;
		$this->resource = ORM::Collection('FilePath', array('resource'=>$vpath))->one();
		return parent::upload($file, $vpath, $allow_duplicates);
	}

	public function insert($fields=null) {
		//ORM::Wrap($this);
		if (!$this->resource) $this->resource = $this->path;
		$id = $this->WRITE($this, $fields, false);//parent::insert();
		return $id;
	}

	public function update($fields=null) {
		ORM::Wrap($this);
		$ok = $this->WRITE($this, $fields, false);//parent::update();
		return $ok;
	}

	private function WRITE($obj, $fields=null, $verify_unique_name=false) {
		$key = $this->SECONDARY_LINK;
		$obj_res = $obj->$key;
		$key = null;

		$obj_unique = NULL;
		if (isset($this->UNIQUE_NAME) && $verify_unique_name) {
			$key = $this->UNIQUE_NAME;
			$obj_unique = $obj->$key;
		}

		$obj->path = $obj->path_auto();

		$res = db_get1('SELECT id FROM '.$this->TABLE_RES. ' WHERE '.$this->SECONDARY_KEY.' = ?', $obj_res);
		if ($res) $res_id = $res['id'];
		else      $res_id = db_set('INSERT INTO '.$this->TABLE_RES. ' ('.$this->SECONDARY_KEY.') VALUES (?)', $obj->path);

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
		else if (!$verify_unique_name) {
			$item_id = $this->id();			
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

	public function onDelete() {
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

}

class File extends ORM_Model {

	public $id;

	public $name;
	public $path;
	public $hash;
	public $mime;
	public $size;

	public $title;
	public $description;

	static $_sql = array(
		'id' => 'MEDIUMINT(11) PRIMARY KEY AUTO_INCREMENT',
		'name' => 'varchar(255)',// unique',
		'path' => 'VARCHAR(255)',
		'hash' => 'varchar(64)',
		'mime' => 'varchar(255)',
		'size' => 'int(9)',
		'title' => 'VARCHAR(1024)',
		'description'=>'TEXT',
	);
	static $_form = array(
		'file' => 'file',
	);
	static $_auto = array(
		'href' => true,
	);
	public function href_auto() {
		return File::Work_Dir() . '/'. $this->path . '/' . $this->name;
	}

	/*
	 * Bad code below 
	 */
	static function WORK_DIR($set = null) {
		static $dir;
		if ($set) {
			$dir = rtrim($set, '/');
		} else if (!$dir) {
			$dir = _work_dir();
			$dir = rtrim($dir, '/');
		}
		return $dir;
	}

/* --- */
	public $LAST_ERROR;
	public $LAST_ERROR_HINT;

	static $php_upload_errors = array( //from http://php.net/manual/en/features.file-upload.errors.php 
		UPLOAD_ERR_OK => 'There is no error, the file uploaded with success.',
		UPLOAD_ERR_INI_SIZE => 'The uploaded file exceeds the upload_max_filesize directive.',
		UPLOAD_ERR_FORM_SIZE => 'The uploaded file exceeds the MAX_FILE_SIZE directive.',
		UPLOAD_ERR_PARTIAL => 'The uploaded file was only partially uploaded.',
		UPLOAD_ERR_NO_FILE => 'No file was uploaded.',
		UPLOAD_ERR_NO_TMP_DIR => 'Missing a temporary folder.',
		UPLOAD_ERR_CANT_WRITE => 'Failed to write file to disk.',
		UPLOAD_ERR_EXTENSION => 'A PHP extension stopped the file upload.',
	);

	public function upload($file, $vpath, $allow_duplicates = FALSE) {

		$vpath = trim($vpath, '/');

		if (!is_array($file)) throw new Exception('Argument 1 must be php $_FILES element');
		if ($file['error']) {
			$this->LAST_ERROR = 'Upload error: '.self::$php_upload_errors[ $file['error'] ];
			$this->LAST_ERROR_HINT = 'PHP_ERR_'.$file['error'];
			return FALSE;
		}

		$local_path = $file['tmp_name'];
		$username = $file['name'];
		$name = $this->filename_filter($username);

		$path = File::work_dir() . '/' . $vpath;

		$this->name = $name;
		if (!$this->title)
		$this->title = isset($file['title']) ? $file['title'] : $username;
		$this->path = $vpath;
		$this->hash = md5_file($local_path);
		$this->size = filesize($local_path);
		$this->mime = ($file['type'] ? $file['type'] : File::file_mimetype($local_path) );

		/* THUMBNAIL HACK */
		if (in_array($this->mime, array('image/png','image/jpg','image/gif'))) {
			if (!File::will_bmp_fit($local_path)) {
				$this->LAST_ERROR = 'Image resolution too large to fit into memory';
				$this->LAST_ERROR_HINT = 'IMAGE_TOO_WIDE';
				return FALSE;
			}
		}

		/* MIMIC HACK */
		$mimic = $allow_duplicates ? NULL : 
			ORM::Collection(get_class($this), array('hash'=>$this->hash), 1)->one();
		if ($mimic) {
			$this->path = $mimic->path;
			$this->name = $mimic->name;
		}

		$new_id = $this->insert();//ORM::Insert($this);

		if (!$new_id) {
			$this->LAST_ERROR = 'Resource '.$vpath.' is untaggable';
			$this->LAST_ERROR_HINT = 'FILE_CANT_VPATH';
			return FALSE;
		}

		/* MIMIC HACK */
		if ($mimic) {
			$mimic->identify($new_id);
			foreach ($this as $key => $val) // copy all object properties
				if (is_object($val)) 
					$mimic->$key = $val;	
			return $mimic;
		}

		/* MAKE directory no matter what */
		File::mkdir($path);

		/* Verify directory exists */
		if (!is_dir($path)) {
			$this->LAST_ERROR = 'Directory '.$path.' does not exist';
			$this->LAST_ERROR_HINT = 'FILE_CANT_MKDIR';
			$this->delete();
			return FALSE;
		}

		$final_path = $path . '/'. $name;

		if (!move_uploaded_file($local_path, $final_path)) {
			$this->LAST_ERROR = 'Uploaded file can not be moved';
			$this->LAST_ERROR_HINT = 'FILE_CANT_MOVE';
			$this->delete();
			return FALSE;
		}

		$this->assemble();

		return $new_id;
	}

	public function onImport($payload) {
		$ret = array();	
		$workdir = File::WORK_DIR();
		foreach ($payload as $file) {
			$ret[] = array(
				'src' => (string) $file->dst ,
				'dst' => $workdir . '/' . (string)$file->dst,
			);
		}
		return $ret;
	}

	public function onExport() {
		$ret = array();

		/* This class does not allow it, but subclasses might bork it up, 
		 * and allow entires without files :/ */
		if (!$this->name) return $ret;

		$workdir = File::WORK_DIR();	

		if (!isset($this->href)) $this->href = $this->href_auto();

		$ret[] = array('src'=>$this->href,'dst'=>preg_replace('~^'.$workdir.'/{0,1}~', '', $this->href));

		return $ret;
	}
	public function onDelete() {

		$total = ORM::Collection(get_class($this), array('hash'=>$this->hash), false)->count();
		if ($total > 1) return;

		$fullname = $this->href;
		$dir = File::Work_Dir() . '/' . $this->path;

		@unlink($fullname);
		@rmdir($dir);
	}

	public function filename_filter($str) {
		return File::makeslug($str, FALSE);
	}

	public function imageSize() {
		return File::image_size($this->href);
	}

/* library */
	static function makeslug($str, $strip_spaces = TRUE, $lowercase = TRUE) {
		/* Idea from unused drupal patch http://drupal.org/node/63924 */
		// substitutes anything but letters, numbers and '_' with separator 
		$str = trim(preg_replace('~[^\\pL0-9_\-.()]+~u', ' ', $str), " ");

		if ($strip_spaces)
		$str = str_replace(' ', '_', $str);

		// HACK!! -- TRANSLIT cyrillic
		$str = strtr($str, array(
		'а'=>'a','б'=>'b','в'=>'v','г'=>'g','д'=>'d','е'=>'e','ё'=>'yo',
		'ж'=>'zh','з'=>'z','и'=>'i','й'=>'y','к'=>'k','л'=>'l','м'=>'m',
		'н'=>'n','о'=>'o','п'=>'p','р'=>'r','с'=>'s','т'=>'t','у'=>'u',
		'ф'=>'f','х'=>'h','ц'=>'ts','ч'=>'ch','ш'=>'sh','щ'=>'sch',
		'ъ'=>"'",'ы'=>'yi','ь'=>'','э'=>'e','ю'=>'yu','я'=>'ya',
		'і'=>'i','ґ'=>'g','є'=>'e','ї'=>'yi','№'=>'#'  ));

		$str = strtr($str, array(
		'А'=>'A','Б'=>'B','В'=>'V','Г'=>'G','Д'=>'D','Е'=>'E','Ё'=>'Yo',
		'Ж'=>'Zh','З'=>'Z','И'=>'I','Й'=>'Y','К'=>'K','Л'=>'L','М'=>'M',
		'Н'=>'N','О'=>'O','П'=>'P','Р'=>'R','С'=>'S','Т'=>'T','У'=>'U',
		'Ф'=>'F','Х'=>'H','Ц'=>'Ts','Ч'=>'Ch','Ш'=>'Sh','Щ'=>'Sch',
		'Ъ'=>"'",'Ы'=>'Yi','Ь'=>'','Э'=>'E','Ю'=>'Yu','Я'=>'Ya',
		'І'=>'I','Ґ'=>'G','Є'=>'E','Ї'=>'Yi','№'=>'#'  ));

		// TRANSLIT with iconv
		$str = iconv('utf-8', 'us-ascii//TRANSLIT', $str);

		if ($lowercase)
		$str = strtolower($str);

		// keep only letters, numbers, '_' and separator
		if ($strip_spaces)
			$str = preg_replace('~[^-a-zA-Z0-9_]+~', '', $str);
		//else
			//$str = preg_replace('~[^-a-zA-Z0-9]+~', '', $str);

		return $str;
	}

	static function mkdir($dir) {
		$steps = preg_split('#/#', $dir, -1, PREG_SPLIT_NO_EMPTY);
		$path = array_shift($steps);
		foreach ($steps as $dir) {
			$path .= '/'.$dir;
			if (!is_dir($path)) {
				_debug_log("Making directory ".$path);
				mkdir($path);
				chmod($path, 0777);
			}
		}
	}

	static function file_extension($file) {
		$basename = basename($file);
		$arr = preg_split('#\.#', $basename);
		if (sizeof($arr) < 2) return false;
		return $arr[sizeof($arr)-1];
	}

	static function file_mimetype($path, $suggested='') {
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

	static function file_shorttype($path) {
		$m = File::file_mimetype($path);
		if (substr($m, -3) == 'ogg' || substr($path, -3) == 'ogg') return 'audio';
		if (substr($path, -4) == 'webm') return 'video';
		if (substr($m, 0, 5) == 'image') return 'img';
		return 'text';
	}

	static function image_size($path) {
		return getimagesize($path);
	}

	static function memory_limit() {
		$limit = ini_get('memory_limit');
		switch( substr($limit,-1) ) {
			case 'G': case 'g': $limit *= 1024;
			case 'M': case 'm': $limit *= 1024;
			case 'K': case 'k': $limit *= 1024;
		}
		return $limit;
	}

	static function will_bmp_fit($path) {
		$res = getimagesize($path);
		if (!isset($res['channels'])) $res['channels'] = 4; //assume the worst

		$bpp = $res['bits'] * $res['channels'];
		$plot = $res[0] * $res[1];
		$bmpsize = $bpp * $plot;

		$bmpsize += 4096; /* random overhead, just for fun */

		$used = memory_get_usage();
		$allowed = self::memory_limit();

		if ($used + $bmpsize > $allowed) {
			return false;
		}
		return true;
	}

	static function gs_ccir709($r,$g,$b) {
		return (($r*0.2125)+($g*0.7154)+($b*0.0721));
	}
	static function gs_bt709($r,$g,$b) {
		return (($r*0.299)+($g*0.587)+($b*0.114));
	}
	static function gs_rmy($r,$g,$b) {
		return (($r*0.5)+($g*0.419)+($b*0.081));
	}
	
	static function gd_truecolor_greyscale($img) { //Creates yiq function
		$w = imagesx($img);
		$h = imagesy($img);	

 		imagealphablending($img, false);
		for ($y = 0; $y < $h; $y++) {
			for ($x = 0; $x < $w; $x++) {
				$rgb = imagecolorat($img, $x, $y);
				//$a = ($rgb & 0x7F000000) >> 24;
				$a = ($rgb & 0xFF000000) >> 24;
				$r = ($rgb >> 16) & 0xFF;
				$g = ($rgb >> 8) & 0xFF;
				$b = $rgb & 0xFF;
				//$alpha = ((int)(substr($a - 255, 1))) >> 1;
				$white = min((int)self::gs_ccir709($r, $g, $b),255);
				
				$color = imagecolorallocatealpha($img, $white, $white, $white, $a );
				imagesetpixel($img, $x, $y, $color);
				imagecolordeallocate ($img, $color);
			}
		}
		imagealphablending($img, true);
	}

	static function make_thumb($from, $to, $extn, $force_x = 120, $force_y = 80, $eforce = 0, $filter = '', $dst_format = null) {
		if (!file_exists($from)) throw new Exception('File `'.$from.'` does not exist');
		if (!self::will_bmp_fit($from)) throw new Exception('File `'.$from.'` is too large to fit in memory');

		if (!$dst_format) $dst_format = $extn;
		
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
				return false;
		}

		$w = imagesx($img);
		$h = imagesy($img);

		$ret = 0;

		if ($h <= $force_y && $w <= $force_x)
		{
			$im = $img;
			if (!$eforce) {
				copy($from, $to);
				return true;
			}
		}
		else {
			$twidth = $w;
			$theight = $h;
			// Proportional resize
			if ($h > $w) {
				$theight = $force_y;
				$twidth = round($theight * $w / $h);
			} else {
				$twidth = $force_x;
				$theight = round($twidth * $h / $w);
			}
			// If one of the dimensions still sticks out, correct it
			if ($theight > $force_y) {
				$theight = $force_y;
				$twidth = round($theight * $w / $h);
			}
			if ($twidth > $force_x) {
				$twidth = $force_x;
				$theight = round($twidth * $h / $w);
			}
			// Crop
			$off_x = 0;
			$off_y = 0;

			$fin_width = $twidth;
			$fin_height = $theight;

			if ($eforce) {
			    // disabled
				if ($theight < $force_y) {
					$off_y = ($force_y - $theight) / 2;
					$fin_height = $force_y;
					//$theight = $force_y; 
				}
				if ($twidth < $force_x) {
					$off_x = ($force_x - $twidth) / 2;
					$fin_width = $force_x;
					//$twidth = $force_x; 
				}
			}

			$src_x = 0;
			$src_y = 0;
			//Fit Crop
			if ($eforce == 2) {
				$x_proc = ($w / $twidth) * $off_x;
				$y_proc = ($h / $theight) * $off_y;

				$src_y += $x_proc;
				$src_x += $y_proc;
				
				$w -= $y_proc;
				$h -= $x_proc;
				
				$off_x = $off_y = 0;
				$twidth = $fin_width;
				$theight = $fin_height;
			}

			$im = imagecreatetruecolor($fin_width, $fin_height);
			$color = imagecolorallocatealpha($im, 255, 255, 255, 127);
			imagealphablending($im, false);
			imagefilledrectangle($im, 0, 0, $fin_width, $fin_height, $color);
			imagealphablending($im, true);
			//imagecopy($im, $img, 0, 0, 0, 0, $twidth, $theight, $w, $h);
			//imagealphablending($img, true);
			//imagealphablending($im, true);

			imagecopyresampled($im, $img, $off_x, $off_y, $src_x, $src_y, $twidth, $theight, $w, $h);
		}
		
		if ($filter == 'grayscale') {
			imagealphablending($im, false);
			imagefilter($im, IMG_FILTER_GRAYSCALE);
			imagealphablending($im, true);
		}		
		if ($filter == 'greyscale') {
			self::gd_truecolor_greyscale($im);
		}		

		switch ($dst_format) {
			case 'jpg':
			case 'jpeg':
				imagejpeg($im, $to);
				break;
			case 'png':
				imagesavealpha($im, true);
				imagepng($im, $to);
				break;
			case 'gif':
				imagegif($im, $to);
				break;
		}
		if ($im != $img)
			imagedestroy($img);
		imagedestroy($im);
		return true;
	}
/* extras */
	public function asPreview() {
		switch (File::file_shorttype($path)) {
			case 'audio':
				return "<audio src='".$this->href."' controls />"; 
			case 'video':
				return "<video src='".$this->href."' controls />"; 
			case 'img':
				return "<img src='".$this->href."' />";
			default:
				return '';
		}
	}

	public function asThumb($max_w = 320, $max_h = 240, $crop = FALSE, $filter = '', $fmt = null) {
		$root = File::work_dir();

		$folder = $filter.'thumbs_'.$max_w.'x'.$max_h;

		if (!isset($this->path)) throw new Exception('Undefined property: File::$path');

		$path   = $root . '/' .                 $this->path . '/'. $this->name;
		$t_dir  = $root . '/' . $folder . '/' . $this->path;
		$t_path = $root . '/' . $folder . '/' . $this->path . '/'. $this->name;

		$extn = File::file_extension($this->name);
		
		if ($fmt) $t_path = preg_replace('/'.$extn.'$/', $fmt, $t_path);

		if (!file_exists($t_path)) {
			File::mkdir($t_dir);
			try {
				File::make_thumb($path, $t_path, $extn, $max_w, $max_h, $crop, $filter, $fmt);
			}
			catch (Exception $e) {
				_debug_log($e->getMessage());
				return $path;
			}
		}

		return $t_path;
	}

}

?>