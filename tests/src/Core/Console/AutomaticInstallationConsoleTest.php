<?php

namespace Friendica\Test\src\Core\Console;

use Friendica\Core\Config\Cache\ConfigCache;
use Friendica\Core\Console\AutomaticInstallation;
use Friendica\Core\Installer;
use Friendica\Core\Logger;
use Friendica\Test\Util\DBAMockTrait;
use Friendica\Test\Util\DBStructureMockTrait;
use Friendica\Test\Util\L10nMockTrait;
use Friendica\Test\Util\RendererMockTrait;
use Friendica\Util\Logger\VoidLogger;
use org\bovigo\vfs\vfsStream;
use org\bovigo\vfs\vfsStreamFile;

/**
 * @runTestsInSeparateProcesses
 * @preserveGlobalState disabled
 * @requires PHP 7.0
 */
class AutomaticInstallationConsoleTest extends ConsoleTest
{
	use L10nMockTrait;
	use DBAMockTrait;
	use DBStructureMockTrait;
	use RendererMockTrait;

	/**
	 * @var vfsStreamFile Assert file without DB credentials
	 */
	private $assertFile;
	/**
	 * @var vfsStreamFile Assert file with DB credentials
	 */
	private $assertFileDb;

	/**
	 * @var ConfigCache The configuration cache to check after each test
	 */
	private $configCache;

	public function setUp()
	{
		parent::setUp();

		if ($this->root->hasChild('config' . DIRECTORY_SEPARATOR . 'local.config.php')) {
			$this->root->getChild('config')
				->removeChild('local.config.php');
		}

		$this->mockL10nT();

		$this->configCache = new ConfigCache();
		$this->configCache->set('system', 'basepath', $this->root->url());
		$this->configCache->set('config', 'php_path', trim(shell_exec('which php')));
		$this->configCache->set('system', 'theme', 'smarty3');

		$this->mockApp($this->root, null, true);

		$this->configMock->shouldReceive('set')->andReturnUsing(function ($cat, $key, $value) {
			if ($key !== 'basepath') {
				return $this->configCache->set($cat, $key, $value);
			} else {
				return true;
			}
		});

		$this->configMock->shouldReceive('has')->andReturn(true);
		$this->configMock->shouldReceive('get')->andReturnUsing(function ($cat, $key) {
			return $this->configCache->get($cat, $key);
		});
		$this->configMock->shouldReceive('load')->andReturnUsing(function ($config, $overwrite = false) {
			return $this->configCache->load($config, $overwrite);
		});

		$this->mode->shouldReceive('isInstall')->andReturn(true);
		Logger::init(new VoidLogger());
	}

	/**
	 * Returns the dataset for each automatic installation test
	 *
	 * @return array the dataset
	 */
	public function dataInstaller()
	{
		return [
			'empty' => [
				'data' => [
					'database' => [
						'hostname'    => '',
						'username'    => '',
						'password'    => '',
						'database'    => '',
						'port'        => '',
					],
					'config' => [
						'php_path'    => '',
						'admin_email' => '',
					],
					'system' => [
						'urlpath'     => '',
						'default_timezone' => '',
						'language'    => '',
					],
				],
			],
			'normal' => [
				'data' => [
					'database' => [
						'hostname'    => 'testhost',
						'port'        => 3306,
						'username'    => 'friendica',
						'password'    => 'a password',
						'database'    => 'database',
					],
					'config' => [
						'php_path'    => '',
						'admin_email' => 'admin@philipp.info',
					],
					'system' => [
						'urlpath'     => 'test/it',
						'default_timezone' => 'en',
						'language'    => 'Europe/Berlin',
					],
				],
			],
			'special' => [
				'data' => [
					'database' => [
						'hostname'    => 'testhost.new.domain',
						'port'        => 3341,
						'username'    => 'fr"§%ica',
						'password'    => '$%\"gse',
						'database'    => 'db',
					],
					'config' => [
						'php_path'    => '',
						'admin_email' => 'admin@philipp.info',
					],
					'system' => [
						'urlpath'     => 'test/it',
						'default_timezone' => 'en',
						'language'    => 'Europe/Berlin',
					],
				],
			],
		];
	}

