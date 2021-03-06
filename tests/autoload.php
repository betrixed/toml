<?php

// we are in vendor/yosymfony/toml/tests
defined('V_ROOT') || define('V_ROOT', dirname(__DIR__, 3));
defined('TOML_SRC') || define('TOML_SRC', V_ROOT . '/betrixed/toml/src/Toml');
include_once V_ROOT . '/autoload.php';

$classLoader = new \Composer\Autoload\ClassLoader();

$classLoader->addPsr4("Toml\\", [TOML_SRC]);
$classLoader->register();