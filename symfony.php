<?php

namespace Deployer;

use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Yaml\Yaml;

require 'recipe/symfony3.php';

inventory('app/config/deploy.yml');
$settings = Yaml::parseFile('app/config/deploy.yml');
foreach ($settings['.settings'] as $key => $value) {
    set($key, $value);
}

$app = get('application');
if (file_exists("deploy.{$app}.php")) {
    require "deploy.{$app}.php";
}

task('dhil:precheck', function () {
    $out = runLocally('git status --porcelain --untracked-files=no');
    if ('' !== $out) {
        $modified = count(explode("\n", $out));
        writeln("<error>Warning:</error> {$modified} modified files have not been committed.");
        writeln($out);
        $response = askConfirmation('Continue?');
        if (! $response) {
            exit;
        }
    }

    $out = runLocally('git cherry -v');
    if ('' !== $out) {
        $commits = count(explode("\n", $out));
        writeln("<error>Warning:</error> {$commits} unpublished commits will not be included in the deployment.");
        $response = askConfirmation('Continue?');
        if (! $response) {
            exit;
        }
    }
});

task('dhil:deploy:quick', function () {
    writeln(run('cd {{ current_path }} && git pull origin master'));
    writeln(run('cd {{ current_path }} && git submodule foreach git pull origin master'));
    writeln(run('{{bin/php}} {{bin/console}} cache:clear --env=prod'));
});

option('skip-tests', null, InputOption::VALUE_NONE, 'Skip testing. Probably a bad idea.');
task('dhil:phpunit', function () {
    if (input()->getOption('skip-tests')) {
        writeln('Skipped');

        return;
    }
    $output = run('cd {{ release_path }} && ./vendor/bin/phpunit', array('timeout' => null));
    writeln($output);
})->desc('Run phpunit.');

task('dhil:clear:test-cache', function () {
    $output = run('{{bin/php}} {{bin/console}} cache:clear --env=test');
    writeln($output);
});

task('dhil:test', array(
    'deploy:info',
    'deploy:prepare',
    'deploy:lock',
    'deploy:release',
    'deploy:update_code',
    'deploy:create_cache_dir',
    'deploy:shared',
    'deploy:vendors',
    'dhil:clear:test-cache',
    'dhil:phpunit',
))->desc('Run test suite on server in a clean environment.');
after('dhil:test', 'deploy:unlock');

task('dhil:bower', function () {
    if(file_exists('bower.json')) {
        $output = run('cd {{ release_path }} && bower -q install');
        writeln($output);
    }
    if (file_exists('package.json')) {
        $output = run('cd {{ release_path }} && yarn install');
        writeln($output);
    }
})->desc('Install dependencies.');

task('dhil:sphinx:build', function () {
    if (file_exists('docs')) {
        runLocally('/usr/local/bin/sphinx-build docs/source web/docs/sphinx');
    }
})->desc('Build sphinx docs locally.');

task('dhil:sphinx:upload', function () {
    if (file_exists('docs')) {
        $user = get('user');
        $host = get('hostname');
        $become = get('become');
        within('{{release_path}}', function () {
            run('mkdir -p web/docs/sphinx');
        });
        runLocally("rsync -av --rsync-path='sudo -u {$become} rsync' ./web/docs/sphinx/ {$user}@{$host}:{{release_path}}/web/docs/sphinx", array('timeout' => null));
    }
})->desc('Upload Sphinx docs to server.');

task('dhil:sphinx', array(
    'dhil:sphinx:build',
    'dhil:sphinx:upload',
))->desc('Build sphinx docs locally and upload to server.');

task('dhil:sami:build', function () {
    if (file_exists('sami.php')) {
        runLocally('/usr/local/bin/sami update sami.php');
    }
})->desc('Build Sami API docs and upload to server.');
task('dhil:sami:upload', function () {
    if (file_exists('sami.php')) {
        $user = get('user');
        $host = get('hostname');
        $become = get('become');
        within('{{release_path}}', function () {
            run('mkdir -p web/docs/api');
        });
        runLocally("rsync -av -e 'ssh' --rsync-path='sudo -u {$become} rsync' ./web/docs/api/ {$user}@{$host}:{{release_path}}/web/docs/api", array('timeout' => null));
    }
})->desc('Build Sami API docs and upload to server.');
task('dhil:sami', array(
    'dhil:sami:build',
    'dhil:sami:upload',
))->desc('Build Sami API docs and upload to server.');

task('dhil:permissions', function(){
    $user = get('user');
    $become = get('become');

    set('become', $user); // prevent sudo -u from failing.
    $output = run('cd {{ current_path }} && sudo chcon -R ' . get('context') . ' ' . implode(' ', get('writable_dirs')));
    if($output) {
        writeln($output);
    }
    set('become', $become);
});

task('dhil:db:backup', function () {
    $user = get('user');
    $become = get('become');
    $app = get('application');

    set('become', $user); // prevent sudo -u from failing.
    $date = date('Y-m-d');
    $current = get('release_name');
    $file = "/home/{$become}/{$app}-{$date}-r{$current}.sql";
    run("sudo mysqldump {$app} -r {$file}");
    run("sudo chown {$become} {$file}");
    set('become', $become);
})->desc('Backup the mysql database.');

task('dhil:db:migrate', function () {
    $output = run('cd {{ release_path }} && ./bin/console doctrine:migrations:migrate --no-interaction');
    writeln($output);
});

task('dhil:db:fetch', function () {
    $user = get('user');
    $become = get('become');
    $app = get('application');
    $stage = get('stage');

    $date = date('Y-m-d');
    $current = get('release_name');

    set('become', $user); // prevent sudo -u from failing.
    $file = "/home/{$user}/{$app}-{$date}-{$stage}-r{$current}.sql";
    run("sudo mysqldump {$app} -r {$file}");
    run("sudo chown {$user} {$file}");

    download($file, basename($file));
    writeln('Downloaded database dump to ' . basename($file));
})->desc('Make a database backup and download it.');

task('success', function () {
    $target = get('target');
    $release = get('release_name');
    $host = get('hostname');
    $path = get('site_path');

    writeln("Successfully deployed {$target} release {$release}");
    writeln("Visit http://{$host}{$path} to check.");
});

task('deploy', array(
    'deploy:info',
    'dhil:precheck',
    'deploy:prepare',
    'deploy:lock',
    'deploy:release',
    'deploy:update_code',
    'deploy:clear_paths',
    'deploy:create_cache_dir',
    'deploy:shared',
    'deploy:vendors',
    'dhil:clear:test-cache',
    'dhil:phpunit',
    'deploy:assets:install',
    'deploy:cache:clear',
    'deploy:writable',
    'dhil:db:backup',
    'dhil:db:migrate',
    'dhil:sphinx',
//    'dhil:sami',
    'dhil:bower',
    'deploy:symlink',
    'dhil:permissions',
    'deploy:unlock',
    'cleanup',
))->desc('Deploy your project');
after('deploy:failed', 'deploy:unlock');
after('deploy', 'success');
