<?php
/**
 * GitHub Project/Repository Downloader proxy.
 * This class will handle downloading, removing master folder prefix, 
 * repacking and proxing back the project as a download.
 * 
 * @author Lawrence Cherone
 * @version 0.2
 */
class GitDL{
	// project files working directory - automatically created
	const PWD = "./project_files/";

	/**
	 * Class construct.
	 *
	 * @param string $url
	 */
	function __construct($url=null){
		// check construct argument
		if(!$url) die('Class Error: Missing construct param: $url');

		// fix trailing slash if any
		$url = rtrim($url, '/');
		
		// assign class properties
		$this->project     = basename($url);
		$this->project_url = $url.'/archive/master.zip';
		$this->tmp_file    = md5($url).'.zip';

		// make project working folder
		if(!file_exists(self::PWD)){
			mkdir(self::PWD.md5($url), 0775, true);
		}
			
		// get project zip from GitHub
		try{
			$this->get_project();
		}catch(Exception $e){
			die($e->getMessage());
		}
		
		// extract project zip from git
		$this->extract(self::PWD.$this->tmp_file, self::PWD.md5($url));

		// remove the master part, by renaming
		rename(self::PWD.md5($url).'/'.$this->project.'-master', self::PWD.md5($url).'/'.$this->project);

		// rezip project files
		$this->zipcreate(self::PWD.md5($url), self::PWD.'new_'.$this->tmp_file);

		// send zip to user
		header('Content-Description: File Transfer');
		header('Content-Type: application/zip');
		header('Content-Disposition: attachment; filename="'.$this->project.'.zip"');
		header('Content-Transfer-Encoding: binary');
		header('Expires: 0');
		header('Cache-Control: must-revalidate, post-check=0, pre-check=0');
		header('Pragma: public');
		header('Content-Length: '.sprintf("%u", filesize(self::PWD.'new_'.$this->tmp_file)));
		readfile(self::PWD.'new_'.$this->tmp_file);

		// cleanup
		$this->destroy_dir(self::PWD.md5($url));
		unlink(self::PWD.$this->tmp_file);
		unlink(self::PWD.'new_'.$this->tmp_file);
	}

	/**
	 * cURL project downloader. 
	 * No support for open base dir/safe mode as there is a GitHub redirect to there CDN
	 * a HEAD pre-check is done to check project exists,
	 * project zip is writen directly to the file.
	 */
	function get_project(){
		// check curl installed
		if(!function_exists('curl_init')){
			throw new Exception('cURL Error: You must have cURL installed to use this class.');
		}
		// check for unsupported settings
		if (ini_get('open_basedir') != '' || ini_get('safe_mode') == 'On'){
			throw new Exception('cURL Error: safe_mode or an open_basedir is enabled, class not supported.');
		}
		
		// HEAD request - To verify the project exists
		$ch = curl_init();
		curl_setopt_array($ch, array(
			CURLOPT_URL => $this->project_url,
			CURLOPT_TIMEOUT => 5,
			CURLOPT_CONNECTTIMEOUT => 5,
			CURLOPT_FAILONERROR => true,
			CURLOPT_RETURNTRANSFER => true,
			CURLOPT_BINARYTRANSFER => true,
			CURLOPT_HEADER => false,
			CURLOPT_NOBODY => true,
			CURLOPT_FOLLOWLOCATION => true,
			CURLOPT_SSL_VERIFYHOST => false,
			CURLOPT_SSL_VERIFYPEER => false,
		));

		// lets grab it
		if(curl_exec($ch) !== false){
			$fp = fopen(self::PWD.$this->tmp_file, 'a+b');
			if(flock($fp, LOCK_EX | LOCK_NB)){
				// empty *possible* contents
				ftruncate($fp, 0);
				rewind($fp);

				// HTTP GET request - write directly to the file
				$ch = curl_init();
				curl_setopt_array($ch, array(
					CURLOPT_URL => $this->project_url,
					CURLOPT_TIMEOUT => 5,
					CURLOPT_CONNECTTIMEOUT => 5,
					CURLOPT_FAILONERROR => true,
					CURLOPT_RETURNTRANSFER => true,
					CURLOPT_BINARYTRANSFER => true,
					CURLOPT_HEADER => false,
					CURLOPT_FILE => $fp,
					CURLOPT_FOLLOWLOCATION => true,
					CURLOPT_SSL_VERIFYHOST => false,
					CURLOPT_SSL_VERIFYPEER => false,
				));

				// transfer failed
				if(curl_exec($ch) === false){
					ftruncate($fp, 0);
					throw new Exception('cURL Error: transfer failed.');
				}
				fflush($fp);
				flock($fp, LOCK_UN);
				curl_close($ch);
			}
			fclose($fp);
		}else{
			curl_close($ch);
			throw new Exception('Error: '.htmlentities($this->project).' project not found on GitHub');
		}
	}

	/**
	 * Create zip from extracted/fixed project.
	 *
	 * @uses ZipArchive
	 * @uses RecursiveIteratorIterator
	 * @param string $source
	 * @param string $destination
	 * @return bool
	 */
	function zipcreate($source, $destination) {
		if (!extension_loaded('zip') || !file_exists($source)) {
			return false;
		}
		$zip = new ZipArchive();
		if (!$zip->open($destination, ZIPARCHIVE::CREATE)) {
			return false;
		}
		$source = str_replace('\\', '/', realpath($source));
		if (is_dir($source) === true) {
			$files = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($source), RecursiveIteratorIterator::SELF_FIRST);
			foreach ($files as $file) {
				$file = str_replace('\\', '/', realpath($file));
				if (is_dir($file) === true) {
					$zip->addEmptyDir(str_replace($source.'/', '', $file.'/'));
				} else if (is_file($file) === true) {
					$zip->addFromString(str_replace($source.'/', '', $file), file_get_contents($file));
				}
			}
		}
		return $zip->close();
	}

	/**
	 * Extract Zip file
	 *
	 * @uses ZipArchive
	 * @param string $source
	 * @param string $destination
	 * @return bool
	 */
	function extract($source, $destination){
		$zip = new ZipArchive;
		if($zip->open($source) === TRUE) {
			$zip->extractTo($destination);
			$zip->close();
			return true;
		} else {
			return false;
		}
	}

	/**
	 * Recursive directory remover/deleter
	 *
	 * @uses RecursiveIteratorIterator
	 * @param string $dir
	 * @return bool
	 */
	function destroy_dir($dir) {
		foreach(new RecursiveIteratorIterator(new RecursiveDirectoryIterator($dir, FilesystemIterator::SKIP_DOTS), RecursiveIteratorIterator::CHILD_FIRST) as $path) {
			$path->isFile() ? unlink($path->getPathname()) : rmdir($path->getPathname());
		}
		return rmdir($dir);
	}

}
?>