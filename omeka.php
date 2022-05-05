<?php

declare(strict_types=1);

/*
 * (c) 2022 Michael Joyce <mjoyce@sfu.ca>
 * This source file is subject to the GPL v2, bundled
 * with this source code in the file LICENSE.
 */

namespace Deployer;

use Symfony\Component\Yaml\Yaml;

const INVENTORY = 'application/config/deploy.yml';

require 'recipe/common.php';

inventory(INVENTORY);
$settings = Yaml::parseFile(INVENTORY);
foreach ($settings['.settings'] as $key => $value) {
    set($key, $value);
}
$app = get('application');
if (file_exists("deploy.{$app}.php")) {
    require "deploy.{$app}.php";
}

task('dhil:precheck', function() : void {
    $out = runLocally('git status --porcelain --untracked-files=no');
    if ('' !== $out) {
        $modified = count(explode("\n", $out));
        writeln("<error>Warning:</error> {$modified} modified files have not been committed.");
        writeln($out);
        $response = askConfirmation('Continue?');
        if ( ! $response) {
            exit;
        }
    }

    $out = runLocally('git cherry -v');
    if ('' !== $out) {
        $commits = count(explode("\n", $out));
        writeln("<error>Warning:</error> {$commits} unpublished commits will not be included in the deployment.");
        $response = askConfirmation('Continue?');
        if ( ! $response) {
            exit;
        }
    }
});

task('dhil:db:backup', function() : void {
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

task('dhil:db:migrate', function() : void {
    $output = run('cd {{ release_path }} && ./bin/console doctrine:migrations:migrate --no-interaction');
    writeln($output);
});

task('dhil:db:fetch', function() : void {
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

task('dhil:media:fetch', function() : void {
    $user = get('user');
    $host = get('hostname');
    $app = get('application');
    $stage = get('stage');

    download('{{ current_path }}/files/', 'files');
});

// restart php-fpm is required for some things.

task('deploy', [
    'deploy:info',
    'deploy:prepare',
    'deploy:lock',
    'deploy:release',
    'deploy:update_code',
    'deploy:shared',
    'deploy:writable',
    'deploy:clear_paths',
    'deploy:symlink',
    'deploy:unlock',
    'cleanup',
    'success',
]);

// [Optional] If deploy fails automatically unlock.
after('deploy:failed', 'deploy:unlock');
