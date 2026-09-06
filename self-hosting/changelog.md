# Changelog

> All notable changes to Databasement are documented here. The format follows
[Keep a Changelog](https://keepachangelog.com/en/1.1.0/) and the project adheres to
[Semantic Versioning](https://semver.org/spec/v2.0.0.html).

# Changelog

All notable changes to Databasement are documented here. The format follows
[Keep a Changelog](https://keepachangelog.com/en/1.1.0/) and the project adheres to
[Semantic Versioning](https://semver.org/spec/v2.0.0.html).

Each section covers one minor version; every entry is prefixed with the patch release
that shipped it. Releases before 1.0.0 are only listed on
[GitHub Releases](https://github.com/David-Crty/databasement/releases).

## [1.7] - 2026-09-06 {#v1-7}

### Added

- `1.7.11` Super admins can list the database servers of every organization at once, from a searchable overview opened on the Configuration → Organizations page, with the latest backup status of each server and a shortcut that switches to its organization and opens the server or its jobs ([#521](https://github.com/David-Crty/databasement/pull/521))
- `1.7.11` The changelog is written for the people who run Databasement and published as a page in the application, linked from the sidebar and the update dialog, and on the documentation site ([#600](https://github.com/David-Crty/databasement/pull/600))
- `1.7.9` Snapshots can carry a free-text comment and be locked, which excludes them from automatic cleanup and blocks manual deletion until unlocked ([#522](https://github.com/David-Crty/databasement/pull/522))
- `1.7.4` A restore from a PostgreSQL snapshot that preserves ownership and privileges can now set the owner of the restored database, and the one-off restore API accepts `options.owner_user` as scheduled restores already did ([#525](https://github.com/David-Crty/databasement/pull/525)) ([#577](https://github.com/David-Crty/databasement/pull/577))
- `1.7.4` PostgreSQL servers have an optional connection database field, defaulting to `postgres`, for managed providers (Heroku, RDS, Neon) whose roles are not allowed to connect to `postgres` ([#560](https://github.com/David-Crty/databasement/pull/560))
- `1.7.1` SSH tunnel configurations can be managed through the REST API at `/api/v1/database-server-ssh-configs`, with optional server-side key generation, so a pipeline can create one and pass its id to the database server endpoint ([#493](https://github.com/David-Crty/databasement/pull/493)) ([#496](https://github.com/David-Crty/databasement/pull/496))
- `1.7.0` A backup configuration can target several storage volumes: the database is dumped once and the archive uploaded to each, and the Restore modal and the download button let you pick the copy when a snapshot has more than one ([#478](https://github.com/David-Crty/databasement/pull/478))
- `1.7.0` PostgreSQL servers have a "Use SSL" option that requires TLS on every connection ([#490](https://github.com/David-Crty/databasement/pull/490)) ([#491](https://github.com/David-Crty/databasement/pull/491))
- `1.7.0` Each volume copy of a snapshot has its own badge on the snapshot list, coloured by state (failed upload with the error, missing file, still uploading), and the download picker shows the archive size ([#495](https://github.com/David-Crty/databasement/pull/495))

### Changed

- `1.7.10` Every button that triggers a server request shows a spinner while the request is in flight, including per-row actions and form submits ([#599](https://github.com/David-Crty/databasement/pull/599))
- `1.7.1` Job logs keep the start and the end of a command's output, note what was dropped, and are written at most once per second, so a dump emitting many warnings no longer makes the interface crawl ([#497](https://github.com/David-Crty/databasement/pull/497))

### Deprecated

- `1.7.0` The `volume_id` and `volume` keys of the v1 backup API are kept but deprecated in favour of `volume_ids` and `volumes` ([#478](https://github.com/David-Crty/databasement/pull/478))

### Fixed

- `1.7.10` A form that fails validation raises a toast, expands the collapsed section holding the first invalid field and scrolls it into view with focus; the connection test buttons validate the same way ([#598](https://github.com/David-Crty/databasement/pull/598))
- `1.7.9` A command log no longer shows a running spinner forever when the job dies before the command finishes (queue timeout, killed worker, fatal error); it is marked failed together with the job ([#541](https://github.com/David-Crty/databasement/pull/541))
- `1.7.8` PostgreSQL servers below version 17 are dumped with the PostgreSQL 16 client, now shipped in the image alongside the 18 client, so their snapshots restore back into the server they came from; when no matching client exists, the job log warns that the snapshot may only load into a newer server ([#595](https://github.com/David-Crty/databasement/pull/595)) ([#596](https://github.com/David-Crty/databasement/pull/596))
- `1.7.5` Page navigation no longer stops working until a manual refresh once the version modal has rendered, a regression from the UI library upgrade in 1.7.4 ([#580](https://github.com/David-Crty/databasement/pull/580))
- `1.7.4` Plain-format PostgreSQL restores fail on the first SQL error instead of being reported as completed with the data untouched ([#550](https://github.com/David-Crty/databasement/pull/550)) ([#562](https://github.com/David-Crty/databasement/pull/562))
- `1.7.4` Encrypted snapshots of PostgreSQL custom-format, Redis, MongoDB, MSSQL and Firebird dumps restore again instead of failing with "output file not found" ([#551](https://github.com/David-Crty/databasement/pull/551)) ([#561](https://github.com/David-Crty/databasement/pull/561))
- `1.7.3` Deleting a row from a later page of a list no longer sends you back to page one; only filter changes reset the page ([#517](https://github.com/David-Crty/databasement/pull/517))
- `1.7.2` A backup running longer than 90 seconds is no longer handed to a second queue worker that dumped the same snapshot concurrently: the queue's `retry_after` now follows the backup job timeout, duplicate deliveries are dropped, and workers restart when the timeout changes ([#488](https://github.com/David-Crty/databasement/pull/488)) ([#510](https://github.com/David-Crty/databasement/pull/510))
- `1.7.1` Backups of MySQL servers on the new year-based version scheme (26.x) work again: the MariaDB dump client's `--routines` flag, which fails on them, is dropped with a warning in the job log ([#494](https://github.com/David-Crty/databasement/pull/494)) ([#498](https://github.com/David-Crty/databasement/pull/498))

### Security

- `1.7.8` The Azure custom blob endpoint rejects link-local and instance metadata addresses, as the S3 endpoints already did, and the SFTP, FTP and SMB host fields only accept hostnames and IP addresses ([#594](https://github.com/David-Crty/databasement/pull/594))
- `1.7.8` Records looked up by id through the web and REST routes are resolved with the organization context in place, so an id from another organization is not found instead of being returned; policies now also check that the record belongs to the current organization ([#591](https://github.com/David-Crty/databasement/pull/591))
- `1.7.8` The Redis password is masked in logged commands; it was stored in cleartext in the job logs, which every member of the organization can read ([#593](https://github.com/David-Crty/databasement/pull/593))
- `1.7.7` The job logs modal checks the view permission wherever the job id comes from, so a job from another organization cannot be opened by setting the id from the browser ([#589](https://github.com/David-Crty/databasement/pull/589))
- `1.7.6` Looking up a single job through the REST API or the MCP job status tool only finds jobs in the current organization ([#584](https://github.com/David-Crty/databasement/pull/584))
- `1.7.6` The bundled web server only executes `public/index.php`; any other `.php` file under `public/` returns 404 instead of running ([#585](https://github.com/David-Crty/databasement/pull/585))
- `1.7.6` Dump flags that choose where the dump client writes or loads options from (such as `--result-file`, `--file`, `--out`, `--rdb` and `/targetfile`) are rejected per engine, and the MSSQL dump flags are now validated by the API too ([#583](https://github.com/David-Crty/databasement/pull/583))
- `1.7.6` The `ssh_config_id` accepted by the database server API must belong to the current organization ([#582](https://github.com/David-Crty/databasement/pull/582))
- `1.7.3` Restore records are scoped to their organization, so a restore from another organization cannot be viewed or deleted by id; cross-organization notification links to snapshots resolve again ([#516](https://github.com/David-Crty/databasement/pull/516))
- `1.7.3` The post-backup and post-restore hook scripts can only be edited by super admins; notification channel URLs reject link-local and instance metadata addresses; SQLite and Firebird database paths are validated ([#515](https://github.com/David-Crty/databasement/pull/515))
- `1.7.2` Snapshots and scheduled restores are scoped to their organization by default and a restore source must belong to the same organization as its target; database host fields are restricted to hostnames and IP addresses, S3 endpoints reject link-local and instance metadata addresses, SQLite and Firebird paths and agent-reported filenames are validated, and `/health/debug` requires authentication ([#511](https://github.com/David-Crty/databasement/pull/511))
- `1.7.0` Bumped `concurrently` to pull in `shell-quote` 1.9.0, fixing a denial of service (CVE-2026-13311) ([#486](https://github.com/David-Crty/databasement/pull/486))

## [1.6] - 2026-07-27 {#v1-6}

### Added

- `1.6.9` Samba/SMB shares are a native volume type, so backups can target Windows or Samba shares without mounting them on the host; the volumes guide also explains how to use NFS through a host mount and a Local volume ([#411](https://github.com/David-Crty/databasement/pull/411))
- `1.6.8` Volumes can carry a storage limit in GB: a backup whose upload would push the volume over the limit fails before uploading, without retry, and sends a notification explaining why; the volume list shows current usage next to the limit ([#458](https://github.com/David-Crty/databasement/pull/458))
- `1.6.8` A per-volume notify-only option for the storage limit uploads the backup anyway and sends a warning notification instead of failing it ([#462](https://github.com/David-Crty/databasement/pull/462))
- `1.6.8` Traditional Chinese (zh-TW) translation ([#456](https://github.com/David-Crty/databasement/pull/456))
- `1.6.7` New `user:reset-password` Artisan command to reset a user's password from the command line when email delivery is not configured ([#453](https://github.com/David-Crty/databasement/pull/453))
- `1.6.6` MongoDB servers can use SRV connection strings (`mongodb+srv://`, as used by MongoDB Atlas) and pass extra connection options such as `tls=true` or `replicaSet=rs0`; the password is masked in logged dump and restore commands ([#444](https://github.com/David-Crty/databasement/pull/444))
- `1.6.5` Azure Blob Storage volume type, with an optional custom endpoint for sovereign clouds, Azure-compatible gateways and the Azurite emulator ([#443](https://github.com/David-Crty/databasement/pull/443))
- `1.6.3` Optional multithreaded compression for zstd and 7z, off by default, spreads compression across all CPU cores for local and remote-agent backups; the compression level is now a slider capped per algorithm (9 for gzip and 7z, 19 for zstd) ([#436](https://github.com/David-Crty/databasement/pull/436))
- `1.6.0` Roles are editable at runtime: the new Roles tab under Configuration lets super admins build custom roles from a catalogue of abilities alongside the built-in Admin, Member, Operator and Viewer roles, and users can be granted extra abilities on top of their role in each organization ([#409](https://github.com/David-Crty/databasement/pull/409))
- `1.6.0` The documentation site is versioned per minor release, with a version switcher in the navbar and older versions served under their own path ([#427](https://github.com/David-Crty/databasement/pull/427))

### Changed

- `1.6.10` Maintenance release with no application changes
- `1.6.7` MySQL and MariaDB dumps no longer repeat every column name on every `INSERT` row, which inflated dump size for no benefit since Restore recreates each table from the dump before inserting its rows ([#452](https://github.com/David-Crty/databasement/pull/452))
- `1.6.5` The multithreaded compression setting also applies to decompression during Restore, so zstd and 7z snapshots are decompressed across all CPU cores ([#442](https://github.com/David-Crty/databasement/pull/442))
- `1.6.5` Configuration screens share a consistent card heading and table layout that stacks on small screens, tooltips on table buttons also show on small screens, and the Organizations card no longer shows its title twice ([#441](https://github.com/David-Crty/databasement/pull/441))
- `1.6.1` Maintenance release with internal changes only
- `1.6.0` Create and edit pages for agents, database servers and volumes have a Back button in the header, and the volume list shows the volume type as a badge with its icon

### Fixed

- `1.6.12` The Docker image declares port 2226, so reverse proxies such as Traefik detect the port the app listens on instead of the base image's 80 and 443 ([#485](https://github.com/David-Crty/databasement/pull/485))
- `1.6.11` The registration and password forms (forgot, reset, confirm) show validation errors inline and keep non-secret fields filled in after a failed submit, instead of bouncing back to a blank form ([#482](https://github.com/David-Crty/databasement/pull/482))
- `1.6.9` Snapshots on Local volumes can be downloaded when the queue worker and the web server run as different users, as in the stock Docker setup; directories created by backups were private to the worker, so downloads returned 404 ([#473](https://github.com/David-Crty/databasement/pull/473))
- `1.6.8` Backup jobs no longer hang when the mail server is unreachable: outgoing mail times out (`MAIL_TIMEOUT`, default 10 seconds), notification errors are logged instead of stalling or failing the job, one broken channel no longer blocks delivery to the others, and Discord, Telegram and Pushover channels pick up an edited token without a worker restart ([#466](https://github.com/David-Crty/databasement/pull/466))
- `1.6.8` The logo link honours a subpath in `APP_URL` when the app is hosted behind a reverse proxy under a prefix ([#459](https://github.com/David-Crty/databasement/pull/459))
- `1.6.6` Tab content such as the commands in the "How to update?" modal and the agent token instructions was invisible in production builds ([#451](https://github.com/David-Crty/databasement/pull/451))
- `1.6.4` PostgreSQL TLS connections work in the Docker image: the application user inherited an unreadable `HOME`, which made the PostgreSQL client silently fall back to an unencrypted connection with `sslmode=prefer` or fail outright with `sslmode=require` ([#438](https://github.com/David-Crty/databasement/pull/438))
- `1.6.2` Links inside older documentation versions stay within that version instead of jumping to the latest docs ([#429](https://github.com/David-Crty/databasement/pull/429))
- `1.6.0` Configuration screens no longer glitch at narrow widths: ability badges stay on one line with the roles table scrolling horizontally, the two Adminer alerts are spaced apart, and long values such as `TRUSTED_PROXIES` are truncated with the full value shown on hover

## [1.5] - 2026-06-26 {#v1-5}

### Added

- `1.5.6` A new Operator role sits between Viewer and Member: it can run Backups and Restores, download Snapshots and manage Restores, but cannot change server, volume or schedule configuration, delete Snapshots or manage users ([#408](https://github.com/David-Crty/databasement/pull/408))
- `1.5.4` Hook scripts can run after every successful Backup and Restore, configured under Configuration → Backup; they receive the job context as `BACKUP_*` / `RESTORE_*` environment variables, stream their output into the job log, never fail the job on a non-zero exit, and post-backup hooks also run on remote agents. The server page now shows a copyable server ID for use in scripts ([#398](https://github.com/David-Crty/databasement/pull/398))
- `1.5.3` Organizations can be merged: merging one into another moves all of its database servers, volumes, agents and SSH configurations, combines the members (an existing role in the destination is kept), and deletes the source; merges and deletions now run as background jobs in a single transaction and are recorded in the log ([#401](https://github.com/David-Crty/databasement/pull/401))
- `1.5.3` Telegram notification channels take an optional Topic ID so messages go to a specific topic of a forum group ([#403](https://github.com/David-Crty/databasement/pull/403))
- `1.5.0` The remote agent Backup pathway is back: an agent installed next to the database connects to Databasement over outbound HTTPS, so firewalled environments no longer need inbound SSH access; the agent's connection status is shown in the Agents list, the server form and the server page ([#390](https://github.com/David-Crty/databasement/pull/390)) ([#394](https://github.com/David-Crty/databasement/pull/394))

### Changed

- `1.5.3` Success and failure notifications share one message format on every channel, including a single email template ([#404](https://github.com/David-Crty/databasement/pull/404))
- `1.5.2` Remote agents are no longer marked as Beta in the menu ([#388](https://github.com/David-Crty/databasement/pull/388)) ([#400](https://github.com/David-Crty/databasement/pull/400))

### Fixed

- `1.5.5` Deleting a Snapshot also removes the date folder it leaves empty on the volume, instead of letting empty folders pile up ([#405](https://github.com/David-Crty/databasement/pull/405)) ([#406](https://github.com/David-Crty/databasement/pull/406))
- `1.5.2` Saving or cancelling a database server edit takes you back to the page you came from instead of always landing on the server list ([#378](https://github.com/David-Crty/databasement/pull/378))
- `1.5.2` Failed Snapshots are once again left out of the Snapshot counters on the server list and server page, after the remote agent change in 1.5.0 undid that fix ([#397](https://github.com/David-Crty/databasement/pull/397))
- `1.5.1` Adminer is disabled for database servers reached through a remote agent, since those cannot be connected to directly ([#395](https://github.com/David-Crty/databasement/pull/395))
- `1.5.0` Failed Snapshots are no longer counted in the Snapshot counters on the server list and server page ([#377](https://github.com/David-Crty/databasement/pull/377))

### Security

- `1.5.6` The bundled SSH/SFTP library (phpseclib) is updated to 3.0.55, fixing CVE-2026-55599, where X.509 certificate validation could be made to send attacker-controlled outbound requests ([#407](https://github.com/David-Crty/databasement/pull/407))

## [1.4] - 2026-06-16 {#v1-4}

### Added

- `1.4.1` Greek is available as an interface language ([#372](https://github.com/David-Crty/databasement/pull/372))
- `1.4.1` PostgreSQL servers can back up ownership and privilege information; the choice is recorded in the snapshot so restores apply it regardless of the target server's own setting ([#370](https://github.com/David-Crty/databasement/pull/370))
- `1.4.0` Email notification channels accept multiple recipients ([#367](https://github.com/David-Crty/databasement/pull/367))
- `1.4.0` Backups can be enabled or disabled from the actions menu of the database server list ([#369](https://github.com/David-Crty/databasement/pull/369))

### Changed

- `1.4.2` The Docker image ships MongoDB's official `mongodump` and `mongorestore` (100.17.0, amd64 and arm64) instead of the Alpine edge package ([#379](https://github.com/David-Crty/databasement/pull/379))

### Removed

- `1.4.0` **Breaking:** The remote agent mode (`DATABASEMENT_URL`, the `agent:run` command, agent pages and API) is removed in favor of SSH tunnels; a migration drops the agent tables ([#365](https://github.com/David-Crty/databasement/pull/365))

### Fixed

- `1.4.2` SQL Server backups and restores work on arm64 images, where `sqlpackage` was previously shipped as a non-functional x64 binary ([#381](https://github.com/David-Crty/databasement/pull/381))
- `1.4.1` Restores are refused for snapshots that are not completed or have no backup file, background jobs no longer error when their record was deleted while they ran, and temporary SSH credential files are cleaned up when a tunnel fails to start ([#373](https://github.com/David-Crty/databasement/pull/373))
- `1.4.0` Weekly and monthly retention keeps the newest snapshot of each period instead of the oldest, so the monthly tier no longer lags by up to a month ([#364](https://github.com/David-Crty/databasement/pull/364))

## [1.3] - 2026-06-06 {#v1-3}

### Added

- `1.3.0` Scheduled Restores: the latest Snapshot of one server can be restored onto another server automatically on an existing backup schedule, managed from a new Scheduled Restores page and the API; a backup schedule in use by a Scheduled Restore can no longer be deleted ([#321](https://github.com/David-Crty/databasement/pull/321))
- `1.3.0` Built-in Adminer database browser: browse a registered server's data from its server page, enabled by default for admins and controlled from Configuration > Application, with read-only access for the demo user (not available for servers reached through an SSH tunnel or a remote agent) ([#199](https://github.com/David-Crty/databasement/pull/199))

### Fixed

- `1.3.1` Scheduled Restores require a source database name, hide Redis servers from the source picker and ask for the schedule before source and target; SQLite and Firebird destination paths are validated the same way in the Restore wizard, the Scheduled Restore wizard and the API, and the SQLite destination autocomplete suggests full file paths instead of bare file names ([#362](https://github.com/David-Crty/databasement/pull/362))

## [1.2] - 2026-06-03 {#v1-2}

### Added

- `1.2.11` Firebird 3, 4 and 5 databases can be backed up and restored; each `.fdb` file is selected by path like SQLite while keeping host, port and credentials, and the Backup and Restore forms accept path-style database names for it ([#357](https://github.com/David-Crty/databasement/pull/357)) ([#356](https://github.com/David-Crty/databasement/pull/356))
- `1.2.6` `OAUTH_ONLY_MODE=true` hides the password login form, rejects password logins server-side and turns off password reset; first-user registration still works so an instance can be set up before its identity provider is connected ([#332](https://github.com/David-Crty/databasement/pull/332))
- `1.2.5` PostgreSQL servers have a "Dump format" setting: the custom format writes `.dump` archives that are restored with 4 parallel `pg_restore` workers (the target server must be PostgreSQL 17 or newer); plain SQL stays the default ([#330](https://github.com/David-Crty/databasement/pull/330))
- `1.2.5` An Ed25519 SSH keypair can be generated from the database server form when using private key authentication; the public key is shown once with a copy button for the SSH server's `authorized_keys` file ([#324](https://github.com/David-Crty/databasement/pull/324))
- `1.2.4` Each database server has a read-only page, linked from its name in the list, that summarises connection, SSH, agent, backup and notification settings and shows the server's latest jobs; row actions move into a dropdown and every backup configuration gets a "Backup now" button ([#319](https://github.com/David-Crty/databasement/pull/319))
- `1.2.3` MySQL and MariaDB servers have a "Use SSL" toggle for managed databases that enforce TLS, such as Amazon RDS with `require_secure_transport=ON` ([#314](https://github.com/David-Crty/databasement/pull/314))
- `1.2.2` Microsoft SQL Server can be backed up and restored: backups are taken with Microsoft's `sqlpackage` tool and restores re-import them after dropping the existing target database ([#293](https://github.com/David-Crty/databasement/pull/293))
- `1.2.0` Multi-organization support: users can belong to several organizations, with a role per organization, and switch between them from the sidebar; database servers, volumes, agents, Snapshots and backup jobs belong to the current organization, and existing data is moved into a "Default" organization on upgrade ([#275](https://github.com/David-Crty/databasement/pull/275)) ([#282](https://github.com/David-Crty/databasement/pull/282))
- `1.2.0` Organizations are managed from Configuration → Organizations (create, edit, delete with resource checks, invitation links, member counts); organization admins manage the users of their own organization while super admins manage all ([#275](https://github.com/David-Crty/databasement/pull/275))
- `1.2.0` The API lists the caller's organizations at `GET /api/v1/user/organizations` and accepts an `X-Organization-Id` header to act in a given organization, the MCP server gains a tool to list organizations and switch context, and users created through OAuth join the organization of their invitation link or `OAUTH_DEFAULT_ORGANIZATION_ID` ([#275](https://github.com/David-Crty/databasement/pull/275))

### Changed

- `1.2.9` Maintenance release with no application changes
- `1.2.8` The Configuration → Application page lists `APP_DISPLAY_TIMEZONE` as the timezone setting instead of `TZ`
- `1.2.5` **Breaking:** SQL Server backups produce DACPAC instead of BACPAC files, so backups of on-premises instances no longer fail Azure compatibility validation; Snapshots taken before this release (`.bacpac.gz`) can no longer be restored ([#318](https://github.com/David-Crty/databasement/pull/318))
- `1.2.3` The Jobs page is split into separate Snapshots and Restores pages, notification links point at the right one, and the Restore modal opens from a server row, a Snapshot row or a "New Restore" button with the known fields pre-selected ([#308](https://github.com/David-Crty/databasement/pull/308))
- `1.2.3` The Snapshots and Restores lists are redesigned: rows are tinted by state, IDs are shown compactly with a popover and are searchable, the Created and Status columns are sortable, a Restore can be re-run from its row, and list polling drops from 5 to 30 seconds ([#312](https://github.com/David-Crty/databasement/pull/312))
- `1.2.0` Admins always see the delete and remove-from-organization buttons for users they have authority over; when a rule blocks the action (user in several organizations, last super admin), the confirmation dialog explains why ([#275](https://github.com/David-Crty/databasement/pull/275))

### Removed

- `1.2.3` The `MYSQL_CLI_TYPE` environment variable is no longer read; the MariaDB client is always used ([#314](https://github.com/David-Crty/databasement/pull/314))

### Fixed

- `1.2.12` A scheduled Backup in All or Pattern mode that cannot reach its database (host down, backup user removed) now records a failed Snapshot and sends the failure notification instead of producing nothing, so the dashboard, API and monitoring see the incident ([#359](https://github.com/David-Crty/databasement/pull/359))
- `1.2.10` Modal dialogs no longer extend behind the address and navigation bars of mobile browsers, keeping the close button and action row reachable ([#341](https://github.com/David-Crty/databasement/pull/341))
- `1.2.10` Timestamps on the health and debug pages are rendered in the display timezone ([#340](https://github.com/David-Crty/databasement/pull/340))
- `1.2.7` Timestamps are stored in UTC and rendered in `APP_DISPLAY_TIMEZONE` (the container entrypoint carries an existing `TZ` value over, in the Helm chart's worker and migration pods too), so jobs no longer show wrong durations or a false failed state when the web and worker containers disagree on the system timezone ([#336](https://github.com/David-Crty/databasement/pull/336))
- `1.2.4` The edit form no longer freezes for 30 seconds when the database host is unreachable: database discovery uses a 5-second connection timeout and is skipped for agent-backed servers ([#320](https://github.com/David-Crty/databasement/pull/320))
- `1.2.3` The default `postgres` database is included in the list of available PostgreSQL databases, so data stored there is no longer left out of backups ([#315](https://github.com/David-Crty/databasement/pull/315))
- `1.2.1` Backup jobs left running or pending after a queue worker crash are marked failed by the scheduled `jobs:recover-stuck` command (formerly `agent:recover-leases`) once they exceed the job timeout plus a 5-minute grace period ([#287](https://github.com/David-Crty/databasement/pull/287))
- `1.2.1` The update check no longer reports an older "latest version" from a stale cache after upgrading ([#288](https://github.com/David-Crty/databasement/pull/288))
- `1.2.1` SQLite backups no longer fail with "unable to open database file" on WAL-mode databases whose `-shm` file does not exist, because the `-readonly` flag is no longer passed to `sqlite3` ([#284](https://github.com/David-Crty/databasement/pull/284))

### Security

- `1.2.11` The `shell-quote` package pulled in by the local `npm` development tooling is upgraded to 1.8.4 to fix CVE-2026-9277 (command injection) ([#343](https://github.com/David-Crty/databasement/pull/343))
- `1.2.2` The SQL Server password is masked in logged `sqlpackage` commands

## [1.1] - 2026-05-06 {#v1-1}

### Added

- `1.1.0` **Breaking:** A database server can have several Backup configurations, each with its own schedule, volume, retention policy and database selection; API clients must send and read a `backups` array instead of the single `backup` object, with the database selection fields moved into each entry, and the MCP `trigger-backup` tool accepts an optional `backup_id` ([#212](https://github.com/David-Crty/databasement/pull/212))
- `1.1.0` Each Backup configuration on the database server list has its own "Backup now" button, so a single configuration can run without triggering all of them, and the success toast names which one ran ([#226](https://github.com/David-Crty/databasement/pull/226))

### Changed

- `1.1.7` The Configuration page is split into Application, Backup, Notification and Authentication tabs, each with its own URL ([#264](https://github.com/David-Crty/databasement/pull/264))
- `1.1.5` The theme follows the operating system's light or dark preference by default instead of always starting dark; the choice is stored in the browser, and an existing cookie preference is carried over on the next visit ([#262](https://github.com/David-Crty/databasement/pull/262))
- `1.1.4` The application runs on PHP 8.5 and Laravel 13; the Docker image ships PHP 8.5 and installs from source need it ([#239](https://github.com/David-Crty/databasement/pull/239))
- `1.1.2` The database server list uses fixed column widths, inline action icons instead of a dropdown menu, and a plain tooltip for the connection status ([#232](https://github.com/David-Crty/databasement/pull/232))

### Removed

- `1.1.4` Laravel Octane is gone and FrankenPHP serves requests directly; the `OCTANE_ENABLED`, `OCTANE_WORKERS` and `OCTANE_MAX_REQUESTS` variables no longer have any effect ([#237](https://github.com/David-Crty/databasement/pull/237))

### Fixed

- `1.1.7` Pagination controls follow the active theme instead of default Tailwind styling, no longer flash a scrollbar, and are translated in French and Spanish ([#277](https://github.com/David-Crty/databasement/pull/277))
- `1.1.7` Navigating between pages no longer flashes the wrong theme for an instant ([#276](https://github.com/David-Crty/databasement/pull/276))
- `1.1.6` Toast notifications are displayed on the login and other authentication pages
- `1.1.6` The Copy buttons in the "How to update?" dialog show their "Copied to clipboard" confirmation again instead of failing
- `1.1.4` SQLite no longer fails with "database is locked" under concurrent load: write transactions take their lock up front (`DB_TRANSACTION_MODE`, default `IMMEDIATE`), WAL journal mode is dropped because its sidecar files left stale locks and do not work on NFS volumes (so `DB_JOURNAL_MODE` no longer applies), and `db:wait` releases its connection between retries ([#236](https://github.com/David-Crty/databasement/pull/236))
- `1.1.4` `db:wait --check-migrations` keeps polling while migrations are pending instead of crashing with a stack trace ([#240](https://github.com/David-Crty/databasement/pull/240))
- `1.1.3` SQLite waits up to 5 seconds for a lock (`DB_BUSY_TIMEOUT`) and uses WAL journal mode (`DB_JOURNAL_MODE`), so the web server, queue worker and scheduler writing at the same time no longer fail with "database is locked" ([#233](https://github.com/David-Crty/databasement/pull/233))
- `1.1.3` The database server list fits on phone screens: the Backup and Jobs columns are hidden on small screens, and the per-configuration "Backup now" button only appears when a server has more than one Backup configuration ([#234](https://github.com/David-Crty/databasement/pull/234))
- `1.1.1` Running a version newer than the latest published release is reported as up to date instead of outdated ([#227](https://github.com/David-Crty/databasement/pull/227))
- `1.1.1` Backup labels on the database server list wrap on small screens instead of overflowing the card ([#228](https://github.com/David-Crty/databasement/pull/228))
- `1.1.0` Notifications after saving or deleting are shown as toasts that sit above other elements and have a proper close icon, replacing the inline status banners ([#224](https://github.com/David-Crty/databasement/pull/224))

## [1.0] - 2026-04-15 {#v1-0}

### Added

- `1.0.12` The snapshots API accepts a `filter[database_server_id]` parameter to list the snapshots of a single server ([#216](https://github.com/David-Crty/databasement/pull/216))
- `1.0.12` The footer shows whether a newer release is available and opens update instructions for Docker Compose, Helm and Docker with copy buttons; the check runs only on tagged builds, and untagged builds show their short commit hash instead ([#217](https://github.com/David-Crty/databasement/pull/217)) ([#222](https://github.com/David-Crty/databasement/pull/222))
- `1.0.10` Notification channels are managed as a list in Configuration (add, edit, delete and test each one) and can be assigned per database server; backups and restores can notify on success as well as failure, defaulting to failures only, and existing notification settings are migrated automatically ([#205](https://github.com/David-Crty/databasement/pull/205))
- `1.0.9` OIDC logins can be assigned a role from a group claim: `OAUTH_OIDC_ROLE_CLAIM` names the claim, `OAUTH_OIDC_ROLE_MAP_ADMIN`, `OAUTH_OIDC_ROLE_MAP_MEMBER` and `OAUTH_OIDC_ROLE_MAP_VIEWER` list the matching groups, and `OAUTH_OIDC_ROLE_STRICT` denies access to users in none of them; works with Keycloak, Authentik and Dex ([#202](https://github.com/David-Crty/databasement/pull/202))
- `1.0.8` Discord notifications can be sent through a webhook URL, without creating a bot or granting it server permissions ([#195](https://github.com/David-Crty/databasement/pull/195))
- `1.0.4` REST API endpoints to create, read, update and delete database servers, volumes and backup schedules, plus endpoints to test a database server or volume connection ([#178](https://github.com/David-Crty/databasement/pull/178))
- `1.0.3` Database servers accept extra dump flags (for example `--no-tablespaces` or `--column-statistics=0`) that are appended to the MySQL, PostgreSQL, MongoDB and Redis dump commands, with a preview of the resulting command; input is validated and each flag is shell-escaped ([#175](https://github.com/David-Crty/databasement/pull/175)) ([#177](https://github.com/David-Crty/databasement/pull/177))
- `1.0.0` Initial stable release

### Changed

- `1.0.11` The database server form merges database selection and backup configuration into a single step grouped by what, where, when and how long to keep, with a segmented retention picker and a live summary of the backup plan ([#210](https://github.com/David-Crty/databasement/pull/210))
- `1.0.5` Restores no longer drop and recreate the target database on MySQL and PostgreSQL, so schema ownership and grants set up outside the dump survive; the restore dialog gains a "drop and recreate" option for a clean slate and, on PostgreSQL, an option to transfer ownership of the restored database to a given user ([#185](https://github.com/David-Crty/databasement/pull/185))
- `1.0.5` The documentation site and the API reference display the released version ([#183](https://github.com/David-Crty/databasement/pull/183))
- `1.0.1` The `:latest` Docker image is also published on version releases, so it carries the version shown in the footer, and the Helm chart index is served from the root of the GitHub Pages site so `helm repo add` works without a `/charts` suffix ([#171](https://github.com/David-Crty/databasement/pull/171))

### Fixed

- `1.0.12` The two-factor challenge sends you back to the login page with an explanation instead of a 500 error when its session data can no longer be decrypted, and the setup key copy button works over plain HTTP ([#219](https://github.com/David-Crty/databasement/pull/219)) ([#223](https://github.com/David-Crty/databasement/pull/223))
- `1.0.12` The login page no longer fails to render when an OAuth provider is enabled, as the provider icons were looked up in the wrong icon set ([#218](https://github.com/David-Crty/databasement/pull/218)) ([#221](https://github.com/David-Crty/databasement/pull/221))
- `1.0.11` Helm: the worker container runs as UID 1000 instead of root, so local backups it writes can be read and downloaded by the web UI instead of returning 404; root-owned files already on the volume are fixed up on the first restart ([#215](https://github.com/David-Crty/databasement/pull/215))
- `1.0.11` Disabling notifications on a database server no longer blocks saving with an invisible "channels required" validation error ([#211](https://github.com/David-Crty/databasement/pull/211))
- `1.0.8` A failing Gotify or webhook notification no longer crashes the backup or restore job that triggered it, and errors from "send test notification" are shown to the user ([#197](https://github.com/David-Crty/databasement/pull/197))
- `1.0.7` Discord bot notifications are delivered again; the channel could not be resolved and every send failed ([#191](https://github.com/David-Crty/databasement/pull/191))
- `1.0.6` Deleting a database server that was the target of cross-server restores no longer leaves jobs stuck as "Pending" or "Unknown", and pending jobs can be cancelled from the Jobs page ([#188](https://github.com/David-Crty/databasement/pull/188))
- `1.0.5` An empty `TRUSTED_PROXIES` value falls back to the default private network ranges instead of trusting no proxy at all, which broke fresh Kubernetes installs ([#184](https://github.com/David-Crty/databasement/pull/184))
- `1.0.2` SQLite backups no longer miss recent writes on databases in WAL mode: the SQLite client's online backup is used instead of copying the file, remote SQLite over SFTP also fetches the `-wal` and `-shm` companion files (flagged best-effort when present), and a missing source file fails the backup instead of producing an empty one ([#174](https://github.com/David-Crty/databasement/pull/174))

[1.7]: https://github.com/David-Crty/databasement/compare/v1.6.12...v1.7.11
[1.6]: https://github.com/David-Crty/databasement/compare/v1.5.6...v1.6.12
[1.5]: https://github.com/David-Crty/databasement/compare/v1.4.2...v1.5.6
[1.4]: https://github.com/David-Crty/databasement/compare/v1.3.1...v1.4.2
[1.3]: https://github.com/David-Crty/databasement/compare/v1.2.12...v1.3.1
[1.2]: https://github.com/David-Crty/databasement/compare/v1.1.7...v1.2.12
[1.1]: https://github.com/David-Crty/databasement/compare/v1.0.12...v1.1.7
[1.0]: https://github.com/David-Crty/databasement/compare/v1.0.0...v1.0.12