	private function assertFinished($txt, $withconfig = false, $copyfile = false)
	{
		$cfg = '';

		if ($withconfig) {
			$cfg = <<<CFG


Creating config file...

 Complete!
CFG;
		}

		if ($copyfile) {
			$cfg = <<<CFG


Copying config file...

 Complete!
CFG;
		}

		$finished = <<<FIN
Initializing setup...

 Complete!


Checking environment...

 NOTICE: Not checking .htaccess/URL-Rewrite during CLI installation.

 Complete!
{$cfg}


Checking database...

 Complete!


Inserting data into database...

 Complete!


Installing theme

 Complete



Installation is finished


FIN;
		$this->assertEquals($finished, $txt);
	}

	private function assertStuckDB($txt)
	{
		$finished = <<<FIN
Initializing setup...

 Complete!


Checking environment...

 NOTICE: Not checking .htaccess/URL-Rewrite during CLI installation.

 Complete!


Creating config file...

 Complete!


Checking database...

[Error] --------
Could not connect to database.: 


FIN;

		$this->assertEquals($finished, $txt);
	}

	/**
	 * Asserts one config entry
	 *
	 * @param string     $cat           The category to test
	 * @param string     $key           The key to test
	 * @param null|array $assertion     The asserted value (null = empty, or array/string)
	 * @param string     $default_value The default value
	 */
	public function assertConfigEntry($cat, $key, $assertion = null, $default_value = null)
	{
		if (!empty($assertion[$cat][$key])) {
			$this->assertEquals($assertion[$cat][$key], $this->configCache->get($cat, $key));
		} elseif (!empty($assertion) && !is_array($assertion)) {
			$this->assertEquals($assertion, $this->configCache->get($cat, $key));
		} elseif (!empty($default_value)) {
			$this->assertEquals($default_value, $this->configCache->get($cat, $key));
		} else {
			$this->assertEmpty($this->configCache->get($cat, $key), $this->configCache->get($cat, $key));
		}
	}

	/**
	 * Asserts all config entries
	 *
	 * @param null|array $assertion    The optional assertion array
	 * @param boolean    $saveDb       True, if the db credentials should get saved to the file
	 * @param boolean    $default      True, if we use the default values
	 * @param boolean    $defaultDb    True, if we use the default value for the DB
	 */
	public function assertConfig($assertion = null, $saveDb = false, $default = true, $defaultDb = true)
	{
		if (!empty($assertion['database']['hostname'])) {
			$assertion['database']['hostname'] .= (!empty($assertion['database']['port']) ? ':' . $assertion['database']['port'] : '');
		}

		$this->assertConfigEntry('database', 'hostname', ($saveDb) ? $assertion : null, (!$saveDb || $defaultDb) ? Installer::DEFAULT_HOST : null);
		$this->assertConfigEntry('database', 'username', ($saveDb) ? $assertion : null);
		$this->assertConfigEntry('database', 'password', ($saveDb) ? $assertion : null);
		$this->assertConfigEntry('database', 'database', ($saveDb) ? $assertion : null);

		$this->assertConfigEntry('config', 'admin_email', $assertion);
		$this->assertConfigEntry('config', 'php_path', trim(shell_exec('which php')));

		$this->assertConfigEntry('system', 'default_timezone', $assertion, ($default) ? Installer::DEFAULT_TZ : null);
		$this->assertConfigEntry('system', 'language', $assertion, ($default) ? Installer::DEFAULT_LANG : null);
	}

	/**
	 * Test the automatic installation without any parameter/setting
	 */
	public function testEmpty()
	{
		$this->app->shouldReceive('getURLPath')->andReturn('')->atLeast()->once();

		$this->mockConnect(true, 1);
		$this->mockConnected(true, 1);
		$this->mockExistsTable('user', false, 1);
		$this->mockUpdate([$this->root->url(), false, true, true], null, 1);

		$this->mockGetMarkupTemplate('local.config.tpl', 'testTemplate', 1);
		$this->mockReplaceMacros('testTemplate', \Mockery::any(), '', 1);

		$console = new AutomaticInstallation($this->consoleArgv);

		$txt = $this->dumpExecute($console);

		$this->assertFinished($txt, true, false);
		$this->assertTrue($this->root->hasChild('config' . DIRECTORY_SEPARATOR . 'local.config.php'));

		$this->assertConfig();
	}

