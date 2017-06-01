<?php

namespace wordpress;

use SDK\Build\PGO\Abstracts;
use SDK\Build\PGO\Interfaces;
use SDK\Build\PGO\Config;
use SDK\Build\PGO\PHP;
use SDK\{Config as SDKConfig, Exception, FileOps};
use SDK\Build\PGO\Tool;

class TrainingCaseHandler extends Abstracts\TrainingCase implements Interfaces\TrainingCase
{
	protected $conf;
	protected $base;
	protected $nginx;
	protected $maria;
	protected $php;
	protected $max_runs = 1;

	public function __construct(Config $conf, ?Interfaces\Server $nginx, ?Interfaces\Server\DB $maria)
	{
		if (!$nginx) {
			throw new Exception("Invalid NGINX object");
		}

		$this->conf = $conf;
		$this->base = $this->conf->getCaseWorkDir($this->getName());
		$this->nginx = $nginx;
		$this->maria = $maria;
		$this->php = $nginx->getPhp();
	}

	public function getName() : string
	{
		return __NAMESPACE__;
	}

	public function getJobFilename() : string
	{
		return $this->conf->getJobDir() . DIRECTORY_SEPARATOR . $this->getName() . ".txt";
	}

	protected function getToolFn() : string
	{
		return $this->conf->getToolsDir() . DIRECTORY_SEPARATOR . "wp-cli.phar";
	}

	protected function getDist() : void
	{
		
		if (!file_exists($this->getToolFn())) {
			$url = "https://raw.github.com/wp-cli/builds/gh-pages/phar/wp-cli.phar";

			echo "Fetching '$url'\n";
			$this->download($url, $this->getToolFn());
		}

		/* Get wp zip. */
		$wptest_dir = $src_fn = $this->conf->getToolSDir() . DIRECTORY_SEPARATOR . "wptest";
		if (!file_exists($wptest_dir)) {
			$url = "https://github.com/manovotny/wptest/archive/master.zip";
			$bn = basename($url);
			$dist = SDKConfig::getTmpDir() . DIRECTORY_SEPARATOR . "wptest.zip";

			echo "Fetching '$url'\n";
			$this->download($url, $dist);


			//echo "Unpacking to '{$this->base}'\n";
			echo "Unpacking to '" . $this->conf->getToolSDir() . "'\n";
			try {
				$this->unzip($dist, $this->conf->getToolSDir());
			} catch (Throwable $e) {
				unlink($dist);
				throw $e;
			}

			$zip = new \ZipArchive;
			$zip->open($dist);
			$stat = $zip->statIndex(0);
			$zip->close();
			$unzipped_dir = $this->conf->getToolSDir() . DIRECTORY_SEPARATOR . $stat["name"];

			if (!rename($unzipped_dir, $wptest_dir)) {
				unlink($dist);
				throw new Exception("Failed to rename '$unzipped_dir' to '$wptest_dir'");
			}

			unlink($dist);
		}

		if (!is_dir($this->base)) {
			echo "Setting up in '{$this->base}'\n";
			/* XXX Use host PHP for this. */
			$php = new PHP\CLI($this->conf);
			$php->exec($this->getToolFn() . " core download --force --path=" . $this->base);
		}
	}

	protected function setupDist() : void
	{
		$this->getDist();
		
		$http_port = $this->getHttpPort();
		$http_host = $this->getHttpHost();
		$db_port = $this->getDbPort();
		$db_host = $this->getDbHost();
		$db_user = $this->getDbUser();
		$db_pass = $this->getDbPass();

		$vars = array(
			$this->conf->buildTplVarName($this->getName(), "docroot") => str_replace("\\", "/", $this->base),
		);
		$tpl_fn = $this->conf->getCasesTplDir($this->getName()) . DIRECTORY_SEPARATOR . "nginx.partial.conf";
		$this->nginx->addServer($tpl_fn, $vars);

		$php = new PHP\CLI($this->conf);

		$this->maria->up();
		$this->nginx->up();

		$this->maria->query("DROP DATABASE IF EXISTS " . $this->getName());
		$this->maria->query("CREATE DATABASE " . $this->getName());

		$cmd_path_arg = "--path=" . $this->base;

		$cmd = $this->getToolFn() . " core config --force --dbname=" . $this->getName() . " --dbuser=$db_user --dbpass=$db_pass --dbhost=$db_host:$db_port $cmd_path_arg";
		$php->exec($cmd);

		$site_adm = trim(shell_exec("pwgen -1 -s 8"));
		$this->conf->setSectionItem($this->getName(), "site_admin_user", $site_adm);
		$site_pw = trim(shell_exec("pwgen -1 -s 8"));
		$this->conf->setSectionItem($this->getName(), "site_admin_pass", $site_pw);
		//save admin user and pass to config
		//$cmd = $this->getToolFn() . " core install --url=$http_host:$http_port --title=hello --admin_user=$site_adm_user --admin_password=$site_adm_pw --admin_email=a@bc.de --skip-email --path=" . $this->base;
		$cmd = $this->getToolFn() . " core install --url=$http_host:$http_port --title=hello --admin_user=$site_adm --admin_password=$site_pw --admin_email=ostc@test.abc --skip-email $cmd_path_arg";
		$php->exec($cmd);

		$cmd = $this->getToolFn() . " plugin install wordpress-importer --activate --allow-root $cmd_path_arg";
		$php->exec($cmd);

		$cmd = $this->getToolFn() . " import " . $this->conf->getToolSDir() . DIRECTORY_SEPARATOR . "wptest-master/wptest.xml --authors=create --allow-root $cmd_path_arg";
		$php->exec($cmd);

		$this->nginx->down(true);
		$this->maria->down(true);

	}

	public function setupUrls()
	{
		$this->maria->up();
		$this->nginx->up();

		$url = "http://" . $this->getHttpHost() . ":" . $this->getHttpPort();
		$s = file_get_contents($url);

		$this->nginx->down(true);
		$this->maria->down(true);

		echo "Generating training urls.\n";

		$lst = array();
		if (preg_match_all(", href=\"([^\"]+" . $this->getHttpHost() . ":" . $this->getHttpPort() . "[^\"]+)\",", $s, $m)) {
			$lst = array_unique($m[1]);
		}

		if (empty($lst)) {
			printf("\033[31m WARNING: Training URL list is empty, check the regex!\033[0m\n");
		}

		$fn = $this->getJobFilename();
		$s = implode("\n", $lst);
		if (strlen($s) !== file_put_contents($fn, $s)) {
			throw new Exception("Couldn't write '$fn'.");
		}
	}

	public function init() : void
	{
		echo "Initializing " . $this->getName() . ".\n";

		$this->setupDist();
		$this->setupUrls();

		echo $this->getName() . " initialization done.\n";
		echo $this->getName() . " site configured to run under " . $this->getHttpHost() . ":" .$this->getHttpPort() . "\n";
	}
}


