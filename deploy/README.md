# Server templates

Installed on the VPS by `scripts/provision-server.sh` (once per server) and `scripts/add-client.sh` (once per client and environment). Placeholders in `{{BRACES}}` are filled in by those scripts. See [docs/deploy/CLOUD.md](../docs/deploy/CLOUD.md).

| File                         | Installed as                                         | By         |
| ---------------------------- | ---------------------------------------------------- | ---------- |
| `systemd/pos-queue@.service` | `/etc/systemd/system/pos-queue@.service`             | provision  |
| `systemd/pos-scheduler@.*`   | `/etc/systemd/system/pos-scheduler@.{service,timer}` | provision  |
| `caddy/Caddyfile`            | `/etc/caddy/Caddyfile` (imports the sites)           | provision  |
| `caddy/site.caddy`           | `/etc/caddy/sites/<client>.caddy`                    | add-client |
| `php/pos.ini`                | `/etc/php/8.5/{fpm,cli}/conf.d/99-pos.ini`           | provision  |
| `php/pool.conf`              | `/etc/php/8.5/fpm/pool.d/pos-<client>.conf`          | add-client |
| `env/production.env`         | `/srv/pos/<client>/shared/.env`                      | add-client |

`<client>` is the instance name of the systemd units: `pos-queue@clinic`, `pos-scheduler@clinic.timer`. A staging copy is its own client (`clinic-staging`).
