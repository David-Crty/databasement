# Migrate to 1.10

> Version 1.10 changes how the queue worker starts. Only **Docker Compose** installations that run a separate `worker` service need to update their `docker-compose.yml`:

# Migrate to 1.10

Version 1.10 changes how the queue worker starts. Only **Docker Compose** installations that run a separate `worker` service need to update their `docker-compose.yml`:

- **Single container** (`docker run` with `ENABLE_QUEUE_WORKER=true`): nothing to do.
- **Helm**: chart 1.10 applies the change for you. If you set `worker.command` in your values, remove it: it is now ignored.

## Why

The old `command:` made the worker run as **root**, so the app could not delete the backups it wrote (`Unable to delete file`). From 1.10 the worker starts like the app, as the application user.

## Update your docker-compose.yml

In the `worker` service, remove the `command:` line and add the `environment:` block:

```diff title="docker-compose.yml"
   worker:
     image: davidcrty/databasement:1
     restart: unless-stopped
+    environment:
+      ENABLE_QUEUE_WORKER: "true"
+      ENABLE_WEB_SERVER: "false"
+      ENABLE_SCHEDULER: "false"
     deploy:
       replicas: 1   # increase to run more backups in parallel
-    command: sh -c "php artisan db:wait --check-migrations && php artisan queue:work --queue=backups,default --tries=3 --timeout=3600 --sleep=3 --max-jobs=1000"
     env_file: .env
     volumes:
       - ./data:/data
```

If you set `PUID`/`PGID`, keep them on **both** the `app` and `worker` services (or in the shared `.env` file) so the two containers run as the same user.

Then pull and recreate:

```bash
docker compose pull
docker compose up -d
```

## If you keep the old command

A 1.10 image still recognises the old `command:` and starts the worker the new way, but it logs a warning on every start and **ignores the arguments** of that command. If you customised them (for example a different `--queue`), move to the environment variables above.

## Files written by an older worker

Backup files the old worker wrote under `/data` are handed back to the application user automatically when the app container starts.

Backups stored on a local volume **outside** `/data` (for example a `/backups` mount) stay owned by root, and the worker can no longer write into those directories. Hand them back once, replacing `1000` with your `PUID`/`PGID`:

```bash
sudo chown -R 1000:1000 /path/to/backups
```

The same applies to SQLite databases you back up from a path outside `/data`: the worker now reads them as the application user, so that user needs read access to them.