	/**
	 * Test the automatic installation with a prepared config file
	 * @dataProvider dataInstaller
	 */
	public function testWithConfig(array $data)
	{
		$this->mockConnect(true, 1);
		$this->mockConnected(true, 1);
		$this->mockExistsTable('user', false, 1);
		$this->mockUpdate([$this->root->url(), false, true, true], null, 1);

		$conf = function ($cat, $key) use ($data) {
			if ($cat == 'database' && $key == 'hostname' && !empty($data['database']['port'])) {
				return $data[$cat][$key] . ':' . $data['database']['port'];
			}
			return $data[$cat][$key];
		};

		$config = <<<CONF
<?php

// Local configuration

// If you're unsure about what any of the config keys below do, please check the config/defaults.config.php for detailed
// documentation of their data type and behavior.

return [
	'database' => [
		'hostname' => '{$conf('database', 'hostname')}',
		'username' => '{$conf('database', 'username')}',
		'password' => '{$conf('database', 'password')}',
		'database' => '{$conf('database', 'database')}',
		'charset' => 'utf8mb4',
	],

	// ****************************************************************
	// The configuration below will be overruled by the admin panel.
	// Changes made below will only have an effect if the database does
	// not contain any configuration for the friendica system.
	// ****************************************************************

	'config' => [
		'admin_email' => '{$conf('config', 'admin_email')}',
		'sitename' => 'Friendica Social Network',
		'register_policy' => \Friendica\Module\Register::OPEN,
		'register_text' => '',
	],
	'system' => [
		'urlpath' => '{$conf('system', 'urlpath')}',
		'default_timezone' => '{$conf('system', 'default_timezone')}',
		'language' => '{$conf('system', 'language')}',
	],
];
CONF;

		vfsStream::newFile('prepared.config.php')
			->at($this->root)
			->setContent($config);

		$console = new AutomaticInstallation($this->consoleArgv);
		$console->setOption('f', 'prepared.config.php');

		$txt = $this->dumpExecute($console);

		$this->assertFinished($txt, false, true);

		$this->assertTrue($this->root->hasChild('config' . DIRECTORY_SEPARATOR . 'local.config.php'));
		$this->assertEquals($config, file_get_contents($this->root->getChild('config' . DIRECTORY_SEPARATOR . 'local.config.php')->url()));

		$this->assertConfig($data, true, false, false);
	}

	/**
	 * Test the automatic installation with environment variables
	 * Includes saving the DB credentials to the file
	 * @dataProvider dataInstaller
	 */
	public function testWithEnvironmentAndSave(array $data)
	{
		$this->app->shouldReceive('getURLPath')->andReturn('')->atLeast()->once();

		$this->mockConnect(true, 1);
		$this->mockConnected(true, 1);
		$this->mockExistsTable('user', false, 1);
		$this->mockUpdate([$this->root->url(), false, true, true], null, 1);

		$this->mockGetMarkupTemplate('local.config.tpl', 'testTemplate', 1);
		$this->mockReplaceMacros('testTemplate', \Mockery::any(), '', 1);

		$this->assertTrue(putenv('MYSQL_HOST='     . $data['database']['hostname']));
		$this->assertTrue(putenv('MYSQL_PORT='     . $data['database']['port']));
		$this->assertTrue(putenv('MYSQL_DATABASE=' . $data['database']['database']));
		$this->assertTrue(putenv('MYSQL_USERNAME=' . $data['database']['username']));
		$this->assertTrue(putenv('MYSQL_PASSWORD=' . $data['database']['password']));

		$this->assertTrue(putenv('FRIENDICA_URL_PATH='   . $data['system']['urlpath']));
		$this->assertTrue(putenv('FRIENDICA_PHP_PATH='   . $data['config']['php_path']));
		$this->assertTrue(putenv('FRIENDICA_ADMIN_MAIL=' . $data['config']['admin_email']));
		$this->assertTrue(putenv('FRIENDICA_TZ='         . $data['system']['default_timezone']));
		$this->assertTrue(putenv('FRIENDICA_LANG='       . $data['system']['language']));

		$console = new AutomaticInstallation($this->consoleArgv);
		$console->setOption('savedb', true);

		$txt = $this->dumpExecute($console);

		$this->assertFinished($txt, true);
		$this->assertConfig($data, true, true, false);
	}

