---
sidebar_position: 3
---

# Kubernetes + Helm

This guide will help you deploy Databasement on Kubernetes using Helm.

## Prerequisites

- A Kubernetes cluster
- [Helm](https://helm.sh/docs/intro/install/) v3.x installed
- [kubectl](https://kubernetes.io/docs/tasks/tools/install-kubectl/) configured for your cluster

## Installation

### 1. Add the Helm Repository

```bash
helm repo add databasement https://david-crty.github.io/databasement/charts
helm repo update
```

### 2. Generate an Application Key

Before deploying, generate an application encryption key:

```bash
docker run --rm davidcrty/databasement:latest php artisan key:generate --show
```

Copy the output (e.g., `base64:abc123...`) for use in your values file.

### 3. Create a Values File

#### Minimal Configuration (SQLite)

For simple deployments using SQLite:

```yaml title="values.yaml"
app:
  url: https://backup.yourdomain.com
  key: "base64:your-generated-key-here"

ingress:
  enabled: true
  className: nginx
  host: backup.yourdomain.com
  tlsSecretName: databasement-tls  # Optional: for HTTPS
```

#### Production Configuration (External Database)

For production with an external MySQL/PostgreSQL database:

```yaml title="values.yaml"
app:
  name: Databasement
  env: production
  debug: false
  url: https://backup.yourdomain.com
  key: "base64:your-generated-key-here"

database:
  connection: mysql  # or pgsql
  host: your-mysql-host.example.com
  port: 3306
  name: databasement
  username: databasement
  password: your-secure-password

ingress:
  enabled: true
  className: nginx
  annotations:
    cert-manager.io/cluster-issuer: letsencrypt-prod
  host: backup.yourdomain.com
  tlsSecretName: databasement-tls

resources:
  limits:
    cpu: 500m
    memory: 512Mi
  requests:
    cpu: 100m
    memory: 256Mi

persistence:
  enabled: true
  size: 10Gi
  storageClass: ""  # Use default storage class
```

### 4. Install the Chart

```bash
helm install databasement databasement/databasement \
  -f values.yaml \
  -n databasement \
  --create-namespace
```

### 5. Verify the Deployment

```bash
kubectl get pods -n databasement
kubectl get svc -n databasement
kubectl get ingress -n databasement
```

## Configuration Reference

### Application Settings

| Parameter | Description | Default |
|-----------|-------------|---------|
| `app.name` | Application name | `Databasement` |
| `app.env` | Environment (`production`, `local`) | `production` |
| `app.debug` | Enable debug mode | `false` |
| `app.url` | Public URL | `http://localhost:8000` |
| `app.key` | Application encryption key (required) | `""` |
| `app.existingSecret` | Use existing secret for app key | `""` |

### Database Settings

| Parameter | Description | Default |
|-----------|-------------|---------|
| `database.connection` | Database type (`sqlite`, `mysql`, `pgsql`) | `sqlite` |
| `database.sqlitePath` | SQLite database path | `/app/database/database.sqlite` |
| `database.host` | Database host (for mysql/pgsql) | `""` |
| `database.port` | Database port | `3306` |
| `database.name` | Database name | `databasement` |
| `database.username` | Database username | `databasement` |
| `database.password` | Database password | `""` |
| `database.existingSecret` | Use existing secret for DB password | `""` |

### Ingress Settings

| Parameter | Description | Default |
|-----------|-------------|---------|
| `ingress.enabled` | Enable ingress | `false` |
| `ingress.className` | Ingress class name | `""` |
| `ingress.annotations` | Ingress annotations | `{}` |
| `ingress.host` | Hostname for the ingress | `databasement.local` |
| `ingress.tlsSecretName` | TLS secret name (enables HTTPS) | `""` |

### Storage Settings

| Parameter | Description | Default |
|-----------|-------------|---------|
| `persistence.enabled` | Enable persistent storage | `true` |
| `persistence.storageClass` | Storage class name | `""` (default) |
| `persistence.accessModes` | PVC access modes | `[ReadWriteOnce]` |
| `persistence.size` | PVC size | `10Gi` |

### Other Settings

| Parameter | Description | Default |
|-----------|-------------|---------|
| `replicaCount` | Number of replicas | `1` |
| `image.repository` | Image repository | `david-crty/databasement` |
| `image.tag` | Image tag | `latest` |
| `serviceAccount.create` | Create service account | `true` |
| `resources.limits.cpu` | CPU limit | `500m` |
| `resources.limits.memory` | Memory limit | `512Mi` |
| `resources.requests.cpu` | CPU request | `100m` |
| `resources.requests.memory` | Memory request | `256Mi` |

## Using External Secrets

For production, use Kubernetes secrets instead of plain values:

```yaml title="values.yaml"
app:
  existingSecret: databasement-secrets
  secretKeys:
    appKey: APP_KEY

database:
  connection: mysql
  host: mysql.example.com
  existingSecret: databasement-db-secrets
  secretKeys:
    password: DB_PASSWORD
```

Create the secrets:

```bash
kubectl create secret generic databasement-secrets \
  --from-literal=APP_KEY='base64:your-key-here' \
  -n databasement

kubectl create secret generic databasement-db-secrets \
  --from-literal=DB_PASSWORD='your-db-password' \
  -n databasement
```

## Upgrading

```bash
helm repo update
helm upgrade databasement databasement/databasement -f values.yaml -n databasement
```

## Uninstalling

```bash
helm uninstall databasement -n databasement
```

:::caution
This will not delete the PersistentVolumeClaim by default. To delete all data:
```bash
kubectl delete pvc -l app.kubernetes.io/name=databasement -n databasement
```
:::

## Troubleshooting

### Check Pod Logs

```bash
kubectl logs -f deployment/databasement -n databasement
```

### Check Pod Status

```bash
kubectl describe pod -l app.kubernetes.io/name=databasement -n databasement
```

### Access Pod Shell

```bash
kubectl exec -it deployment/databasement -n databasement -- sh
```

### Run Artisan Commands

```bash
kubectl exec deployment/databasement -n databasement -- php artisan migrate:status
kubectl exec deployment/databasement -n databasement -- php artisan config:show database
```
