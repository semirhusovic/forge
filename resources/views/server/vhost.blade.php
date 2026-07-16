<VirtualHost *:80>
    ServerName {{ $site->domain }}
    DocumentRoot {{ $site->webRoot() }}

    <Directory {{ $site->webRoot() }}>
        Options -Indexes +FollowSymLinks
        AllowOverride All
        Require all granted
    </Directory>

    <FilesMatch \.php$>
        SetHandler "proxy:unix:{{ $site->fpmSocket() }}|fcgi://localhost"
    </FilesMatch>

    ErrorLog ${APACHE_LOG_DIR}/{{ $site->domain }}-error.log
    CustomLog ${APACHE_LOG_DIR}/{{ $site->domain }}-access.log combined
</VirtualHost>
