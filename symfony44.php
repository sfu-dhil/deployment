<?php

declare(strict_types=1);

/*
 * (c) 2021 Michael Joyce <mjoyce@sfu.ca>
 * This source file is subject to the GPL v2, bundled
 * with this source code in the file LICENSE.
 */

namespace Deployer;

use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Yaml\Yaml;

require 'recipe/symfony4.php';

inventory('config/deploy.yaml');
$settings = Yaml::parseFile('config/deploy.yaml');
foreach ($settings['.settings'] as $key => $value) {
    set($key, $value);
}

$app = get('application');
if (file_exists("deploy.{$app}.php")) {
    require "deploy.{$app}.php";
}

set('console', fn () => parse('{{bin/php}} {{release_path}}/bin/console --no-interaction --quiet'));
set('lock_path', fn () => parse('{{deploy_path}}/.dep/deploy.lock'));

/*
 * Check that there are no modified files or commits that haven't been pushed. Ask the
 * user to confirm.
 */
task('dhil:precheck', function () : void {
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
        writeln("<error>Warning:</error> {$commits} commits not pushed.");
        $response = askConfirmation('Continue?');
        if ( ! $response) {
            exit;
        }
    }

    $res = run('[ -f {{lock_path}} ] && echo Locked || echo OK');
    if ('Locked' === trim($res)) {
        writeln('<error>Warning:</error> Target is locked. Unlock and continue?');
        $response = askConfirmation('Continue?');
        if ( ! $response) {
            exit;
        }
        run('rm -f {{lock_path}}');
    }
});

// Install the bundle assets.
task('dhil:assets', function () : void {
    $output = run('{{console}} assets:install --symlink');
    writeln($output);
})->desc('Install any bundle assets.');

/*
 * Run the testsuite on the server.
 *
 * Use the option --skip-tests to skip this step, but do so with caution.
 */
option('skip-tests', null, InputOption::VALUE_NONE, 'Skip testing. Probably a bad idea.');
task('dhil:phpunit', function () : void {
    if (input()->getOption('skip-tests')) {
        writeln('Skipped');

        return;
    }
    $output = run('cd {{ release_path }} && ./vendor/bin/phpunit', ['timeout' => null]);
    writeln($output);
})->desc('Run phpunit.');

// Empty out the test cache. Do this before and after running the test suite.
task('dhil:clear:test-cache', function () : void {
    $output = run('{{console}} cache:clear --env=test');
    writeln($output);
});

/*
 * Set up a clean environment on the server and run the test suite. Use with caution, as the
 * shared cache may present issues with the production site. It's very rare but it does happen.
 */
task('dhil:test', [
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
    'dhil:clear:test-cache',
])->desc('Run test suite on server in a clean environment.');
after('dhil:test', 'deploy:unlock');

// Install the yarn dependencies.
task('dhil:yarn', function () : void {
    $output = run('cd {{ release_path }} && yarn install --prod --silent');
    writeln($output);
})->desc('Install bower dependencies.');

// Install the fonts dependencies.
task('dhil:fonts', function () : void {
    if ( ! file_exists('config/fonts.yaml')) {
        return;
    }
    $output = run('cd {{ release_path }} && ./bin/console nines:fonts:download');
    writeln($output);
})->desc('Install fonts.');

// Build the Sphinx documentation.
task('dhil:sphinx:build', function () : void {
    if (file_exists('docs')) {
        runLocally('/usr/local/bin/sphinx-build docs/source public/docs/sphinx');
    }
})->desc('Build sphinx docs locally.');

// Upload the complete Sphinx documentation.
task('dhil:sphinx:upload', function () : void {
    if (file_exists('docs')) {
        $user = get('user');
        $host = get('hostname');
        $become = get('become');
        within('{{release_path}}', function () : void {
            run('mkdir -p public/docs/sphinx');
        });
        runLocally("rsync -av --rsync-path='sudo -u {$become} rsync' ./public/docs/sphinx/ {$user}@{$host}:{{release_path}}/public/docs/sphinx", ['timeout' => null]);
    }
})->desc('Upload Sphinx docs to server.');

/*
 * Build and install the Sphinx docs. This is a simple wrapper
 * around dhil:sphinx:build and dhil:sphinx:upload.
 */
task('dhil:sphinx', [
    'dhil:sphinx:build',
    'dhil:sphinx:upload',
])->desc('Build sphinx docs locally and upload to server.');

// Build the internal documentation.
task('dhil:sami:build', function () : void {
    if (file_exists('sami.php')) {
        runLocally('/usr/local/bin/sami update sami.php');
    }
})->desc('Build Sami API docs and upload to server.');

// Upload the internal documentation to the server.
task('dhil:sami:upload', function () : void {
    if (file_exists('sami.php')) {
        $user = get('user');
        $host = get('hostname');
        $become = get('become');
        within('{{release_path}}', function () : void {
            run('mkdir -p public/docs/api');
        });
        runLocally("rsync -av -e 'ssh' --rsync-path='sudo -u {$become} rsync' ./public/docs/api/ {$user}@{$host}:{{release_path}}/public/docs/api", ['timeout' => null]);
    }
})->desc('Build Sami API docs and upload to server.');

/*
 * Build and upload the internal documentation. This is really just a simple wrapper around
 * dhil:sami:build and dhil:sami:upload.
 */