	/**
	 * Test the automatic installation with environment variables
	 * Don't save the db credentials to the file
	 * @dataProvider dataInstaller
	 */
	public function testWithEnvironmentWithoutSave(array $data)
	{
		$this->app->shouldReceive('getURLPath')->andReturn('')->atLeast()->once();

		$this->mockConnect(true, 1);
		$this->mockConnected(true, 1);
		$this->mockExistsTable('user', false, 1);
		$this->mockUpdate([$this->root->url(), false, true, true], null, 1);

		$this->mockGetMarkupTemplate('local.config.tpl', 'testTemplate', 1);
		$this->mockReplaceMacros('testTemplate', \Mockery::any(), '', 1);

		$this->assertTrue(putenv('MYSQL_HOST=' . $data['database']['hostname']));
		$this->assertTrue(putenv('MYSQL_PORT=' . $data['database']['port']));
		$this->assertTrue(putenv('MYSQL_DATABASE=' . $data['database']['database']));
		$this->assertTrue(putenv('MYSQL_USERNAME=' . $data['database']['username']));
		$this->assertTrue(putenv('MYSQL_PASSWORD=' . $data['database']['password']));

		$this->assertTrue(putenv('FRIENDICA_URL_PATH=' . $data['system']['urlpath']));
		$this->assertTrue(putenv('FRIENDICA_PHP_PATH=' . $data['config']['php_path']));
		$this->assertTrue(putenv('FRIENDICA_ADMIN_MAIL=' . $data['config']['admin_email']));
		$this->assertTrue(putenv('FRIENDICA_TZ=' . $data['system']['default_timezone']));
		$this->assertTrue(putenv('FRIENDICA_LANG=' . $data['system']['language']));

		$console = new AutomaticInstallation($this->consoleArgv);

		$txt = $this->dumpExecute($console);

		$this->assertFinished($txt, true);
		$this->assertConfig($data, false, true);
	}

	/**
	 * Test the automatic installation with arguments
	 * @dataProvider dataInstaller
	 */
	public function testWithArguments(array $data)
	{
		$this->app->shouldReceive('getURLPath')->andReturn('')->atLeast()->once();

		$this->mockConnect(true, 1);
		$this->mockConnected(true, 1);
		$this->mockExistsTable('user', false, 1);
		$this->mockUpdate([$this->root->url(), false, true, true], null, 1);

		$this->mockGetMarkupTemplate('local.config.tpl', 'testTemplate', 1);
		$this->mockReplaceMacros('testTemplate', \Mockery::any(), '', 1);

		$console = new AutomaticInstallation($this->consoleArgv);

		$option = function($var, $cat, $key) use ($data, $console) {
			if (!empty($data[$cat][$key])) {
				$console->setOption($var, $data[$cat][$key]);
			}
		};
		$option('dbhost'   , 'database', 'hostname');
		$option('dbport'   , 'database', 'port');
		$option('dbuser'   , 'database', 'username');
		$option('dbpass'   , 'database', 'password');
		$option('dbdata'   , 'database', 'database');
		$option('urlpath'  , 'system'  , 'urlpath');
		$option('phppath'  , 'config'  , 'php_path');
		$option('admin'    , 'config'  , 'admin_email');
		$option('tz'       , 'system'  , 'default_timezone');
		$option('lang'     , 'system'  , 'language');

		$txt = $this->dumpExecute($console);

		$this->assertFinished($txt, true);
		$this->assertConfig($data, true, true, true);
	}

