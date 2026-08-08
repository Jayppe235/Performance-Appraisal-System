# PMAS school hosting request

Please provide a temporary HTTPS host for PMAS with:

- PHP 8.1 or newer and extensions: PDO MySQL, OpenSSL, Mbstring, GD, ZIP, and cURL
- Private MySQL/MariaDB reachable from PHP but not from the public internet
- A dedicated non-root database user restricted to one PMAS database
- SSH/SFTP deployment access using a dedicated key
- A document root pointing to `<deploy-root>/current`
- Persistent storage at `<deploy-root>/shared/uploads`
- Server environment variables for Apache/PHP
- Daily cron capability and encrypted off-server backup storage
- A temporary TLS-protected URL; the final school subdomain will follow acceptance

Provide these values securely, not by email or in Git:

- SSH host, port, username, private deployment key, and absolute deploy root
- Temporary HTTPS base URL
- Database host, port, name, username, and password
- School-controlled email domain for temporary accounts

Outbound HTTPS must be allowed for Resend and the configured AI provider when those
features are enabled. Long-running HTTP responses must support the dashboard
server-sent events endpoint.
