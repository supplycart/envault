<?php


use function Deployer\cd;
use function Deployer\currentHost;
use function Deployer\desc;
use function Deployer\get;
use function Deployer\run;
use function Deployer\task;

desc('Create deploy key');
task('provision:deploy-key', function () {
    run('ssh-keygen -t ed25519 -C "deployer" -f ~/.ssh/id_ed25519');
    run('cat ~/.ssh/id_ed25519.pub');
})
    ->oncePerNode()
    ->verbose();

desc('Rename hostname');
task('provision:set-hostname', function () {
    $hostname = currentHost();
    run("sudo hostnamectl set-hostname {$hostname->getHostname()}");
})->verbose();

desc('Install Nginx');
task('provision:nginx', function () {
    run('sudo add-apt-repository -y ppa:ondrej/nginx');
    run('sudo apt update && sudo apt install -y nginx acl zip unzip', [
        'env' => ['DEBIAN_FRONTEND' => 'noninteractive'],
    ]);
    run("sudo systemctl enable nginx");
})
    ->verbose();

desc('Install PHP');
task('provision:php', function () {
    run('sudo add-apt-repository -y ppa:ondrej/php');

    $version = get('php_version');

    $packages = [
        "php$version-bcmath",
        "php$version-cli",
        "php$version-curl",
        "php$version-fpm",
        "php$version-gd",
        "php$version-imap",
        "php$version-intl",
        "php$version-mbstring",
        "php$version-mysql",
        "php$version-pgsql",
        "php$version-readline",
        "php$version-sqlite3",
        "php$version-xml",
        "php$version-zip",
        "php$version-redis",
    ];
    run('sudo apt update && sudo apt install -y ' . implode(' ', $packages), [
        'env' => ['DEBIAN_FRONTEND' => 'noninteractive'],
    ]);
    run('php -v');
})
    ->verbose();

desc('Installs Composer');
task('provision:composer', function () {
    run('curl -sS https://getcomposer.org/installer | php');
    run('sudo mv composer.phar /usr/local/bin/composer');
    run('composer -V');
})
    ->verbose();

desc('Install Node');
task('provision:node', function () {
    run('sudo apt remove -y nodejs');
    run('curl -fsSL https://deb.nodesource.com/setup_16.x | sudo -E bash -');
    run('sudo apt install -y nodejs', ['env' => ['DEBIAN_FRONTEND' => 'noninteractive']]);
    run('node -v');
    run('npm -v');
})
    ->verbose();

desc('Install Supervisor');
task('provision:supervisor', function () {
    run('sudo apt install -y supervisor', ['env' => ['DEBIAN_FRONTEND' => 'noninteractive']]);
})
    ->verbose();

desc('Install Wkhtmltopdf');
task('provision:wkhtmltopdf', function () {
    run('wget https://github.com/wkhtmltopdf/packaging/releases/download/0.12.6-1/wkhtmltox_0.12.6-1.focal_arm64.deb');
    run('sudo apt install -y xfonts-75dpi ./wkhtmltox_0.12.6-1.focal_arm64.deb',
        ['env' => ['DEBIAN_FRONTEND' => 'noninteractive']]);
    run('which wkhtmltopdf');
})
    ->verbose();

desc('First deployment');
task('provision:deploy', [
    'deploy:prepare',
    'deploy:vendors',
    'deploy:publish',
]);