	/**
	 * Test the automatic installation with a wrong database connection
	 */
	public function testNoDatabaseConnection()
	{
		$this->app->shouldReceive('getURLPath')->andReturn('')->atLeast()->once();
		$this->mockConnect(false, 1);

		$this->mockGetMarkupTemplate('local.config.tpl', 'testTemplate', 1);
		$this->mockReplaceMacros('testTemplate', \Mockery::any(), '', 1);

		$console = new AutomaticInstallation($this->consoleArgv);

		$txt = $this->dumpExecute($console);

		$this->assertStuckDB($txt);
		$this->assertTrue($this->root->hasChild('config' . DIRECTORY_SEPARATOR . 'local.config.php'));

		$this->assertConfig(null, false, true, false);
	}

	public function testGetHelp()
	{
		// Usable to purposely fail if new commands are added without taking tests into account
		$theHelp = <<<HELP
Installation - Install Friendica automatically
Synopsis
	bin/console autoinstall [-h|--help|-?] [-v] [-a] [-f]

Description
    Installs Friendica with data based on the local.config.php file or environment variables

Notes
    Not checking .htaccess/URL-Rewrite during CLI installation.

Options
    -h|--help|-?            Show help information
    -v                      Show more debug information.
    -a                      All setup checks are required (except .htaccess)
    -f|--file <config>      prepared config file (e.g. "config/local.config.php" itself) which will override every other config option - except the environment variables)
    -s|--savedb             Save the DB credentials to the file (if environment variables is used)
    -H|--dbhost <host>      The host of the mysql/mariadb database (env MYSQL_HOST)
    -p|--dbport <port>      The port of the mysql/mariadb database (env MYSQL_PORT)
    -d|--dbdata <database>  The name of the mysql/mariadb database (env MYSQL_DATABASE)
    -U|--dbuser <username>  The username of the mysql/mariadb database login (env MYSQL_USER or MYSQL_USERNAME)
    -P|--dbpass <password>  The password of the mysql/mariadb database login (env MYSQL_PASSWORD)
    -u|--urlpath <url_path> The URL path of Friendica - f.e. '/friendica' (env FRIENDICA_URL_PATH) 
    -b|--phppath <php_path> The path of the PHP binary (env FRIENDICA_PHP_PATH) 
    -A|--admin <mail>       The admin email address of Friendica (env FRIENDICA_ADMIN_MAIL)
    -T|--tz <timezone>      The timezone of Friendica (env FRIENDICA_TZ)
    -L|--lang <language>    The language of Friendica (env FRIENDICA_LANG)
 
Environment variables
   MYSQL_HOST                  The host of the mysql/mariadb database (mandatory if mysql and environment is used)
   MYSQL_PORT                  The port of the mysql/mariadb database
   MYSQL_USERNAME|MYSQL_USER   The username of the mysql/mariadb database login (MYSQL_USERNAME is for mysql, MYSQL_USER for mariadb)
   MYSQL_PASSWORD              The password of the mysql/mariadb database login
   MYSQL_DATABASE              The name of the mysql/mariadb database
   FRIENDICA_URL_PATH          The URL path of Friendica (f.e. '/friendica')
   FRIENDICA_PHP_PATH          The path of the PHP binary
   FRIENDICA_ADMIN_MAIL        The admin email address of Friendica (this email will be used for admin access)
   FRIENDICA_TZ                The timezone of Friendica
   FRIENDICA_LANG              The langauge of Friendica
   
Examples
	bin/console autoinstall -f 'input.config.php
		Installs Friendica with the prepared 'input.config.php' file

	bin/console autoinstall --savedb
		Installs Friendica with environment variables and saves them to the 'config/local.config.php' file

	bin/console autoinstall -h localhost -p 3365 -U user -P passwort1234 -d friendica
		Installs Friendica with a local mysql database with credentials

HELP;

		$console = new AutomaticInstallation($this->consoleArgv);
		$console->setOption('help', true);

		$txt = $this->dumpExecute($console);

		$this->assertEquals($theHelp, $txt);
	}
}