task('dhil:sami', [
    'dhil:sami:build',
    'dhil:sami:upload',
])->desc('Build Sami API docs and upload to server.');

/*
 * Create a backup of the MySQL database. The mysql dump file will be saved as
 * {$app}-{$date}-{$revision}.sql.
 */
task('dhil:db:backup', function () : void {
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

// Create a MySQL database backup and download it from the server.
task('dhil:db:schema', function () : void {
    $user = get('user');
    $become = get('become');
    $app = get('application');
    $stage = get('stage');

    $date = date('Y-m-d');
    $current = get('release_name');

    set('become', $user); // prevent sudo -u from failing.
    $file = "/home/{$user}/{$app}-schema-{$date}-{$stage}-r{$current}.sql";
    run("sudo mysqldump {$app} --flush-logs --no-data -r {$file}");
    run("sudo chown {$user} {$file}");

    download($file, basename($file));
    writeln('Downloaded database dump to ' . basename($file));
    set('become', $become);
})->desc('Make a database backup and download it.');

// Create a MySQL database backup and download it from the server.
option('all-tables', null, InputOption::VALUE_NONE, 'Do not ignore any tables when fetching database.');
task('dhil:db:data', function () : void {
    $user = get('user');
    $become = get('become');
    $app = get('application');
    $stage = get('stage');

    $date = date('Y-m-d');
    $current = get('release_name');

    set('become', $user); // prevent sudo -u from failing.
    $file = "/home/{$user}/{$app}-data-{$date}-{$stage}-r{$current}.sql";
    $ignore = get('ignore_tables', []);
    if (count($ignore) && ! input()->getOption('all-tables')) {
        $ignoredTables = implode(',', array_map(fn ($s) => $app . '.' . $s, $ignore));
        run("sudo mysqldump {$app} --flush-logs --no-create-info -r {$file} --ignore-table={{$ignoredTables}}");
    } else {
        run("sudo mysqldump {$app} -r {$file}");
    }
    run("sudo chown {$user} {$file}");

    download($file, basename($file));
    writeln('Downloaded database dump to ' . basename($file));
    set('become', $become);
})->desc('Make a database backup and download it.');

task('dhil:db:migrate', function () : void {
    $count = (int) runLocally('find migrations -type f -name "*.php" | wc -l');
    if ($count > 1) {
        $options = '--allow-no-migration';
        if ('' !== get('migrations_config')) {
            $options = sprintf('%s --configuration={{release_path}}/{{migrations_config}}', $options);
        }
        run(sprintf('cd {{release_path}} && {{bin/php}} {{bin/console}} doctrine:migrations:migrate %s {{console_options}}', $options));
    } else {
        if (1 === $count) {
            $options = '';
            if ('' !== get('migrations_config')) {
                $options = '--configuration={{release_path}}/{{migrations_config}}';
            }
            run(sprintf('cd {{release_path}} && {{bin/php}} {{bin/console}} doctrine:migrations:rollup %s {{console_options}}', $options));
        } else {
            writeln('No migrations found.');
        }
    }
})->desc('Apply database changes');

task('dhil:db:rollup', function () : void {
    if ( ! file_exists('migrations')) {
        mkdir('migrations');
    }
    $count = (int) runLocally('find migrations -type f -name "*.php" | wc -l');
    if (0 !== $count) {
        writeln("There are {$count} migrations which must be removed before rolling up.");

        exit;
    }
    runLocally('php bin/console doctrine:migrations:dump-schema');
    runLocally('php bin/console doctrine:migrations:rollup');
});

task('dhil:permissions', function () : void {
    $user = get('user');
    $become = get('become');

    set('become', $user); // prevent sudo -u from failing.
    $output = run('cd {{ release_path }} && sudo chcon -R ' . get('context') . ' ' . implode(' ', get('writable_dirs')));
    $output .= run('cd {{ release_path }} && sudo chcon -R unconfined_u:object_r:httpd_log_t:s0 var/log');
    if ($output) {
        writeln($output);
    }

    set('become', $become);
});

// Display a success message.
task('success', function () : void {
    $target = get('target');
    $release = get('release_name');
    $host = get('hostname');
    $path = get('site_path');

    writeln("Successfully deployed {$target} release {$release}");
    writeln("Visit http://{$host}{$path} to check.");
});

// Create a MySQL database backup and download it from the server.
task('dhil:db:fetch', [
    'dhil:db:schema',
    'dhil:db:data',
]);

task('deploy', [
    'dhil:precheck',
    'deploy:info',
    'deploy:prepare',
    'deploy:lock',
    'deploy:release',
    'deploy:update_code',
    'deploy:shared',
    'deploy:vendors',

    'dhil:assets',
    'dhil:clear:test-cache',
    'dhil:phpunit',
    'dhil:clear:test-cache',
    'dhil:db:backup',
    'dhil:db:migrate',
    'dhil:sphinx',
    //    'dhil:sami',
    'dhil:yarn',
    'dhil:fonts',

    'deploy:writable',
    'dhil:permissions',
    'deploy:cache:clear',
    'deploy:cache:warmup',
    'deploy:symlink',
    'deploy:unlock',
    'cleanup',
]);
after('deploy:failed', 'deploy:unlock');
after('deploy', 'success');