desc('Configure nginx config');
task('configure:nginx', function () {
    $app = get('application');
    $phpVersion = get('php_version');
    $domain = get('domain');

    $nginxConfig = <<<EOF
    server {
        listen 80;
        listen [::]:80;
        root /home/ubuntu/$app/current/public;
        server_name $domain;
    
        add_header X-Frame-Options "SAMEORIGIN";
        add_header X-Content-Type-Options "nosniff";
        add_header X-XSS-Protection "1; mode=block";
        add_header Strict-Transport-Security "max-age=31536000; includeSubDomains; preload";
    
        index index.php index.htm index.html;
    
        charset utf-8;
    
        location / {
            try_files \$uri \$uri/ /index.php?\$query_string;
        }
    
        location = /favicon.ico { access_log off; log_not_found off; }
        location = /robots.txt  { access_log off; log_not_found off; }
    
        error_page 404 /index.php;
    
        location ~ \.php$ {
            fastcgi_pass unix:/var/run/php/php$phpVersion-fpm.sock;
            fastcgi_index index.php;
            fastcgi_param SCRIPT_FILENAME \$realpath_root\$fastcgi_script_name;
            fastcgi_buffers 16 16k;
            fastcgi_buffer_size 64k;
            include fastcgi_params;
        }
    
        location ~ /\.(?!well-known).* {
            deny all;
        }
    }
    EOF;

    run("echo $'$nginxConfig' > nginx.config");
    run("sudo mv -f nginx.config /etc/nginx/sites-available/default");
    run("sudo sed -i 's/user .*/user ubuntu;/' /etc/nginx/nginx.conf");
    run("sudo sed -i 's/#server_names_hash_bucket_size .*/server_names_hash_bucket_size 64;/' /etc/nginx/nginx.conf");
    run("sudo systemctl reload nginx");
})
    ->verbose();

desc('Configure PHP');
task('configure:php', function () {
    $version = get('php_version');

    // Configure PHP-FPM
    run("sudo systemctl enable php$version-fpm");
    run("sudo sed -i 's/memory_limit = .*/memory_limit = 512M/' /etc/php/$version/fpm/php.ini");
    run("sudo sed -i 's/post_max_size = .*/post_max_size = 100M/' /etc/php/$version/fpm/php.ini");
    run("sudo sed -i 's/max_execution_time = .*/max_execution_time = 300/' /etc/php/$version/fpm/php.ini");
    run("sudo sed -i 's/user = .*/user = ubuntu/' /etc/php/$version/fpm/pool.d/www.conf");
    run("sudo sed -i 's/group = .*/group = ubuntu/' /etc/php/$version/fpm/pool.d/www.conf");
    run("sudo sed -i 's/listen.owner = .*/listen.owner = ubuntu/' /etc/php/$version/fpm/pool.d/www.conf");
    run("sudo sed -i 's/listen.group = .*/listen.group = ubuntu/' /etc/php/$version/fpm/pool.d/www.conf");
    run("sudo chown -R ubuntu: /var/run/php");
    run("sudo service php$version-fpm restart");
    run("sudo service php$version-fpm status");
})
    ->verbose();

desc('Configure Scheduler Cron Job');
task('configure:scheduler', function () {
    $app = get('application');
    run('echo "* * * * * cd /home/ubuntu/' . $app . '/current && php artisan schedule:run >> /dev/null 2>&1" | crontab -');
})
    ->oncePerNode()
    ->verbose();

desc('Configure Horizon');
task('configure:horizon', function () {
    $app = get('application');

    $horizonConfig = <<<EOF
    [program:horizon]
    process_name=%(program_name)s
    command=php /home/ubuntu/$app/current/artisan horizon
    autostart=true
    autorestart=true
    user=ubuntu
    redirect_stderr=true
    stdout_logfile=/home/ubuntu/$app/shared/storage/logs/horizon.log
    EOF;

    run("echo $'$horizonConfig' > horizon.config");
    run("sudo mv -f horizon.config /etc/supervisor/conf.d/horizon.conf");
    run("sudo supervisorctl update");
    run("sudo supervisorctl status all");
})
    ->verbose();

desc('Provision server with dependencies');
task('provision', [
    'provision:set-hostname',
    'provision:nginx',
    'provision:php',
    'provision:node',
    'provision:composer',
    // 'provision:supervisor',
    // 'provision:wkhtmltopdf',
    // 'provision:deploy'
]);

desc('Configure all dependencies');
task('configure', [
    'configure:nginx',
    'configure:php',
    // 'configure:horizon',
    'configure:scheduler',
]);

desc('Update .env file');
task('deploy:env', function () {
    $envaultCommand = get('envault_command');

    if ($envaultCommand) {
        cd('{{current_path}}');
        run($envaultCommand);
    }
})->verbose();