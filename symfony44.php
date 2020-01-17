<?php

namespace Deployer;

use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Yaml\Yaml;

require 'recipe/symfony4.php';

inventory('config/deploy.yml');
$settings = Yaml::parseFile('config/deploy.yml');
foreach ($settings['.settings'] as $key => $value) {
    set($key, $value);
}
