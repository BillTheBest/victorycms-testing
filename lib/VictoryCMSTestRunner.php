<?php
//
//  VictoryCMS - Content managment system and framework.
//
//  Copyright (C) 2009, 2010  Lewis Gunsch <lgunsch@victorycms.org>
//  Copyright (C) 2009 Andrew Crouse <acrouse@victorycms.org>
//
//  This file is part of VictoryCMS.
//
//  VictoryCMS is free software: you can redistribute it and/or modify
//  it under the terms of the GNU General Public License as published by
//  the Free Software Foundation, either version 2 of the License, or
//  (at your option) any later version.
//
//  VictoryCMS is distributed in the hope that it will be useful,
//  but WITHOUT ANY WARRANTY; without even the implied warranty of
//  MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
//  GNU General Public License for more details.
//
//  You should have received a copy of the GNU General Public License
//  along with VictoryCMS.  If not, see <http://www.gnu.org/licenses/>.

namespace Vcms;

require_once 'VictoryCMS.php';
require_once 'Vcms-ClassFileMapFactory.php';
require_once 'Vcms-ClassFileMap.php';
require_once 'Vcms-ClassFileMapAutoloader.php';

/**
 * VictoryCMS testing environment main bootstrapping class; This is the entry point
 * to the VictoryCMS testing system. It initializes a test class autoloader and runs
 * all the tests located in the lib/test directory and the app/test directory. It
 * will load all the tests, execute them, and then report on the results.
 *
 * Normally a test folder is located in the /path/to/web/root/www/test/ directory
 * with a index.php so that it is web-reachable where test results can be seen on a
 * testing server.
 *
 * Example: http://www.example.com/test/index.php
 *
 * *Note*: This depends on *VictoryCMS.php*, *ClassFileMapAutoloader.php*,
 * *ClassFileMap.php*, and *ClassFileMapFactory.php* for dynamically building all the
 * required objects during testing and interacting with VictoryCMS. This files should
 * be located in the same directory as VictoryCMSTestRunner.php.
 *
 * @filesource
 * @category VictoryCMS
 * @package  Testing
 * @author   Lewis Gunsch <lgunsch@victorycms.org>
 * @author   Andrew Crouse <amcrouse@victorycms.org>
 * @license  GPL http://www.gnu.org/licenses/gpl.html
 * @link     http://www.victorycms.org/
 * @see      http://ajbrown.org/blog/2008/12/02/an-auto-loader-using-php-tokenizer.html
 */
class VictoryCMSTestRunner extends VictoryCMS
{
	/** A.J. Brown's dynamic tokenizing autoloader */
	protected $autoLoader;

	/** Library testing path */
	protected $libTestPath;

	/** application testing path */
	protected $appTestPath;

	/**
	 * Create a new VictoryCMSTestRunner for running all tests located in the
	 * lib/test directory and the app/test directory. All classes under the lib
	 * path and the app path will be able to be autoloaded by the recursive
	 * autoloader.
	 *
	 * @param string $settings_path path to the settings JSON file.
	 */
	public function __construct($settings_path)
	{
		// Seed the registry and initialize the environment
		static::seedRegistry($settings_path, true);
		static::initialize();

		// instanciate a new auto loader
		$this->autoLoader = new ClassFileMapAutoloader();

		// set the lib testing path
		$libPath = Registry::get(RegistryKeys::LIB_PATH);
		$this->libTestPath = $libPath.DIRECTORY_SEPARATOR.'test';

		// ensure sanity of the lib testing path, which must exist
		if (! is_dir($this->libTestPath)) {
			echo "The lib/test directory is missing from the lib/ directory.\n";
			echo "You should create the directory lib/test.\n";
			exit();

		}
		if (! is_readable($this->libTestPath)) {
			echo "The lib path '.$this->libTestPath.' is not readable.\n";
			exit();
		}

		// build a class file map for VictoryCMS and add it to the autoloader
		$libPathMap = ClassFileMapFactory::generate($libPath, "lib-map");
		$this->autoLoader->addClassFileMap($libPathMap);

		// register the autoloader
		$registered = $this->autoLoader->registerAutoload();
		if (! $registered) {
			exit('VictoryCMS could not attach the required testing autoloader!');
		}

		// load in configuration file settings and any external libraries
		static::load();
		static::loadLibraries();

		// If the app path is set, configure it also
		if (Registry::isKey(RegistryKeys::APP_PATH)) {

			// set the app testing path
			$appPath = FileUtils::truepath(Registry::get(RegistryKeys::APP_PATH));
			$this->appTestPath = $appPath.DIRECTORY_SEPARATOR.'test';

			// ensure sanity of the app testing path
			if (! is_dir($this->appTestPath)) {
				echo "The app/test directory is missing from the app/ directory.\n";
				echo "You should create the directory app/test.\n";
				exit();
			}
			if (! is_readable($this->appTestPath)) {
				echo "The app path '.$this->appTestPath.' is not readable.\n";
				exit();
			}

			// build a class file map for the web application and add it to the autoloader
			$appPathMap = ClassFileMapFactory::generate($appPath, "app-map");
			$this->autoLoader->addClassFileMap($appPathMap);
		}
	}

