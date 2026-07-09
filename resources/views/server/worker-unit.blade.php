[Unit]
Description=Forge worker {{ $worker->id }} for {{ $site->domain }}
After=network.target

[Service]
User=forge
Restart=always
RestartSec=3
WorkingDirectory={{ $site->root_path }}
ExecStart={{ config('forge.php_binary') }} artisan {{ $worker->command }}

[Install]
WantedBy=multi-user.target