	/**
	 * Run the tests and report on the results.
	 *
	 * @return void
	 */
	public function test()
	{
		$this->runTestGroupsByPath($this->libTestPath, 'lib');
		if (isset($this->appTestPath)) {
			$this->runTestGroupsByPath($this->appTestPath, 'app');
		}
	}

	/**
	 * Run test suites grouped by the directories and sub-directories.
	 *
	 * @param string $testPath Path containing optional sub-directories and test cases.
	 * @param string $baseName Base-name to prepend to test suite names.
	 *
	 * @return void
	 */
	protected function runTestGroupsByPath($testPath, $baseName)
	{
		$files = FileUtils::findPHPFiles($testPath);
		$testCases = $this->buildTestCaseArray($testPath, $baseName, $files);

		/* Run each set of test cases - each test suite is 1 directory */
		foreach ($testCases as $testName => $pathArray) {

			$test = $this->buildTestSuite($testName, $pathArray);

			/* run this test suite */
			if (\TextReporter::inCli()) {
				$test->run(new \TextReporter());
			} else {
				$test->run(new \HtmlReporter());
			}
		}
	}

	/**
	 * This collects the test case paths under each directory, replaces the common
	 * part of the path with the $baseName, and replace the directory separators
	 * with -.
	 *
	 * @param string $testPath full test path to match against the files.
	 * @param string $baseName Base-name to prepend to test suite names.
	 * @param array  $files    of full PHP test case paths.
	 *
	 * @return array of arrays with the key being a test case name and the value
	 * being an array of paths to be loaded for the test case.
	 */
	private function buildTestCaseArray($testPath, $baseName, $files)
	{
		$testCases = array();
		foreach ($files as $filePath) {
			if (is_file($filePath) && is_readable($filePath)) {
				$dirName = dirname($filePath);
				$dirName = str_replace($testPath, $baseName, $dirName);
				$dirName = str_replace(''.DIRECTORY_SEPARATOR, '-', $dirName);
				if (array_key_exists($dirName, $testCases)) {
					array_push($testCases[$dirName], $filePath);
				} else {
					$testCases[$dirName] = array(0 => $filePath);
				}
			}
		}

		return $testCases;
	}

	/**
	 * This creates a new TestSuite with the given name and loads it with the test
	 * any UnitTestCase classes located in the PHP files listed in the array
	 * $pathArray.
	 *
	 * @param string $testName  TestSuite name.
	 * @param array  $pathArray PHP file paths to look for UnitTestSuite classes.
	 *
	 * @return \TestSuite of UnitTestCase's.
	 */
	private function buildTestSuite($testName, $pathArray)
	{
		$test = new \TestSuite('Test Suite: '.$testName);
		foreach ($pathArray as $i => $path) {
			/* reverse-lookup the classes from each path. If the class
			 * is a UnitTestCase then add it into the test suite
			 */
			$classes = $this->autoLoader->reverseLookup($path);
			foreach ($classes as $index => $class) {
				/* If class is a singleton then it will have a private
				 * constructor. We can determine this using a reflection class.
				 */
				$rfClass = new \ReflectionClass($class);
				$constructor = $rfClass->getConstructor();

				if ($constructor != null && ! ($constructor->isPrivate() || $constructor->isProtected())) {
					$instance = new $class;
					if ($instance instanceof \UnitTestCase) {
						$test->addFile($path);
					}
				}
			}
		}
		return $test;
	}
}